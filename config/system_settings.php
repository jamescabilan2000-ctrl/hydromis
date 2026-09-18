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

function container_price_defaults(): array {
    return [
        '2.5gal-slim' => ['water' => 15.00, 'container' => 20.00],
        '5gal-slim' => ['water' => 20.00, 'container' => 20.00],
        '5gal-round' => ['water' => 20.00, 'container' => 20.00],
    ];
}

function validate_container_prices($prices): array {
    if (!is_array($prices)) throw new InvalidArgumentException('Enter all container prices.');
    $validated = [];
    foreach (container_price_defaults() as $size => $defaults) {
        foreach ($defaults as $kind => $default) {
            $value = $prices[$size][$kind] ?? null;
            if ((!is_string($value) && !is_int($value) && !is_float($value))
                || !preg_match('/\A\d{1,6}(?:\.\d{1,2})?\z/', (string)$value)
                || (float)$value > 99999.99) {
                throw new InvalidArgumentException('Prices must be between 0 and 99,999.99 pesos, with up to two decimal places.');
            }
            $validated[$size][$kind] = round((float)$value, 2);
        }
    }
    return $validated;
}

function system_container_prices($conn): array {
    ensure_system_settings_schema($conn);
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='container_prices' LIMIT 1");
    if ($result && ($row = $result->fetch_assoc())) {
        try {
            return validate_container_prices(json_decode($row['setting_value'], true));
        } catch (InvalidArgumentException $e) {
            // Keep ordering available if a stored setting is invalid.
        }
    }
    return container_price_defaults();
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
