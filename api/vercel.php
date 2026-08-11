<?php
// Vercel runs the legacy PHP application through one serverless entry point.
require_once dirname(__DIR__) . '/config/vercel_session.php';
configure_vercel_sessions();

$requestedPath = trim((string)($_GET['path'] ?? ''), '/');
if ($requestedPath === '') {
    $requestedPath = 'index.php';
}

$requestedPath = str_replace('\\', '/', $requestedPath);
if (str_contains($requestedPath, '..') || !preg_match('/^[A-Za-z0-9_\/.\-]+$/', $requestedPath)) {
    http_response_code(400);
    exit('Invalid request path.');
}

if (!str_ends_with(strtolower($requestedPath), '.php')) {
    $requestedPath = rtrim($requestedPath, '/') . '/index.php';
}

$publicRootPages = [
    'index.php', 'home.php', 'login.php', 'logout.php', 'onboarding.php',
    'privacy.php', 'terms.php', 'create_account.php', 'admin_portal.php',
];
$isRootPage = in_array($requestedPath, $publicRootPages, true);
$isPortalPage = preg_match('#^(admin|staff|rider|user)/[A-Za-z0-9_-]+\.php$#', $requestedPath) === 1;
$isPublicApi = $requestedPath === 'api/delivery_tracker.php';
if (!$isRootPage && !$isPortalPage && !$isPublicApi) {
    http_response_code(404);
    exit('Page not found.');
}

$projectRoot = dirname(__DIR__);
$target = realpath($projectRoot . DIRECTORY_SEPARATOR . $requestedPath);
if ($target === false || !str_starts_with($target, $projectRoot . DIRECTORY_SEPARATOR) || !is_file($target)) {
    http_response_code(404);
    exit('Page not found.');
}

$_SERVER['SCRIPT_FILENAME'] = $target;
$_SERVER['SCRIPT_NAME'] = '/' . $requestedPath;
$_SERVER['PHP_SELF'] = '/' . $requestedPath;
chdir(dirname($target));
require $target;
