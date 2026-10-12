<?php
require_once 'check_auth.php';
require_once '../config/database.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="hydromis_export_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');

// Export Users
fputcsv($output, ['=== USERS DATA ===']);
fputcsv($output, ['User ID', 'Full Name', 'Contact', 'Status', 'Created At']);

$users = $conn->query("SELECT user_id, full_name, contact_number, status, created_at FROM users ORDER BY created_at DESC");
if ($users) {
    while ($row = $users->fetch_assoc()) {
        fputcsv($output, [$row['user_id'], $row['full_name'], $row['contact_number'], $row['status'], $row['created_at']]);
    }
}

fputcsv($output, []);
fputcsv($output, ['=== TRANSACTIONS DATA ===']);
fputcsv($output, ['Transaction ID', 'Customer Name', 'Amount', 'Description', 'Status', 'Created At']);

$trans = $conn->query("
    SELECT t.transaction_id, u.full_name, t.amount, t.description, t.status, t.created_at
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    ORDER BY t.created_at DESC
");
if ($trans) {
    while ($row = $trans->fetch_assoc()) {
        fputcsv($output, [$row['transaction_id'], $row['full_name'], $row['amount'], $row['description'], $row['status'], $row['created_at']]);
    }
}

fputcsv($output, []);
fputcsv($output, ['=== RIDERS DATA ===']);
fputcsv($output, ['Rider ID', 'Username', 'Full Name', 'Age', 'Contact', 'Status', 'Created At']);

$riders = $conn->query("SELECT rider_id, username, full_name, age, contact_number, status, created_at FROM rider_users ORDER BY created_at DESC");
if ($riders) {
    while ($row = $riders->fetch_assoc()) {
        fputcsv($output, [$row['rider_id'], $row['username'], $row['full_name'], $row['age'], $row['contact_number'], $row['status'], $row['created_at']]);
    }
}

fclose($output);
exit;
