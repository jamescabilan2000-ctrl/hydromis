<?php
// All customers live in users; guest rows have no account credentials.
function ordering_barangays(): array {
    return ['BagongBanwa Island','Banlasan','Batasan Island','BilangBilangan Island','Bosongon','Buenos Aires','Bunacan','Cabulijan','Cahayag','Cawayanan','Centro','Genonocan','Guiwanon','Ilijan Norte','Ilijan Sur','Libertad','Macaas','Matabao','Mocaboc Island','Panadtaran','Panaytayon','Pandan','Pangapasan Island','Pinayagan Norte','Pinayagan Sur','Pooc Occidental','Pooc Oriental','Potohan','Talenceras','Tan-awan','Tinangnan','Ubojan','Ubay Island','Villanueva'];
}

function validate_order_contact(array $input, string $fulfillment): array {
    $data = [];
    foreach (['full_name','contact_number','barangay','purok'] as $key) {
        $data[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }
    $data['full_name'] = preg_replace('/\s+/u', ' ', $data['full_name']) ?? '';
    $errors = [];
    if (!in_array($fulfillment, ['delivery','pickup'], true)) $errors[] = 'Choose delivery or pickup.';
    if (!preg_match("/\A[\p{L}][\p{L} .’'\-]{1,99}\z/u", $data['full_name'])) $errors[] = 'Name must be 2–100 characters using letters, spaces, apostrophes, periods or hyphens.';
    if (!preg_match('/\A09[0-9]{9}\z/', $data['contact_number'])) $errors[] = 'Contact number must be exactly 11 digits beginning with 09.';
    if (($fulfillment === 'delivery' || $data['barangay'] !== '') && !in_array($data['barangay'], ordering_barangays(), true)) $errors[] = 'Select a valid barangay for delivery. Address is optional for pickup.';
    if (($fulfillment === 'delivery' || $data['purok'] !== '') && !preg_match("/\A[\p{L}\p{N} .,#\/’'\-]{1,100}\z/u", $data['purok'])) $errors[] = 'Enter a valid purok/street for delivery (maximum 100 characters).';
    $data['address'] = implode(', ', array_filter([$data['purok'], $data['barangay']], static fn($v) => $v !== ''));
    return [$data, $errors];
}

function guest_order_identity(string $id): bool { return str_starts_with($id, 'GUEST-'); }

function ensure_guest_ordering_schema($conn): void {
    $ready = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='guest_ordering_schema_version' LIMIT 1");
    if ($ready && ($ready->fetch_assoc()['setting_value'] ?? '') === '2') return;
    $queries = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS customer_type VARCHAR(20) NOT NULL DEFAULT 'registered'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS barangay VARCHAR(512) NOT NULL DEFAULT ''",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS purok VARCHAR(512) NOT NULL DEFAULT ''",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS access_token_hash CHAR(64) NULL UNIQUE",
        "ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_type VARCHAR(20) NOT NULL DEFAULT 'registered'",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS order_source VARCHAR(30) NOT NULL DEFAULT 'customer'",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS created_by_staff_id VARCHAR(50) NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_name TEXT NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_contact TEXT NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_address TEXT NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_barangay VARCHAR(512) NULL",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_purok VARCHAR(512) NULL"
    ];
    foreach ($queries as $sql) if (!$conn->query($sql)) throw new RuntimeException('Unable to prepare guest ordering schema.');
    // Upgrade the previous separate-table design without changing identifiers,
    // encrypted contact values, transaction ownership or private tracking links.
    $legacy = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_customers'");
    if ($legacy && $legacy->num_rows > 0) {
        if (!$conn->query("INSERT INTO users (user_id,full_name,contact_number,address,barangay,purok,access_token_hash,customer_type,status,loyalty_points,created_at) SELECT user_id,full_name,contact_number,address,barangay,purok,access_token_hash,'guest','approved',0,created_at FROM guest_customers ON DUPLICATE KEY UPDATE user_id=users.user_id")) throw new RuntimeException('Unable to migrate guest customers.');
        $check = $conn->query("SELECT COUNT(*) AS missing FROM guest_customers g LEFT JOIN users u ON u.user_id=g.user_id WHERE u.user_id IS NULL OR u.customer_type<>'guest' OR NOT (u.full_name <=> g.full_name) OR NOT (u.contact_number <=> g.contact_number) OR NOT (u.address <=> g.address) OR NOT (u.barangay <=> g.barangay) OR NOT (u.purok <=> g.purok) OR NOT (u.access_token_hash <=> g.access_token_hash)");
        if (!$check || (int)$check->fetch_assoc()['missing'] !== 0) throw new RuntimeException('Guest migration could not verify all contacts; the original table has been preserved.');
    }
    // Compatibility view contains no separate storage.
    $sql = "CREATE OR REPLACE VIEW order_customers AS SELECT user_id,full_name,contact_number,address,qr_code_path,status,loyalty_points,created_at,email,contact_lookup,customer_type FROM users";
    if (!$conn->query($sql)) throw new RuntimeException('Unable to prepare the order customer directory.');
    if ($legacy && $legacy->num_rows > 0 && !$conn->query('DROP TABLE guest_customers')) throw new RuntimeException('Guest contacts were migrated, but the old table could not be removed.');
    if (!$conn->query("INSERT INTO system_settings (setting_key,setting_value) VALUES ('guest_ordering_schema_version','2') ON DUPLICATE KEY UPDATE setting_value='2'")) throw new RuntimeException('Unable to record guest ordering schema version.');
}

function order_contact_snapshot($conn, string $transactionId, array $customer, array $contact, string $source, ?string $staff): bool {
    $name = encrypt_sensitive($contact['full_name']);
    $mobile = encrypt_sensitive($contact['contact_number']);
    $address = encrypt_sensitive($contact['address']);
    $barangay = encrypt_sensitive($contact['barangay']);
    $purok = encrypt_sensitive($contact['purok']);
    $type = guest_order_identity((string)$customer['user_id']) ? 'guest' : 'registered';
    $stmt = $conn->prepare('UPDATE transactions SET customer_type=?,order_source=?,created_by_staff_id=?,customer_name=?,customer_contact=?,customer_address=?,customer_barangay=?,customer_purok=? WHERE transaction_id=?');
    if (!$stmt) return false;
    $stmt->bind_param('sssssssss', $type, $source, $staff, $name, $mobile, $address, $barangay, $purok, $transactionId);
    if (!$stmt->execute()) return false;
    if ($type === 'guest') {
        $id = (string)$customer['user_id'];
        $profile = $conn->prepare("UPDATE users SET full_name=?,contact_number=?,address=?,barangay=?,purok=? WHERE user_id=? AND customer_type='guest'");
        if (!$profile) return false;
        $profile->bind_param('ssssss', $name, $mobile, $address, $barangay, $purok, $id);
        if (!$profile->execute()) return false;
    }
    return true;
}
