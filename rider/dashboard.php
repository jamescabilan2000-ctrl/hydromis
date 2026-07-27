<?php
include 'check_auth.php';
require_once '../config/database.php';

$column_exists = function ($table, $column) use ($conn) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column' LIMIT 1");
    return $result && $result->num_rows > 0;
};

// DummyDeliveriesResult class
class DummyDeliveriesResult {
    private $data;
    private $index = 0;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function fetch_assoc() {
        if ($this->index < count($this->data)) {
            return $this->data[$this->index++];
        }
        return null;
    }
    
    public function __get($name) {
        if ($name === 'num_rows') {
            return count($this->data);
        }
        return null;
    }
}

// Make rider tables/columns available even on first run.
$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id BIGSERIAL PRIMARY KEY,
    rider_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    age INT,
    address TEXT,
    contact_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS age INT");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS address TEXT");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50)");
$conn->query("CREATE TABLE IF NOT EXISTS rider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    rider_id VARCHAR(50) NOT NULL,
    rider_latitude DECIMAL(10, 8) NOT NULL,
    rider_longitude DECIMAL(11, 8) NOT NULL,
    accuracy FLOAT,
    speed FLOAT,
    heading FLOAT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    INDEX idx_rider_id (rider_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
)");
$conn->query("CREATE TABLE IF NOT EXISTS rider_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_id (rider_id),
    INDEX idx_is_read (is_read)
)");
$transactions_has_assigned_rider = $column_exists('transactions', 'assigned_rider');
$transaction_rider_expr = $transactions_has_assigned_rider ? "COALESCE(t.rider_id, t.assigned_rider)" : "t.rider_id";

$rider_id = sanitize($_SESSION['rider_id']);
$session_username = sanitize($_SESSION['username'] ?? '');
$session_full_name = sanitize($_SESSION['full_name'] ?? '');
$rider_flash = $_SESSION['rider_flash'] ?? '';
unset($_SESSION['rider_flash']);
$assignment_mismatch_warning = '';
$other_assigned_deliveries = [];

// Rider can ONLY see orders assigned to them specifically
$rider_scope_ids = [];
if ($rider_id !== '') {
    $rider_scope_ids[] = $rider_id;
} else {
    // If no rider_id in session, try to get it from database using username/full_name
    if ($session_username !== '') {
        $check_sql = "SELECT rider_id FROM rider_users WHERE username = '$session_username' LIMIT 1";
        $check_res = $conn->query($check_sql);
        if ($check_res && $row = $check_res->fetch_assoc()) {
            $rider_scope_ids[] = $row['rider_id'];
            $_SESSION['rider_id'] = $row['rider_id'];
        }
    } elseif ($session_full_name !== '') {
        $check_sql = "SELECT rider_id FROM rider_users WHERE full_name = '$session_full_name' LIMIT 1";
        $check_res = $conn->query($check_sql);
        if ($check_res && $row = $check_res->fetch_assoc()) {
            $rider_scope_ids[] = $row['rider_id'];
            $_SESSION['rider_id'] = $row['rider_id'];
        }
    }
}

// If still no rider ID, show error
if (empty($rider_scope_ids)) {
    die('Error: Rider not properly authenticated. Please log in again.');
}

if (!empty($rider_scope_ids)) {
    $rider_id = sanitize($rider_scope_ids[0]);
    $_SESSION['rider_id'] = $rider_scope_ids[0];
}

$escaped_scope_ids = [];
foreach ($rider_scope_ids as $scope_id) {
    $escaped_scope_ids[] = "'" . $conn->real_escape_string($scope_id) . "'";
}
if (empty($escaped_scope_ids)) {
    $escaped_scope_ids[] = "''";
}
$rider_scope_condition = 'IN (' . implode(', ', $escaped_scope_ids) . ')';
$rider_not_scope_condition = 'NOT IN (' . implode(', ', $escaped_scope_ids) . ')';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');
    $sql = '';

        if (($action === 'accept' || $action === 'start_delivery') && $transaction_id !== '') {
            $sql = "UPDATE transactions
                                SET delivery_status = 'on_way'
                    WHERE transaction_id = '{$transaction_id}'
                                    AND status = 'approved'
                      AND COALESCE(NULLIF(delivery_status, ''), 'assigned') IN ('assigned', 'pending', 'on_way')
                                    AND {$transaction_rider_expr} {$rider_scope_condition}";
            $_SESSION['rider_flash'] = "Status updated: {$transaction_id} is now On the Way.";
    } elseif ($action === 'complete' && $transaction_id !== '') {
        $sql = "UPDATE transactions
                SET delivery_status = 'delivered'
                WHERE transaction_id = '{$transaction_id}'
                                    AND status = 'approved'
                                    AND {$transaction_rider_expr} {$rider_scope_condition}
                                    AND COALESCE(NULLIF(delivery_status, ''), 'assigned') IN ('assigned', 'pending', 'on_way', 'on_the_way')";
        $_SESSION['rider_flash'] = "Delivery completed: {$transaction_id}.";
    } elseif ($action === 'update_status' && $transaction_id !== '') {
        $new_status = sanitize($_POST['new_status'] ?? '');
        $allowed = ['assigned', 'pending', 'on_way', 'delivered'];
        if (in_array($new_status, $allowed, true)) {
                $sql = "UPDATE transactions
                                        SET delivery_status = '{$new_status}'
                    WHERE transaction_id = '{$transaction_id}'
                                            AND status = 'approved'
                                            AND {$transaction_rider_expr} {$rider_scope_condition}";
            $label = $new_status === 'on_way' ? 'On the Way' : ucfirst($new_status);
            $_SESSION['rider_flash'] = "Status updated: {$transaction_id} -> {$label}.";
        } else {
            $_SESSION['rider_flash'] = 'Invalid delivery status selected.';
        }
    }

    if ($sql !== '' && !$conn->query($sql)) {
        $_SESSION['rider_flash'] = 'Action failed: ' . $conn->error;
    }

    header('Location: dashboard.php');
    exit();
}

