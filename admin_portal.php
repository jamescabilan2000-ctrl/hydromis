<?php
$portalConfigPath = __DIR__ . '/config/portal.php';
$adminUrl = 'https://hydro-mis-1-dliypihz5-jamescabilans-projects.vercel.app';

if (file_exists($portalConfigPath)) {
    $loaded = require $portalConfigPath;
    if (is_array($loaded) && !empty($loaded['admin_url'])) {
        $adminUrl = $loaded['admin_url'];
    }
}

header('Location: ' . $adminUrl);
exit();
