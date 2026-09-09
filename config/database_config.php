<?php

// Hostinger MySQL configuration.
function hydromis_database_config(?string $directory = null): array {
    $directory = $directory ?? __DIR__;
    $config = [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
    ];
    $files = ['database.local.php'];
    foreach ($files as $file) {
        $path = $directory . '/' . $file;
        if (is_file($path)) {
            $local = require $path;
            if (is_array($local)) $config = array_replace($config, $local);
        }
    }
    $config['driver'] = 'mysql';
    return $config;
}
