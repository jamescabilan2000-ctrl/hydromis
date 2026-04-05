<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $payment_id = sanitize($_POST['payment_id']);
    $transaction_id = sanitize($_POST['transaction_id']);
    $action = sanitize($_POST['action']);
    
    if ($action == 'verify') {
        // Update payment status
        $conn->query("UPDATE payments SET payment_status = 'paid' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'paid', payment_date = NOW() WHERE transaction_id = '$transaction_id'");
    } elseif ($action == 'reject') {
        // Update payment status
        $conn->query("UPDATE payments SET payment_status = 'failed' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'failed' WHERE transaction_id = '$transaction_id'");
    }
    
    header('Location: payments.php');
    exit();
}

// Get all payments
$payments = $conn->query("
    SELECT p.*, t.description, u.full_name, u.contact_number
    FROM payments p
    JOIN transactions t ON p.transaction_id = t.transaction_id
    JOIN users u ON p.user_id = u.user_id
    ORDER BY p.created_at DESC
");

// Get payment statistics
$total_payments = $conn->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'];
$pending_payments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE payment_status = 'pending' OR payment_status = 'processing'")->fetch_assoc()['count'];
$verified_payments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE payment_status = 'paid'")->fetch_assoc()['count'];
$total_amount = $conn->query("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'paid'")->fetch_assoc()['total'] ?? 0;

// Include the view
require_once 'payments_view.php';
