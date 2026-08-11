<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/storage_service.php';

$userId = strtoupper(trim((string)($_GET['user_id'] ?? '')));
$authorizedUserId = strtoupper((string)($_SESSION['qr_download_user_id'] ?? $_SESSION['user_id'] ?? ''));
if ($userId === '' || !preg_match('/^[A-Z0-9-]{3,50}$/', $userId) || !hash_equals($authorizedUserId, $userId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('QR download is not authorized for this session.');
}

$contents = hydromis_read_bytes('qrcodes/' . $userId . '.png');
if ($contents === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('QR code not found.');
}

$disposition = isset($_GET['inline']) ? 'inline' : 'attachment';
$filename = 'HydroMIS-' . $userId . '-QR.png';
header('Content-Type: image/png');
header('Content-Length: ' . strlen($contents));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $contents;
