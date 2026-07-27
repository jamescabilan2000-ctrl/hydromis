<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This migration may only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';

$plans = [
    'users' => ['pk' => 'id', 'fields' => ['username', 'full_name', 'email', 'contact_number', 'address'], 'lookups' => ['username' => 'username_lookup', 'contact_number' => 'contact_lookup']],
    'rider_users' => ['pk' => 'id', 'fields' => ['username', 'full_name', 'address', 'contact_number'], 'lookups' => ['username' => 'username_lookup', 'contact_number' => 'contact_lookup']],
    'admin_users' => ['pk' => 'id', 'fields' => ['username', 'full_name'], 'lookups' => ['username' => 'username_lookup']],
    'admin_profiles' => ['pk' => 'id', 'fields' => ['first_name', 'last_name', 'email', 'phone'], 'lookups' => []],
];

$conn->begin_transaction();
try {
    foreach ($plans as $table => $plan) {
        $available = [];
        $columnRows = $conn->query("SHOW COLUMNS FROM $table");
        if (!$columnRows) continue;
        while ($column = $columnRows->fetch_assoc()) $available[] = $column['Field'];
        $plan['fields'] = array_values(array_intersect($plan['fields'], $available));
        $fields = implode(', ', array_merge([$plan['pk']], $plan['fields']));
        $rows = $conn->query("SELECT $fields FROM $table");
        if (!$rows) continue;
        while ($row = $rows->fetch_assoc()) {
            $sets = [];
            foreach ($plan['fields'] as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') continue;
                $plain = (string)$row[$field];
                $encrypted = $conn->real_escape_string(encrypt_sensitive($plain));
                $sets[] = "$field = '$encrypted'";
                if (isset($plan['lookups'][$field])) {
                    $lookupColumn = $plan['lookups'][$field];
                    $lookup = $conn->real_escape_string(sensitive_lookup($plain));
                    $sets[] = "$lookupColumn = '$lookup'";
                }
            }
            if ($sets) {
                $pk = (int)$row[$plan['pk']];
                if (!$conn->query("UPDATE $table SET " . implode(', ', $sets) . " WHERE {$plan['pk']} = $pk")) {
                    throw new RuntimeException("Migration failed for $table/$pk: {$conn->error}");
                }
            }
        }
        echo "Protected $table\n";
    }

    // Settings may contain name/email/phone values, so encrypt the whole document.
    $settings = $conn->query('SELECT id, settings FROM admin_settings');
    if ($settings) {
        while ($row = $settings->fetch_assoc()) {
            $value = $conn->real_escape_string(encrypt_sensitive((string)$row['settings']));
            $id = (int)$row['id'];
            if (!$conn->query("UPDATE admin_settings SET settings = '$value' WHERE id = $id")) {
                throw new RuntimeException("Migration failed for admin_settings/$id: {$conn->error}");
            }
        }
    }
    $conn->commit();
    echo "AES-256-GCM migration completed. Back up config/.encryption_key securely.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
