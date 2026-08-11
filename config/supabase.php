<?php
// Safe, tracked defaults. Put real credentials in supabase.local.php, which is
// excluded from Git, or provide the corresponding SUPABASE_DB_* variables.
return [
    'driver' => 'pgsql',
    'host' => getenv('SUPABASE_DB_HOST') ?: '',
    'port' => getenv('SUPABASE_DB_PORT') ?: '6543',
    'database' => getenv('SUPABASE_DB_NAME') ?: 'postgres',
    'user' => getenv('SUPABASE_DB_USER') ?: '',
    'password' => getenv('SUPABASE_DB_PASSWORD') ?: '',
    'sslmode' => getenv('SUPABASE_DB_SSLMODE') ?: 'require',
];
