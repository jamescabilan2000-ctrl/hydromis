<?php
const CUSTOMER_IDLE_TIMEOUT_SECONDS = 900;

function clear_customer_session(): void {
    foreach (['customer_user_id','customer_order_csrf','customer_auto_logout_csrf','qr_priority_user','payment_transaction_id','checkout_receipts'] as $key) {
        unset($_SESSION[$key]);
    }
    unset($_SESSION['customer_last_activity']);
}

function require_customer_order_access(string $userId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['customer_user_id'])) {
        header('Location: scan_qr.php'); exit;
    }
    $lastActivity = (int)($_SESSION['customer_last_activity'] ?? time());
    if (time() - $lastActivity >= CUSTOMER_IDLE_TIMEOUT_SECONDS) {
        clear_customer_session();
        header('Location: scan_qr.php?session=expired'); exit;
    }
    if (!hash_equals((string)$_SESSION['customer_user_id'], htmlspecialchars_decode($userId))) {
        http_response_code(403); exit('This order belongs to another customer.');
    }
    $_SESSION['customer_last_activity'] = time();
    if (empty($_SESSION['customer_order_csrf'])) $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
    if (empty($_SESSION['customer_auto_logout_csrf'])) $_SESSION['customer_auto_logout_csrf'] = bin2hex(random_bytes(32));
}
