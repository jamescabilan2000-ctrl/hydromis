<?php
// Supabase PostgreSQL configuration.
// Credentials can come from:
// 1) config/supabase.php (recommended for local dev)
// 2) SUPABASE_DB_* environment variables
$supabaseConfig = [];
$supabaseConfigPath = __DIR__ . '/supabase.php';
if (file_exists($supabaseConfigPath)) {
    $loadedConfig = require $supabaseConfigPath;
    if (is_array($loadedConfig)) {
        $supabaseConfig = $loadedConfig;
    }
}

define('DB_HOST', $supabaseConfig['host'] ?? getenv('SUPABASE_DB_HOST') ?: 'db.YOUR_PROJECT_REF.supabase.co');
define('DB_PORT', $supabaseConfig['port'] ?? getenv('SUPABASE_DB_PORT') ?: '5432');
define('DB_USER', $supabaseConfig['user'] ?? getenv('SUPABASE_DB_USER') ?: 'postgres');
define('DB_PASS', $supabaseConfig['password'] ?? getenv('SUPABASE_DB_PASS') ?: 'YOUR_SUPABASE_DB_PASSWORD');
define('DB_NAME', $supabaseConfig['database'] ?? getenv('SUPABASE_DB_NAME') ?: 'postgres');
define('DB_SSLMODE', $supabaseConfig['sslmode'] ?? getenv('SUPABASE_DB_SSLMODE') ?: 'require');

/**
 * Lightweight mysqli-compatible result wrapper for PDO rows.
 */
class DBCompatResult {
    private $rows = [];
    private $pointer = 0;
    public $num_rows = 0;

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc() {
        if ($this->pointer >= $this->num_rows) {
            return null;
        }

        $row = $this->rows[$this->pointer];
        $this->pointer++;
        return $row;
    }
}

/**
 * mysqli-like wrapper so existing pages can run on Supabase PostgreSQL.
 */
class DBCompatConnection {
    public $connect_error = '';
    public $error = '';
    public $insert_id = 0;

    private $pdo = null;

    public function __construct($host, $user, $pass, $db) {
        try {
            $dsn = 'pgsql:host=' . $host . ';port=' . DB_PORT . ';dbname=' . $db . ';sslmode=' . DB_SSLMODE;
            $this->pdo = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function set_charset($charset) {
        return true;
    }

    public function real_escape_string($input) {
        if (!$this->pdo) {
            return addslashes((string) $input);
        }

        $quoted = $this->pdo->quote((string) $input);
        return trim($quoted, "'");
    }

    public function query($sql) {
        if (!$this->pdo) {
            $this->error = 'Database connection is not available.';
            return false;
        }

        $this->error = '';
        $sql = $this->translateSql(trim($sql));

        try {
            $stmt = $this->pdo->query($sql);

            if ($this->isResultSetQuery($sql)) {
                return new DBCompatResult($stmt->fetchAll());
            }

            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function close() {
        $this->pdo = null;
        return true;
    }

    private function isResultSetQuery($sql) {
        return (bool) preg_match('/^(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE)\b/i', $sql);
    }

    private function translateSql($sql) {
        // MySQL compatibility: SHOW TABLES LIKE 'table_name'
        if (preg_match("/^SHOW\\s+TABLES\\s+LIKE\\s+'([^']+)'/i", $sql, $matches)) {
            $tableName = str_replace("'", "''", $matches[1]);
            return "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '" . $tableName . "'";
        }

        return $sql;
    }
}

if (!extension_loaded('pdo_pgsql') || !extension_loaded('pgsql')) {
    die('Supabase requires PHP extensions pdo_pgsql and pgsql. Enable them in C:/xampp/php/php.ini and restart Apache.');
}

if (strpos(DB_HOST, 'YOUR_PROJECT_REF') !== false || strpos(DB_PASS, 'YOUR_SUPABASE_DB_PASSWORD') !== false) {
    die('Supabase is enabled. Update config/supabase.php with your real Supabase DB host and password.');
}

$conn = new DBCompatConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die('Supabase connection failed: ' . $conn->connect_error);
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
