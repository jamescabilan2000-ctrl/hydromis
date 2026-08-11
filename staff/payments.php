<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);

$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) DEFAULT 'cash'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(255)");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_date TIMESTAMP NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255)");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) DEFAULT 'pending'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50) NULL");
$conn->query("CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(255) UNIQUE NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL,
    payment_reference VARCHAR(255),
    payment_status VARCHAR(20) DEFAULT 'pending',
    payment_proof VARCHAR(255),
    gcash_number VARCHAR(20),
    maya_number VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$existingPayments = $conn->query("SELECT transaction_id FROM payments");
$existingTransactionIds = [];
if ($existingPayments) {
    while ($row = $existingPayments->fetch_assoc()) {
        $existingTransactionIds[$row['transaction_id']] = true;
    }
}

$seedTransactions = $conn->query("SELECT transaction_id, user_id, amount, payment_status FROM transactions WHERE transaction_id NOT LIKE 'RWD-%' AND transaction_id NOT LIKE 'DEMO-%' ORDER BY created_at DESC");
if ($seedTransactions) {
    while ($transaction = $seedTransactions->fetch_assoc()) {
        $txId = $transaction['transaction_id'];
        if (!isset($existingTransactionIds[$txId])) {
            $paymentId = generateID('PAY');
            $userId = $conn->real_escape_string($transaction['user_id']);
            $amount = (float)($transaction['amount'] ?? 0);
            $currentStatus = $conn->real_escape_string($transaction['payment_status'] ?? 'pending');
            $conn->query("INSERT INTO payments (payment_id, transaction_id, user_id, amount, payment_method, payment_reference, payment_status, notes) VALUES ('$paymentId', '$txId', '$userId', '$amount', 'cash', NULL, '$currentStatus', 'Auto-created from transaction')");
            $existingTransactionIds[$txId] = true;
        }
    }
}

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $payment_id = sanitize($_POST['payment_id']);
    $transaction_id = sanitize($_POST['transaction_id']);
    $action = sanitize($_POST['action']);
    $redirect = 'payments.php';
    
    if ($action == 'verify') {
        $conn->query("UPDATE payments SET payment_status = 'paid' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'paid', payment_date = NOW() WHERE transaction_id = '$transaction_id'");
        $orderResult = $conn->query("SELECT status,fulfillment_method FROM transactions WHERE transaction_id='$transaction_id' LIMIT 1");
        $order = $orderResult ? $orderResult->fetch_assoc() : null;
        if ($order && ($order['status'] ?? '') === 'pending') {
            $staffId = (string)($_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'STAFF');
            [$approved, $approvalMessage] = approve_or_cancel_order_for_stock($conn, $transaction_id, $staffId);
            $_SESSION['staff_flash'] = $approved
                ? 'Payment verified and order approved. You can now assign a rider.'
                : 'Payment was verified, but the order could not enter operations: ' . $approvalMessage;
            $_SESSION['staff_flash_type'] = $approved ? 'success' : 'warning';
            if ($approved && ($order['fulfillment_method'] ?? 'delivery') === 'delivery') {
                $redirect = 'dashboard.php?view=deliveries';
            }
        } elseif ($order && ($order['status'] ?? '') === 'approved' && ($order['fulfillment_method'] ?? 'delivery') === 'delivery') {
            $_SESSION['staff_flash'] = 'Payment verified. The delivery is ready for rider assignment.';
            $_SESSION['staff_flash_type'] = 'success';
            $redirect = 'dashboard.php?view=deliveries';
        }
    } elseif ($action == 'reject') {
        $conn->query("UPDATE payments SET payment_status = 'failed' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'failed' WHERE transaction_id = '$transaction_id'");
    }
    
    header('Location: ' . $redirect);
    exit();
}

$payments = $conn->query("SELECT p.payment_id, p.transaction_id, p.transaction_id AS order_id, p.amount, p.payment_status AS status, p.created_at, p.payment_method, p.payment_reference, p.payment_proof, p.gcash_number, p.maya_number, p.notes, u.full_name, u.contact_number
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.user_id
    WHERE p.transaction_id NOT LIKE 'RWD-%' AND p.transaction_id NOT LIKE 'DEMO-%'
    ORDER BY p.created_at DESC");

$total_payments = 0;
$pending_payments = 0;
$verified_payments = 0;
$total_amount = 0;

if ($payments) {
    $total_payments = $payments->num_rows;
    $stats = $conn->query("SELECT
        SUM(CASE WHEN payment_status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS verified_count,
        SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) AS total_amount
        FROM payments
        WHERE transaction_id NOT LIKE 'RWD-%' AND transaction_id NOT LIKE 'DEMO-%'");
    if ($stats && $stats->num_rows > 0) {
        $row = $stats->fetch_assoc();
        $pending_payments = (int)($row['pending_count'] ?? 0);
        $verified_payments = (int)($row['verified_count'] ?? 0);
        $total_amount = (float)($row['total_amount'] ?? 0);
    }
}

require_once 'payments_view.php';