$notifications = [];
$notifications_result = $conn->query("SELECT id, transaction_id, title, message, created_at FROM rider_notifications WHERE rider_id = '$rider_id' AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
if ($notifications_result) {
    while ($notification = $notifications_result->fetch_assoc()) {
        $notifications[] = $notification;
    }
    if (!empty($notifications)) {
        $conn->query("UPDATE rider_notifications SET is_read = 1 WHERE rider_id = '$rider_id' AND is_read = 0");
    }
}

$assigned_deliveries = [];
$active_data = $conn->query("SELECT
        t.transaction_id,
        t.amount,
        t.delivery_status,
        t.rider_id,
        t.assigned_rider,
        COALESCE(u.full_name, 'Unknown Customer') AS full_name,
        COALESCE(u.contact_number, '-') AS contact_number,
        COALESCE(u.address, 'No address provided') AS address
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status != 'denied'
      AND (t.rider_id {$rider_scope_condition} OR t.assigned_rider {$rider_scope_condition})
      AND COALESCE(NULLIF(t.delivery_status, ''), 'assigned') IN ('assigned', 'pending', 'on_way', 'delivered')
    ORDER BY t.created_at DESC");

if ($active_data) {
    while ($row = $active_data->fetch_assoc()) {
        $effective_status = trim((string)($row['delivery_status'] ?? ''));
        if ($effective_status === '') {
            $effective_status = 'assigned';
        } elseif ($effective_status === 'pending' && !empty($row['transaction_id'])) {
            $effective_status = 'assigned';
        }
        $assigned_deliveries[] = [
            'id' => $row['transaction_id'],
            'customer' => $row['full_name'],
            'phone' => $row['contact_number'],
            'delivery' => $row['address'],
            'amount' => (float) $row['amount'],
            'status' => $effective_status
        ];
    }
}

// Fallback: force-include assigned/pending deliveries in Active tab data.
// Some environments can render Assigned tab data while Active query cache appears empty.
$pending_for_active = $conn->query("SELECT
        t.transaction_id,
        t.amount,
        t.rider_id,
        t.assigned_rider,
        COALESCE(u.full_name, 'Unknown Customer') AS full_name,
        COALESCE(u.contact_number, '-') AS contact_number,
        COALESCE(u.address, 'No address provided') AS address
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'approved'
      AND (t.rider_id {$rider_scope_condition} OR t.assigned_rider {$rider_scope_condition})
      AND COALESCE(NULLIF(t.delivery_status, ''), 'assigned') IN ('assigned', 'pending')
    ORDER BY t.created_at DESC
    LIMIT 50");

if ($pending_for_active) {
    $seen_ids = [];
    foreach ($assigned_deliveries as $delivery_row) {
        $seen_ids[$delivery_row['id']] = true;
    }

    while ($row = $pending_for_active->fetch_assoc()) {
        if (!isset($seen_ids[$row['transaction_id']])) {
            $assigned_deliveries[] = [
                'id' => $row['transaction_id'],
                'customer' => $row['full_name'],
                'phone' => $row['contact_number'],
                'delivery' => $row['address'],
                'amount' => (float) $row['amount'],
                'status' => 'assigned'
            ];
            $seen_ids[$row['transaction_id']] = true;
        }
    }
}

$available = $conn->query("SELECT
        t.transaction_id,
        t.amount,
        t.created_at,
        t.rider_id,
        t.assigned_rider,
        COALESCE(u.full_name, 'Unknown Customer') AS full_name,
        COALESCE(u.contact_number, '-') AS contact_number
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'approved'
      AND (t.rider_id {$rider_scope_condition} OR t.assigned_rider {$rider_scope_condition})
      AND t.delivery_status IN ('assigned', 'pending')
    ORDER BY t.created_at ASC
    LIMIT 20");

if (!$available) {
    $available = new DummyDeliveriesResult([]);
}

$completed_count_result = $conn->query("SELECT COUNT(*) AS count
    FROM transactions
    WHERE " . ($transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id") . " {$rider_scope_condition}
      AND delivery_status = 'delivered'
      AND DATE(updated_at) = CURRENT_DATE");
$completed_count = ($completed_count_result && $completed_count_result->num_rows > 0)
    ? (int) $completed_count_result->fetch_assoc()['count']
    : 0;

$total_delivered_result = $conn->query("SELECT COUNT(*) AS count
    FROM transactions
    WHERE " . ($transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id") . " {$rider_scope_condition}
      AND delivery_status = 'delivered'");
$total_delivered = ($total_delivered_result && $total_delivered_result->num_rows > 0)
    ? (int) $total_delivered_result->fetch_assoc()['count']
    : 0;

$cash_collected_result = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE " . ($transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id") . " {$rider_scope_condition}
      AND delivery_status = 'delivered'
      AND DATE(updated_at) = CURRENT_DATE");
$cash_collected_today = ($cash_collected_result && $cash_collected_result->num_rows > 0)
    ? (float) $cash_collected_result->fetch_assoc()['total']
    : 0;

$cash_collected_total_result = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE " . ($transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id") . " {$rider_scope_condition}
      AND delivery_status = 'delivered'");
$cash_collected_total = ($cash_collected_total_result && $cash_collected_total_result->num_rows > 0)
    ? (float) $cash_collected_total_result->fetch_assoc()['total']
    : 0;

$completed_deliveries = [];
$completed_data = $conn->query("SELECT
        t.transaction_id,
        t.amount,
        t.updated_at,
        COALESCE(u.full_name, 'Unknown Customer') AS full_name
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
        WHERE (t.rider_id {$rider_scope_condition} OR t.assigned_rider {$rider_scope_condition})
      AND t.delivery_status = 'delivered'
    ORDER BY t.updated_at DESC
    LIMIT 20");
if ($completed_data) {
    while ($row = $completed_data->fetch_assoc()) {
        $completed_deliveries[] = $row;
    }
}
$completed_display_count = count($completed_deliveries);

$active_count = 0;
foreach ($assigned_deliveries as $delivery_item) {
    if ($delivery_item['status'] !== 'delivered') {
        $active_count++;
    }
}
$orders_count = count($assigned_deliveries);
$commission_today = $completed_count * 150;
$commission_total = $total_delivered * 150;
$total_income_total = $cash_collected_total + $commission_total;
$earnings = $cash_collected_today;
$rating = max(4.5, min(5.0, 4.5 + ($total_delivered / 300)));
$_SESSION['rider_email'] = $_SESSION['username'] ?? ($_SESSION['rider_email'] ?? $_SESSION['rider_id']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/rider_dashboard.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <script src="../js/app-utilities.js"></script>
    <script src="../js/rider-gps-tracker.js"></script>
</head>
<body>
<?php include 'dashboard.html'; ?>
<?php if (!empty($rider_flash)): ?>
    <script>
        if (typeof Notifications !== 'undefined') {
            Notifications.success(<?php echo json_encode($rider_flash); ?>);
        } else {
            alert(<?php echo json_encode($rider_flash); ?>);
        }
    </script>
<?php endif; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
