<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';

$systemLogo = system_logo_path($conn);
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);

$delivery_operations_view = (($_GET['view'] ?? '') === 'deliveries');
$operations_section = strtolower((string)($_GET['section'] ?? 'deliveries'));
if (!in_array($operations_section, ['deliveries', 'pickups', 'feedbacks'], true)) $operations_section = 'deliveries';

function format_currency($amount) {
    return 'PHP ' . number_format((float)$amount, 2);
}

$flash_message = $_SESSION['staff_flash'] ?? '';
$flash_type = $_SESSION['staff_flash_type'] ?? 'info';
unset($_SESSION['staff_flash'], $_SESSION['staff_flash_type']);
$staff_id = sanitize($_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');

$column_exists = function ($table, $column) use ($conn) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column' LIMIT 1");
    return $result && $result->num_rows > 0;
};

$ensure_column = function ($table, $column, $definition) use ($conn, $column_exists) {
    if (!$column_exists($table, $column)) {
        $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
};

$scalar_value = function ($sql, $field, $default = 0) use ($conn) {
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc()) && array_key_exists($field, $row)) {
        return $row[$field];
    }
    return $default;
};

$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
$ensure_column('rider_users', 'age', 'INT');
$ensure_column('rider_users', 'address', 'TEXT');
$ensure_column('transactions', 'rider_id', 'VARCHAR(50)');
$ensure_column('transactions', 'delivery_status', "VARCHAR(30) DEFAULT 'pending'");
$ensure_column('transactions', 'approved_by', 'VARCHAR(50) NULL');
$transactions_has_assigned_rider = $column_exists('transactions', 'assigned_rider');
$transaction_rider_expr = $transactions_has_assigned_rider ? "COALESCE(t.rider_id, t.assigned_rider)" : "t.rider_id";
$conn->query("CREATE TABLE IF NOT EXISTS feedback_ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    rating INT NOT NULL,
    feedback_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feedback_transaction_user (transaction_id, user_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS rider_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL UNIQUE,
    rider_latitude DECIMAL(10, 8) NOT NULL DEFAULT 12.8797,
    rider_longitude DECIMAL(11, 8) NOT NULL DEFAULT 121.7740,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

$demo_pending_check = $conn->query("SELECT transaction_id FROM transactions WHERE transaction_id LIKE 'DEMO-%' LIMIT 1");
if (!$demo_pending_check || $demo_pending_check->num_rows === 0) {
    $demo_user_result = $conn->query("SELECT user_id FROM users ORDER BY created_at ASC LIMIT 1");
    if ($demo_user_result && $demo_user_result->num_rows > 0) {
        $demo_user = $demo_user_result->fetch_assoc();
        $demo_transaction_id = 'DEMO-' . strtoupper(bin2hex(random_bytes(4)));
        $demo_user_id = $conn->real_escape_string($demo_user['user_id']);
        $demo_amount = 1500;
        $conn->query("INSERT INTO transactions (transaction_id, user_id, amount, status, description, created_at) VALUES ('$demo_transaction_id', '$demo_user_id', $demo_amount, 'pending', 'Demo refill delivery request', NOW())");
    }
}

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $transaction_id = sanitize($_POST['transaction_id']);
    $action = sanitize($_POST['action']);
    $return_section = in_array(($_POST['return_section'] ?? ''), ['pickups', 'feedbacks'], true) ? $_POST['return_section'] : '';
    $sql = '';
    $assignment_notification = null;

    if ($action === 'approve') {
        [$approvedOrder, $stockMessage] = approve_or_cancel_order_for_stock($conn, $transaction_id, (string)$_SESSION['admin_id']);
        $sql = '';
        $_SESSION['staff_flash'] = $approvedOrder ? "Transaction {$transaction_id} approved successfully." : $stockMessage;
        $_SESSION['staff_flash_type'] = 'success';
    } elseif ($action === 'deny') {
        deny_order_and_release_stock($conn, $transaction_id, (string)$_SESSION['admin_id'], 'The staff could not proceed with your order. Please contact the shop for assistance.');
        $sql = '';
        $_SESSION['staff_flash'] = "Transaction {$transaction_id} denied.";
        $_SESSION['staff_flash_type'] = 'danger';
    } elseif ($action === 'on_the_way' || $action === 'on_way') {
        $sql = '';
        $_SESSION['staff_flash'] = 'Only the assigned rider can start a delivery.';
        $_SESSION['staff_flash_type'] = 'warning';
    } elseif ($action === 'delivered') {
        $sql = '';
        $_SESSION['staff_flash'] = 'Only the assigned rider can complete a delivery.';
        $_SESSION['staff_flash_type'] = 'warning';
    } elseif ($action === 'notify_pickup_ready') {
        $sql = '';
        $pickupResult = $conn->query("SELECT user_id FROM transactions
            WHERE transaction_id = '$transaction_id' AND status = 'approved'
              AND fulfillment_method = 'pickup' AND COALESCE(delivery_status, 'pending') <> 'delivered'
            LIMIT 1");
        $pickupOrder = $pickupResult ? $pickupResult->fetch_assoc() : null;
        if ($pickupOrder) {
            add_user_notification(
                $conn,
                (string)$pickupOrder['user_id'],
                $transaction_id,
                'Your pickup order is ready',
                'Your order is ready for collection at the HydroMIS water refilling station.',
                'order'
            );
            $_SESSION['staff_flash'] = "Customer notified that pickup order {$transaction_id} is ready.";
            $_SESSION['staff_flash_type'] = 'success';
        } else {
            $_SESSION['staff_flash'] = 'This pickup order is no longer available for notification.';
            $_SESSION['staff_flash_type'] = 'warning';
        }
    } elseif ($action === 'pickup_collected') {
        $sql = "UPDATE transactions SET delivery_status = 'delivered', payment_status = 'paid',
                payment_date = COALESCE(payment_date, NOW())
            WHERE transaction_id = '$transaction_id' AND status = 'approved'
              AND fulfillment_method = 'pickup' AND COALESCE(delivery_status, 'pending') <> 'delivered'";
        $_SESSION['staff_flash'] = "Pickup order {$transaction_id} marked as collected.";
        $_SESSION['staff_flash_type'] = 'success';
    } elseif ($action === 'verify_payment_fast') {
        $sql = "UPDATE transactions SET payment_status = 'paid', payment_date = NOW()
            WHERE transaction_id = '$transaction_id' AND status = 'approved' AND payment_status <> 'paid'";
        $_SESSION['staff_flash'] = "Payment for {$transaction_id} verified.";
        $_SESSION['staff_flash_type'] = 'success';
    } elseif ($action === 'assign_rider') {
        $rider_id = sanitize($_POST['rider_id'] ?? '');
        $assignmentOrderResult = $conn->query("SELECT status,payment_status,fulfillment_method FROM transactions WHERE transaction_id='$transaction_id' LIMIT 1");
        $assignmentOrder = $assignmentOrderResult ? $assignmentOrderResult->fetch_assoc() : null;
        if (!$assignmentOrder) {
            $_SESSION['staff_flash'] = 'Order not found.';
            $_SESSION['staff_flash_type'] = 'danger';
        } elseif (($assignmentOrder['status'] ?? '') !== 'approved') {
            $_SESSION['staff_flash'] = 'Approve the order before assigning a rider.';
            $_SESSION['staff_flash_type'] = 'warning';
        } elseif (($assignmentOrder['payment_status'] ?? '') !== 'paid') {
            $_SESSION['staff_flash'] = 'Verify the customer payment before assigning a rider.';
            $_SESSION['staff_flash_type'] = 'warning';
        } elseif (($assignmentOrder['fulfillment_method'] ?? 'delivery') !== 'delivery') {
            $_SESSION['staff_flash'] = 'Pickup orders do not require a rider.';
            $_SESSION['staff_flash_type'] = 'warning';
        } elseif ($rider_id !== '') {
            $rider_check = $conn->query("SELECT full_name FROM rider_users WHERE rider_id = '$rider_id' AND status = 'active' LIMIT 1");
            if ($rider_check && $rider_check->num_rows > 0) {
                $rider_info = $rider_check->fetch_assoc();
                $assignment_sets = ["rider_id = '$rider_id'"];
                if ($transactions_has_assigned_rider) {
                    $assignment_sets[] = "assigned_rider = '$rider_id'";
                }
                $assignment_sets[] = "delivery_status = 'pending'";
                $assignment_sets[] = "status = 'approved'";
                $assignment_sets[] = "approved_by = '{$staff_id}'";
                $sql = "UPDATE transactions SET " . implode(', ', $assignment_sets) . " WHERE transaction_id = '$transaction_id' AND status = 'approved' AND payment_status = 'paid' AND COALESCE(fulfillment_method,'delivery') = 'delivery'";
                $assignment_notification = ['rider_id' => $rider_id, 'transaction_id' => $transaction_id];
                $_SESSION['staff_flash'] = "Assigned {$rider_info['full_name']} to transaction {$transaction_id}. Rider notified.";
                $_SESSION['staff_flash_type'] = 'success';
            } else {
                $_SESSION['staff_flash'] = 'Selected rider is not available.';
                $_SESSION['staff_flash_type'] = 'warning';
            }
        } else {
            $_SESSION['staff_flash'] = 'Please select a rider before assigning.';
            $_SESSION['staff_flash_type'] = 'warning';
        }
    }

    if (!empty($sql)) {
        if (!$conn->query($sql)) {
            $_SESSION['staff_flash'] = 'Action failed: ' . $conn->error;
            $_SESSION['staff_flash_type'] = 'danger';
        } elseif ($action === 'on_the_way' || $action === 'on_way') {
            if ($conn->affected_rows > 0) {
                $customerResult = $conn->query("SELECT user_id FROM transactions WHERE transaction_id='$transaction_id' LIMIT 1");
                $customerOrder = $customerResult ? $customerResult->fetch_assoc() : null;
                if ($customerOrder) {
                    add_user_notification($conn,(string)$customerOrder['user_id'],$transaction_id,'Your delivery is on the way','Your rider has started the delivery. You can open Track Order to follow its progress.','delivery');
                }
            }
        } elseif ($action === 'verify_payment_fast' && $conn->affected_rows > 0) {
            $conn->query("UPDATE payments SET payment_status = 'paid' WHERE transaction_id = '$transaction_id'");
        } elseif ($action === 'pickup_collected' && $conn->affected_rows > 0) {
            $conn->query("UPDATE payments SET payment_status = 'paid' WHERE transaction_id = '$transaction_id'");
            $pickupCustomerResult = $conn->query("SELECT user_id FROM transactions WHERE transaction_id='$transaction_id' LIMIT 1");
            $pickupCustomer = $pickupCustomerResult ? $pickupCustomerResult->fetch_assoc() : null;
            if ($pickupCustomer) {
                add_user_notification($conn, (string)$pickupCustomer['user_id'], $transaction_id, 'Pickup completed', 'Your pickup order has been marked as collected. Thank you!', 'order');
            }
        } elseif ($action === 'assign_rider') {
            if ($conn->affected_rows === 0) {
                $_SESSION['staff_flash'] = "No rider assignment saved for {$transaction_id}.";
                $_SESSION['staff_flash_type'] = 'warning';
            } elseif ($assignment_notification) {
                $notification_title = 'New delivery assigned';
                $notification_message = "You have been assigned to order {$assignment_notification['transaction_id']}. Please open your rider dashboard to review it.";
                $notification_stmt = $conn->prepare("INSERT INTO rider_notifications (rider_id, transaction_id, title, message) VALUES (?, ?, ?, ?)");
                $notification_stmt->bind_param('ssss', $assignment_notification['rider_id'], $assignment_notification['transaction_id'], $notification_title, $notification_message);
                if (!$notification_stmt->execute()) {
                    $_SESSION['staff_flash'] = "Rider assigned to {$transaction_id}, but the notification could not be created.";
                    $_SESSION['staff_flash_type'] = 'warning';
                }
            }
        }
    }

    header('Location: dashboard.php?view=deliveries' . ($return_section !== '' ? '&section=' . urlencode($return_section) : ''));
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS reward_claims (id INT AUTO_INCREMENT PRIMARY KEY, transaction_id VARCHAR(50) NOT NULL UNIQUE, user_id VARCHAR(50) NOT NULL, reward_code VARCHAR(80) NOT NULL, reward_title VARCHAR(255) NOT NULL, points_used INT NOT NULL, claim_status VARCHAR(20) NOT NULL DEFAULT 'pending', claimed_by VARCHAR(80) NULL, claimed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_reward_claim_status (claim_status), INDEX idx_reward_claim_user (user_id))");
$analytics_period = strtolower((string)($_GET['analytics_period'] ?? 'day'));
if (!in_array($analytics_period, ['day', 'month', 'year'], true)) $analytics_period = 'day';
$analytics_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['analytics_date'] ?? '')) ? $_GET['analytics_date'] : date('Y-m-d');
$analytics_month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['analytics_month'] ?? '')) ? $_GET['analytics_month'] : date('Y-m');
$analytics_year = preg_match('/^\d{4}$/', (string)($_GET['analytics_year'] ?? '')) ? (int)$_GET['analytics_year'] : (int)date('Y');
if ($analytics_period === 'day') {
    $analytics_condition = "DATE(created_at)='" . $conn->real_escape_string($analytics_date) . "'";
    $analytics_updated_condition = "DATE(updated_at)='" . $conn->real_escape_string($analytics_date) . "'";
    $analytics_label = date('M d, Y', strtotime($analytics_date));
    $analytics_divisor = 1;
} elseif ($analytics_period === 'month') {
    $analytics_condition = "DATE_FORMAT(created_at,'%Y-%m')='" . $conn->real_escape_string($analytics_month) . "'";
    $analytics_updated_condition = "DATE_FORMAT(updated_at,'%Y-%m')='" . $conn->real_escape_string($analytics_month) . "'";
    $analytics_label = date('F Y', strtotime($analytics_month . '-01'));
    $analytics_divisor = (int)date('t', strtotime($analytics_month . '-01'));
} else {
    $analytics_condition = "YEAR(created_at)={$analytics_year}";
    $analytics_updated_condition = "YEAR(updated_at)={$analytics_year}";
    $analytics_label = (string)$analytics_year;
    $analytics_divisor = 365;
}
$analytics_exclusions = "transaction_id NOT LIKE 'RWD-%' AND COALESCE(description,'') NOT LIKE 'Reward Redemption - %'";
$pending  = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE status='pending' AND {$analytics_condition} AND {$analytics_exclusions}", 'count', 0);
$reward_claims_pending = (int)$scalar_value("SELECT COUNT(*) as count FROM reward_claims WHERE claim_status='pending'", 'count', 0);
$approved = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE status='approved' AND {$analytics_condition} AND {$analytics_exclusions}", 'count', 0);
$approved_operations = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE status='approved' AND transaction_id NOT LIKE 'RWD-%' AND COALESCE(description,'') NOT LIKE 'Reward Redemption - %'", 'count', 0);
$denied   = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE status='denied' AND {$analytics_condition} AND {$analytics_exclusions}", 'count', 0);
$revenue  = (float)$scalar_value("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE status='approved' AND {$analytics_condition} AND {$analytics_exclusions}", 'total', 0);
$in_transit = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE status='approved' AND delivery_status IN ('on_way','on_the_way')", 'count', 0);
$delivered_today = (int)$scalar_value("SELECT COUNT(*) as count FROM transactions WHERE delivery_status='delivered' AND {$analytics_updated_condition} AND {$analytics_exclusions}", 'count', 0);

