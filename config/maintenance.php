<?php

function hydromis_maintenance_enabled($conn): bool {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='maintenance_mode' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return ($row['setting_value'] ?? '0') === '1';
}

function hydromis_enforce_maintenance($conn): void {
    if (PHP_SAPI === 'cli' || !hydromis_maintenance_enabled($conn)) return;

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    // Administrators must always retain a way to sign in and restore service.
    if (str_contains($script, '/admin/') || str_ends_with($script, '/login.php') || str_ends_with($script, '/logout.php')) return;

    http_response_code(503);
    header('Retry-After: 900');
    if (str_contains($script, '/api/')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'maintenance' => true, 'message' => 'HydroMIS is temporarily unavailable for maintenance.']);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HydroMIS Maintenance</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:linear-gradient(145deg,#071827,#0b3340);font-family:Segoe UI,Arial,sans-serif;color:#eafcff}.card{width:min(560px,100%);padding:42px;border:1px solid rgba(94,234,212,.25);border-radius:24px;background:rgba(6,25,38,.84);box-shadow:0 24px 80px rgba(0,0,0,.35);text-align:center}.icon{font-size:48px}.eyebrow{margin-top:18px;color:#5eead4;font-weight:700;letter-spacing:.12em;text-transform:uppercase;font-size:12px}h1{margin:10px 0 12px;font-size:clamp(28px,6vw,42px)}p{margin:0;color:#b8ced8;line-height:1.7}.note{margin-top:22px;padding-top:20px;border-top:1px solid rgba(255,255,255,.1);font-size:13px}</style></head><body><main class="card"><div class="icon">&#128295;</div><div class="eyebrow">Scheduled maintenance</div><h1>We will be back shortly</h1><p>HydroMIS is temporarily paused while the water station performs system maintenance. Please try again later.</p><p class="note">No new orders or account changes can be submitted during this time.</p></main></body></html>';
    exit;
}

