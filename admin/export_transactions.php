<?php
require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/../config/database.php';

$type = $_GET['type'] ?? 'transactions';
$date = $_GET['date'] ?? '';
$method = $_GET['method'] ?? 'all';
if (!is_string($type) || !in_array($type, ['transactions', 'sales'], true)
    || !is_string($date) || !is_string($method)
    || !in_array($method, ['all', 'cash', 'gcash', 'maya'], true)) {
    http_response_code(400);
    exit('Invalid export filters.');
}
if ($date !== '') {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        http_response_code(400);
        exit('Invalid export date.');
    }
}

$conditions = ['1=1'];
$params = [];
if ($type === 'sales') {
    $conditions[] = "t.status = 'approved'";
}
if ($date !== '') {
    $conditions[] = 'DATE(t.created_at) = ?';
    $params[] = $date;
}
if ($method !== 'all') {
    $conditions[] = 't.payment_method = ?';
    $params[] = $method;
}
$stmt = $conn->prepare('SELECT t.transaction_id, u.full_name, t.amount,
    t.description, t.payment_method, t.status, t.created_at
    FROM transactions t LEFT JOIN users u ON t.user_id = u.user_id
    WHERE ' . implode(' AND ', $conditions) . ' ORDER BY t.created_at DESC');
if ($params) {
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
}
$stmt->execute();
$rows = $stmt->get_result();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $type . '-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Transaction ID', 'Customer', 'Amount (PHP)', 'Description', 'Payment Method', 'Status', 'Date'], ',', '"', '');
while ($row = $rows->fetch_assoc()) {
    $row['amount'] = number_format((float)$row['amount'], 2, '.', '');
    // Keep user-entered text from being interpreted as spreadsheet formulas.
    foreach ($row as $key => $value) {
        $value = (string)($value ?? '');
        if ($key !== 'amount' && preg_match('/^[\s\x00-\x20]*[=+@-]|^[\t\r\n]/u', $value)) {
            $value = "'" . $value;
        }
        $row[$key] = $value;
    }
    fputcsv($output, array_values($row), ',', '"', '');
}
fclose($output);
$stmt->close();
exit;