$analyticsDays = [];
if ($analytics_period === 'day') {
    for ($i=0;$i<6;$i++) $analyticsDays[(string)$i] = ['label'=>date('ga', mktime($i*4)), 'orders'=>0, 'revenue'=>0.0];
    $bucket_expression = "FLOOR(HOUR(created_at)/4)";
} elseif ($analytics_period === 'month') {
    for ($i=0;$i<5;$i++) $analyticsDays[(string)$i] = ['label'=>'W'.($i+1), 'orders'=>0, 'revenue'=>0.0];
    $bucket_expression = "FLOOR((DAY(created_at)-1)/7)";
} else {
    for ($i=1;$i<=12;$i++) $analyticsDays[(string)$i] = ['label'=>date('M', mktime(0,0,0,$i,1)), 'orders'=>0, 'revenue'=>0.0];
    $bucket_expression = "MONTH(created_at)";
}
$analyticsResult = $conn->query("SELECT {$bucket_expression} AS bucket, COUNT(*) AS orders, COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS revenue FROM transactions WHERE {$analytics_condition} AND {$analytics_exclusions} GROUP BY bucket ORDER BY bucket");
if ($analyticsResult) {
    while ($row = $analyticsResult->fetch_assoc()) {
        $bucket = (string)$row['bucket'];
        if (isset($analyticsDays[$bucket])) {
            $analyticsDays[$bucket]['orders'] = (int)$row['orders'];
            $analyticsDays[$bucket]['revenue'] = (float)$row['revenue'];
        }
    }
}
$maxAnalyticsOrders = max(1, ...array_column($analyticsDays, 'orders'));
$weekOrders = array_sum(array_column($analyticsDays, 'orders'));
$weekRevenue = array_sum(array_column($analyticsDays, 'revenue'));

