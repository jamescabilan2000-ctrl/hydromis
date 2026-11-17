<?php

function ensure_payment_log_schema($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS payment_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id VARCHAR(255) NULL,
        transaction_id VARCHAR(255) NOT NULL,
        user_id VARCHAR(255) NULL,
        customer_name VARCHAR(512) NULL,
        action VARCHAR(40) NOT NULL,
        old_status VARCHAR(30) NULL,
        new_status VARCHAR(30) NOT NULL,
        payment_method VARCHAR(30) NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        actor_id VARCHAR(80) NULL,
        actor_name VARCHAR(512) NULL,
        actor_role VARCHAR(30) NOT NULL DEFAULT 'system',
        notes VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_payment_log_created (created_at),
        INDEX idx_payment_log_transaction (transaction_id),
        INDEX idx_payment_log_customer (user_id),
        INDEX idx_payment_log_actor (actor_role,actor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function log_payment_event($conn, array $event): bool {
    ensure_payment_log_schema($conn);
    $actor = function_exists('activity_actor') ? activity_actor() : ['id' => null, 'name' => 'System', 'role' => 'system'];
    $values = [
        $event['payment_id'] ?? null, $event['transaction_id'] ?? '', $event['user_id'] ?? null,
        $event['customer_name'] ?? null, $event['action'] ?? 'updated', $event['old_status'] ?? null,
        $event['new_status'] ?? 'pending', $event['payment_method'] ?? null, (float)($event['amount'] ?? 0),
        $actor['id'] ?? null, $actor['name'] ?? 'System', $actor['role'] ?? 'system', $event['notes'] ?? null
    ];
    $stmt = $conn->prepare("INSERT INTO payment_audit_logs (payment_id,transaction_id,user_id,customer_name,action,old_status,new_status,payment_method,amount,actor_id,actor_name,actor_role,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) return false;
    $stmt->bind_param('ssssssssdssss', ...$values);
    return $stmt->execute();
}

