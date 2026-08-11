<?php
require_once __DIR__ . '/data_security.php';
// XAMPP/MySQL configuration.
// Credentials can come from config/supabase.php (kept for compatibility)
// or from MYSQL_DB_* environment variables.
$databaseConfig = [];
$databaseConfigPath = __DIR__ . '/supabase.php';
if (file_exists($databaseConfigPath)) {
    $loadedConfig = require $databaseConfigPath;
    if (is_array($loadedConfig)) {
        $databaseConfig = $loadedConfig;
    }
}

$localDatabaseConfigPath = __DIR__ . '/supabase.local.php';
if (file_exists($localDatabaseConfigPath)) {
    $localConfig = require $localDatabaseConfigPath;
    if (is_array($localConfig)) $databaseConfig = array_replace($databaseConfig, $localConfig);
}

define('DB_HOST', $databaseConfig['host'] ?? getenv('MYSQL_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', $databaseConfig['port'] ?? getenv('MYSQL_DB_PORT') ?: '3306');
define('DB_USER', $databaseConfig['user'] ?? getenv('MYSQL_DB_USER') ?: 'root');
define('DB_PASS', $databaseConfig['password'] ?? getenv('MYSQL_DB_PASSWORD') ?: '');
define('DB_NAME', $databaseConfig['database'] ?? getenv('MYSQL_DB_NAME') ?: 'hydromis');

function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(htmlspecialchars($input));
}

function generateID($prefix) {
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
}