$pending_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number, u.qr_code_path
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'pending'
    ORDER BY t.created_at ASC
");

$rider_list = [];
$rider_result = $conn->query("SELECT rider_id, full_name, age, address, contact_number FROM rider_users WHERE status = 'active' ORDER BY full_name ASC");
if ($rider_result) {
    while ($r = $rider_result->fetch_assoc()) $rider_list[] = $r;
}

$approved_trans = $conn->query("
    SELECT 
        t.*,
        {$transaction_rider_expr} AS effective_rider_id,
        u.full_name, u.contact_number, u.address,
        ru.full_name AS rider_name, ru.age AS rider_age,
        ru.address AS rider_address, ru.contact_number AS rider_contact,
        COALESCE(rl.rider_latitude, 0) as rider_latitude,
        COALESCE(rl.rider_longitude, 0) as rider_longitude,
        rl.last_update as gps_last_update
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN rider_users ru ON {$transaction_rider_expr} = ru.rider_id
    LEFT JOIN rider_locations rl ON t.transaction_id = rl.transaction_id
    WHERE t.status = 'approved'
      AND COALESCE(t.fulfillment_method,'delivery') = 'delivery'
      AND t.transaction_id NOT LIKE 'RWD-%'
      AND COALESCE(t.description,'') NOT LIKE 'Reward Redemption - %'
    ORDER BY t.created_at DESC
    LIMIT 10
");

$pickup_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number, u.email, u.address
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'approved'
      AND t.fulfillment_method = 'pickup'
      AND COALESCE(t.delivery_status, 'pending') <> 'delivered'
      AND t.transaction_id NOT LIKE 'RWD-%'
    ORDER BY t.created_at ASC
");

$avg_feedback  = (float)$scalar_value("SELECT COALESCE(AVG(rating),0) AS avg_rating FROM feedback_ratings", 'avg_rating', 0);
$feedback_count = (int)$scalar_value("SELECT COUNT(*) AS count FROM feedback_ratings", 'count', 0);
$recent_feedback = $conn->query("
    SELECT fr.*, u.full_name, t.transaction_id
    FROM feedback_ratings fr
    JOIN users u ON fr.user_id = u.user_id
    JOIN transactions t ON fr.transaction_id = t.transaction_id
    ORDER BY fr.updated_at DESC, fr.created_at DESC
    LIMIT 6
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard — HydroMIS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
/* ─── TOKENS ─────────────────────────────────────────────── */
:root {
  --bg:         #0b1120;
  --bg-2:       #0f1829;
  --surface:    #131d30;
  --surface-2:  #19243c;
  --border:     rgba(255,255,255,.07);
  --border-2:   rgba(255,255,255,.12);
  --text:       #e8eef8;
  --muted:      #7b90b4;
  --accent:     #3b82f6;
  --accent-glow:rgba(59,130,246,.28);
  --green:      #10b981;
  --green-glow: rgba(16,185,129,.22);
  --amber:      #f59e0b;
  --amber-glow: rgba(245,158,11,.22);
  --red:        #ef4444;
  --red-glow:   rgba(239,68,68,.22);
  --purple:     #8b5cf6;
  --purple-glow:rgba(139,92,246,.22);
  --teal:       #06b6d4;
  --radius:     16px;
  --radius-sm:  10px;
  --radius-lg:  22px;
  --shadow:     0 4px 24px rgba(0,0,0,.35);
  --shadow-lg:  0 8px 48px rgba(0,0,0,.5);
  --font-head:  'Plus Jakarta Sans', 'DM Sans', Arial, sans-serif;
  --font-body:  'DM Sans', Arial, sans-serif;
  --sidebar-w:  270px;
  --transition: .22s cubic-bezier(.4,0,.2,1);
}

/* ─── RESET ──────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 14px;
  line-height: 1.6;
  min-height: 100vh;
}

/* ─── LAYOUT ─────────────────────────────────────────────── */
.shell { display: flex; min-height: 100vh; }

/* ─── SIDEBAR ────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w);
  flex-shrink: 0;
  background: var(--bg-2);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  padding: 28px 18px 24px;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
}
.brand {
  padding: 0 8px 28px;
  border-bottom: 1px solid var(--border);
}
.brand-logo {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}
.brand-icon {
  width: 42px; height: 42px;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--accent) 0%, #1d4ed8 100%);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  box-shadow: 0 0 0 1px rgba(59,130,246,.3), 0 4px 16px var(--accent-glow);
}
.brand-name {
  font-family: var(--font-head);
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -.5px;
  line-height: 1;
}
.brand-sub {
  font-size: 11px;
  color: var(--muted);
  letter-spacing: 1.5px;
  text-transform: uppercase;
  margin-top: 2px;
}

.nav { flex: 1; padding-top: 22px; }
.nav-label {
  font-size: 10px;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--muted);
  padding: 0 12px;
  margin-bottom: 8px;
}
.nav a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  border-radius: var(--radius-sm);
  color: var(--muted);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all var(--transition);
  margin-bottom: 2px;
  position: relative;
}
.nav a .nav-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  background: transparent;
  transition: all var(--transition);
  flex-shrink: 0;
}
.nav a:hover { color: var(--text); background: var(--surface); }
.nav a:hover .nav-icon { background: var(--surface-2); color: var(--accent); }
.nav a.active {
  color: var(--text);
  background: linear-gradient(135deg, rgba(59,130,246,.18) 0%, rgba(59,130,246,.08) 100%);
  font-weight: 600;
}
.nav a.active .nav-icon {
  background: rgba(59,130,246,.2);
  color: var(--accent);
}
.nav a.active::before {
  content: '';
  position: absolute;
  left: 0; top: 50%;
  transform: translateY(-50%);
  width: 3px; height: 60%;
  border-radius: 0 4px 4px 0;
  background: var(--accent);
}
.nav-badge {
  margin-left: auto;
  background: var(--red);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 999px;
  min-width: 20px;
  text-align: center;
}

.sidebar-footer {
  border-top: 1px solid var(--border);
  padding-top: 20px;
}
.staff-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  border: 1px solid var(--border);
  margin-bottom: 12px;
}
.staff-avatar {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--accent) 0%, var(--purple) 100%);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}
.staff-info strong { display: block; font-size: 13px; font-weight: 600; }
.staff-info span { font-size: 11px; color: var(--muted); }
.logout-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 10px;
  border-radius: var(--radius-sm);
  background: rgba(239,68,68,.1);
  border: 1px solid rgba(239,68,68,.2);
  color: var(--red);
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  transition: all var(--transition);
}
.logout-btn:hover { background: rgba(239,68,68,.18); color: var(--red); text-decoration: none; }

/* ─── MAIN ───────────────────────────────────────────────── */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

.topbar {
  position: sticky;
  top: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 28px;
  height: 64px;
  background: rgba(11,17,32,.88);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
}
.topbar-left { display: flex; align-items: center; gap: 12px; }
.topbar-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-time {
  font-size: 12px;
  color: var(--muted);
  padding: 6px 12px;
  border-radius: 8px;
  background: var(--surface);
  border: 1px solid var(--border);
  font-feature-settings: 'tnum';
}
.icon-btn {
  width: 36px; height: 36px;
  border-radius: 9px;
  background: var(--surface);
  border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  color: var(--muted);
  cursor: pointer;
  transition: all var(--transition);
  text-decoration: none;
  font-size: 13px;
}
.icon-btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-2); }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { padding: 28px; flex: 1; }

