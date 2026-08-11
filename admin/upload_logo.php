<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
require_once '../config/storage_service.php';

header('Content-Type: application/json');
ensure_system_settings_schema($conn);

if (!hash_equals((string)($_SESSION['system_logo_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh and try again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Select a logo image to upload.']);
    exit;
}

$file = $_FILES['logo'];
if ($file['size'] > 3 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Logo must be 3 MB or smaller.']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime])) {
    echo json_encode(['success' => false, 'message' => 'Use a JPG, PNG, or WebP image.']);
    exit;
}

$imageInfo = @getimagesize($file['tmp_name']);
if (!$imageInfo || $imageInfo[0] < 64 || $imageInfo[1] < 64) {
    echo json_encode(['success' => false, 'message' => 'Logo must be at least 64 × 64 pixels.']);
    exit;
}

$oldPath = system_logo_path($conn);
$filename = 'hydromis-logo-' . time() . '.' . $extensions[$mime];
$newPath = 'uploads/system/' . $filename;
if (!hydromis_store_upload($file['tmp_name'], $newPath, $mime)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save the uploaded logo.']);
    exit;
}
$staffId = (string)($_SESSION['admin_auth_id'] ?? $_SESSION['admin_id'] ?? 'ADMIN');
$stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('system_logo', ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)");
$stmt->bind_param('ss', $newPath, $staffId);
if (!$stmt->execute()) {
    hydromis_delete_object($newPath);
    echo json_encode(['success' => false, 'message' => 'Unable to update the logo setting.']);
    exit;
}

if (str_starts_with($oldPath, 'uploads/system/')) hydromis_delete_object($oldPath);

echo json_encode(['success' => true, 'message' => 'System logo updated.', 'path' => hydromis_storage_url($newPath)]);
