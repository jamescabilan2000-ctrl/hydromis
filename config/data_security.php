<?php
/**
 * HydroMIS field-level data protection.
 *
 * Values are encrypted with AES-256-GCM (random 96-bit nonce and authentication
 * tag). Passwords must never use this class; keep using password_hash().
 */
final class DataSecurity
{
    private const PREFIX = 'enc:v1:';
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $configured = getenv('HYDROMIS_ENCRYPTION_KEY') ?: '';
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('HYDROMIS_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
            }
            return self::$key = $decoded;
        }

        // Local XAMPP fallback. Production deployments should inject the env var.
        $keyFile = __DIR__ . '/.encryption_key';
        if (!is_file($keyFile)) {
            $key = random_bytes(32);
            if (file_put_contents($keyFile, base64_encode($key), LOCK_EX) === false) {
                throw new RuntimeException('Unable to create the HydroMIS encryption key.');
            }
        }
        $decoded = base64_decode(trim((string) file_get_contents($keyFile)), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('The HydroMIS encryption key is invalid.');
        }
        return self::$key = $decoded;
    }

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '' || self::isEncrypted($plaintext)) {
            return $plaintext;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt sensitive data.');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || !self::isEncrypted($value)) {
            return $value; // supports safe, gradual migration of existing rows
        }
        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Encrypted data is malformed.');
        }
        $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('Encrypted data authentication failed.');
        }
        return $plaintext;
    }

    public static function lookup(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        return hash_hmac('sha256', mb_strtolower(trim($value), 'UTF-8'), self::key());
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}

function encrypt_sensitive(?string $value): ?string { return DataSecurity::encrypt($value); }
function decrypt_sensitive(?string $value): ?string { return DataSecurity::decrypt($value); }
function sensitive_lookup(?string $value): ?string { return DataSecurity::lookup($value); }