.page-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 28px;
}
.page-hero h1 {
  font-family: var(--font-head);
  font-size: 36px;
  font-weight: 800;
  letter-spacing: -1px;
  line-height: 1.1;
}
.page-hero p { color: var(--muted); font-size: 14px; margin-top: 6px; }
.hero-actions { display: flex; gap: 10px; align-items: center; flex-shrink: 0; }

/* ─── BUTTONS ────────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  padding: 10px 18px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: all var(--transition);
  white-space: nowrap;
  text-decoration: none;
}
.btn-ghost {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
}
.btn-ghost:hover { background: var(--surface-2); border-color: var(--border-2); color: var(--text); text-decoration: none; }
.btn-primary {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 0 0 1px rgba(59,130,246,.3);
}
.btn-primary:hover { background: #2563eb; color: #fff; text-decoration: none; }
.btn-success { background: var(--green); color: #fff; }
.btn-success:hover { background: #059669; filter: brightness(1.05); }
.btn-danger { background: var(--red); color: #fff; }
.btn-danger:hover { background: #dc2626; filter: brightness(1.05); }
.btn-warning { background: var(--amber); color: #000; }
.btn-warning:hover { background: #d97706; }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
.btn-xs { padding: 4px 10px; font-size: 11px; border-radius: 6px; }

/* ─── FLASH ──────────────────────────────────────────────── */
.flash {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px;
  border-radius: var(--radius);
  margin-bottom: 22px;
  font-size: 14px;
  font-weight: 500;
  animation: slideDown .3s ease;
}
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.flash-success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3); color: var(--green); }
.flash-danger  { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.3);  color: var(--red); }
.flash-warning { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3); color: var(--amber); }
.flash-info    { background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.3); color: var(--accent); }
.flash-close { margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; opacity: .7; font-size: 16px; }
.flash-close:hover { opacity: 1; }

/* ─── ALERT BANNER ───────────────────────────────────────── */
.pending-banner {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 18px;
  border-radius: var(--radius);
  background: linear-gradient(135deg, rgba(245,158,11,.15) 0%, rgba(245,158,11,.05) 100%);
  border: 1px solid rgba(245,158,11,.35);
  margin-bottom: 24px;
  animation: pulse-border 2s ease infinite;
}
@keyframes pulse-border {
  0%,100% { border-color: rgba(245,158,11,.35); }
  50%      { border-color: rgba(245,158,11,.7); }
}
.pending-banner .pulse-dot {
  width: 10px; height: 10px; border-radius: 50%;
  background: var(--amber);
  flex-shrink: 0;
  box-shadow: 0 0 0 0 var(--amber-glow);
  animation: pulse-dot 1.5s ease infinite;
}
@keyframes pulse-dot {
  0%   { box-shadow: 0 0 0 0 var(--amber-glow); }
  70%  { box-shadow: 0 0 0 8px transparent; }
  100% { box-shadow: 0 0 0 0 transparent; }
}
.pending-banner strong { font-weight: 700; color: var(--amber); }
.pending-banner a { margin-left: auto; flex-shrink: 0; }

/* ─── STATS ──────────────────────────────────────────────── */
.stats-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.stat {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 22px 22px 20px;
  position: relative;
  overflow: hidden;
  transition: transform var(--transition), border-color var(--transition);
}
.stat::after {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  border-radius: 2px 2px 0 0;
}
.stat-green::after { background: var(--green); }
.stat-blue::after  { background: var(--accent); }
.stat-purple::after{ background: var(--purple); }
.stat-amber::after { background: var(--amber); }
.stat:hover { transform: translateY(-2px); border-color: var(--border-2); }
.stat-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.stat-chip {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
}
.chip-green  { background: var(--green-glow); color: var(--green); }
.chip-blue   { background: var(--accent-glow); color: var(--accent); }
.chip-purple { background: var(--purple-glow); color: var(--purple); }
.chip-amber  { background: var(--amber-glow); color: var(--amber); }
.stat-trend {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 999px;
}
.trend-up   { background: var(--green-glow);   color: var(--green); }
.trend-warn { background: var(--amber-glow); color: var(--amber); }
.trend-neu  { background: var(--surface-2); color: var(--muted); }
.stat-val {
  font-family: 'DM Sans', Arial, sans-serif;
  font-size: clamp(16px, 2vw, 24px);
  font-weight: 800;
  letter-spacing: -.3px;
  line-height: 1.1;
  margin-bottom: 6px;
  word-break: break-all;
  overflow-wrap: anywhere;
}
.stat-label { font-size: 13px; color: var(--muted); font-weight: 500; }

/* ─── QUICK LINKS ────────────────────────────────────────── */
.quick-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.quick-card {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  border-radius: var(--radius-lg);
  background: var(--surface);
  border: 1px solid var(--border);
  text-decoration: none;
  color: var(--text);
  transition: all var(--transition);
  overflow: hidden;
  position: relative;
}
.quick-card::before {
  content: '';
  position: absolute;
  inset: 0;
  opacity: 0;
  transition: opacity var(--transition);
}
.quick-amber::before { background: linear-gradient(135deg, var(--amber-glow) 0%, transparent 60%); }
.quick-blue::before  { background: linear-gradient(135deg, var(--accent-glow) 0%, transparent 60%); }
.quick-green::before { background: linear-gradient(135deg, var(--green-glow) 0%, transparent 60%); }
.quick-card:hover { border-color: var(--border-2); transform: translateY(-2px); box-shadow: var(--shadow); color: var(--text); text-decoration: none; }
.quick-card:hover::before { opacity: 1; }
.quick-icon {
  width: 48px; height: 48px;
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
  position: relative; z-index: 1;
}
.quick-copy { flex: 1; min-width: 0; position: relative; z-index: 1; }
.quick-copy h3 { font-family: var(--font-head); font-size: 16px; font-weight: 700; margin-bottom: 3px; }
.quick-copy p { font-size: 12px; color: var(--muted); line-height: 1.4; }
.quick-arrow { color: var(--muted); font-size: 14px; flex-shrink: 0; position: relative; z-index: 1; transition: transform var(--transition); }
.quick-card:hover .quick-arrow { transform: translateX(4px); color: var(--text); }

