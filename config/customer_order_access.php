<?php
function require_customer_order_access(string $userId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['customer_user_id'])) {
        header('Location: scan_qr.php'); exit;
    }
    if (!hash_equals((string)$_SESSION['customer_user_id'], htmlspecialchars_decode($userId))) {
        http_response_code(403); exit('This order belongs to another customer.');
    }
    if (empty($_SESSION['customer_order_csrf'])) $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
}
