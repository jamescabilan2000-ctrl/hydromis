<?php
require_once __DIR__ . '/storage_service.php';

function ensure_system_settings_schema($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(500) NOT NULL,
        updated_by VARCHAR(80) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

function system_logo_path($conn): string {
    ensure_system_settings_schema($conn);
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo' LIMIT 1");
    if ($result && ($row = $result->fetch_assoc()) && trim((string)$row['setting_value']) !== '') {
        return (string)$row['setting_value'];
    }
    return 'imagess/hydromis-logo-v2.png';
}

function system_int_setting($conn, string $key, int $default, int $min = 0, int $max = 100): int {
    ensure_system_settings_schema($conn);
    $safeKey = $conn->real_escape_string($key);
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='$safeKey' LIMIT 1");
    $value = $result && ($row = $result->fetch_assoc()) ? (int)$row['setting_value'] : $default;
    return max($min, min($max, $value));
}

function set_system_setting($conn, string $key, string $value, string $updatedBy = ''): bool {
    ensure_system_settings_schema($conn);
    $safeKey = $conn->real_escape_string($key);
    $safeValue = $conn->real_escape_string($value);
    $safeUpdatedBy = $conn->real_escape_string($updatedBy);
    return (bool)$conn->query("INSERT INTO system_settings (setting_key,setting_value,updated_by) VALUES ('$safeKey','$safeValue','$safeUpdatedBy') ON DUPLICATE KEY UPDATE setting_value='$safeValue',updated_by='$safeUpdatedBy'");
}