function generateUserID() {
    global $conn;

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'USR-' . strtoupper(bin2hex(random_bytes(4)));
        $safeCandidate = $conn->real_escape_string($candidate);
        $existing = $conn->query("SELECT id FROM users WHERE user_id = '$safeCandidate' LIMIT 1");
        if (!$existing || $existing->num_rows === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique customer ID.');
}

if (($databaseConfig['driver'] ?? 'mysql') === 'pgsql') {
    define('DB_SSLMODE', $databaseConfig['sslmode'] ?? 'require');
    require __DIR__ . '/database_pgsql.php';

    require_once __DIR__ . '/loyalty_service.php';
    enforce_annual_loyalty_reset($conn);

    require_once __DIR__ . '/activity_logger.php';
    auto_log_system_request($conn);
    return;
}

/**
 * Lightweight result wrapper for mysqli queries.
 */
class DBCompatResult {
    private $result;
    public $num_rows = 0;

    public function __construct($result) {
        $this->result = $result;
        $this->num_rows = $result ? $result->num_rows : 0;
    }

    public function fetch_assoc() {
        if (!$this->result) {
            return null;
        }

        $row = $this->result->fetch_assoc();
        if (!is_array($row)) return $row;
        foreach ($row as $column => $value) {
            if (is_string($value) && DataSecurity::isEncrypted($value)) {
                $row[$column] = DataSecurity::decrypt($value);
            }
        }
        return $row;
    }
}

/** Prepared statements must use the same decryption path as normal queries. */
class DBCompatStatement {
    private mysqli_stmt $statement;
    public function __construct(mysqli_stmt $statement) { $this->statement = $statement; }
    public function bind_param(string $types, &...$variables): bool { return $this->statement->bind_param($types, ...$variables); }
    public function execute(?array $params = null): bool { return $params === null ? $this->statement->execute() : $this->statement->execute($params); }
    public function get_result() {
        $result = $this->statement->get_result();
        return $result === false ? false : new DBCompatResult($result);
    }
    public function close(): bool { return $this->statement->close(); }
    public function __get(string $name) { return $this->statement->$name; }
}

/**
 * mysqli-like wrapper so existing pages can continue using the same API.
 */
class DBCompatConnection {
    public $connect_error = '';
    public $error = '';
    public $insert_id = 0;
    public $affected_rows = 0;

    private $mysqli = null;

    public function __construct($host, $user, $pass, $db, $port = 3306) {
        mysqli_report(MYSQLI_REPORT_OFF);

        // Connect to the MySQL server first so a fresh XAMPP installation does
        // not require a manual phpMyAdmin import before the app can start.
        $this->mysqli = new mysqli($host, $user, $pass, '', (int) $port);

        if ($this->mysqli->connect_errno) {
            $this->connect_error = $this->mysqli->connect_error;
            return;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $db)) {
            $this->connect_error = 'Invalid database name.';
            $this->mysqli->close();
            $this->mysqli = null;
            return;
        }

        if (!$this->mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") ||
            !$this->mysqli->select_db($db)) {
            $this->connect_error = $this->mysqli->error;
            return;
        }

        $this->set_charset('utf8mb4');
        $this->ensureSchema();
    }

    public function set_charset($charset) {
        if (!$this->mysqli) {
            return false;
        }

        return $this->mysqli->set_charset($charset);
    }

    public function real_escape_string($input) {
        if (!$this->mysqli) {
            return addslashes((string) $input);
        }

        return $this->mysqli->real_escape_string((string) $input);
    }

    public function query($sql) {
        if (!$this->mysqli) {
            $this->error = 'Database connection is not available.';
            $this->affected_rows = 0;
            return false;
        }

        $this->error = '';
        $this->affected_rows = 0;
        $sql = $this->translateSql(trim($sql));

        try {
            $result = $this->mysqli->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->error = $e->getMessage();
            return false;
        }

        if ($result === false) {
            $this->error = $this->mysqli->error;
            return false;
        }

        $this->affected_rows = $this->mysqli->affected_rows;

        if ($result === true) {
            $this->insert_id = $this->mysqli->insert_id;
            return true;
        }

        return new DBCompatResult($result);
    }

    public function prepare($sql) {
        if (!$this->mysqli) {
            $this->error = 'Database connection is not available.';
            return false;
        }

        $this->error = '';
        $sql = $this->translateSql(trim($sql));
        $stmt = $this->mysqli->prepare($sql);

        if ($stmt === false) {
            $this->error = $this->mysqli->error;
            return false;
        }

        return new DBCompatStatement($stmt);
    }

    public function begin_transaction() {
        if (!$this->mysqli) {
            $this->error = 'Database connection is not available.';
            return false;
        }
        return $this->mysqli->begin_transaction();
    }

    public function commit() {
        if (!$this->mysqli) return false;
        return $this->mysqli->commit();
    }

    public function rollback() {
        if (!$this->mysqli) return false;
        return $this->mysqli->rollback();
    }

    public function close() {
        if ($this->mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
        }

        return true;
    }

    private function translateSql($sql) {
        if (preg_match("/^SHOW\\s+TABLES\\s+LIKE\\s+'([^']+)'/i", $sql, $matches)) {
            $tableName = $this->real_escape_string($matches[1]);
            return "SHOW TABLES LIKE '$tableName'";
        }

        return $sql;
    }

    private function ensureSchema() {
        $this->query("CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id VARCHAR(50) UNIQUE NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) UNIQUE NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            contact_number VARCHAR(20),
            qr_code_path VARCHAR(255),
            status VARCHAR(20) DEFAULT 'approved',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) UNIQUE NOT NULL,
            user_id VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            description TEXT,
            water_type VARCHAR(20) DEFAULT 'regular',
            quantity INT DEFAULT 1,
            price_per_unit DECIMAL(10,2) NULL,
            discount DECIMAL(10,2) DEFAULT 0,
            notes TEXT,
            delivery_status VARCHAR(30) DEFAULT 'pending',
            assigned_rider VARCHAR(50) NULL,
            approved_by VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS water_type VARCHAR(20) DEFAULT 'regular'");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS quantity INT DEFAULT 1");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS price_per_unit DECIMAL(10,2) NULL");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) DEFAULT 0");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS notes TEXT");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) DEFAULT 'pending'");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS assigned_rider VARCHAR(50) NULL");
        $this->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS approved_by VARCHAR(50) NULL");

        $this->query("CREATE TABLE IF NOT EXISTS rider_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rider_id VARCHAR(50) UNIQUE NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            age INT,
            address TEXT,
            contact_number VARCHAR(20),
            status VARCHAR(20) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS rider_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) NOT NULL,
            rider_id VARCHAR(50) NULL,
            rider_latitude DECIMAL(10,8) NOT NULL DEFAULT 12.8797,
            rider_longitude DECIMAL(11,8) NOT NULL DEFAULT 121.7740,
            accuracy FLOAT NULL,
            speed FLOAT NULL,
            heading FLOAT NULL,
            last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_rider_id (rider_id),
            INDEX idx_last_update (last_update)
        )");

        $this->query("CREATE TABLE IF NOT EXISTS rider_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rider_id VARCHAR(50) NOT NULL,
            transaction_id VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rider_id (rider_id),
            INDEX idx_is_read (is_read)
        )");

        $this->query("CREATE TABLE IF NOT EXISTS rider_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) NOT NULL,
            sender VARCHAR(50) NOT NULL,
            recipient VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_sender (sender),
            INDEX idx_recipient (recipient)
        )");

        $this->query("CREATE TABLE IF NOT EXISTS feedback_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255) NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            rating INT NOT NULL,
            feedback_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id VARCHAR(255) UNIQUE NOT NULL,
            transaction_id VARCHAR(255) NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(20) NOT NULL,
            payment_reference VARCHAR(255),
            payment_status VARCHAR(20) DEFAULT 'pending',
            payment_proof VARCHAR(255),
            gcash_number VARCHAR(20),
            maya_number VARCHAR(20),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS admin_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id VARCHAR(50) NOT NULL UNIQUE,
            settings JSON NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS admin_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id VARCHAR(50) NOT NULL UNIQUE,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            email VARCHAR(255),
            phone VARCHAR(20),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $this->query("CREATE TABLE IF NOT EXISTS payment_qr_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_method VARCHAR(20) NOT NULL UNIQUE,
            qr_image_path VARCHAR(255) NOT NULL,
            account_number VARCHAR(50) NOT NULL DEFAULT '',
            account_name VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // Seed default QR settings if not present
        $qrCheck = $this->query("SELECT id FROM payment_qr_settings LIMIT 1");
        if (!$qrCheck || $qrCheck->num_rows === 0) {
            $this->query("INSERT IGNORE INTO payment_qr_settings (payment_method, qr_image_path, account_number, account_name) VALUES
                ('gcash', 'imagess/cashg.jpg', '0993 909 3915', 'James C.'),
                ('maya', 'imagess/ayam.jpg', '0993 909 3915', 'James C.')
            ");
        }

        $schemaColumns = [
            ['users', 'loyalty_points', 'INT DEFAULT 0'],
            ['users', 'points_year', 'INT NULL'],
            ['transactions', 'payment_method', 'VARCHAR(20) DEFAULT "cash"'],
            ['transactions', 'payment_reference', 'VARCHAR(255)'],
            ['transactions', 'payment_status', 'VARCHAR(20) DEFAULT "pending"'],
            ['transactions', 'payment_date', 'TIMESTAMP NULL'],
            ['transactions', 'payment_proof', 'VARCHAR(255)'],
            ['transactions', 'delivery_status', 'VARCHAR(30) DEFAULT "pending"'],
            ['transactions', 'assigned_rider', 'VARCHAR(255) NULL'],
            ['transactions', 'rider_id', 'VARCHAR(50) NULL'],
            ['transactions', 'approved_by', 'VARCHAR(50) NULL'],
            ['transactions', 'loyalty_points_earned', 'INT DEFAULT 0'],
            ['transactions', 'amount_tendered', 'DECIMAL(10,2) NULL'],
            ['transactions', 'change', 'DECIMAL(10,2) NULL'],
            ['transactions', 'container_size', 'VARCHAR(30) NULL'],
            ['transactions', 'container_status', 'VARCHAR(20) NULL'],
            ['transactions', 'fulfillment_method', 'VARCHAR(20) NULL'],
            ['transactions', 'inventory_item_id', 'INT NULL'],
            ['transactions', 'inventory_reserved', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['transactions', 'cancellation_reason', 'VARCHAR(255) NULL'],
            ['rider_locations', 'rider_id', 'VARCHAR(50) NULL'],
            ['rider_locations', 'accuracy', 'FLOAT NULL'],
            ['rider_locations', 'speed', 'FLOAT NULL'],
            ['rider_locations', 'heading', 'FLOAT NULL'],
            ['rider_users', 'age', 'INT'],
            ['rider_users', 'address', 'TEXT'],
            ['rider_users', 'contact_number', 'VARCHAR(20)'],
            ['admin_profiles', 'avatar_path', 'VARCHAR(255) NULL'],
            ['admin_users', 'profile_image', 'VARCHAR(255) NULL']
        ];

        // AES-GCM values are longer than their plaintext. Lookup columns contain
        // keyed HMACs so randomized ciphertext never needs to be compared.
        $securityColumns = [
            ['users', 'username_lookup', 'CHAR(64) NULL'],
            ['users', 'contact_lookup', 'CHAR(64) NULL'],
            ['rider_users', 'username_lookup', 'CHAR(64) NULL'],
            ['rider_users', 'contact_lookup', 'CHAR(64) NULL'],
            ['admin_users', 'username_lookup', 'CHAR(64) NULL']
            ,['admin_users', 'source_user_id', 'VARCHAR(50) NULL']
            ,['admin_users', 'login_enabled', 'TINYINT(1) NOT NULL DEFAULT 1']
            ,['rider_users', 'source_user_id', 'VARCHAR(50) NULL']
            ,['rider_users', 'login_enabled', 'TINYINT(1) NOT NULL DEFAULT 1']
        ];
        foreach ($securityColumns as $col) {
            [$table, $column, $definition] = $col;
            $this->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}");
        }
        foreach (['users' => ['username','full_name','email','contact_number','address'], 'rider_users' => ['username','full_name','address','contact_number'], 'admin_users' => ['username','full_name'], 'admin_profiles' => ['first_name','last_name','email','phone']] as $table => $columns) {
            foreach ($columns as $column) {
                // VARCHAR keeps existing indexes valid; 512 bytes accommodates the
                // nonce, tag and base64 expansion of typical personal data.
                $this->query("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR(512) NULL");
            }
        }
        $this->query("ALTER TABLE admin_settings MODIFY COLUMN settings LONGTEXT NOT NULL");

        foreach ($schemaColumns as $col) {
            [$table, $column, $definition] = $col;
            $this->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}");
        }

        $this->ensureSeedUsers();
    }

    private function ensureSeedUsers() {
        // Repair legacy seed rows that predate encrypted usernames/lookups. This
        // runs once for those rows; established accounts keep their passwords.
        $adminLookup = sensitive_lookup('admin');
        $adminUsername = $this->real_escape_string(encrypt_sensitive('admin'));
        $adminName = $this->real_escape_string(encrypt_sensitive('Admin User'));
        $adminCheck = $this->query("SELECT username_lookup FROM admin_users WHERE admin_id = 'ADM-001' AND role = 'admin' LIMIT 1");
        if (!$adminCheck || $adminCheck->num_rows === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $this->query("INSERT INTO admin_users (admin_id, username, username_lookup, password, full_name, role) VALUES ('ADM-001', '$adminUsername', '$adminLookup', '$hash', '$adminName', 'admin')");
        } else {
            $admin = $adminCheck->fetch_assoc();
            if (empty($admin['username_lookup'])) {
                $hash = password_hash('admin123', PASSWORD_DEFAULT);
                $this->query("UPDATE admin_users SET username = '$adminUsername', username_lookup = '$adminLookup', password = '$hash', full_name = '$adminName', role = 'admin' WHERE admin_id = 'ADM-001'");
            }
        }

        $staffLookup = sensitive_lookup('staff1');
        $staffUsername = $this->real_escape_string(encrypt_sensitive('staff1'));
        $staffName = $this->real_escape_string(encrypt_sensitive('Sarah Staff'));
        $staffCheck = $this->query("SELECT username_lookup FROM admin_users WHERE admin_id = 'STF-001' AND role = 'staff' LIMIT 1");
        if (!$staffCheck || $staffCheck->num_rows === 0) {
            $staffHash = password_hash('staff123', PASSWORD_DEFAULT);
            $this->query("INSERT INTO admin_users (admin_id, username, username_lookup, password, full_name, role) VALUES ('STF-001', '$staffUsername', '$staffLookup', '$staffHash', '$staffName', 'staff')");
        } else {
            $staff = $staffCheck->fetch_assoc();
            if (empty($staff['username_lookup'])) {
                $staffHash = password_hash('staff123', PASSWORD_DEFAULT);
                $this->query("UPDATE admin_users SET username = '$staffUsername', username_lookup = '$staffLookup', password = '$staffHash', full_name = '$staffName', role = 'staff' WHERE admin_id = 'STF-001'");
            }
        }

        $riderHash = password_hash('rider123', PASSWORD_DEFAULT);
        $riderLookup = sensitive_lookup('rider1');
        $riderUsername = $this->real_escape_string(encrypt_sensitive('rider1'));
        $riderName = $this->real_escape_string(encrypt_sensitive('Default Rider'));
        $riderContact = $this->real_escape_string(encrypt_sensitive('09990000001'));
        $riderContactLookup = sensitive_lookup('09990000001');
        $riderCheck = $this->query("SELECT username FROM rider_users WHERE username_lookup = '$riderLookup' LIMIT 1");
        if (!$riderCheck || $riderCheck->num_rows === 0) {
            $this->query("INSERT INTO rider_users (rider_id, username, username_lookup, password, full_name, contact_number, contact_lookup, status) VALUES ('RID-001', '$riderUsername', '$riderLookup', '$riderHash', '$riderName', '$riderContact', '$riderContactLookup', 'active')");
        }
    }
}

if (!extension_loaded('mysqli')) {
    die('XAMPP/MySQL requires the mysqli PHP extension. Enable it in C:/xampp/php/php.ini and restart Apache.');
}

$conn = new DBCompatConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    die('MySQL connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/loyalty_service.php';
enforce_annual_loyalty_reset($conn);

require_once __DIR__ . '/activity_logger.php';
auto_log_system_request($conn);
?>
