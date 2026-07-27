<?php
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

define('DB_HOST', $databaseConfig['host'] ?? getenv('MYSQL_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', $databaseConfig['port'] ?? getenv('MYSQL_DB_PORT') ?: '3306');
define('DB_USER', $databaseConfig['user'] ?? getenv('MYSQL_DB_USER') ?: 'root');
define('DB_PASS', $databaseConfig['password'] ?? getenv('MYSQL_DB_PASSWORD') ?: '');
define('DB_NAME', $databaseConfig['database'] ?? getenv('MYSQL_DB_NAME') ?: 'hydromis');

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

        return $this->result->fetch_assoc();
    }
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
        $this->mysqli = new mysqli($host, $user, $pass, $db, $port);

        if ($this->mysqli->connect_errno) {
            $this->connect_error = $this->mysqli->connect_error;
            return;
        }

        $this->set_charset('utf8');
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

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
            transaction_id VARCHAR(50) NOT NULL UNIQUE,
            rider_latitude DECIMAL(10,8) NOT NULL DEFAULT 12.8797,
            rider_longitude DECIMAL(11,8) NOT NULL DEFAULT 121.7740,
            last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

        $schemaColumns = [
            ['transactions', 'payment_method', 'VARCHAR(20) DEFAULT "cash"'],
            ['transactions', 'payment_reference', 'VARCHAR(255)'],
            ['transactions', 'payment_status', 'VARCHAR(20) DEFAULT "pending"'],
            ['transactions', 'payment_date', 'TIMESTAMP NULL'],
            ['transactions', 'payment_proof', 'VARCHAR(255)'],
            ['transactions', 'delivery_status', 'VARCHAR(30) DEFAULT "pending"'],
            ['transactions', 'assigned_rider', 'VARCHAR(255) NULL'],
            ['transactions', 'rider_id', 'VARCHAR(50) NULL'],
            ['transactions', 'approved_by', 'VARCHAR(50) NULL'],
            ['rider_users', 'age', 'INT'],
            ['rider_users', 'address', 'TEXT'],
            ['rider_users', 'contact_number', 'VARCHAR(20)']
        ];

        foreach ($schemaColumns as $col) {
            [$table, $column, $definition] = $col;
            $this->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}");
        }

        $this->ensureSeedUsers();
    }

    private function ensureSeedUsers() {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $check = $this->query("SELECT username FROM admin_users WHERE username = 'admin' LIMIT 1");
        if (!$check || $check->num_rows === 0) {
            $this->query("INSERT INTO admin_users (admin_id, username, password, full_name, role) VALUES ('ADM-001', 'admin', '$hash', 'Admin User', 'admin')");
        }

        $staffHash = password_hash('admin123', PASSWORD_DEFAULT);
        $staffCheck = $this->query("SELECT username FROM admin_users WHERE username = 'staff1' LIMIT 1");
        if (!$staffCheck || $staffCheck->num_rows === 0) {
            $this->query("INSERT INTO admin_users (admin_id, username, password, full_name, role) VALUES ('ADM-002', 'staff1', '$staffHash', 'Sarah Staff', 'staff')");
        }

        $riderHash = password_hash('rider123', PASSWORD_DEFAULT);
        $riderCheck = $this->query("SELECT username FROM rider_users WHERE username = 'rider1' LIMIT 1");
        if (!$riderCheck || $riderCheck->num_rows === 0) {
            $this->query("INSERT INTO rider_users (rider_id, username, password, full_name, contact_number, status) VALUES ('RID-001', 'rider1', '$riderHash', 'Default Rider', '09990000001', 'active')");
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

$conn->set_charset('utf8');

function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(htmlspecialchars($input));
}

function generateID($prefix) {
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
}
?>
