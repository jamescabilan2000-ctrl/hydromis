# HydroMIS data security

HydroMIS protects account and profile personal data with authenticated
AES-256-GCM encryption. Each value uses a new random nonce and includes an
authentication tag. Application reads are decrypted by the database result
wrapper.

Passwords are deliberately **not encrypted**. They remain one-way hashes made
with PHP `password_hash()` and checked with `password_verify()`.

Usernames and contact numbers have keyed HMAC lookup columns. This permits exact
login/duplicate/search checks without deterministic encryption. Numeric database
primary keys and the generated `USR-*`, `RID-*`, transaction, and payment IDs are
kept as pseudonymous relational identifiers because encrypting foreign keys would
break referential integrity and joins.

## Key management

Production must provide `HYDROMIS_ENCRYPTION_KEY` containing a base64-encoded
32-byte key. Generate it outside the web root, for example:

```powershell
C:\xampp\php\php.exe -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

For local XAMPP development only, the application creates
`config/.encryption_key`; `config/.htaccess` blocks HTTP access. Back up the key
separately. Losing it makes encrypted data unrecoverable. Never commit or rotate
it without a controlled re-encryption migration.

## Existing data

With MySQL running, migrate existing plaintext rows once:

```powershell
C:\xampp\php\php.exe database\migrate_aes256.php
```

Back up the database and encryption key before running this in production.
