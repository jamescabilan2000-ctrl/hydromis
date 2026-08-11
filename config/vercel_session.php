<?php

/**
 * Durable PHP sessions for Vercel's stateless serverless runtime.
 * Local XAMPP requests continue to use PHP's normal file session handler.
 */
final class SupabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private bool $transactionOpen = false;

    public function __construct(array $config)
    {
        $host = (string)($config['host'] ?? '');
        $port = (string)($config['port'] ?? '6543');
        $database = (string)($config['database'] ?? 'postgres');
        $sslmode = (string)($config['sslmode'] ?? 'require');
        $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";

        $this->pdo = new PDO($dsn, (string)($config['user'] ?? ''), (string)($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Supabase's transaction pooler (port 6543) does not retain
            // server-side prepared statements between pooled transactions.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS hydromis_sessions (
            session_id VARCHAR(128) PRIMARY KEY,
            payload TEXT NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_hydromis_sessions_expiry ON hydromis_sessions(expires_at)');
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->transactionOpen) {
            $this->pdo->commit();
            $this->transactionOpen = false;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        $this->pdo->beginTransaction();
        $this->transactionOpen = true;
        $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $lock->execute([$id]);

        $statement = $this->pdo->prepare('SELECT payload FROM hydromis_sessions WHERE session_id = ? AND expires_at > CURRENT_TIMESTAMP');
        $statement->execute([$id]);
        $payload = $statement->fetchColumn();
        if (!is_string($payload)) {
            return '';
        }
        $decoded = base64_decode($payload, true);
        return $decoded === false ? '' : $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $lifetime = max(1800, (int)ini_get('session.gc_maxlifetime'));
        $statement = $this->pdo->prepare("INSERT INTO hydromis_sessions (session_id, payload, expires_at, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP + (CAST(? AS INTEGER) * INTERVAL '1 second'), CURRENT_TIMESTAMP)
            ON CONFLICT (session_id) DO UPDATE SET
                payload = EXCLUDED.payload,
                expires_at = EXCLUDED.expires_at,
                updated_at = CURRENT_TIMESTAMP");
        return $statement->execute([$id, base64_encode($data), $lifetime]);
    }

    public function destroy(string $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM hydromis_sessions WHERE session_id = ?');
        return $statement->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->pdo->prepare('DELETE FROM hydromis_sessions WHERE expires_at <= CURRENT_TIMESTAMP');
        $statement->execute();
        return $statement->rowCount();
    }
}

function configure_vercel_sessions(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || getenv('VERCEL') === false) {
        return;
    }

    $config = require __DIR__ . '/supabase.php';
    $localConfigPath = __DIR__ . '/supabase.local.php';
    if (is_file($localConfigPath)) {
        $localConfig = require $localConfigPath;
        if (is_array($localConfig)) {
            $config = array_replace($config, $localConfig);
        }
    }
    $handler = new SupabaseSessionHandler($config);
    session_set_save_handler($handler, true);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '7200');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
