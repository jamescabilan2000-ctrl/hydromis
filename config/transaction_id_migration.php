<?php

function ensure_sequential_transaction_ids($conn): void {
    $mustQuery = static function (string $sql) use ($conn) {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('Transaction ID migration failed: ' . $conn->error);
        }
        return $result;
    };

    $mustQuery("CREATE TABLE IF NOT EXISTS order_id_sequence (
        sequence_key TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        last_value BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $mustQuery("INSERT IGNORE INTO order_id_sequence (sequence_key,last_value) VALUES (1,1000)");
    $mustQuery("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
        completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $lockResult = $mustQuery("SELECT GET_LOCK('hydromis_sequential_transaction_ids',60) AS acquired");
    $lockRow = $lockResult->fetch_assoc();
    if ((int)($lockRow['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire the transaction ID migration lock.');
    }

    try {
        $migrationKey = 'sequential_transaction_ids_1001_v1';
        $safeMigrationKey = $conn->real_escape_string($migrationKey);
        $done = $mustQuery("SELECT migration_key FROM schema_migrations WHERE migration_key='$safeMigrationKey' LIMIT 1");
        if ($done->num_rows > 0) {
            return;
        }

        $orders = $mustQuery("SELECT transaction_id FROM transactions
            WHERE transaction_id NOT LIKE 'RWD-%' AND transaction_id NOT LIKE 'DEMO-%'
            ORDER BY created_at ASC, id ASC");
        $oldIds = [];
        while ($order = $orders->fetch_assoc()) $oldIds[] = (string)$order['transaction_id'];

        $mustQuery("CREATE TEMPORARY TABLE tmp_order_id_map (
            old_id VARCHAR(255) NOT NULL PRIMARY KEY,
            stage_id VARCHAR(40) NOT NULL UNIQUE,
            new_id VARCHAR(20) NOT NULL UNIQUE
        ) ENGINE=InnoDB");

        $next = 1001;
        foreach ($oldIds as $index => $oldId) {
            $safeOld = $conn->real_escape_string($oldId);
            $stageId = '__HM_TXN_MIG_' . $index . '__';
            $safeStage = $conn->real_escape_string($stageId);
            $newId = (string)$next++;
            $mustQuery("INSERT INTO tmp_order_id_map (old_id,stage_id,new_id) VALUES ('$safeOld','$safeStage','$newId')");
        }

        $columns = $mustQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='transaction_id'
            GROUP BY TABLE_NAME ORDER BY TABLE_NAME");
        $tables = [];
        while ($column = $columns->fetch_assoc()) $tables[] = (string)$column['TABLE_NAME'];

        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $collision = $mustQuery("SELECT COUNT(*) AS total FROM $quotedTable target
                INNER JOIN tmp_order_id_map mapping ON target.transaction_id=mapping.new_id
                LEFT JOIN tmp_order_id_map source ON target.transaction_id=source.old_id
                WHERE source.old_id IS NULL");
            if ((int)$collision->fetch_assoc()['total'] > 0) {
                throw new RuntimeException("Cannot renumber orders safely: $table already contains a target ID.");
            }
        }

        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start the transaction ID migration.');
        }
        $mustQuery('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $mustQuery("UPDATE $quotedTable target INNER JOIN tmp_order_id_map mapping
                ON target.transaction_id=mapping.old_id SET target.transaction_id=mapping.stage_id");
        }
        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $mustQuery("UPDATE $quotedTable target INNER JOIN tmp_order_id_map mapping
                ON target.transaction_id=mapping.stage_id SET target.transaction_id=mapping.new_id");
        }

        $lastValue = $next - 1;
        $mustQuery("UPDATE order_id_sequence SET last_value=$lastValue WHERE sequence_key=1");
        $mustQuery("INSERT INTO schema_migrations (migration_key) VALUES ('$safeMigrationKey')");
        $mustQuery('SET FOREIGN_KEY_CHECKS=1');
        if (!$conn->commit()) {
            throw new RuntimeException('Could not commit the transaction ID migration.');
        }
    } catch (Throwable $error) {
        $conn->rollback();
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        throw $error;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('hydromis_sequential_transaction_ids')");
    }
}

function resolve_legacy_customer_id($conn, string $userId): string {
    $safeId = $conn->real_escape_string($userId);
    $result = $conn->query("SELECT current_user_id FROM legacy_user_ids WHERE legacy_user_id='$safeId' LIMIT 1");
    if ($result && ($row = $result->fetch_assoc())) return (string)$row['current_user_id'];
    return $userId;
}

function ensure_sequential_customer_ids($conn): void {
    $mustQuery = static function (string $sql) use ($conn) {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('Customer ID migration failed: ' . $conn->error);
        }
        return $result;
    };

    $mustQuery("CREATE TABLE IF NOT EXISTS customer_id_sequence (
        sequence_key TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        last_value BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $mustQuery("INSERT IGNORE INTO customer_id_sequence (sequence_key,last_value) VALUES (1,10000)");
    $mustQuery("CREATE TABLE IF NOT EXISTS legacy_user_ids (
        legacy_user_id VARCHAR(50) NOT NULL PRIMARY KEY,
        current_user_id VARCHAR(50) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_legacy_user_current (current_user_id)
    ) ENGINE=InnoDB");

    $lockResult = $mustQuery("SELECT GET_LOCK('hydromis_sequential_customer_ids',60) AS acquired");
    $lockRow = $lockResult->fetch_assoc();
    if ((int)($lockRow['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire the customer ID migration lock.');
    }

    try {
        $migrationKey = 'sequential_customer_ids_10001_v1';
        $safeMigrationKey = $conn->real_escape_string($migrationKey);
        $done = $mustQuery("SELECT migration_key FROM schema_migrations WHERE migration_key='$safeMigrationKey' LIMIT 1");
        if ($done->num_rows > 0) return;

        $customers = $mustQuery('SELECT user_id FROM users ORDER BY created_at ASC, id ASC');
        $oldIds = [];
        while ($customer = $customers->fetch_assoc()) $oldIds[] = (string)$customer['user_id'];

        $mustQuery("CREATE TEMPORARY TABLE tmp_customer_id_map (
            old_id VARCHAR(255) NOT NULL PRIMARY KEY,
            stage_id VARCHAR(40) NOT NULL UNIQUE,
            new_id VARCHAR(20) NOT NULL UNIQUE
        ) ENGINE=InnoDB");

        $next = 10001;
        foreach ($oldIds as $index => $oldId) {
            $safeOld = $conn->real_escape_string($oldId);
            $stageId = '__HM_USER_MIG_' . $index . '__';
            $safeStage = $conn->real_escape_string($stageId);
            $newId = (string)$next++;
            $mustQuery("INSERT INTO tmp_customer_id_map (old_id,stage_id,new_id) VALUES ('$safeOld','$safeStage','$newId')");
        }

        $columns = $mustQuery("SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('user_id','source_user_id')
            ORDER BY TABLE_NAME,COLUMN_NAME");
        $references = [];
        while ($column = $columns->fetch_assoc()) $references[] = [(string)$column['TABLE_NAME'], (string)$column['COLUMN_NAME']];

        foreach ($references as [$table, $column]) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
            $collision = $mustQuery("SELECT COUNT(*) AS total FROM $quotedTable target
                INNER JOIN tmp_customer_id_map mapping ON target.$quotedColumn=mapping.new_id
                LEFT JOIN tmp_customer_id_map source ON target.$quotedColumn=source.old_id
                WHERE source.old_id IS NULL");
            if ((int)$collision->fetch_assoc()['total'] > 0) {
                throw new RuntimeException("Cannot renumber customers safely: $table already contains a target ID.");
            }
        }

        if (!$conn->begin_transaction()) throw new RuntimeException('Could not start the customer ID migration.');
        $mustQuery('SET FOREIGN_KEY_CHECKS=0');
        $mustQuery("INSERT INTO legacy_user_ids (legacy_user_id,current_user_id)
            SELECT old_id,new_id FROM tmp_customer_id_map WHERE old_id<>new_id
            ON DUPLICATE KEY UPDATE current_user_id=VALUES(current_user_id)");
        foreach ($references as [$table, $column]) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
            $mustQuery("UPDATE $quotedTable target INNER JOIN tmp_customer_id_map mapping
                ON target.$quotedColumn=mapping.old_id SET target.$quotedColumn=mapping.stage_id");
        }
        foreach ($references as [$table, $column]) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
            $mustQuery("UPDATE $quotedTable target INNER JOIN tmp_customer_id_map mapping
                ON target.$quotedColumn=mapping.stage_id SET target.$quotedColumn=mapping.new_id");
        }

        $lastValue = $next - 1;
        $mustQuery("UPDATE customer_id_sequence SET last_value=$lastValue WHERE sequence_key=1");
        $mustQuery("INSERT INTO schema_migrations (migration_key) VALUES ('$safeMigrationKey')");
        $mustQuery('SET FOREIGN_KEY_CHECKS=1');
        if (!$conn->commit()) throw new RuntimeException('Could not commit the customer ID migration.');
    } catch (Throwable $error) {
        $conn->rollback();
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        throw $error;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('hydromis_sequential_customer_ids')");
    }
}