/* ─── CONTENT GRID ───────────────────────────────────────── */
.content-grid {
  display: grid;
  grid-template-columns: 1.3fr .7fr;
  gap: 20px;
  margin-bottom: 20px;
}

/* ─── CARD ───────────────────────────────────────────────── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid var(--border);
  gap: 12px;
}
.card-title {
  font-family: var(--font-head);
  font-size: 15px;
  font-weight: 700;
  display: flex; align-items: center; gap: 8px;
}
.card-title .dot {
  width: 8px; height: 8px; border-radius: 50%;
}
.dot-green  { background: var(--green); }
.dot-blue   { background: var(--accent); }
.dot-amber  { background: var(--amber); }
.card-meta { font-size: 12px; color: var(--muted); }
.card-link {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  text-decoration: none;
  transition: color var(--transition);
}
.card-link:hover { color: #93c5fd; text-decoration: none; }

/* ─── TABLE ──────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 12px 18px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: var(--muted);
  background: rgba(255,255,255,.02);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody td {
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: background var(--transition); }
tbody tr:hover { background: rgba(255,255,255,.025); }
.t-id { font-family: 'Courier New', monospace; font-size: 12px; color: var(--muted); font-weight: 600; }
.t-name { font-weight: 600; }
.t-amount { font-family: var(--font-head); font-weight: 700; color: var(--green); }
.t-date { font-size: 12px; color: var(--muted); }

/* ─── BADGES ─────────────────────────────────────────────── */
.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .5px;
  text-transform: uppercase;
  white-space: nowrap;
}
.badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
.badge-pending  { background: rgba(245,158,11,.15); color: var(--amber);  border: 1px solid rgba(245,158,11,.3); }
.badge-pending::before { background: var(--amber); }
.badge-approved, .badge-delivered { background: rgba(16,185,129,.15); color: var(--green); border: 1px solid rgba(16,185,129,.3); }
.badge-approved::before, .badge-delivered::before { background: var(--green); }
.badge-denied   { background: rgba(239,68,68,.15); color: var(--red); border: 1px solid rgba(239,68,68,.3); }
.badge-denied::before { background: var(--red); }
.badge-on_the_way, .badge-on_way, .badge-assigned {
  background: rgba(59,130,246,.15); color: var(--accent); border: 1px solid rgba(59,130,246,.3);
}
.badge-on_the_way::before, .badge-on_way::before, .badge-assigned::before { background: var(--accent); }

/* ─── ACTION BTNS ────────────────────────────────────────── */
.action-row { display: flex; gap: 6px; flex-wrap: wrap; }

/* ─── DELIVERY STACK ─────────────────────────────────────── */
.delivery-list { padding: 0; }
.delivery-item {
  display: flex; flex-direction: column; gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--border);
  transition: background var(--transition);
}
.delivery-item:last-child { border-bottom: none; }
.delivery-item:hover { background: rgba(255,255,255,.02); }
.delivery-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.delivery-id { font-family: 'Courier New', monospace; font-size: 12px; color: var(--muted); margin-bottom: 3px; }
.delivery-cust { font-weight: 600; font-size: 14px; }
.delivery-rider {
  font-size: 12px;
  display: flex; align-items: center; gap: 5px;
  margin-top: 4px;
}
.rider-online { color: var(--green); }
.rider-unassigned { color: var(--amber); }
.assign-row { display: flex; gap: 8px; align-items: center; }
.assign-select {
  flex: 1;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text);
  padding: 7px 10px;
  font-size: 12px;
  font-family: var(--font-body);
  outline: none;
  transition: border-color var(--transition);
  min-width: 0;
}
.assign-select:focus { border-color: var(--accent); }
.assign-select option { background: var(--surface); }

/* ─── FEEDBACK ───────────────────────────────────────────── */
.feedback-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 18px; }
.review-card {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px;
  transition: border-color var(--transition);
}
.review-card:hover { border-color: var(--border-2); }
.review-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.review-name { font-weight: 600; font-size: 14px; }
.review-meta { font-size: 11px; color: var(--muted); margin-top: 1px; }
.stars { display: flex; gap: 2px; }
.star { font-size: 13px; }
.star-full  { color: var(--amber); }
.star-empty { color: var(--surface-2); filter: brightness(2); }
.review-body { font-size: 13px; color: var(--muted); line-height: 1.5; margin-top: 10px; font-style: italic; }

/* ─── RATING SUMMARY ─────────────────────────────────────── */
.rating-summary {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 22px;
  border-bottom: 1px solid var(--border);
  background: rgba(255,255,255,.02);
}
.rating-big {
  font-family: var(--font-head);
  font-size: 42px;
  font-weight: 800;
  color: var(--amber);
  line-height: 1;
}
.rating-stars-lg { display: flex; gap: 3px; margin-bottom: 4px; }
.rating-stars-lg .star { font-size: 16px; }
.rating-count { font-size: 12px; color: var(--muted); }

/* ─── EMPTY ──────────────────────────────────────────────── */
.empty {
  padding: 40px;
  text-align: center;
  color: var(--muted);
}
.empty i { font-size: 32px; margin-bottom: 12px; display: block; opacity: .4; }
.empty p { font-size: 14px; }

/* ─── SECTION ────────────────────────────────────────────── */
.section { margin-bottom: 20px; }

/* ─── DIVIDER ────────────────────────────────────────────── */
.divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }

