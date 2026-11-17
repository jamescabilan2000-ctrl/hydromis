<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/customer_order_access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$token = (string)($_POST['csrf_token'] ?? '');
$stored = (string)($_SESSION['customer_auto_logout_csrf'] ?? '');
if ($stored === '' || !hash_equals($stored, $token)) {
    http_response_code(403);
    exit('Invalid request.');
}

if (($_POST['action'] ?? '') === 'keepalive') {
    if (empty($_SESSION['customer_user_id'])) { http_response_code(401); exit; }
    $_SESSION['customer_last_activity'] = time();
    http_response_code(204);
    exit;
}

clear_customer_session();
header('Location: scan_qr.php?session=expired', true, 303);
exit;
