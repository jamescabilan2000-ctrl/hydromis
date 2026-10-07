<?php
// Monetary values are validated and multiplied in integer centavos.
function cap_price_cents($value): int {
    if (!is_scalar($value) || !preg_match('/\A\d{1,5}(?:\.\d{1,2})?\z/', (string)$value)) {
        throw new InvalidArgumentException('Cap price must be 0 to 99,999.99 pesos with at most two decimal places.');
    }
    $parts = explode('.', (string)$value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}
function cap_quantity($enabled, $value): int {
    if (!$enabled) return 0;
    if (!is_scalar($value) || !preg_match('/\A[1-9][0-9]{0,4}\z/', (string)$value)) {
        throw new InvalidArgumentException('Cap quantity must be a positive whole number, up to 99,999.');
    }
    return (int)$value;
}
function configured_cap_price($conn): ?float {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gallon_cap_unit_price' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) return null;
    try { return cap_price_cents($row['setting_value']) / 100; }
    catch (InvalidArgumentException $e) { return null; }
}
function cap_order_values($enabled, $quantity, ?float $price): array {
    $qty = cap_quantity($enabled, $quantity);
    if ($qty > 0 && $price === null) throw new InvalidArgumentException('Gallon caps are unavailable until the station configures a price.');
    $cents = $price === null ? 0 : cap_price_cents(number_format($price, 2, '.', ''));
    return ['cap_quantity' => $qty, 'cap_unit_price' => $qty ? $cents / 100 : 0, 'cap_subtotal' => $qty * $cents / 100];
}
function order_price_breakdown(array $order): array {
    $water = isset($order['order_water_subtotal']) ? (float)$order['order_water_subtotal'] : round((int)($order['quantity'] ?? 0) * (float)($order['price_per_unit'] ?? 0), 2);
    $caps = (float)($order['cap_subtotal'] ?? 0);
    $discount = (float)($order['discount'] ?? 0);
    $total = (float)($order['amount'] ?? $order['final_amount'] ?? 0);
    return ['water' => $water, 'caps' => $caps, 'discount' => $discount,
        'delivery' => max(0.0, round($total - $water - $caps + $discount, 2)), 'total' => $total];
}
function render_order_caps(array $order): void {
    $qty = (int)($order['cap_quantity'] ?? 0);
    $b = order_price_breakdown($order);
    $money = static fn($n) => 'PHP ' . number_format((float)$n, 2);
    echo '<div style="margin:8px 0;font-size:12px;white-space:normal">Gallons: ' . (int)($order['quantity'] ?? 0) . ' · Caps requested: ' . $qty . ($qty === 0 ? ' (No caps requested)' : '') . '</div>';
    echo '<details style="margin:8px 0;font-size:12px;white-space:normal"><summary style="cursor:pointer">Order Details</summary><div style="max-width:100%;overflow-x:auto"><table style="width:100%;margin-top:8px"><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Subtotal</th></tr></thead><tbody>';
    echo '<tr><td>' . (($order['container_status'] ?? '') === 'new' ? 'Water + new container' : 'Water') . '</td><td>' . (int)($order['quantity'] ?? 0) . '</td><td>' . $money($order['price_per_unit'] ?? 0) . '</td><td>' . $money($b['water']) . '</td></tr>';
    if (abs($b['water'] - (int)($order['quantity'] ?? 0) * (float)($order['price_per_unit'] ?? 0)) >= 0.005) echo '<tr><td colspan="4">Water uses volume pricing; the displayed average unit price is rounded.</td></tr>';
    echo '<tr><td>Gallon caps</td><td>' . $qty . '</td><td>' . $money($order['cap_unit_price'] ?? 0) . '</td><td>' . $money($b['caps']) . '</td></tr></tbody></table></div><div>Delivery: ' . $money($b['delivery']) . '</div><div>Discount: ' . $money($b['discount']) . '</div><strong>Total: ' . $money($b['total']) . '</strong></details>';
}

function cap_summary_sql(string $dateSql = ''): string {
    $completed = "(status='completed' OR delivery_status IN ('delivered','completed'))";
    return "SELECT COALESCE(SUM(cap_quantity),0) AS requested,
        COALESCE(SUM(CASE WHEN $completed THEN cap_quantity ELSE 0 END),0) AS fulfilled,
        COALESCE(SUM(CASE WHEN $completed AND payment_status='paid' THEN cap_subtotal ELSE 0 END),0) AS sales
        FROM transactions WHERE status NOT IN ('cancelled','canceled','denied')
        AND COALESCE(delivery_status,'') NOT IN ('cancelled','canceled','denied')
        AND transaction_id NOT LIKE 'RWD-%' AND transaction_id NOT LIKE 'DEMO-%' $dateSql";
}
