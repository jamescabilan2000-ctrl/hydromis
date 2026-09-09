<?php

// On Hostinger, copy this file to config/database.local.php and replace every
// placeholder with your existing MySQL database credentials from hPanel.
// Keep an existing database.local.php when uploading application updates.
// For GitHub Actions deployment, use this content as MYSQL_LOCAL_PHP instead.
return [
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'HOSTING_ACCOUNT_hydromis',
    'user' => 'HOSTING_ACCOUNT_hydromis',
    'password' => 'YOUR_DATABASE_PASSWORD',
];
