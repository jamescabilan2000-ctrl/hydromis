<?php
// Cap pricing snapshots use existing customer notes and system settings.
function cap_request_quantity(bool $enabled, $value): int {
    if (!$enabled) return 0;
    if (!is_scalar($value) || !preg_match('/\A[1-9][0-9]{0,4}\z/', (string)$value)) {
        throw new InvalidArgumentException('Caps requested must be a whole number from 1 to 99,999.');
    }
    return (int)$value;
}
function caps_requested_from_notes(string $notes): int {
    if (preg_match('/Caps requested: ([1-9][0-9]{0,4}) \(if available\)\./', $notes, $match)) return (int)$match[1];
    return str_contains($notes, 'Please include a gallon cap if available.') ? 1 : 0;
}
function notes_without_cap_request(string $notes): string {
    return trim(preg_replace('/(?:^|\R)Caps requested: [1-9][0-9]{0,4} \(if available\)\.(?=\R|$)/', '', preg_replace('/(?:^|\R)Cap price: PHP [0-9]+\.[0-9]{2}; cap subtotal: PHP [0-9]+\.[0-9]{2}\.(?=\R|$)/', '', str_replace('Please include a gallon cap if available.', '', $notes))));
}
function notes_with_cap_request(string $notes, int $quantity, float $unitPrice = 0): string {
    $notes = notes_without_cap_request($notes);
    return $quantity > 0 ? $notes . ($notes !== '' ? "\n" : '') . "Caps requested: $quantity (if available).\nCap price: PHP " . number_format($unitPrice, 2, '.', '') . "; cap subtotal: PHP " . number_format($quantity * round($unitPrice * 100) / 100, 2, '.', '') . "." : $notes;
}

function validate_cap_price($value): float {
    if (!is_scalar($value) || !preg_match('/\A\d{1,5}(?:\.\d{1,2})?\z/', (string)$value)) throw new InvalidArgumentException('Enter a cap price from 0 to 99,999.99 with at most two decimal places.');
    return round((float)$value, 2);
}
function cap_unit_price($conn): float {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gallon_cap_unit_price' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? validate_cap_price($row['setting_value']) : 0.0;
}
function cap_charge(int $quantity, float $price): float { return $quantity * round($price * 100) / 100; }
function saved_cap_details(string $notes): array {
    $quantity = caps_requested_from_notes($notes);
    $price = preg_match('/Cap price: PHP ([0-9]+\.[0-9]{2}); cap subtotal: PHP [0-9]+\.[0-9]{2}\./', $notes, $m) ? (float)$m[1] : 0.0;
    return ['quantity'=>$quantity, 'price'=>$price, 'subtotal'=>cap_charge($quantity,$price)];
}
function summarize_caps(array $orders): array {
    $totals=['requested'=>0,'fulfilled'=>0,'sales'=>0.0];
    foreach ($orders as $order) {
        if (in_array($order['status'] ?? '', ['cancelled','canceled','denied'],true) || in_array($order['delivery_status'] ?? '',['cancelled','canceled','denied'],true) || preg_match('/^(RWD|DEMO)-/', $order['transaction_id'] ?? '')) continue;
        $caps=saved_cap_details((string)($order['notes'] ?? ''));
        $totals['requested'] += $caps['quantity'];
        if (in_array($order['delivery_status'] ?? '',['delivered','completed'],true) || ($order['status'] ?? '')==='completed') {
            $totals['fulfilled'] += $caps['quantity'];
            if (($order['payment_status'] ?? '')==='paid') $totals['sales'] += $caps['subtotal'];
        }
    }
    $totals['sales']=round($totals['sales'],2);
    return $totals;
}