/* ─── SCROLLBAR ──────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg-2); }
::-webkit-scrollbar-thumb { background: var(--surface-2); border-radius: 999px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 1280px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .content-grid { grid-template-columns: 1fr; }
}
@media (max-width: 960px) {
  :root { --sidebar-w: 220px; }
  .quick-row { grid-template-columns: 1fr; }
  .feedback-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .shell { flex-direction: column; }
  .sidebar { width: 100%; height: auto; position: relative; padding: 16px; }
  .stats-row { grid-template-columns: 1fr 1fr; }
  .page { padding: 16px; }
  .page-hero { flex-direction: column; }
  .hero-actions { width: 100%; }
}
@media (max-width: 480px) {
  .stats-row { grid-template-columns: 1fr; }
}
</style>
<link href="../css/staff-sidebar.css" rel="stylesheet">
<link href="../css/staff-sidebar-size.css" rel="stylesheet">
<link href="../css/staff-pages-unified.css" rel="stylesheet">
<link href="../css/staff-delivery-operations.css" rel="stylesheet">
</head>
<body class="<?php echo $delivery_operations_view ? 'delivery-operations-page' : 'analytics-dashboard-page'; ?>">
<div class="shell">

  <!-- ─── SIDEBAR ─────────────────────────────────────── -->
  <div class="dashboard-legacy-sidebar" style="display:none">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-logo">
        <div class="brand-icon"><img src="<?php echo htmlspecialchars(hydromis_asset_url($systemLogo, '../')); ?>" alt="HydroMIS logo" style="width:28px;height:28px;object-fit:contain;"></div>
        <div>
          <div class="brand-name">HydroMIS</div>
          <div class="brand-sub">Water Refilling</div>
        </div>
      </div>
    </div>

    <nav class="nav">
      <div class="nav-label" style="margin-top:0;">Main</div>
      <a href="dashboard.php" class="<?php echo $delivery_operations_view ? '' : 'active'; ?>">
        <div class="nav-icon"><i class="fas fa-gauge-high"></i></div>
        Dashboard
        <?php if ($pending > 0): ?>
        <span class="nav-badge"><?php echo $pending; ?></span>
        <?php endif; ?>
      </a>
      <a href="dashboard.php?view=deliveries" class="<?php echo $delivery_operations_view ? 'active' : ''; ?>">
        <div class="nav-icon"><i class="fas fa-truck-fast"></i></div>
        Delivery Operations
        <?php if ($in_transit > 0): ?><span class="nav-badge"><?php echo $in_transit; ?></span><?php endif; ?>
      </a>
      <a href="pending.php">
        <div class="nav-icon"><i class="fas fa-hourglass-half"></i></div>
        Pending Approvals
        <?php if ($pending > 0): ?>
        <span class="nav-badge"><?php echo $pending; ?></span>
        <?php endif; ?>
      </a>

      <div class="nav-label" style="margin-top:18px;">Finance</div>
      <a href="payments.php">
        <div class="nav-icon"><i class="fas fa-credit-card"></i></div>
        Payments
      </a>
      <a href="history.php">
        <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div>
        Transaction History
      </a>
      <a href="rewards.php">
        <div class="nav-icon"><i class="fas fa-gift"></i></div>
        Reward Claims
        <?php if ($reward_claims_pending > 0): ?><span class="nav-badge"><?php echo $reward_claims_pending; ?></span><?php endif; ?>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="staff-card">
        <div class="staff-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></div>
        <div class="staff-info">
          <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>
          <span>Staff Account</span>
        </div>
      </div>
      <a href="../logout.php" class="logout-btn">
        <i class="fas fa-right-from-bracket"></i> Sign Out
      </a>
    </div>
  </aside>
  </div>
  <?php $staff_active = $delivery_operations_view ? 'deliveries' : 'dashboard'; include 'sidebar.php'; ?>

  <!-- ─── MAIN ─────────────────────────────────────────── -->
  <main class="main">

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title"><?php echo $delivery_operations_view ? ($operations_section === 'pickups' ? 'Pickup Orders' : ($operations_section === 'feedbacks' ? 'Customer Feedback' : 'Delivery Operations')) : 'Analytics Dashboard'; ?></div>
        <?php if ($delivery_operations_view): ?><div class="topbar-subtitle"><?php echo $operations_section === 'pickups' ? 'Prepare and complete customer pickup orders' : ($operations_section === 'feedbacks' ? 'Review ratings and comments from customers' : 'Assign riders and monitor active delivery runs'); ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Page -->
    <div class="page">

      <!-- Flash -->
      <?php if (!empty($flash_message)): ?>
      <div class="flash flash-<?php echo htmlspecialchars($flash_type); ?>">
        <i class="fas fa-<?php echo $flash_type === 'success' ? 'circle-check' : ($flash_type === 'danger' ? 'circle-xmark' : ($flash_type === 'warning' ? 'triangle-exclamation' : 'circle-info')); ?>"></i>
        <?php echo htmlspecialchars($flash_message); ?>
        <button class="flash-close" onclick="this.parentElement.remove()">&#x2715;</button>
      </div>
      <?php endif; ?>

      <?php if (!$delivery_operations_view): ?>
      <form method="get" class="card" id="analyticsPeriodForm" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:18px;padding:14px 16px;">
        <div style="display:flex;flex-direction:column;gap:6px;"><label for="analyticsPeriod" style="color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Analytics period</label><select id="analyticsPeriod" name="analytics_period" style="min-height:40px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);color:var(--text);font:700 12px var(--font-body);"><option value="day" <?php echo $analytics_period==='day'?'selected':''; ?>>Day</option><option value="month" <?php echo $analytics_period==='month'?'selected':''; ?>>Month</option><option value="year" <?php echo $analytics_period==='year'?'selected':''; ?>>Year</option></select></div>
        <div class="analytics-period-value" data-period="day" style="display:flex;flex-direction:column;gap:6px;"><label style="color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;">Select day</label><input type="date" name="analytics_date" value="<?php echo htmlspecialchars($analytics_date); ?>" style="min-height:40px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);color:var(--text);"></div>
        <div class="analytics-period-value" data-period="month" style="display:flex;flex-direction:column;gap:6px;"><label style="color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;">Select month</label><input type="month" name="analytics_month" value="<?php echo htmlspecialchars($analytics_month); ?>" style="min-height:40px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);color:var(--text);"></div>
        <div class="analytics-period-value" data-period="year" style="display:flex;flex-direction:column;gap:6px;"><label style="color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;">Select year</label><input type="number" min="2020" max="<?php echo date('Y')+1; ?>" name="analytics_year" value="<?php echo $analytics_year; ?>" style="width:110px;min-height:40px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);color:var(--text);"></div>
        <button type="submit" class="btn btn-primary btn-sm" style="min-height:40px;"><i class="fas fa-chart-line"></i> Apply</button>
        <span style="margin-left:auto;align-self:center;color:var(--muted);font-size:12px;"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($analytics_label); ?></span>
      </form>
      <?php endif; ?>

      <!-- Stats -->
      <?php if (!$delivery_operations_view): ?>
      <div class="stats-row">
        <div class="card stat stat-green">
          <div class="stat-head">
            <div class="stat-chip chip-green"><i class="fas fa-peso-sign"></i></div>
            <span class="stat-trend trend-up"><i class="fas fa-arrow-up"></i> Revenue</span>
          </div>
          <div class="stat-val"><?php echo format_currency($revenue); ?></div>
          <div class="stat-label"><?php echo $approved; ?> approved orders</div>
        </div>
        <div class="card stat stat-blue">
          <div class="stat-head">
            <div class="stat-chip chip-blue"><i class="fas fa-bag-shopping"></i></div>
            <span class="stat-trend trend-neu">Total</span>
          </div>
          <div class="stat-val"><?php echo $pending + $approved + $denied; ?></div>
          <div class="stat-label"><?php echo $pending; ?> pending · <?php echo $denied; ?> denied</div>
        </div>
        <div class="card stat stat-purple">
          <div class="stat-head">
            <div class="stat-chip chip-purple"><i class="fas fa-motorcycle"></i></div>
            <span class="stat-trend <?php echo count($rider_list) > 0 ? 'trend-up' : 'trend-warn'; ?>"><?php echo count($rider_list); ?> active</span>
          </div>
          <div class="stat-val"><?php echo count($rider_list); ?></div>
          <div class="stat-label">Active riders available</div>
        </div>
        <div class="card stat stat-amber">
          <div class="stat-head">
            <div class="stat-chip chip-amber"><i class="fas fa-truck-fast"></i></div>
            <span class="stat-trend <?php echo $in_transit > 0 ? 'trend-up' : 'trend-neu'; ?>">In transit</span>
          </div>
          <div class="stat-val"><?php echo $in_transit; ?></div>
          <div class="stat-label"><?php echo $delivered_today; ?> delivered in selected period</div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$delivery_operations_view): ?>
      <section class="analytics-grid section">
        <div class="card analytics-chart-card">
          <div class="card-head"><div class="card-title"><span class="dot dot-blue"></span><?php echo ucfirst($analytics_period); ?> Order Activity</div><span class="card-meta"><?php echo $weekOrders; ?> orders</span></div>
          <div class="analytics-bars">
            <?php foreach ($analyticsDays as $day): ?>
            <div class="analytics-bar-item" title="<?php echo $day['orders']; ?> orders · <?php echo format_currency($day['revenue']); ?>">
              <span><?php echo $day['orders']; ?></span>
              <div class="analytics-track"><i style="height:<?php echo max(5, round(($day['orders'] / $maxAnalyticsOrders) * 100)); ?>%"></i></div>
              <small><?php echo htmlspecialchars($day['label']); ?></small>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card analytics-summary">
          <div class="card-head"><div class="card-title"><span class="dot dot-green"></span><?php echo htmlspecialchars($analytics_label); ?></div></div>
          <div class="analytics-metric"><i class="fas fa-receipt"></i><div><strong><?php echo $weekOrders; ?></strong><span>Total orders</span></div></div>
          <div class="analytics-metric"><i class="fas fa-peso-sign"></i><div><strong><?php echo format_currency($weekRevenue); ?></strong><span>Approved revenue</span></div></div>
          <div class="analytics-metric"><i class="fas fa-chart-line"></i><div><strong><?php echo number_format($weekOrders / max(1,$analytics_divisor), 1); ?></strong><span>Average orders per day</span></div></div>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($delivery_operations_view): ?>
      <!-- Pending Approvals + Delivery -->
      <div class="content-grid section">
        <!-- Pending Approvals Table -->
        <?php if (!$delivery_operations_view): ?>
        <div class="card">
          <div class="card-head">
            <div class="card-title">
              <span class="dot dot-amber"></span>
              Pending Approvals
            </div>
            <a href="pending.php" class="card-link">View all <i class="fas fa-arrow-right"></i></a>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Order ID</th>
                  <th>Customer</th>
                  <th>Contact</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($pending_trans && $pending_trans->num_rows > 0): ?>
                  <?php while ($row = $pending_trans->fetch_assoc()): ?>
                  <tr>
                    <td><span class="t-id"><?php echo htmlspecialchars($row['transaction_id']); ?></span></td>
                    <td><span class="t-name"><?php echo htmlspecialchars($row['full_name']); ?></span></td>
                    <td style="color:var(--muted);font-size:12px;"><?php echo htmlspecialchars($row['contact_number']); ?></td>
                    <td><span class="t-amount"><?php echo format_currency($row['amount']); ?></span></td>
                    <td><span class="t-date"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span></td>
                    <td>
                      <div class="action-row" style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
                        <?php if (!empty($rider_list) && ($row['status'] ?? '') === 'approved' && ($row['payment_status'] ?? '') === 'paid'): ?>
                        <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                          <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                          <input type="hidden" name="action" value="assign_rider">
                          <select name="rider_id" class="assign-select" aria-label="Select rider" style="min-width:140px;">
                            <option value="">— Select rider —</option>
                            <?php foreach ($rider_list as $rider): ?>
                            <option value="<?php echo htmlspecialchars($rider['rider_id']); ?>"><?php echo htmlspecialchars($rider['full_name']); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-user-check"></i> Assign</button>
                        </form>
                        <?php else: ?>
                        <div style="font-size:11px;color:var(--amber);"><i class="fas fa-lock"></i> Approve order and verify payment before rider assignment</div>
                        <?php endif; ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                          <form method="POST" style="display:inline;">
                            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve transaction <?php echo htmlspecialchars($row['transaction_id']); ?>?')">
                              <i class="fas fa-check"></i> Approve
                            </button>
                          </form>
                          <form method="POST" style="display:inline;">
                            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                            <input type="hidden" name="action" value="deny">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deny transaction <?php echo htmlspecialchars($row['transaction_id']); ?>?')">
                              <i class="fas fa-xmark"></i> Deny
                            </button>
                          </form>
                        </div>
                      </div>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="6">
                    <div class="empty">
                      <i class="fas fa-circle-check"></i>
                      <p>No pending transactions — you're all caught up!</p>
                    </div>
                  </td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($operations_section === 'deliveries'): ?>
        <!-- Delivery Runs -->
        <div class="card">
          <div class="card-head">
            <div class="card-title">
              <span class="dot dot-blue"></span>
              Delivery Runs
            </div>
            <span class="card-meta"><?php echo $approved_operations; ?> approved</span>
          </div>
          <div class="delivery-list">
            <?php if ($approved_trans && $approved_trans->num_rows > 0): ?>
              <?php while ($row = $approved_trans->fetch_assoc()):
                $ds = $row['delivery_status'] ?: 'pending';
                $has_rider = !empty($row['effective_rider_id']);
                $badge_class = 'badge-pending';
                $status_label = ucfirst(str_replace('_', ' ', $ds));
                if ($ds === 'delivered')                               { $badge_class = 'badge-delivered'; $status_label = 'Delivered'; }
                elseif ($ds === 'on_way' || $ds === 'on_the_way')     { $badge_class = 'badge-on_way'; $status_label = 'On the Way'; }
                elseif ($ds === 'assigned' || $has_rider)             { $badge_class = 'badge-assigned'; $status_label = 'Assigned'; }
              ?>
              <div class="delivery-item">
                <div class="delivery-top">
                  <div>
                    <div class="delivery-id"><?php echo htmlspecialchars($row['transaction_id']); ?></div>
                    <div class="delivery-cust"><?php echo htmlspecialchars($row['full_name']); ?></div>
                    <div class="delivery-rider">
                      <?php if ($row['rider_name']): ?>
                        <span class="rider-online"><i class="fas fa-motorcycle"></i></span>
                        <span><?php echo htmlspecialchars($row['rider_name']); ?></span>
                        <?php if (($ds === 'on_way' || $ds === 'on_the_way') && $row['gps_last_update']): ?>
                          <span style="color:var(--green);font-size:11px;margin-left:4px;"><i class="fas fa-satellite-dish"></i> GPS</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="rider-unassigned"><i class="fas fa-user-slash"></i> Unassigned</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
                    <span style="font-family:var(--font-head);font-weight:700;color:var(--green);font-size:13px;"><?php echo format_currency($row['amount']); ?></span>
                  </div>
                </div>
                <?php $paymentVerified = ($row['payment_status'] ?? '') === 'paid'; ?>
                <?php if (!empty($rider_list) && $ds !== 'delivered' && $paymentVerified): ?>
                <form method="POST" class="assign-row">
                  <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                  <input type="hidden" name="action" value="assign_rider">
                  <select name="rider_id" class="assign-select" aria-label="Select rider">
                    <option value="">— Select rider —</option>
                    <?php foreach ($rider_list as $rider): ?>
                    <option value="<?php echo htmlspecialchars($rider['rider_id']); ?>"
                      <?php echo (($row['effective_rider_id'] ?? '') === $rider['rider_id']) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($rider['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-user-check"></i> Assign</button>
                </form>
                <?php elseif (!$paymentVerified): ?>
                <form method="POST" class="assign-row" onsubmit="return confirm('Verify payment for this order?');">
                  <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                  <input type="hidden" name="action" value="verify_payment_fast">
                  <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-circle-check"></i> Verify Payment</button>
                  <span style="font-size:11px;color:var(--amber);"><i class="fas fa-lock"></i> Rider assignment unlocks after verification</span>
                </form>
                <?php elseif (empty($rider_list)): ?>
                <div style="font-size:12px;color:var(--muted);"><i class="fas fa-triangle-exclamation"></i> No active riders</div>
                <?php endif; ?>
              </div>
              <?php endwhile; ?>
            <?php else: ?>
              <div class="empty">
                <i class="fas fa-box-open"></i>
                <p>No approved deliveries yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($operations_section === 'pickups'): ?>
        <!-- Approved pickup orders awaiting collection -->
        <div class="card" id="pickup-orders">
          <div class="card-head">
            <div class="card-title"><span class="dot dot-amber"></span>Pickup Orders</div>
            <span class="card-meta"><?php echo $pickup_trans ? $pickup_trans->num_rows : 0; ?> awaiting collection</span>
          </div>
          <div class="delivery-list">
            <?php if ($pickup_trans && $pickup_trans->num_rows > 0): ?>
              <?php while ($pickup = $pickup_trans->fetch_assoc()): ?>
              <div class="delivery-item">
                <div class="delivery-top">
                  <div>
                    <div class="delivery-id"><?php echo htmlspecialchars($pickup['transaction_id']); ?></div>
                    <div class="delivery-cust"><?php echo htmlspecialchars($pickup['full_name']); ?></div>
                    <div class="delivery-rider"><span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($pickup['contact_number'] ?: 'No contact number'); ?></span></div>
                  </div>
                  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                    <span class="badge badge-pending">Preparing</span>
                    <span style="font-family:var(--font-head);font-weight:700;color:var(--green);font-size:13px;"><?php echo format_currency($pickup['amount']); ?></span>
                  </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:9px 16px;margin:14px 0;padding:13px;border:1px solid rgba(148,163,184,.16);border-radius:12px;background:rgba(15,23,42,.24);font-size:12px;">
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Order</span><strong><?php echo htmlspecialchars(($pickup['water_type'] === 'nowater' ? 'No-Water' : 'Regular Water') . ' · ' . (int)$pickup['quantity'] . ' gallon' . ((int)$pickup['quantity'] === 1 ? '' : 's')); ?></strong></div>
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Price / Unit</span><strong><?php echo format_currency($pickup['price_per_unit']); ?></strong></div>
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Container</span><strong><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string)($pickup['container_size'] ?: 'Not specified'))) . ' · ' . ucfirst((string)($pickup['container_status'] ?: 'existing'))); ?></strong></div>
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Payment</span><strong><?php echo htmlspecialchars(strtoupper((string)$pickup['payment_method']) . ' · ' . ucfirst((string)$pickup['payment_status'])); ?></strong></div>
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Customer ID</span><strong><?php echo htmlspecialchars($pickup['user_id']); ?></strong></div>
                  <div><span style="display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;">Ordered</span><strong><?php echo date('M d, Y · h:i A', strtotime($pickup['created_at'])); ?></strong></div>
                </div>
                <div class="assign-row">
                  <form method="POST" onsubmit="return confirm('Notify the customer that this pickup order is ready?');">
                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($pickup['transaction_id']); ?>">
                    <input type="hidden" name="action" value="notify_pickup_ready">
                    <input type="hidden" name="return_section" value="pickups">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-bell"></i> Notify Customer</button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Mark this pickup order as collected?');">
                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($pickup['transaction_id']); ?>">
                    <input type="hidden" name="action" value="pickup_collected">
                    <input type="hidden" name="return_section" value="pickups">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Mark Collected</button>
                  </form>
                </div>
              </div>
              <?php endwhile; ?>
            <?php else: ?>
              <div class="empty"><i class="fas fa-store"></i><p>No pickup orders awaiting collection</p></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php if (false): // Recent transactions are available in Payment History. ?>
      <!-- Recent Transactions -->
      <div class="card section">
        <div class="card-head">
          <div class="card-title">
            <span class="dot dot-green"></span>
            Recent Transactions
          </div>
          <a href="history.php" class="card-link">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Description</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($all_trans && $all_trans->num_rows > 0): ?>
                <?php while ($row = $all_trans->fetch_assoc()): ?>
                <tr>
                  <td><span class="t-id" title="<?php echo htmlspecialchars($row['transaction_id']); ?>"><?php echo htmlspecialchars(str_starts_with((string)$row['transaction_id'], 'RWD-') && strlen((string)$row['transaction_id']) > 12 ? 'RWD-' . substr((string)$row['transaction_id'], 4, 8) : $row['transaction_id']); ?></span></td>
                  <td><span class="t-name"><?php echo htmlspecialchars($row['full_name']); ?></span></td>
                  <td>
                    <span class="t-amount"><?php echo format_currency($row['amount']); ?></span>
                    <?php if (str_starts_with((string)$row['transaction_id'], 'RWD-')): ?>
                    <span style="display:inline-flex;align-items:center;gap:5px;margin-top:5px;padding:4px 8px;border:1px solid rgba(167,139,250,.28);border-radius:999px;background:rgba(167,139,250,.12);color:#c4b5fd;font-size:9px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;">
                      <i class="fas fa-gift"></i> Points redemption
                    </span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:13px;color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($row['description']); ?>
                    <?php if (!empty($row['container_size'])): ?>
                    <div style="margin-top:4px;font-size:10px;">
                      <?php echo ($row['container_status'] ?? '') === 'new' ? 'New container' : 'Customer container'; ?> ·
                      <?php echo ($row['fulfillment_method'] ?? '') === 'pickup' ? 'Self pickup' : 'Delivery'; ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge badge-<?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst(htmlspecialchars($row['status'])); ?></span></td>
                  <td><span class="t-date"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span></td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="6">
                  <div class="empty">
                    <i class="fas fa-inbox"></i>
                    <p>No transactions recorded yet</p>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($delivery_operations_view && $operations_section === 'feedbacks'): ?>
      <!-- Feedback -->
      <div class="card section">
        <div class="card-head">
          <div class="card-title">
            <span class="dot dot-amber"></span>
            Customer Feedback
          </div>
          <span class="card-meta"><?php echo $feedback_count; ?> review<?php echo $feedback_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($recent_feedback && $recent_feedback->num_rows > 0): ?>
        <div class="rating-summary">
          <div class="rating-big"><?php echo number_format($avg_feedback, 1); ?></div>
          <div>
            <div class="rating-stars-lg">
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="star <?php echo $i <= round($avg_feedback) ? 'star-full' : 'star-empty'; ?>">★</span>
              <?php endfor; ?>
            </div>
            <div class="rating-count">Based on <?php echo $feedback_count; ?> reviews</div>
          </div>
        </div>
        <div class="feedback-grid">
          <?php while ($review = $recent_feedback->fetch_assoc()): ?>
          <div class="review-card">
            <div class="review-head">
              <div>
                <div class="review-name"><?php echo htmlspecialchars($review['full_name']); ?></div>
                <div class="review-meta"><?php echo htmlspecialchars($review['transaction_id']); ?> · <?php echo date('M d, Y', strtotime($review['updated_at'] ?: $review['created_at'])); ?></div>
              </div>
              <div class="stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star <?php echo $i <= (int)$review['rating'] ? 'star-full' : 'star-empty'; ?>">★</span>
                <?php endfor; ?>
              </div>
            </div>
            <div class="review-body">"<?php echo htmlspecialchars($review['feedback_message'] ?: 'No written feedback provided.'); ?>"</div>
          </div>
          <?php endwhile; ?>
        </div>
        <?php else: ?>
          <div class="empty">
            <i class="fas fa-comment-slash"></i>
            <p>No customer feedback yet</p>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div><!-- /page -->
  </main>
</div><!-- /shell -->

<script>
// Live clock
function tick() {
  const el = document.getElementById('liveClock');
  if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
tick();
setInterval(tick, 1000);

const analyticsPeriod = document.getElementById('analyticsPeriod');
function syncAnalyticsPeriodFields() {
  document.querySelectorAll('.analytics-period-value').forEach(field => {
    field.style.display = field.dataset.period === analyticsPeriod?.value ? 'flex' : 'none';
  });
}
analyticsPeriod?.addEventListener('change', syncAnalyticsPeriodFields);
syncAnalyticsPeriodFields();

// Auto-dismiss flash after 5s
setTimeout(() => {
  const f = document.querySelector('.flash');
  if (f) f.style.cssText = 'opacity:0;transform:translateY(-6px);transition:.3s ease;';
  setTimeout(() => f && f.remove(), 300);
}, 5000);
</script>
</body>
</html>
