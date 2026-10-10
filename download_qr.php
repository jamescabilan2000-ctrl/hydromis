<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/storage_service.php';

$userId = strtoupper(trim((string)($_GET['user_id'] ?? '')));
$authorizedUserIds = [
    $_SESSION['qr_download_user_id'] ?? '',
    $_SESSION['user_id'] ?? '',
    $_SESSION['customer_user_id'] ?? '',
];
$authorized = false;
foreach ($authorizedUserIds as $authorizedUserId) {
    if ($authorizedUserId !== '' && hash_equals(strtoupper((string)$authorizedUserId), $userId)) {
        $authorized = true;
        break;
    }
}
if ($userId === '' || !preg_match('/^[A-Z0-9-]{3,50}$/', $userId) || !$authorized) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('QR download is not authorized for this session.');
}

$contents = hydromis_read_bytes('qrcodes/' . $userId . '.png');
if ($contents === null) {
    // Older accounts or a deployment may not have a saved PNG. Recreate a
    // scannable code using the customer ID, without sending personal details.
    require_once __DIR__ . '/config/database.php';
    $customer = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1');
    $customer->bind_param('s', $userId);
    $customer->execute();
    if (!$customer->get_result()->fetch_assoc()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Customer QR code not found.');
    }
    $payload = json_encode(['user_id' => $userId]);
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $generated = @file_get_contents('https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($payload), false, $context);
    if ($generated === false || !str_starts_with($generated, "\x89PNG\r\n\x1a\n")) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Your QR code is temporarily unavailable. Please try again.');
    }
    $contents = $generated;
    hydromis_store_bytes('qrcodes/' . $userId . '.png', $contents, 'image/png');
}

$disposition = isset($_GET['inline']) ? 'inline' : 'attachment';
$filename = 'HydroMIS-' . $userId . '-QR.png';
header('Content-Type: image/png');
header('Content-Length: ' . strlen($contents));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $contents;
