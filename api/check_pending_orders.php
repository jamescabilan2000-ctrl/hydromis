<?php
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Ensure database connection
require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Validate staff or admin session
$is_staff = !empty($_SESSION['staff_auth_id']) || (!empty($_SESSION['admin_id']) && ($_SESSION['role'] ?? '') === 'staff');
$is_admin = !empty($_SESSION['admin_auth_id']) || (!empty($_SESSION['admin_id']) && ($_SESSION['role'] ?? '') === 'admin');

if (!$is_staff && !$is_admin) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Fetch pending transactions count and details
$pending_sql = "
    SELECT t.transaction_id, t.user_id, t.amount, t.description, t.water_type, t.quantity, 
           t.container_size, t.container_status, t.fulfillment_method, t.payment_method, 
           t.payment_status, t.created_at, u.full_name, u.contact_number
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'pending'
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 10
";

$pending_result = $conn->query($pending_sql);
$pending_orders = [];
if ($pending_result && $pending_result->num_rows > 0) {
    while ($row = $pending_result->fetch_assoc()) {
        $created_timestamp = strtotime($row['created_at']);
        $diff = time() - $created_timestamp;
        if ($diff < 60) {
            $time_ago = 'Just now';
        } elseif ($diff < 3600) {
            $time_ago = floor($diff / 60) . 'm ago';
        } elseif ($diff < 86400) {
            $time_ago = floor($diff / 3600) . 'h ago';
        } else {
            $time_ago = date('M d, h:i A', $created_timestamp);
        }

        $pending_orders[] = [
            'transaction_id' => $row['transaction_id'],
            'user_id' => $row['user_id'],
            'full_name' => $row['full_name'] ?: 'Guest / Unknown',
            'contact_number' => $row['contact_number'] ?: '',
            'amount' => (float)$row['amount'],
            'water_type' => ucfirst($row['water_type'] ?? 'Regular'),
            'container_size' => $row['container_size'] ?: 'Standard',
            'container_status' => $row['container_status'] ?: '',
            'quantity' => (int)($row['quantity'] ?? 1),
            'fulfillment_method' => strtolower($row['fulfillment_method'] ?? 'delivery'),
            'payment_method' => strtoupper($row['payment_method'] ?? 'COD'),
            'payment_status' => $row['payment_status'] ?: 'pending',
            'created_at' => $row['created_at'],
            'time_ago' => $time_ago
        ];
    }
}

// Fetch counts for badges
$total_pending = count($pending_orders);
if ($total_pending === 10) {
    $count_res = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'pending'");
    if ($count_res) {
        $total_pending = (int)($count_res->fetch_assoc()['total'] ?? 0);
    }
}

$pickup_res = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'approved' AND fulfillment_method = 'pickup' AND COALESCE(delivery_status, 'pending') <> 'delivered'");
$pickup_count = $pickup_res ? (int)($pickup_res->fetch_assoc()['total'] ?? 0) : 0;

$delivery_res = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'approved' AND COALESCE(fulfillment_method, 'delivery') = 'delivery' AND COALESCE(NULLIF(delivery_status, ''), 'pending') <> 'delivered' AND transaction_id NOT LIKE 'RWD-%' AND COALESCE(description, '') NOT LIKE 'Reward Redemption - %'");
$delivery_count = $delivery_res ? (int)($delivery_res->fetch_assoc()['total'] ?? 0) : 0;

$rewards_res = $conn->query("SELECT COUNT(*) AS total FROM reward_claims WHERE claim_status = 'pending'");
$rewards_count = $rewards_res ? (int)($rewards_res->fetch_assoc()['total'] ?? 0) : 0;

// Shared rider exception/response feed for every staff page.
$conn->query("CREATE TABLE IF NOT EXISTS staff_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(190) NOT NULL UNIQUE,
    transaction_id VARCHAR(50) NOT NULL,
    rider_id VARCHAR(50) NULL,
    notification_type VARCHAR(40) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_notification_unread (is_read, created_at)
)");
$conn->query("INSERT IGNORE INTO staff_notifications (event_key,transaction_id,rider_id,notification_type,title,message)
    SELECT CONCAT('unanswered:',t.transaction_id,':',COALESCE(t.rider_id,'')),t.transaction_id,t.rider_id,
      'assignment_unanswered','Rider has not responded',
      CONCAT('Rider ',COALESCE(t.rider_id,'(unknown)'),' has not accepted order ',t.transaction_id,' after 5 minutes.')
    FROM transactions t
    WHERE t.status='approved' AND t.rider_assignment_status='pending'
      AND t.updated_at <= DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
$conn->query("INSERT IGNORE INTO staff_notifications (event_key,transaction_id,rider_id,notification_type,title,message)
    SELECT CONCAT('overdue:',t.transaction_id,':',COALESCE(t.rider_id,'')),t.transaction_id,t.rider_id,
      'delivery_overdue','Delivery still not completed',
      CONCAT('Rider ',COALESCE(t.rider_id,'(unknown)'),' has not completed order ',t.transaction_id,' after 60 minutes on the way.')
    FROM transactions t
    WHERE t.status='approved' AND t.delivery_status IN ('on_way','on_the_way')
      AND t.updated_at <= DATE_SUB(NOW(),INTERVAL 60 MINUTE)");

$staff_notifications = [];
$staff_alert_result = $conn->query("SELECT id,transaction_id,rider_id,notification_type,title,message,created_at FROM staff_notifications WHERE is_read=0 ORDER BY id ASC LIMIT 20");
$last_staff_alert_id = 0;
if ($staff_alert_result) {
    while ($alert = $staff_alert_result->fetch_assoc()) {
        $staff_notifications[] = $alert;
        $last_staff_alert_id = (int)$alert['id'];
    }
}
if ($last_staff_alert_id > 0) {
    $conn->query("UPDATE staff_notifications SET is_read=1 WHERE is_read=0 AND id<=$last_staff_alert_id");
}

echo json_encode([
    'success' => true,
    'pending_count' => $total_pending,
    'pickup_count' => $pickup_count,
    'delivery_count' => $delivery_count,
    'rewards_count' => $rewards_count,
    'staff_notifications' => $staff_notifications,
    'pending_orders' => $pending_orders,
    'server_time' => date('Y-m-d H:i:s')
]);
