<?php

/**
 * Keep every customer on one system-wide 12-month loyalty cycle.
 * The first installation date becomes the anniversary. The first request on
 * or after that anniversary resets all balances and advances the cycle.
 */
function enforce_annual_loyalty_reset($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(500) NOT NULL,
        updated_by VARCHAR(80) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='loyalty_points_reset_at' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row || strtotime((string)($row['setting_value'] ?? '')) === false) {
        $conn->query("INSERT INTO system_settings (setting_key,setting_value,updated_by)
            VALUES ('loyalty_points_reset_at', DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s'), 'SYSTEM')
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by='SYSTEM'");
        $conn->query("UPDATE users SET points_year=YEAR(CURDATE()) WHERE points_year IS NULL");
        return;
    }

    if (strtotime((string)$row['setting_value']) > time()) return;

    $conn->begin_transaction();
    $locked = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='loyalty_points_reset_at' FOR UPDATE");
    $lockedRow = $locked ? $locked->fetch_assoc() : null;
    $resetAt = (string)($lockedRow['setting_value'] ?? '');
    if ($resetAt === '' || strtotime($resetAt) > time()) {
        $conn->commit();
        return;
    }

    if (!$conn->query("UPDATE users SET loyalty_points=0,points_year=YEAR(CURDATE())")) {
        $conn->rollback();
        return;
    }

    $safeResetAt = $conn->real_escape_string($resetAt);
    if (!$conn->query("UPDATE system_settings
        SET setting_value=DATE_FORMAT(DATE_ADD('$safeResetAt', INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s'),updated_by='SYSTEM'
        WHERE setting_key='loyalty_points_reset_at'")) {
        $conn->rollback();
        return;
    }
    $conn->commit();

    // If the system was offline for more than a year, advance/reset again until
    // the stored anniversary is in the future.
    enforce_annual_loyalty_reset($conn);
}

function loyalty_points_reset_at($conn): ?string {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='loyalty_points_reset_at' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    $value = (string)($row['setting_value'] ?? '');
    return strtotime($value) === false ? null : $value;
}

