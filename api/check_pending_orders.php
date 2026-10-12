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

$delivery_res = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'approved' AND COALESCE(fulfillment_method, 'delivery') = 'delivery' AND COALESCE(NULLIF(delivery_status, ''), 'pending') = 'pending' AND transaction_id NOT LIKE 'RWD-%' AND COALESCE(description, '') NOT LIKE 'Reward Redemption - %'");
$delivery_count = $delivery_res ? (int)($delivery_res->fetch_assoc()['total'] ?? 0) : 0;

$rewards_res = $conn->query("SELECT COUNT(*) AS total FROM reward_claims WHERE claim_status = 'pending'");
$rewards_count = $rewards_res ? (int)($rewards_res->fetch_assoc()['total'] ?? 0) : 0;

echo json_encode([
    'success' => true,
    'pending_count' => $total_pending,
    'pickup_count' => $pickup_count,
    'delivery_count' => $delivery_count,
    'rewards_count' => $rewards_count,
    'pending_orders' => $pending_orders,
    'server_time' => date('Y-m-d H:i:s')
]);
