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
        '2.5gal-slim' => ['water' => 15.00, 'container' => 160.00],
        '5gal-slim' => ['water' => 20.00, 'container' => 160.00],
        '5gal-round' => ['water' => 20.00, 'container' => 160.00],
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
    // Bundled prices include water; legacy surcharge settings must not be reused.
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='container_bundle_prices' LIMIT 1");
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

// Quantity is the number of gallon containers ordered.
function system_refill_pricing_mode($conn): string {
    ensure_system_settings_schema($conn);
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='refill_pricing_mode' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return ($row['setting_value'] ?? '') === 'per_gallon' ? 'per_gallon' : 'quantity';
}

function order_pricing_defaults(): array {
    return ['flat_max' => 4, 'flat_total' => 80.0, 'bulk_unit' => 15.0, 'delivery_unit' => 10.0];
}

function validate_order_pricing_rules($rules): array {
    if (!is_array($rules)) throw new InvalidArgumentException('Enter all quantity and delivery prices.');
    if (!preg_match('/\A(?:[1-9]|[12][0-9]|30)\z/', (string)($rules['flat_max'] ?? ''))) {
        throw new InvalidArgumentException('The fixed-price quantity must be a whole number from 1 to 30.');
    }
    $validated = ['flat_max' => (int)$rules['flat_max']];
    foreach (['flat_total', 'bulk_unit', 'delivery_unit'] as $key) {
        $value = $rules[$key] ?? null;
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/\A\d{1,6}(?:\.\d{1,2})?\z/', (string)$value) || (float)$value > 99999.99) {
            throw new InvalidArgumentException('Prices must be between 0 and 99,999.99 pesos, with up to two decimal places.');
        }
        $validated[$key] = round((float)$value, 2);
    }
    return $validated;
}

function system_order_pricing_rules($conn): array {
    ensure_system_settings_schema($conn);
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='order_pricing_rules' LIMIT 1");
    if ($result && ($row = $result->fetch_assoc())) {
        try { return validate_order_pricing_rules(json_decode($row['setting_value'], true)); }
        catch (InvalidArgumentException $e) {}
    }
    return order_pricing_defaults();
}

function quantity_pricing_description(array $rules): string {
    return 'Water: 1–' . $rules['flat_max'] . ' gallons PHP ' . number_format($rules['flat_total'], 2)
        . ' total; ' . ($rules['flat_max'] + 1) . '+ PHP ' . number_format($rules['bulk_unit'], 2) . ' each';
}

function water_order_total(int $quantity, ?float $unitPrice = null, ?array $rules = null): float {
    if ($quantity < 1) return 0.0;
    if ($unitPrice !== null) return round($unitPrice * $quantity, 2);
    $rules ??= order_pricing_defaults();
    return $quantity <= $rules['flat_max'] ? $rules['flat_total'] : round($rules['bulk_unit'] * $quantity, 2);
}
