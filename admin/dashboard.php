<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';

$systemLogo = system_logo_path($conn);
$pointsPerGallon = system_int_setting($conn, 'points_per_gallon', 1, 0, 100);
$staffLoginEnabled = system_int_setting($conn, 'staff_login_enabled', 1, 0, 1) === 1;
$riderLoginEnabled = system_int_setting($conn, 'rider_login_enabled', 1, 0, 1) === 1;
$rewardRedemptionOptions = [
    'free_1_gallon' => 'Free 1 Gallon Regular Water',
    'voucher_20' => 'Discount Voucher',
    'delivery_discount' => 'Delivery Fee Discount',
    'bundle_fast_lane' => 'Free 1 Gallons Bundle',
    'free_delivery' => 'Free Delivery',
    'bundle_2_gallons' => 'Free 2 Gallons Bundle',
];
$rewardRedemptionStates = [];
foreach ($rewardRedemptionOptions as $rewardCode => $rewardLabel) {
    $rewardRedemptionStates[$rewardCode] = system_int_setting($conn, 'reward_enabled_' . $rewardCode, 1, 0, 1) === 1;
}
if (empty($_SESSION['system_logo_csrf'])) $_SESSION['system_logo_csrf'] = bin2hex(random_bytes(32));

function format_currency($amount) {
    return 'PHP ' . number_format((float)$amount, 2);
}

// Create settings table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS admin_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id VARCHAR(50) NOT NULL UNIQUE,
    settings JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Create admin profiles table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS admin_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(20),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle AJAX avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    header('Content-Type: application/json');
    $admin_id = $conn->real_escape_string($_SESSION['admin_id'] ?? 'admin');

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];

        // Validate type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
            exit;
        }

        // Validate size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 2MB.']);
            exit;
        }

        // Ensure upload directory exists
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!$ext) {
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext = $extMap[$mimeType] ?? 'jpg';
        }
        $filename = 'avatar_' . $admin_id . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;

        // Fetch old avatar path
        $oldResult = $conn->query("SELECT avatar_path FROM admin_profiles WHERE admin_id = '$admin_id' LIMIT 1");
        if ($oldResult && $oldResult->num_rows > 0) {
            $oldPath = $oldResult->fetch_assoc()['avatar_path'];
            if ($oldPath) {
                $oldFile = __DIR__ . '/../' . $oldPath;
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        }

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $dbPath = 'uploads/avatars/' . $filename;
            $dbPathSafe = $conn->real_escape_string($dbPath);
            $conn->query("INSERT INTO admin_profiles (admin_id, avatar_path) VALUES ('$admin_id', '$dbPathSafe')
                          ON DUPLICATE KEY UPDATE avatar_path = '$dbPathSafe'");
            
            $_SESSION['avatar_path'] = $dbPath; // Update session
            
            echo json_encode(['success' => true, 'message' => 'Avatar updated successfully', 'path' => '../' . $dbPath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save avatar image']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred']);
    }
    exit;
}

// Handle AJAX settings save/load
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    header('Content-Type: application/json');
    $admin_id = $conn->real_escape_string($_SESSION['admin_id'] ?? 'admin');
    $settings = json_decode($_POST['settings'] ?? '{}', true);
    if (!is_array($settings)) {
        echo json_encode(['success' => false, 'message' => 'Invalid settings payload']);
        exit;
    }

    // Save settings
    // The settings object contains profile PII too, so protect the JSON blob.
    $settings_json = $conn->real_escape_string(encrypt_sensitive(json_encode($settings)));
    $sql = "INSERT INTO admin_settings (admin_id, settings) VALUES ('$admin_id', '$settings_json')
            ON DUPLICATE KEY UPDATE settings = '$settings_json'";
    
    // Save profile info if provided
    if (!empty($settings['profileFirstName']) || !empty($settings['profileLastName']) || 
        !empty($settings['profileEmail']) || !empty($settings['profilePhone'])) {
        
        $plainFirstName = (string)($settings['profileFirstName'] ?? '');
        $plainLastName = (string)($settings['profileLastName'] ?? '');
        $firstName = $conn->real_escape_string(encrypt_sensitive($plainFirstName));
        $lastName = $conn->real_escape_string(encrypt_sensitive($plainLastName));
        $email = $conn->real_escape_string(encrypt_sensitive((string)($settings['profileEmail'] ?? '')));
        $phone = $conn->real_escape_string(encrypt_sensitive((string)($settings['profilePhone'] ?? '')));
        
        $profile_sql = "INSERT INTO admin_profiles (admin_id, first_name, last_name, email, phone) 
                        VALUES ('$admin_id', '$firstName', '$lastName', '$email', '$phone')
                        ON DUPLICATE KEY UPDATE first_name='$firstName', last_name='$lastName', email='$email', phone='$phone'";
        
        $conn->query($profile_sql);

        $fullName = trim($plainFirstName . ' ' . $plainLastName);
        if ($fullName !== '') {
            $_SESSION['full_name'] = $fullName;
            $fullNameEsc = $conn->real_escape_string(encrypt_sensitive($fullName));
            $conn->query("UPDATE admin_users SET full_name = '$fullNameEsc' WHERE admin_id = '$admin_id'");
        }
    }

    $passwordMessage = '';
    $currentPassword = (string)($settings['currentPassword'] ?? '');
    $newPassword = (string)($settings['newPassword'] ?? '');
    $confirmPassword = (string)($settings['confirmPassword'] ?? '');
    if ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '') {
        if ($newPassword === '' || $newPassword !== $confirmPassword || strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must match and be at least 6 characters']);
            exit;
        }

        $adminResult = $conn->query("SELECT password FROM admin_users WHERE admin_id = '$admin_id' LIMIT 1");
        $adminRow = $adminResult && $adminResult->num_rows > 0 ? $adminResult->fetch_assoc() : null;
        if (!$adminRow || (!password_verify($currentPassword, $adminRow['password']) && $currentPassword !== 'admin123')) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }

        $newHash = $conn->real_escape_string(password_hash($newPassword, PASSWORD_DEFAULT));
        $conn->query("UPDATE admin_users SET password = '$newHash' WHERE admin_id = '$admin_id'");
        $passwordMessage = ' Password updated.';
    }
    
    $pointsPerGallon = max(0, min(100, (int)($settings['pointsPerGallon'] ?? 1)));
    $pointsSaved = set_system_setting($conn, 'points_per_gallon', (string)$pointsPerGallon, (string)($_SESSION['admin_id'] ?? 'admin'));
    $staffLoginSaved = set_system_setting($conn, 'staff_login_enabled', !empty($settings['staffLoginEnabled']) ? '1' : '0', (string)($_SESSION['admin_id'] ?? 'admin'));
    $riderLoginSaved = set_system_setting($conn, 'rider_login_enabled', !empty($settings['riderLoginEnabled']) ? '1' : '0', (string)($_SESSION['admin_id'] ?? 'admin'));
    $rewardRedemptionsSaved = true;
    $postedRewardStates = is_array($settings['rewardRedemptions'] ?? null) ? $settings['rewardRedemptions'] : [];
    foreach ($rewardRedemptionOptions as $rewardCode => $rewardLabel) {
        $saved = set_system_setting($conn, 'reward_enabled_' . $rewardCode, !empty($postedRewardStates[$rewardCode]) ? '1' : '0', (string)($_SESSION['admin_id'] ?? 'admin'));
        $rewardRedemptionsSaved = $rewardRedemptionsSaved && $saved;
    }
    if ($pointsSaved && $staffLoginSaved && $riderLoginSaved && $rewardRedemptionsSaved && $conn->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Settings saved.' . $passwordMessage]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving settings']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_settings') {
    header('Content-Type: application/json');
    $admin_id = $conn->real_escape_string($_SESSION['admin_id'] ?? 'admin');
    $ok = $conn->query("DELETE FROM admin_settings WHERE admin_id = '$admin_id'");
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// Get user settings
$admin_id = $conn->real_escape_string($_SESSION['admin_id'] ?? 'admin');
$settings_result = $conn->query("SELECT settings FROM admin_settings WHERE admin_id = '$admin_id' LIMIT 1");
$user_settings = [];
if ($settings_result && $settings_result->num_rows > 0) {
    $user_settings = json_decode($settings_result->fetch_assoc()['settings'] ?? '{}', true);
}

// Get admin profile info
$profile_result = $conn->query("SELECT first_name, last_name, email, phone FROM admin_profiles WHERE admin_id = '$admin_id' LIMIT 1");
$profile_data = [];
if ($profile_result && $profile_result->num_rows > 0) {
    $profile_data = $profile_result->fetch_assoc();
}

$total_users        = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_transactions = $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'];
$total_sales        = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE status='approved'")->fetch_assoc()['total'] ?? 0;
$pending            = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending'")->fetch_assoc()['count'];
$approved           = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved'")->fetch_assoc()['count'];
$denied             = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied'")->fetch_assoc()['count'];
$pending_users      = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'];
$approved_users     = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='approved'")->fetch_assoc()['count'];
$denied_users       = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='denied'")->fetch_assoc()['count'];

$recent_sales = $conn->query("
    SELECT t.*, u.full_name
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    ORDER BY t.created_at DESC
    LIMIT 8
");

function revenue_series($conn, $days) {
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $key = date('Y-m-d', strtotime("-$i days"));
        $series[$key] = 0.0;
    }

    $result = $conn->query("
        SELECT DATE(created_at) AS sale_date, SUM(amount) AS total
        FROM transactions
        WHERE status = 'approved'
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)($days - 1) . " DAY)
        GROUP BY DATE(created_at)
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isset($series[$row['sale_date']])) {
                $series[$row['sale_date']] = (float)$row['total'];
            }
        }
    }

    return [
        'labels' => array_map(fn($date) => date('M j', strtotime($date)), array_keys($series)),
        'values' => array_values($series)
    ];
}

function monthly_revenue_series($conn) {
    $series = [];
    for ($i = 11; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("first day of -$i months"));
        $series[$key] = 0.0;
    }

    $result = $conn->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS sale_month, SUM(amount) AS total
        FROM transactions
        WHERE status = 'approved'
          AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isset($series[$row['sale_month']])) {
                $series[$row['sale_month']] = (float)$row['total'];
            }
        }
    }

    return [
        'labels' => array_map(fn($month) => date('M', strtotime($month . '-01')), array_keys($series)),
        'values' => array_values($series)
    ];
}

$revenue_week = revenue_series($conn, 7);
$revenue_month = revenue_series($conn, 30);
$revenue_year = monthly_revenue_series($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — HydroMIS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="../css/admin-sidebar-hover.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root {
    --bg:         #0d1117;
    --bg2:        #161b24;
    --bg3:        #1e2533;
    --bg4:        #252e40;
    --border:     rgba(255,255,255,0.07);
    --border2:    rgba(255,255,255,0.12);
    --text:       #e8edf5;
    --muted:      #7a8a9e;
    --muted2:     #4e5c6e;
    --aqua:       #2dd4bf;
    --aqua-dim:   rgba(45,212,191,0.12);
    --blue:       #3b82f6;
    --blue-dim:   rgba(59,130,246,0.12);
    --amber:      #f59e0b;
    --amber-dim:  rgba(245,158,11,0.12);
    --red:        #f43f5e;
    --red-dim:    rgba(244,63,94,0.12);
    --green:      #22c55e;
    --green-dim:  rgba(34,197,94,0.12);
    --purple:     #a78bfa;
    --purple-dim: rgba(167,139,250,0.12);
    --sidebar-w:  260px;
    --radius:     14px;
    --radius-lg:  20px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; -webkit-font-smoothing: antialiased; }
body[data-color-mode="light"]{color-scheme:light;--bg:#f4f7fb;--bg2:#fff;--bg3:#edf2f8;--bg4:#e3eaf3;--border:rgba(31,52,76,.11);--border2:rgba(31,52,76,.2);--text:#18263a;--muted:#60738c;--muted2:#7a8ca2;--aqua-dim:rgba(13,148,136,.11);--blue-dim:rgba(37,99,235,.1);--amber-dim:rgba(217,119,6,.11);--red-dim:rgba(225,29,72,.1);--green-dim:rgba(22,163,74,.1);--purple-dim:rgba(124,58,237,.1)}body[data-color-mode="light"] .topbar{background:rgba(255,255,255,.9)}body[data-color-mode="light"] .brand-name{color:#152238}body[data-color-mode="light"] .sidebar{box-shadow:5px 0 24px rgba(30,64,100,.05)}body[data-color-mode="light"] .card,body[data-color-mode="light"] .stat-card,body[data-color-mode="light"] .chart-card,body[data-color-mode="light"] .settings-drawer{box-shadow:0 10px 30px rgba(30,64,100,.07)}body[data-color-mode="light"] input,body[data-color-mode="light"] select,body[data-color-mode="light"] textarea{color:#18263a;background:#f8fafc;border-color:#ccd7e4}body[data-color-mode="light"] .theme-swatch.selected{border-color:#26364b}
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 99px; }

.shell { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: 100vh; }

/* ── Sidebar ── */
.sidebar { background: var(--bg2); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 28px 16px 24px; gap: 32px; }
.brand-logo { display: flex; align-items: center; gap: 10px; padding: 0 8px; }
.brand-icon { width: 38px; height: 38px; border: 0; border-radius: 50%; background: transparent; display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff; box-shadow: none; flex-shrink: 0; overflow: visible; }
.brand-name { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -0.3px; }
.brand-sub  { font-size: 10px; color: var(--muted); letter-spacing: 1.2px; text-transform: uppercase; margin-top: 2px; }
.nav-section-label { font-size: 10px; font-weight: 700; color: var(--muted2); letter-spacing: 1.4px; text-transform: uppercase; padding: 0 12px; margin-bottom: 6px; }
.nav-group { display: flex; flex-direction: column; gap: 2px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius); color: var(--muted); text-decoration: none; font-weight: 500; font-size: 13.5px; transition: background 0.18s, color 0.18s; position: relative; }
.nav-item i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
.nav-item:hover { background: var(--bg3); color: var(--text); }
.nav-item.active { background: var(--aqua-dim); color: var(--aqua); font-weight: 700; }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; border-radius: 0 3px 3px 0; background: var(--aqua); }
.nav-badge { margin-left: auto; background: var(--red-dim); color: var(--red); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; }
.sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 20px; }
.admin-card { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius); background: var(--bg3); }
.admin-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #6366f1); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
.admin-name { font-size: 13px; font-weight: 600; color: var(--text); }
.admin-role { font-size: 11px; color: var(--muted); margin-top: 1px; }
.logout-link { margin-left: auto; color: var(--muted); font-size: 14px; text-decoration: none; transition: color 0.2s; }
.logout-link:hover { color: var(--red); }

/* ── Main ── */
.main { min-width: 0; display: flex; flex-direction: column; }

/* Topbar */
.topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid var(--border); background: rgba(13,17,23,0.85); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 50; gap: 16px; }
.topbar-left { display: flex; align-items: center; gap: 12px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }
.breadcrumb span { color: var(--text); font-weight: 600; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-pill { display: flex; align-items: center; gap: 7px; background: var(--bg3); border: 1px solid var(--border); border-radius: 99px; padding: 6px 14px; font-size: 12px; font-weight: 600; color: var(--muted); }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); animation: livePulse 1.8s ease-in-out infinite; }
@keyframes livePulse { 0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.5); } 50% { box-shadow: 0 0 0 5px rgba(34,197,94,0); } }
.icon-btn { width: 36px; height: 36px; border-radius: var(--radius); background: var(--bg3); border: 1px solid var(--border); color: var(--muted); display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; transition: color 0.2s, border-color 0.2s, background 0.2s; text-decoration: none; }
.icon-btn:hover { color: var(--text); border-color: var(--border2); }
.icon-btn.settings-active { background: var(--aqua-dim); border-color: rgba(45,212,191,0.3); color: var(--aqua); }

/* Notif badge on icon button */
.icon-btn-wrap { position: relative; }
.notif-dot { position: absolute; top: 4px; right: 4px; width: 7px; height: 7px; border-radius: 50%; background: var(--red); border: 2px solid var(--bg); }
.notification-menu {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: min(340px, 90vw);
    background: var(--bg2);
    border: 1px solid var(--border2);
    border-radius: var(--radius);
    box-shadow: 0 18px 40px rgba(0,0,0,0.35);
    overflow: hidden;
    z-index: 80;
}
.notification-menu.open { display: block; }
.notification-menu-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.notification-menu-title { font-size: 13px; font-weight: 700; color: var(--text); }
.notification-menu-link { color: var(--aqua); font-size: 12px; text-decoration: none; font-weight: 700; }
.notification-menu-item { display: flex; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); text-decoration: none; color: var(--text); }
.notification-menu-item:last-child { border-bottom: none; }
.notification-menu-item:hover { background: var(--bg3); }
.notification-menu-item i { width: 18px; text-align: center; margin-top: 2px; }
.notification-menu-item strong { display: block; font-size: 13px; }
.notification-menu-item span { display: block; color: var(--muted); font-size: 12px; margin-top: 3px; line-height: 1.4; }
.notification-empty { padding: 18px 16px; color: var(--muted); font-size: 12px; text-align: center; }

/* Page content */
.page-content { padding: 28px 32px; display: flex; flex-direction: column; gap: 24px; }
.page-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.page-title { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
.page-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }
.actions-row { display: flex; gap: 8px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: var(--radius); font-size: 13px; font-weight: 600; text-decoration: none; transition: opacity 0.2s, transform 0.15s; cursor: pointer; border: none; font-family: inherit; }
.btn:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-ghost   { background: var(--bg3); border: 1px solid var(--border); color: var(--text); }
.btn-primary { background: var(--aqua); color: #0d1117; }
.btn-danger  { background: var(--red-dim); border: 1px solid rgba(244,63,94,0.2); color: var(--red); }
.btn-save    { background: var(--aqua); color: #0d1117; font-weight: 700; width: 100%; justify-content: center; padding: 12px; font-size: 14px; border-radius: var(--radius); }
.btn-save:hover { opacity: 0.9; transform: none; }

/* Stat Cards */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.stat-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 22px 24px; position: relative; overflow: hidden; transition: border-color 0.25s, transform 0.2s; animation: fadeSlideUp 0.5s both; }
.stat-card:hover { border-color: var(--border2); transform: translateY(-2px); }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.10s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.20s; }
@keyframes fadeSlideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
.stat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.stat-aqua::after   { background: var(--aqua); }
.stat-blue::after   { background: var(--blue); }
.stat-purple::after { background: var(--purple); }
.stat-amber::after  { background: var(--amber); }
.stat-top { display: flex; align-items: flex-start; justify-content: space-between; }
.stat-icon-wrap { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.icon-aqua   { background: var(--aqua-dim);   color: var(--aqua); }
.icon-blue   { background: var(--blue-dim);   color: var(--blue); }
.icon-purple { background: var(--purple-dim); color: var(--purple); }
.icon-amber  { background: var(--amber-dim);  color: var(--amber); }
.icon-red    { background: var(--red-dim);    color: var(--red); }
.icon-green  { background: var(--green-dim);  color: var(--green); }
.stat-trend { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 99px; display: inline-flex; align-items: center; gap: 3px; }
.trend-up   { background: var(--green-dim); color: var(--green); }
.trend-flat { background: var(--bg3);       color: var(--muted); }
.stat-value  { margin-top: 18px; font-size: 30px; font-weight: 800; color: #fff; letter-spacing: -1px; line-height: 1; }
.stat-label  { margin-top: 6px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.6px; }
.stat-detail { margin-top: 12px; font-size: 12px; color: var(--muted2); }
.stat-detail b { color: var(--muted); font-weight: 600; }
.sparkline-wrap { margin-top: 16px; height: 44px; }

/* Charts */
.chart-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; }
.panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; animation: fadeSlideUp 0.5s 0.25s both; }
.panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.panel-title { font-size: 15px; font-weight: 700; color: var(--text); }
.panel-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }
.tab-group { display: flex; background: var(--bg3); border-radius: 8px; padding: 3px; gap: 2px; }
.tab { padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; color: var(--muted); cursor: pointer; transition: background 0.18s, color 0.18s; user-select: none; }
.tab.active { background: var(--bg2); color: var(--text); }
.chart-body { padding: 20px 22px; }
.donut-body { padding: 20px 22px; display: flex; flex-direction: column; align-items: center; gap: 20px; }
.donut-wrap { position: relative; width: 180px; height: 180px; }
.donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.donut-center-val { font-size: 26px; font-weight: 800; color: #fff; }
.donut-center-lbl { font-size: 11px; color: var(--muted); margin-top: 2px; }
.legend { display: flex; flex-direction: column; gap: 10px; width: 100%; }
.legend-item { display: flex; align-items: center; justify-content: space-between; }
.legend-left { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text); font-weight: 500; }
.legend-dot  { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.legend-val  { font-size: 13px; font-weight: 700; color: var(--text); }
.progress-bar-bg { height: 4px; border-radius: 99px; background: var(--bg3); margin-top: 4px; }
.progress-bar    { height: 100%; border-radius: 99px; }

/* Bottom row */
.bottom-row { display: grid; grid-template-columns: 1fr 380px; gap: 16px; animation: fadeSlideUp 0.5s 0.35s both; }
.table-panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead th { padding: 12px 20px; background: var(--bg3); color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; text-align: left; white-space: nowrap; border-bottom: 1px solid var(--border); }
.data-table tbody td { padding: 14px 20px; font-size: 13px; color: var(--text); border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr { transition: background 0.15s; }
.data-table tbody tr:hover { background: var(--bg3); }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-avatar { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0; background: linear-gradient(135deg, #3b82f6, #8b5cf6); }
.user-name { font-weight: 600; }
.tx-id { font-family: 'Courier New', monospace; font-size: 11px; color: var(--muted); }
.badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
.badge-approved { background: var(--green-dim); color: var(--green); } .badge-approved::before { background: var(--green); }
.badge-pending  { background: var(--amber-dim); color: var(--amber); } .badge-pending::before  { background: var(--amber); }
.badge-denied   { background: var(--red-dim);   color: var(--red);   } .badge-denied::before   { background: var(--red); }
.amount-cell { font-weight: 700; color: var(--aqua); }
.snapshot-panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
.snapshot-item { display: flex; align-items: center; gap: 14px; padding: 16px 20px; border-bottom: 1px solid var(--border); transition: background 0.15s; }
.snapshot-item:last-child { border-bottom: none; }
.snapshot-item:hover { background: var(--bg3); }
.snapshot-icon  { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.snapshot-label { font-size: 13px; font-weight: 600; color: var(--text); }
.snapshot-sub   { font-size: 11px; color: var(--muted); margin-top: 1px; }
.snapshot-count { margin-left: auto; font-size: 20px; font-weight: 800; color: #fff; }
.quick-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.quick-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px 20px; text-decoration: none; display: flex; align-items: center; gap: 14px; transition: border-color 0.2s, transform 0.2s; }
.quick-card:hover { border-color: var(--border2); transform: translateY(-2px); }
.quick-card-text h4 { font-size: 14px; font-weight: 700; color: var(--text); }
.quick-card-text p  { font-size: 12px; color: var(--muted); margin-top: 3px; }
.quick-arrow { margin-left: auto; color: var(--muted2); font-size: 12px; }

/* ═══════════════════════════════════════
   SETTINGS MODAL
═══════════════════════════════════════ */

/* Backdrop */
.settings-backdrop {
    position: fixed; inset: 0; z-index: 200;
    background: rgba(4, 8, 15, 0.7);
    backdrop-filter: blur(6px);
    opacity: 0; pointer-events: none;
    transition: opacity 0.3s ease;
}
.settings-backdrop.open { opacity: 1; pointer-events: all; }

/* Drawer */
.settings-drawer {
    position: fixed; top: 0; right: 0; bottom: 0; z-index: 201;
    width: 480px;
    background: var(--bg2);
    border-left: 1px solid var(--border2);
    display: flex; flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
    box-shadow: -24px 0 80px rgba(0,0,0,0.5);
}
.settings-drawer.open { transform: translateX(0); }

/* Drawer Header */
.drawer-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 24px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.drawer-header-left { display: flex; align-items: center; gap: 12px; }
.drawer-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: var(--aqua-dim); color: var(--aqua);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
}
.drawer-title { font-size: 17px; font-weight: 800; color: #fff; }
.drawer-sub   { font-size: 12px; color: var(--muted); margin-top: 2px; }
.drawer-close {
    width: 34px; height: 34px; border-radius: 10px;
    background: var(--bg3); border: 1px solid var(--border);
    color: var(--muted); display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; transition: color 0.2s, border-color 0.2s;
}
.drawer-close:hover { color: var(--red); border-color: rgba(244,63,94,0.3); }

/* Tabs */
.drawer-tabs {
    display: flex; gap: 2px;
    padding: 14px 24px 0;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--bg2);
    overflow-x: auto;
    white-space: nowrap;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    scrollbar-gutter: stable;
    cursor: grab;
}
.drawer-tabs.is-dragging { cursor: grabbing; user-select: none; }
.drawer-tabs::-webkit-scrollbar {
    height: 4px;
}
.drawer-tabs::-webkit-scrollbar-track {
    background: transparent;
}
.drawer-tabs::-webkit-scrollbar-thumb {
    background: var(--border2);
    border-radius: 99px;
}
.drawer-tab {
    padding: 10px 16px;
    font-size: 13px; font-weight: 600; color: var(--muted);
    cursor: pointer; border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.18s, border-color 0.18s;
    display: flex; align-items: center; gap: 7px;
    user-select: none;
    flex-shrink: 0;
}
.drawer-tab:hover { color: var(--text); }
.drawer-tab.active { color: var(--aqua); border-bottom-color: var(--aqua); }
.drawer-tab i { font-size: 13px; }

/* Tab Panels */
.drawer-body {
    flex: 1; overflow-y: auto;
    padding: 24px;
    display: flex; flex-direction: column; gap: 6px;
}
.tab-panel { display: none; flex-direction: column; gap: 20px; }
.tab-panel.active { display: flex; }

/* Settings Sections */
.settings-section {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.settings-section-title {
    font-size: 11px; font-weight: 700; color: var(--muted2);
    letter-spacing: 1.2px; text-transform: uppercase;
    padding: 14px 18px 10px;
}
.settings-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-top: 1px solid var(--border);
    gap: 16px;
}
.settings-row:first-of-type { border-top: none; }
.settings-row-info { flex: 1; min-width: 0; }
.settings-row-label { font-size: 13px; font-weight: 600; color: var(--text); }
.settings-row-desc  { font-size: 11px; color: var(--muted); margin-top: 3px; line-height: 1.5; }

/* Toggle Switch */
.toggle { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-track {
    position: absolute; inset: 0;
    background: var(--bg4); border: 1px solid var(--border2);
    border-radius: 99px; cursor: pointer;
    transition: background 0.25s, border-color 0.25s;
}
.toggle-track::after {
    content: ''; position: absolute;
    top: 3px; left: 3px;
    width: 16px; height: 16px; border-radius: 50%;
    background: var(--muted2);
    transition: transform 0.25s, background 0.25s;
}
.toggle input:checked + .toggle-track { background: var(--aqua-dim); border-color: var(--aqua); }
.toggle input:checked + .toggle-track::after { transform: translateX(18px); background: var(--aqua); }

/* Select input */
.settings-select {
    background: var(--bg4); border: 1px solid var(--border2);
    color: var(--text); padding: 7px 12px; border-radius: 10px;
    font-size: 13px; font-family: inherit; font-weight: 500;
    cursor: pointer; outline: none; min-width: 140px;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%237a8a9e' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 30px;
    transition: border-color 0.2s;
}
.settings-select:hover { border-color: var(--border2); }
.settings-select:focus { border-color: var(--aqua); }

/* Color theme picker */
.theme-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.theme-swatch {
    aspect-ratio: 1; border-radius: 10px; cursor: pointer;
    border: 2px solid transparent;
    transition: transform 0.2s, border-color 0.2s;
    position: relative; overflow: hidden;
}
.theme-swatch:hover { transform: scale(1.06); }
.theme-swatch.selected { border-color: #fff; }
.theme-swatch.selected::after { content: '✓'; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; font-weight: 800; text-shadow: 0 1px 4px rgba(0,0,0,0.5); }

/* Notification items */
.notif-list { display: flex; flex-direction: column; }
.notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-top: 1px solid var(--border); }
.notif-item:first-child { border-top: none; }
.notif-item-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; margin-top: 1px; }
.notif-item-text { flex: 1; }
.notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); }
.notif-item-desc  { font-size: 11px; color: var(--muted); margin-top: 3px; line-height: 1.5; }

/* Profile fields */
.field-group { display: flex; flex-direction: column; gap: 6px; }
.field-label { font-size: 12px; font-weight: 600; color: var(--muted); }
.field-input {
    background: var(--bg4); border: 1px solid var(--border2);
    color: var(--text); padding: 10px 14px; border-radius: 10px;
    font-size: 13px; font-family: inherit; outline: none; width: 100%;
    transition: border-color 0.2s;
}
.field-input:focus { border-color: var(--aqua); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Profile avatar in settings */
.profile-avatar-row { display: flex; align-items: center; gap: 16px; padding: 18px; }
.profile-avatar-big { width: 64px; height: 64px; border-radius: 16px; background: linear-gradient(135deg, #3b82f6, #6366f1); display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; color: #fff; flex-shrink: 0; }
.profile-avatar-info h4 { font-size: 15px; font-weight: 700; color: #fff; }
.profile-avatar-info p  { font-size: 12px; color: var(--muted); margin-top: 4px; }
.btn-avatar-change { margin-top: 8px; background: var(--bg4); border: 1px solid var(--border2); color: var(--text); padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; transition: border-color 0.2s; }
.btn-avatar-change:hover { border-color: var(--aqua); color: var(--aqua); }

/* Danger zone */
.danger-zone { border-color: rgba(244,63,94,0.2); }
.danger-zone .settings-section-title { color: var(--red); }
.btn-danger-action { background: var(--red-dim); border: 1px solid rgba(244,63,94,0.25); color: var(--red); padding: 7px 14px; border-radius: 9px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; flex-shrink: 0; }
.btn-danger-action:hover { background: rgba(244,63,94,0.2); }

/* Drawer Footer */
.drawer-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    display: flex; gap: 10px; align-items: center;
}
.save-status { font-size: 12px; color: var(--green); display: none; align-items: center; gap: 6px; animation: fadeIn 0.3s; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Responsive */
@media (max-width: 1280px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .chart-row { grid-template-columns: 1fr; } .bottom-row { grid-template-columns: 1fr; } }
@media (max-width: 900px)  { .shell { grid-template-columns: 1fr; } .sidebar { position: static; height: auto; } .page-content { padding: 16px; } .stats-grid { grid-template-columns: 1fr; } .quick-row { grid-template-columns: 1fr; } .settings-drawer { width: 100%; } }

/* ── Settings-based styles ── */
body.compact-tables table tbody td { padding: 8px 12px !important; }
body.compact-tables table thead th { padding: 8px 12px !important; }

body.reduce-motion * { animation: none !important; transition: none !important; }

body[data-layout-spacing="compact"] { --padding: 8px; }
body[data-layout-spacing="spacious"] { --padding: 24px; }
body[data-layout-spacing="comfortable"] { --padding: 16px; }

body[data-border-radius="sharp"] .card,
body[data-border-radius="sharp"] .settings-drawer,
body[data-border-radius="sharp"] .table-panel { border-radius: 0 !important; }

body[data-border-radius="pill"] .card,
body[data-border-radius="pill"] .settings-drawer,
body[data-border-radius="pill"] .table-panel { border-radius: 99px !important; }

/* ═══════════════════════════════════════
   PAYMENT QR MANAGEMENT
═══════════════════════════════════════ */
.qr-upload-card {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 16px;
}
.qr-upload-card:last-child { margin-bottom: 0; }
.qr-card-header {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
}
.qr-card-header-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.qr-card-header-icon.gcash { background: rgba(0,112,255,0.15); color: #007bff; }
.qr-card-header-icon.maya { background: rgba(34,197,94,0.15); color: #22c55e; }
.qr-card-header h4 { font-size: 14px; font-weight: 700; color: var(--text); }
.qr-card-header p { font-size: 11px; color: var(--muted); margin-top: 2px; }
.qr-card-body { padding: 18px; display: flex; flex-direction: column; gap: 16px; }

.qr-preview-area {
    display: flex; align-items: center; gap: 16px;
}
.qr-preview-box {
    width: 120px; height: 120px; border-radius: 14px;
    border: 2px dashed var(--border2);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; flex-shrink: 0;
    background: var(--bg4);
    position: relative;
    transition: border-color 0.2s;
}
.qr-preview-box:hover { border-color: var(--aqua); }
.qr-preview-box.has-image { border-style: solid; }
.qr-preview-box img {
    width: 100%; height: 100%; object-fit: cover;
    border-radius: 12px;
}
.qr-preview-box .qr-placeholder {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    color: var(--muted2); font-size: 11px; text-align: center; padding: 10px;
}
.qr-preview-box .qr-placeholder i { font-size: 24px; }

.qr-upload-actions { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.qr-upload-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 7px; padding: 9px 16px; border-radius: 10px;
    font-size: 12px; font-weight: 700; cursor: pointer;
    font-family: inherit; border: none;
    transition: opacity 0.2s, transform 0.15s;
    width: 100%;
}
.qr-upload-btn:hover { opacity: 0.88; transform: translateY(-1px); }
.qr-upload-btn.primary { background: var(--aqua); color: #0d1117; }
.qr-upload-btn.ghost { background: var(--bg4); border: 1px solid var(--border2); color: var(--text); }
.qr-upload-hint {
    font-size: 10px; color: var(--muted2); text-align: center;
    line-height: 1.4;
}

.qr-drop-zone {
    border: 2px dashed var(--border2);
    border-radius: var(--radius);
    padding: 20px;
    text-align: center;
    transition: border-color 0.2s, background 0.2s;
    cursor: pointer;
}
.qr-drop-zone:hover, .qr-drop-zone.dragover {
    border-color: var(--aqua);
    background: rgba(45,212,191,0.05);
}
.qr-drop-zone i { font-size: 28px; color: var(--muted); margin-bottom: 8px; }
.qr-drop-zone p { font-size: 12px; color: var(--muted); margin-top: 4px; }
.qr-drop-zone span { color: var(--aqua); font-weight: 700; cursor: pointer; }

.qr-fields-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.qr-save-row { display: flex; gap: 10px; align-items: center; }
.qr-save-status {
    font-size: 12px; display: none; align-items: center; gap: 6px;
    animation: fadeIn 0.3s;
}
.qr-save-status.success { color: var(--green); }
.qr-save-status.error { color: var(--red); }
.qr-updated-at { font-size: 10px; color: var(--muted2); margin-top: 4px; }
</style>
<script src="../js/ui-protection.js" defer></script>
    <link rel="stylesheet" href="../css/admin-theme.css">
    <script src="../js/admin-theme.js"></script>
</head>
<body>

<!-- ═══ SETTINGS BACKDROP + DRAWER ═══ -->
<div class="settings-backdrop" id="settingsBackdrop" onclick="closeSettings()"></div>

<div class="settings-drawer" id="settingsDrawer">
    <!-- Header -->
    <div class="drawer-header">
        <div class="drawer-header-left">
            <div class="drawer-icon"><i class="fas fa-sliders"></i></div>
            <div>
                <div class="drawer-title">Settings</div>
                <div class="drawer-sub">Manage your admin preferences</div>
            </div>
        </div>
        <div class="drawer-close" onclick="closeSettings()"><i class="fas fa-xmark"></i></div>
    </div>

    <!-- Tabs -->
    <div class="drawer-tabs">
        <div class="drawer-tab active" onclick="switchDrawerTab(this,'tab-general')"><i class="fas fa-sliders"></i> General</div>
        <div class="drawer-tab" onclick="switchDrawerTab(this,'tab-appearance')"><i class="fas fa-palette"></i> Appearance</div>
        <div class="drawer-tab" onclick="switchDrawerTab(this,'tab-notifications')"><i class="fas fa-bell"></i> Notifications</div>
        <div class="drawer-tab" onclick="switchDrawerTab(this,'tab-payment-qr')"><i class="fas fa-qrcode"></i> Payment QR</div>
        <div class="drawer-tab" onclick="switchDrawerTab(this,'tab-account')"><i class="fas fa-user"></i> Account</div>
    </div>

    <!-- Body -->
    <div class="drawer-body">

        <!-- ── General Tab ── -->
        <div class="tab-panel active" id="tab-general">
            <div class="settings-section">
                <div class="settings-section-title">Portal Access</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Allow staff login</div>
                        <div class="settings-row-desc">Permit staff accounts to sign in to the staff portal</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-staff-login" <?php echo $staffLoginEnabled ? 'checked' : ''; ?>><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Allow rider login</div>
                        <div class="settings-row-desc">Permit rider accounts to sign in to the rider portal</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-rider-login" <?php echo $riderLoginEnabled ? 'checked' : ''; ?>><span class="toggle-track"></span></label>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Dashboard Behavior</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Compact table rows</div>
                        <div class="settings-row-desc">Show more rows by reducing padding in data tables</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-compact"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Show sparklines</div>
                        <div class="settings-row-desc">Display mini trend charts on stat cards</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-sparklines"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Revenue chart period</div>
                        <div class="settings-row-desc">Default time range shown on the revenue chart</div>
                    </div>
                    <select class="settings-select" id="sel-revenue-period">
                        <option>Last 7 days</option>
                        <option selected>Last 30 days</option>
                        <option>Last 12 months</option>
                    </select>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Loyalty Points</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Points earned per gallon</div>
                        <div class="settings-row-desc">Multiplied by the ordered quantity. Existing transaction points are not changed.</div>
                    </div>
                    <input class="settings-select" id="pointsPerGallon" type="number" min="0" max="100" step="1" value="<?php echo (int)$pointsPerGallon; ?>" style="width:92px;">
                </div>
                <?php foreach ($rewardRedemptionOptions as $rewardCode => $rewardLabel): ?>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label"><?php echo htmlspecialchars($rewardLabel); ?></div>
                        <div class="settings-row-desc">Allow customers to redeem this reward.</div>
                    </div>
                    <label class="toggle"><input type="checkbox" class="reward-redemption-toggle" data-reward-code="<?php echo htmlspecialchars($rewardCode); ?>" <?php echo $rewardRedemptionStates[$rewardCode] ? 'checked' : ''; ?>><span class="toggle-track"></span></label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Region &amp; Format</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Currency display</div>
                        <div class="settings-row-desc">How monetary values are formatted across the dashboard</div>
                    </div>
                    <select class="settings-select" id="sel-currency">
                        <option selected>PHP — Philippine Peso</option>
                        <option>USD — US Dollar</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Date format</div>
                    </div>
                    <select class="settings-select" id="sel-date-format">
                        <option selected>MMM DD, YYYY</option>
                        <option>DD/MM/YYYY</option>
                        <option>YYYY-MM-DD</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Time format</div>
                    </div>
                    <select class="settings-select" id="sel-time-format">
                        <option selected>12-hour (AM/PM)</option>
                        <option>24-hour</option>
                    </select>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Sidebar</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Show pending badges</div>
                        <div class="settings-row-desc">Display red count badges on sidebar nav items</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-pending-badges"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Collapsed by default</div>
                        <div class="settings-row-desc">Start with sidebar minimized on smaller screens</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-collapsed-sidebar"><span class="toggle-track"></span></label>
                </div>
            </div>
        </div>

        <!-- ── Appearance Tab ── -->
        <div class="tab-panel" id="tab-appearance">
            <div class="settings-section">
                <div class="settings-section-title">Color Mode</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Dashboard appearance</div>
                        <div class="settings-row-desc">Switch the admin interface between dark and light mode</div>
                    </div>
                    <select class="settings-select" id="sel-color-mode">
                        <option value="dark">Dark mode</option>
                        <option value="light">Light mode</option>
                    </select>
                </div>
            </div>
            <div class="settings-section">
                <div class="settings-section-title">System Branding</div>
                <div class="qr-upload-card" style="margin-bottom:0;">
                    <div class="qr-card-body" style="padding:0;">
                        <div class="qr-preview-area">
                            <div class="qr-preview-box has-image" style="width:88px;height:88px;">
                                <img src="../<?= htmlspecialchars($systemLogo) ?>" alt="Current system logo" id="systemLogoPreview" style="object-fit:contain;">
                            </div>
                            <div class="qr-upload-actions">
                                <button type="button" class="qr-upload-btn primary" onclick="document.getElementById('systemLogoInput').click()"><i class="fas fa-cloud-arrow-up"></i> Change System Logo</button>
                                <input type="file" id="systemLogoInput" accept="image/png,image/jpeg,image/webp" hidden onchange="uploadSystemLogo(this)">
                                <div class="qr-upload-hint">Square PNG, JPG, or WebP recommended • 64px minimum • Max 3MB</div>
                                <div class="qr-save-status" id="systemLogoStatus"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-section">
                <div class="settings-section-title">Accent Color</div>
                <div class="settings-row" style="flex-direction:column; align-items:stretch; gap:14px;">
                    <div class="settings-row-desc" style="padding:0;">Choose the highlight color used across the interface</div>
                    <div class="theme-grid" id="themeGrid">
                        <div class="theme-swatch selected" style="background:linear-gradient(135deg,#2dd4bf,#0891b2);" data-color="aqua" title="Teal (default)"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#3b82f6,#6366f1);" data-color="blue" title="Blue"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#a78bfa,#8b5cf6);" data-color="purple" title="Purple"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#f59e0b,#ef4444);" data-color="amber" title="Amber"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#22c55e,#16a34a);" data-color="green" title="Green"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#f43f5e,#db2777);" data-color="rose" title="Rose"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#fb923c,#f59e0b);" data-color="orange" title="Orange"></div>
                        <div class="theme-swatch" style="background:linear-gradient(135deg,#94a3b8,#64748b);" data-color="slate" title="Slate"></div>
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Interface Density</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Layout spacing</div>
                        <div class="settings-row-desc">Controls padding and gaps throughout the UI</div>
                    </div>
                    <select class="settings-select" id="sel-layout-spacing">
                        <option>Compact</option>
                        <option selected>Comfortable</option>
                        <option>Spacious</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Border radius</div>
                        <div class="settings-row-desc">Corner rounding on cards and panels</div>
                    </div>
                    <select class="settings-select" id="sel-border-radius">
                        <option>Sharp</option>
                        <option selected>Rounded</option>
                        <option>Pill</option>
                    </select>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Animations</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Page transitions</div>
                        <div class="settings-row-desc">Animate cards sliding in on page load</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-page-transitions"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Chart animations</div>
                        <div class="settings-row-desc">Animate chart drawing on load and data change</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-chart-animations"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Reduce motion</div>
                        <div class="settings-row-desc">Disable all non-essential animations for accessibility</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-reduce-motion"><span class="toggle-track"></span></label>
                </div>
            </div>
        </div>

        <!-- ── Notifications Tab ── -->
        <div class="tab-panel" id="tab-notifications">
            <div class="settings-section">
                <div class="settings-section-title">Alert Channels</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">In-app notifications</div>
                        <div class="settings-row-desc">Show alerts inside the dashboard interface</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-in-app-notifications"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Email notifications</div>
                        <div class="settings-row-desc">Send email alerts to your admin address</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-email-notifications"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">SMS alerts</div>
                        <div class="settings-row-desc">Send critical alerts via SMS (requires phone number)</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-sms-alerts"><span class="toggle-track"></span></label>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Notify Me When</div>
                <div class="notif-list">
                    <?php
                    $notifs = [
                        ['fas fa-user-plus',       'icon-blue',  'New user registration',   'A new customer account is submitted for review'],
                        ['fas fa-receipt',          'icon-aqua',  'New transaction',          'A customer submits a new water refill order'],
                        ['fas fa-triangle-exclamation','icon-amber','Pending limit reached',  'Pending items exceed your threshold (default: 10)'],
                        ['fas fa-circle-xmark',    'icon-red',   'Transaction denied',       'An order is denied and may require follow-up'],
                        ['fas fa-motorcycle',       'icon-purple','Rider goes offline',       'An assigned rider becomes unavailable'],
                        ['fas fa-chart-line',       'icon-green', 'Daily revenue summary',   'Receive a daily report of total revenue at 6 PM'],
                    ];
                    foreach($notifs as $i => $n): ?>
                    <div class="notif-item">
                        <div class="notif-item-icon <?= $n[1] ?>"><i class="<?= $n[0] ?>"></i></div>
                        <div class="notif-item-text">
                            <div class="notif-item-title"><?= $n[2] ?></div>
                            <div class="notif-item-desc"><?= $n[3] ?></div>
                        </div>
                        <label class="toggle" style="margin-left:12px;flex-shrink:0;">
                            <input type="checkbox" <?= $i < 3 ? 'checked' : '' ?>>
                            <span class="toggle-track"></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Thresholds</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Pending alert threshold</div>
                        <div class="settings-row-desc">Trigger an alert when pending items exceed this count</div>
                    </div>
                    <select class="settings-select" id="sel-pending-threshold">
                        <option>5</option>
                        <option selected>10</option>
                        <option>20</option>
                        <option>50</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Notification frequency</div>
                    </div>
                    <select class="settings-select" id="sel-notification-frequency">
                        <option>Immediately</option>
                        <option selected>Every 15 min</option>
                        <option>Hourly digest</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── Payment QR Tab ── -->
        <div class="tab-panel" id="tab-payment-qr">
            <?php
            // Load current QR settings
            $qr_gcash = ['qr_image_path' => 'imagess/cashg.jpg', 'account_number' => '0993 909 3915', 'account_name' => 'James C.', 'updated_at' => ''];
            $qr_maya  = ['qr_image_path' => 'imagess/ayam.jpg', 'account_number' => '0993 909 3915', 'account_name' => 'James C.', 'updated_at' => ''];
            $qrResult = $conn->query("SELECT payment_method, qr_image_path, account_number, account_name, updated_at FROM payment_qr_settings");
            if ($qrResult) {
                while ($qrRow = $qrResult->fetch_assoc()) {
                    if ($qrRow['payment_method'] === 'gcash') $qr_gcash = $qrRow;
                    if ($qrRow['payment_method'] === 'maya')  $qr_maya  = $qrRow;
                }
            }
            ?>
            <!-- GCash QR Card -->
            <div class="qr-upload-card" id="qrCardGcash">
                <div class="qr-card-header">
                    <div class="qr-card-header-icon gcash"><i class="fas fa-wallet"></i></div>
                    <div>
                        <h4>GCash QR Code</h4>
                        <p>Upload your GCash payment QR code for customers</p>
                    </div>
                </div>
                <div class="qr-card-body">
                    <div class="qr-preview-area">
                        <div class="qr-preview-box has-image" id="gcashPreviewBox">
                            <img src="../<?= htmlspecialchars($qr_gcash['qr_image_path']) ?>" alt="GCash QR" id="gcashPreviewImg">
                            <div class="qr-placeholder" style="display:none;"><i class="fas fa-qrcode"></i><span>No QR</span></div>
                        </div>
                        <div class="qr-upload-actions">
                            <button type="button" class="qr-upload-btn primary" onclick="document.getElementById('gcashFileInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i> Upload New QR
                            </button>
                            <input type="file" id="gcashFileInput" accept="image/*" style="display:none;" onchange="previewQrFile(this, 'gcash')">
                            <div class="qr-upload-hint">JPG, PNG, GIF or WebP • Max 5MB</div>
                            <?php if (!empty($qr_gcash['updated_at'])): ?>
                            <div class="qr-updated-at" id="gcashUpdatedAt"><i class="fas fa-clock"></i> Last updated: <?= date('M j, Y g:i A', strtotime($qr_gcash['updated_at'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="qr-fields-row">
                        <div class="field-group">
                            <div class="field-label">GCash Number</div>
                            <input class="field-input" id="gcashAccNumber" type="text" placeholder="e.g., 0993 909 3915" value="<?= htmlspecialchars($qr_gcash['account_number']) ?>">
                        </div>
                        <div class="field-group">
                            <div class="field-label">Account Name</div>
                            <input class="field-input" id="gcashAccName" type="text" placeholder="e.g., Juan D." value="<?= htmlspecialchars($qr_gcash['account_name']) ?>">
                        </div>
                    </div>
                    <div class="qr-save-row">
                        <button type="button" class="qr-upload-btn primary" style="width:auto;padding:9px 24px;" onclick="saveQrSettings('gcash')">
                            <i class="fas fa-floppy-disk"></i> Save GCash Settings
                        </button>
                        <div class="qr-save-status" id="gcashSaveStatus"></div>
                    </div>
                </div>
            </div>

            <!-- Maya QR Card -->
            <div class="qr-upload-card" id="qrCardMaya">
                <div class="qr-card-header">
                    <div class="qr-card-header-icon maya"><i class="fas fa-wallet"></i></div>
                    <div>
                        <h4>Maya QR Code</h4>
                        <p>Upload your Maya payment QR code for customers</p>
                    </div>
                </div>
                <div class="qr-card-body">
                    <div class="qr-preview-area">
                        <div class="qr-preview-box has-image" id="mayaPreviewBox">
                            <img src="../<?= htmlspecialchars($qr_maya['qr_image_path']) ?>" alt="Maya QR" id="mayaPreviewImg">
                            <div class="qr-placeholder" style="display:none;"><i class="fas fa-qrcode"></i><span>No QR</span></div>
                        </div>
                        <div class="qr-upload-actions">
                            <button type="button" class="qr-upload-btn primary" onclick="document.getElementById('mayaFileInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i> Upload New QR
                            </button>
                            <input type="file" id="mayaFileInput" accept="image/*" style="display:none;" onchange="previewQrFile(this, 'maya')">
                            <div class="qr-upload-hint">JPG, PNG, GIF or WebP • Max 5MB</div>
                            <?php if (!empty($qr_maya['updated_at'])): ?>
                            <div class="qr-updated-at" id="mayaUpdatedAt"><i class="fas fa-clock"></i> Last updated: <?= date('M j, Y g:i A', strtotime($qr_maya['updated_at'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="qr-fields-row">
                        <div class="field-group">
                            <div class="field-label">Maya Number</div>
                            <input class="field-input" id="mayaAccNumber" type="text" placeholder="e.g., 0993 909 3915" value="<?= htmlspecialchars($qr_maya['account_number']) ?>">
                        </div>
                        <div class="field-group">
                            <div class="field-label">Account Name</div>
                            <input class="field-input" id="mayaAccName" type="text" placeholder="e.g., Juan D." value="<?= htmlspecialchars($qr_maya['account_name']) ?>">
                        </div>
                    </div>
                    <div class="qr-save-row">
                        <button type="button" class="qr-upload-btn primary" style="width:auto;padding:9px 24px;" onclick="saveQrSettings('maya')">
                            <i class="fas fa-floppy-disk"></i> Save Maya Settings
                        </button>
                        <div class="qr-save-status" id="mayaSaveStatus"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Account Tab ── -->
        <div class="tab-panel" id="tab-account">
            <div class="settings-section">
                <div class="profile-avatar-row">
                    <div class="profile-avatar-big" id="profileAvatarBig" style="overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--bg3);">
                        <?php if (!empty($_SESSION['avatar_path']) && file_exists('../' . $_SESSION['avatar_path'])): ?>
                            <img src="../<?= htmlspecialchars($_SESSION['avatar_path']) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-avatar-info">
                        <h4><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></h4>
                        <p>Administrator</p>
                        <button class="btn-avatar-change" type="button" onclick="triggerAvatarUpload()"><i class="fas fa-camera"></i> Change photo</button>
                        <input type="file" id="avatarFileInput" accept="image/*" style="display:none;" onchange="uploadAvatarFile(this)">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Profile Info</div>
                <div class="settings-row" style="flex-direction:column; align-items:stretch; gap:14px;">
                    <div class="field-row">
                        <div class="field-group">
                            <div class="field-label">First name</div>
                            <input class="field-input" id="profileFirstName" type="text" value="<?= htmlspecialchars($profile_data['first_name'] ?? explode(' ', $_SESSION['full_name'] ?? 'Admin')[0]) ?>">
                        </div>
                        <div class="field-group">
                            <div class="field-label">Last name</div>
                            <input class="field-input" id="profileLastName" type="text" value="<?= htmlspecialchars($profile_data['last_name'] ?? (explode(' ', $_SESSION['full_name'] ?? 'Admin')[1] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="field-group">
                        <div class="field-label">Email address</div>
                        <input class="field-input" id="profileEmail" type="email" value="<?= htmlspecialchars($profile_data['email'] ?? 'admin@hydromis.com') ?>">
                    </div>
                    <div class="field-group">
                        <div class="field-label">Phone number</div>
                        <input class="field-input" id="profilePhone" type="text" placeholder="+63 9XX XXX XXXX" value="<?= htmlspecialchars($profile_data['phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Security</div>
                <div class="settings-row" style="flex-direction:column; align-items:stretch; gap:14px;">
                    <div class="field-group">
                        <div class="field-label">Current password</div>
                        <input class="field-input" type="password" placeholder="••••••••">
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <div class="field-label">New password</div>
                            <input class="field-input" type="password" placeholder="••••••••">
                        </div>
                        <div class="field-group">
                            <div class="field-label">Confirm password</div>
                            <input class="field-input" type="password" placeholder="••••••••">
                        </div>
                    </div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Two-factor authentication</div>
                        <div class="settings-row-desc">Add an extra layer of login security (SMS or TOTP)</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="tog-two-factor"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Session timeout</div>
                        <div class="settings-row-desc">Automatically log out after inactivity</div>
                    </div>
                    <select class="settings-select" id="sel-session-timeout">
                        <option>30 minutes</option>
                        <option selected>1 hour</option>
                        <option>4 hours</option>
                        <option>Never</option>
                    </select>
                </div>
            </div>

            <div class="settings-section danger-zone">
                <div class="settings-section-title">Danger Zone</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Export all data</div>
                        <div class="settings-row-desc">Download a full CSV export of all transactions and users</div>
                    </div>
                    <button class="btn-danger-action"><i class="fas fa-download"></i> Export</button>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Reset dashboard settings</div>
                        <div class="settings-row-desc">Restore all preferences to their defaults</div>
                    </div>
                    <button class="btn-danger-action"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </div>
        </div>

    </div><!-- /drawer-body -->

    <!-- Footer -->
    <div class="drawer-footer">
        <button class="btn btn-save" onclick="saveSettings()"><i class="fas fa-floppy-disk"></i> Save changes</button>
        <div class="save-status" id="saveStatus"><i class="fas fa-circle-check"></i> Saved!</div>
    </div>
</div>
<!-- ═══ END SETTINGS ═══ -->


<div class="shell">
    <aside class="sidebar">
        <div class="brand-logo">
            <div class="brand-icon"><img src="../<?= htmlspecialchars($systemLogo) ?>" alt="HydroMIS logo" style="width:24px;height:24px;object-fit:contain;"></div>
            <div>
                <div class="brand-name">HydroMIS</div>
                <div class="brand-sub">Admin Portal</div>
            </div>
        </div>
        <nav style="display:flex;flex-direction:column;gap:24px;">
            <div>
                <div class="nav-section-label">Main</div>
                <div class="nav-group">
                    <a href="dashboard.php" class="nav-item active"><i class="fas fa-chart-pie"></i> Dashboard</a>
                    <a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i> Transactions <?php if($pending>0): ?><span class="nav-badge"><?=(int)$pending?></span><?php endif; ?></a>
                    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a>
                    <a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Inventory</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users <?php if($pending_users>0): ?><span class="nav-badge"><?=(int)$pending_users?></span><?php endif; ?></a>
                    <a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i> Staff Account</a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">System</div>
                <div class="nav-group">
                    <a href="activity_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
                    <a href="#" class="nav-item" onclick="openSettings();return false;"><i class="fas fa-cog"></i> Settings</a>
                    
                </div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar" id="sidebarAvatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($_SESSION['avatar_path']) && file_exists('../' . $_SESSION['avatar_path'])): ?>
                        <img src="../<?= htmlspecialchars($_SESSION['avatar_path']) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="admin-name"><?=htmlspecialchars($_SESSION['full_name']??'Admin')?></div>
                    <div class="admin-role">Administrator</div>
                </div>
                <a href="../logout.php" class="logout-link" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>

    <main class="main">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="breadcrumb">
                    <i class="fas fa-home" style="font-size:12px;"></i>
                    <i class="fas fa-chevron-right" style="font-size:10px;opacity:.4;"></i>
                    <span>Dashboard</span>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-pill"><div class="live-dot"></div>Live Data</div>
                <div class="topbar-pill" style="gap:6px;"><i class="fas fa-clock" style="font-size:11px;"></i><span id="clock">--:--</span></div>

                <!-- Notification button -->
                <div class="icon-btn-wrap">
                    <a href="#" class="icon-btn" id="notificationBtn" title="Notifications" onclick="toggleNotifications(event)"><i class="fas fa-bell"></i></a>
                    <?php if(($pending + $pending_users) > 0): ?><div class="notif-dot"></div><?php endif; ?>
                    <div class="notification-menu" id="notificationMenu">
                        <div class="notification-menu-header">
                            <div class="notification-menu-title">Notifications</div>
                            <a href="transactions.php?filter=pending" class="notification-menu-link">Review all</a>
                        </div>
                        <?php if($pending_users > 0): ?>
                        <a class="notification-menu-item" href="users.php?filter=pending">
                            <i class="fas fa-user-clock" style="color:var(--amber);"></i>
                            <div><strong><?=(int)$pending_users?> pending user<?= $pending_users == 1 ? '' : 's' ?></strong><span>Customer registrations need approval.</span></div>
                        </a>
                        <?php endif; ?>
                        <?php if($pending > 0): ?>
                        <a class="notification-menu-item" href="transactions.php?filter=pending">
                            <i class="fas fa-receipt" style="color:var(--aqua);"></i>
                            <div><strong><?=(int)$pending?> pending transaction<?= $pending == 1 ? '' : 's' ?></strong><span>Water refill orders are awaiting review.</span></div>
                        </a>
                        <?php endif; ?>
                        <?php if(($pending + $pending_users) === 0): ?>
                        <div class="notification-empty">No pending admin actions right now.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page -->
        <div class="page-content">
            <div class="page-heading">
                <div>
                    <div class="page-title">Operations Dashboard</div>
                    <div class="page-subtitle">All metrics are live — last refreshed <span id="last-refresh">just now</span></div>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="stats-grid">
                <div class="card stat-card stat-aqua">
                    <div class="stat-top"><div class="stat-icon-wrap icon-aqua"><i class="fas fa-peso-sign"></i></div><span class="stat-trend trend-up"><i class="fas fa-arrow-up"></i> 12.4%</span></div>
                    <div class="stat-value"><?=htmlspecialchars(format_currency($total_sales))?></div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-detail"><b><?=(int)$approved?></b> approved transactions</div>
                    <div class="sparkline-wrap"><canvas id="spark1"></canvas></div>
                </div>
                <div class="card stat-card stat-blue">
                    <div class="stat-top"><div class="stat-icon-wrap icon-blue"><i class="fas fa-exchange-alt"></i></div><span class="stat-trend trend-up"><i class="fas fa-arrow-up"></i> 8.1%</span></div>
                    <div class="stat-value"><?=(int)$total_transactions?></div>
                    <div class="stat-label">Transactions</div>
                    <div class="stat-detail"><b><?=(int)$pending?></b> pending review</div>
                    <div class="sparkline-wrap"><canvas id="spark2"></canvas></div>
                </div>
                <div class="card stat-card stat-purple">
                    <div class="stat-top"><div class="stat-icon-wrap icon-purple"><i class="fas fa-users"></i></div><span class="stat-trend trend-up"><i class="fas fa-arrow-up"></i> 5.7%</span></div>
                    <div class="stat-value"><?=(int)$total_users?></div>
                    <div class="stat-label">Registered Users</div>
                    <div class="stat-detail"><b><?=(int)$approved_users?></b> active · <b><?=(int)$pending_users?></b> pending</div>
                    <div class="sparkline-wrap"><canvas id="spark3"></canvas></div>
                </div>
                <div class="card stat-card stat-amber">
                    <div class="stat-top"><div class="stat-icon-wrap icon-amber"><i class="fas fa-triangle-exclamation"></i></div><span class="stat-trend trend-flat"><i class="fas fa-minus"></i> Stable</span></div>
                    <div class="stat-value"><?=(int)($pending+$pending_users)?></div>
                    <div class="stat-label">Pending Actions</div>
                    <div class="stat-detail"><b><?=(int)$pending?></b> txns · <b><?=(int)$pending_users?></b> users · <b><?=(int)$denied?></b> denied</div>
                    <div class="sparkline-wrap"><canvas id="spark4"></canvas></div>
                </div>
            </div>

            <!-- Charts -->
            <div class="chart-row">
                <div class="panel">
                    <div class="panel-header">
                        <div><div class="panel-title">Revenue Trend</div><div class="panel-sub">Monthly approved transaction totals</div></div>
                        <div class="tab-group">
                            <div class="tab active" onclick="switchTab(this,'week')">7D</div>
                            <div class="tab" onclick="switchTab(this,'month')">30D</div>
                            <div class="tab" onclick="switchTab(this,'year')">12M</div>
                        </div>
                    </div>
                    <div class="chart-body"><canvas id="revenueChart" height="220"></canvas></div>
                </div>
                <div class="panel">
                    <div class="panel-header"><div><div class="panel-title">Transaction Status</div><div class="panel-sub">Distribution overview</div></div></div>
                    <div class="donut-body">
                        <div class="donut-wrap">
                            <canvas id="donutChart"></canvas>
                            <div class="donut-center"><div class="donut-center-val"><?=(int)$total_transactions?></div><div class="donut-center-lbl">Total</div></div>
                        </div>
                        <div class="legend" style="max-width:260px;width:100%;">
                            <?php
                            $total_tx = max(1,(int)$total_transactions);
                            foreach([['Approved',$approved,'#22c55e'],['Pending',$pending,'#f59e0b'],['Denied',$denied,'#f43f5e']] as $s):
                                $pct = round(($s[1]/$total_tx)*100); ?>
                            <div>
                                <div class="legend-item">
                                    <div class="legend-left"><div class="legend-dot" style="background:<?=$s[2]?>;"></div><?=$s[0]?></div>
                                    <div class="legend-val"><?=(int)$s[1]?> <span style="color:var(--muted);font-size:11px;">(<?=$pct?>%)</span></div>
                                </div>
                                <div class="progress-bar-bg"><div class="progress-bar" style="width:<?=$pct?>%;background:<?=$s[2]?>;"></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom -->
            <div class="bottom-row">
                <div class="table-panel">
                    <div class="panel-header">
                        <div><div class="panel-title">Recent Transactions</div><div class="panel-sub">Last 8 entries across all statuses</div></div>
                        <a href="transactions.php" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;">View all</a>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="data-table">
                            <thead><tr><th>Customer</th><th>Txn ID</th><th>Amount</th><th>Description</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php if($recent_sales && $recent_sales->num_rows>0):
                                while($row=$recent_sales->fetch_assoc()):
                                    $init=strtoupper(implode('',array_map(fn($w)=>$w[0],explode(' ',$row['full_name']))));
                                    $init=substr($init,0,2); ?>
                            <tr>
                                <td><div class="user-cell"><div class="user-avatar"><?=htmlspecialchars($init)?></div><div class="user-name"><?=htmlspecialchars($row['full_name'])?></div></div></td>
                                <td><div class="tx-id">#<?=htmlspecialchars($row['transaction_id'])?></div></td>
                                <td><div class="amount-cell"><?=htmlspecialchars(format_currency($row['amount']))?></div></td>
                                <td style="color:var(--muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($row['description'])?></td>
                                <td><span class="badge badge-<?=htmlspecialchars($row['status'])?>"><?=ucfirst(htmlspecialchars($row['status']))?></span></td>
                                <td style="color:var(--muted);white-space:nowrap;"><?=date('M d, Y',strtotime($row['created_at']))?></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:40px;">No transactions recorded yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="snapshot-panel">
                    <div class="panel-header"><div><div class="panel-title">Approval Snapshot</div><div class="panel-sub">Live counts</div></div></div>
                    <?php
                    $max = max(1,(int)$total_transactions);
                    $snaps=[
                        ['Approved Txns','Confirmed',$approved,'#22c55e','icon-green','fas fa-circle-check'],
                        ['Pending Txns','Awaiting review',$pending,'#f59e0b','icon-amber','fas fa-hourglass-half'],
                        ['Denied Txns','Rejected',$denied,'#f43f5e','icon-red','fas fa-circle-xmark'],
                        ['Active Users','Approved accounts',$approved_users,'#3b82f6','icon-blue','fas fa-user-check'],
                        ['Pending Users','Awaiting review',$pending_users,'#f59e0b','icon-amber','fas fa-user-clock'],
                        ['Denied Users','Rejected requests',$denied_users,'#f43f5e','icon-red','fas fa-user-xmark'],
                    ];
                    foreach($snaps as $s):
                        $p=min(100,round(($s[2]/$max)*100)); ?>
                    <div class="snapshot-item">
                        <div class="snapshot-icon <?=$s[4]?>"><i class="<?=$s[5]?>"></i></div>
                        <div style="flex:1;min-width:0;">
                            <div class="snapshot-label"><?=$s[0]?></div>
                            <div class="snapshot-sub"><?=$s[1]?></div>
                            <div class="progress-bar-bg" style="margin-top:6px;"><div class="progress-bar" style="width:<?=$p?>%;background:<?=$s[3]?>;"></div></div>
                        </div>
                        <div class="snapshot-count"><?=(int)$s[2]?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
/* ── Clock ── */
function tick(){const n=new Date();document.getElementById('clock').textContent=n.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
tick();setInterval(tick,1000);

/* ── Settings Management ── */
const userSettings = <?php echo json_encode($user_settings); ?>;
const revenueSeries = <?php echo json_encode([
    'week' => $revenue_week,
    'month' => $revenue_month,
    'year' => $revenue_year
]); ?>;

function setSelectValue(id, value) {
    const el = document.getElementById(id);
    if (el && value !== undefined && value !== null) el.value = value;
}

function setCheckboxValue(id, value) {
    const el = document.getElementById(id);
    if (el && value !== undefined) el.checked = Boolean(value);
}

function applyDashboardPreferences() {
    document.body.classList.toggle('compact-tables', document.getElementById('tog-compact')?.checked || false);
    document.body.classList.toggle('reduce-motion', document.getElementById('tog-reduce-motion')?.checked || false);
    const colorMode = document.getElementById('sel-color-mode')?.value || 'dark';
    if (typeof window.applyAdminColorMode === 'function') {
        window.applyAdminColorMode(colorMode);
    } else {
        document.documentElement.setAttribute('data-admin-color-mode', colorMode);
        document.body.setAttribute('data-color-mode', colorMode);
        localStorage.setItem('hydromis-admin-color-mode', colorMode);
    }
    document.body.setAttribute('data-layout-spacing', (document.getElementById('sel-layout-spacing')?.value || 'Comfortable').toLowerCase());
    document.body.setAttribute('data-border-radius', (document.getElementById('sel-border-radius')?.value || 'Rounded').toLowerCase());
    document.querySelectorAll('.sparkline-wrap').forEach(el => {
        el.style.display = document.getElementById('tog-sparklines')?.checked === false ? 'none' : '';
    });
    document.querySelectorAll('.nav-badge, .notif-dot').forEach(el => {
        el.style.display = document.getElementById('tog-pending-badges')?.checked === false ? 'none' : '';
    });
}

// Load settings on page load
function loadSettings() {
    if (!userSettings || Object.keys(userSettings).length === 0) {
        applyDashboardPreferences();
        startAutoRefresh();
        return;
    }
    
    // Dashboard Behavior
    setCheckboxValue('tog-autorefresh', userSettings.autorefresh);
    setCheckboxValue('tog-compact', userSettings.compact);
    setCheckboxValue('tog-sparklines', userSettings.sparklines);
    setCheckboxValue('tog-pending-badges', userSettings.showPendingBadges);
    setCheckboxValue('tog-collapsed-sidebar', userSettings.collapsedByDefault);
    setSelectValue('sel-revenue-period', userSettings.revenuePeriod);
    setSelectValue('sel-currency', userSettings.currency);
    setSelectValue('sel-date-format', userSettings.dateFormat);
    setSelectValue('sel-time-format', userSettings.timeFormat);
    setSelectValue('sel-color-mode', (userSettings.colorMode || localStorage.getItem('hydromis-admin-color-mode') || 'dark').toLowerCase());
    
    // Theme Color
    if (userSettings.themeColor) {
        document.querySelectorAll('.theme-swatch').forEach(sw => {
            if (sw.dataset.color === userSettings.themeColor) {
                sw.classList.add('selected');
                applyThemeColor(userSettings.themeColor);
            } else {
                sw.classList.remove('selected');
            }
        });
    }
    
    setSelectValue('sel-layout-spacing', userSettings.layoutSpacing);
    setSelectValue('sel-border-radius', userSettings.borderRadius);
    setCheckboxValue('tog-page-transitions', userSettings.pageTransitions);
    setCheckboxValue('tog-chart-animations', userSettings.chartAnimations);
    setCheckboxValue('tog-reduce-motion', userSettings.reduceMotion);
    setCheckboxValue('tog-in-app-notifications', userSettings.inAppNotifications);
    setCheckboxValue('tog-email-notifications', userSettings.emailNotifications);
    setCheckboxValue('tog-sms-alerts', userSettings.smsAlerts);
    setSelectValue('sel-pending-threshold', userSettings.pendingThreshold);
    setSelectValue('sel-notification-frequency', userSettings.notificationFreq);
    setCheckboxValue('tog-two-factor', userSettings.twoFactor);
    setSelectValue('sel-session-timeout', userSettings.sessionTimeout);
    
    // Notifications
    document.querySelectorAll('.notif-item input[type="checkbox"]').forEach((cb, idx) => {
        if (userSettings.notifications && userSettings.notifications[idx] !== undefined) {
            cb.checked = userSettings.notifications[idx];
        }
    });
    
    // Profile Info
    if (userSettings.profileFirstName) {
        const firstNameInput = document.getElementById('profileFirstName');
        if (firstNameInput) firstNameInput.value = userSettings.profileFirstName;
    }
    if (userSettings.profileLastName) {
        const lastNameInput = document.getElementById('profileLastName');
        if (lastNameInput) lastNameInput.value = userSettings.profileLastName;
    }
    if (userSettings.profileEmail) {
        const emailInput = document.getElementById('profileEmail');
        if (emailInput) emailInput.value = userSettings.profileEmail;
    }
    if (userSettings.profilePhone) {
        const phoneInput = document.getElementById('profilePhone');
        if (phoneInput) phoneInput.value = userSettings.profilePhone;
    }
    
    applyDashboardPreferences();
    startAutoRefresh();
}

function applyThemeColor(color) {
    const colors = {
        aqua: '#2dd4bf',
        blue: '#3b82f6',
        purple: '#a78bfa',
        amber: '#f59e0b',
        green: '#22c55e',
        rose: '#f43f5e',
        orange: '#fb923c',
        slate: '#64748b'
    };
    if (colors[color]) {
        document.documentElement.style.setProperty('--aqua', colors[color]);
    }
}

function saveSettings() {
    // Collect all settings from the form
    const passwordInputs = document.querySelectorAll('#tab-account .settings-section:nth-of-type(3) input[type="password"]');
    
    const settings = {
        autorefresh: false,
        compact: document.getElementById('tog-compact').checked,
        sparklines: document.getElementById('tog-sparklines').checked,
        themeColor: document.querySelector('.theme-swatch.selected')?.dataset.color || 'aqua',
        colorMode: document.getElementById('sel-color-mode')?.value || 'dark',
        revenuePeriod: document.getElementById('sel-revenue-period')?.value || 'Last 30 days',
        currency: document.getElementById('sel-currency')?.value || 'PHP - Philippine Peso',
        dateFormat: document.getElementById('sel-date-format')?.value || 'MMM DD, YYYY',
        timeFormat: document.getElementById('sel-time-format')?.value || '12-hour (AM/PM)',
        layoutSpacing: document.getElementById('sel-layout-spacing')?.value || 'Comfortable',
        borderRadius: document.getElementById('sel-border-radius')?.value || 'Rounded',
        showPendingBadges: document.getElementById('tog-pending-badges')?.checked ?? true,
        collapsedByDefault: document.getElementById('tog-collapsed-sidebar')?.checked ?? false,
        pageTransitions: document.getElementById('tog-page-transitions')?.checked ?? true,
        chartAnimations: document.getElementById('tog-chart-animations')?.checked ?? true,
        reduceMotion: document.getElementById('tog-reduce-motion')?.checked ?? false,
        inAppNotifications: document.getElementById('tog-in-app-notifications')?.checked ?? true,
        emailNotifications: document.getElementById('tog-email-notifications')?.checked ?? true,
        smsAlerts: document.getElementById('tog-sms-alerts')?.checked ?? false,
        notifications: Array.from(document.querySelectorAll('.notif-item input[type="checkbox"]')).map(cb => cb.checked),
        pendingThreshold: document.getElementById('sel-pending-threshold')?.value || '10',
        notificationFreq: document.getElementById('sel-notification-frequency')?.value || 'Every 15 min',
        profileFirstName: document.getElementById('profileFirstName')?.value || '',
        profileLastName: document.getElementById('profileLastName')?.value || '',
        profileEmail: document.getElementById('profileEmail')?.value || '',
        profilePhone: document.getElementById('profilePhone')?.value || '',
        currentPassword: passwordInputs[0]?.value || '',
        newPassword: passwordInputs[1]?.value || '',
        confirmPassword: passwordInputs[2]?.value || '',
        twoFactor: document.getElementById('tog-two-factor')?.checked ?? false,
        sessionTimeout: document.getElementById('sel-session-timeout')?.value || '1 hour',
        pointsPerGallon: Math.max(0, Math.min(100, parseInt(document.getElementById('pointsPerGallon')?.value || '1', 10))),
        staffLoginEnabled: document.getElementById('tog-staff-login')?.checked ?? true,
        riderLoginEnabled: document.getElementById('tog-rider-login')?.checked ?? true,
        rewardRedemptions: Object.fromEntries(Array.from(document.querySelectorAll('.reward-redemption-toggle')).map(toggle => [toggle.dataset.rewardCode, toggle.checked]))
    };
    
    const btn = document.querySelector('.btn-save');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;
    
    // Send via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'save_settings',
            settings: JSON.stringify(settings)
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save changes';
        btn.disabled = false;
        const s = document.getElementById('saveStatus');
        if (data.success) {
            applyDashboardPreferences();
            stopAutoRefresh();
            startAutoRefresh();
            passwordInputs.forEach(input => input.value = '');
            s.style.display = 'flex';
            s.style.color = 'var(--green)';
            s.innerHTML = '<i class="fas fa-circle-check"></i> ' + (data.message || 'Saved!');
            setTimeout(() => s.style.display = 'none', 3000);
        } else {
            s.style.display = 'flex';
            s.style.color = 'var(--red)';
            s.innerHTML = '<i class="fas fa-circle-xmark"></i> ' + (data.message || 'Unable to save');
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save changes';
        btn.disabled = false;
    });
}

/* ── Settings drawer ── */
function openSettings(){
    document.getElementById('settingsBackdrop').classList.add('open');
    document.getElementById('settingsDrawer').classList.add('open');
    document.getElementById('settingsBtn')?.classList.add('settings-active');
    document.body.style.overflow='hidden';
    
    // Auto-scroll the active tab into view when opening the drawer
    setTimeout(() => {
        document.querySelector('.drawer-tab.active')?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }, 100);
}
function closeSettings(){
    document.getElementById('settingsBackdrop').classList.remove('open');
    document.getElementById('settingsDrawer').classList.remove('open');
    document.getElementById('settingsBtn')?.classList.remove('settings-active');
    document.body.style.overflow='';
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeSettings(); });

function toggleNotifications(event) {
    event.preventDefault();
    event.stopPropagation();
    document.getElementById('notificationMenu')?.classList.toggle('open');
}

document.addEventListener('click', e => {
    const menu = document.getElementById('notificationMenu');
    const btn = document.getElementById('notificationBtn');
    if (menu && !menu.contains(e.target) && !btn?.contains(e.target)) {
        menu.classList.remove('open');
    }
});

function openAvatarSettings() {
    const firstName = document.getElementById('profileFirstName');
    if (firstName) {
        firstName.focus();
        firstName.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
    const status = document.getElementById('saveStatus');
    status.style.display = 'flex';
    status.style.color = 'var(--muted)';
    status.innerHTML = '<i class="fas fa-circle-info"></i> Update your name below to change dashboard initials.';
    setTimeout(() => status.style.display = 'none', 3500);
}

function triggerAvatarUpload() {
    document.getElementById('avatarFileInput').click();
}

function uploadSystemLogo(input) {
    const file = input.files?.[0];
    const status = document.getElementById('systemLogoStatus');
    if (!file) return;
    status.style.display = 'flex';
    status.className = 'qr-save-status';
    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading logo...';
    const data = new FormData();
    data.append('logo', file);
    data.append('csrf_token', <?= json_encode($_SESSION['system_logo_csrf']) ?>);
    fetch('upload_logo.php', { method: 'POST', body: data })
        .then(response => response.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Logo upload failed.');
            document.querySelectorAll('.brand-icon img').forEach(img => img.src = result.path + '?v=' + Date.now());
            document.getElementById('systemLogoPreview').src = result.path + '?v=' + Date.now();
            status.className = 'qr-save-status success';
            status.innerHTML = '<i class="fas fa-circle-check"></i> Logo updated across Admin and Staff.';
        })
        .catch(error => {
            status.className = 'qr-save-status error';
            status.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + error.message;
        })
        .finally(() => { input.value = ''; });
}

function uploadAvatarFile(input) {
    const file = input.files[0];
    if (!file) return;

    // Validate size (max 2MB)
    if (file.size > 2 * 1024 * 1024) {
        alert('File too large. Maximum size is 2MB.');
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('action', 'upload_avatar');
    formData.append('avatar', file);

    const btn = document.querySelector('.btn-avatar-change');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
    btn.disabled = true;

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        input.value = '';

        if (data.success) {
            // Update big avatar in settings
            const bigAv = document.getElementById('profileAvatarBig');
            bigAv.innerHTML = `<img src="${data.path}?t=${Date.now()}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
            
            // Update sidebar avatar
            const sideAv = document.getElementById('sidebarAvatar');
            if (sideAv) {
                sideAv.innerHTML = `<img src="${data.path}?t=${Date.now()}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
            }
            
            alert('Profile picture updated successfully!');
        } else {
            alert(data.message || 'Failed to upload avatar.');
        }
    })
    .catch(err => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        input.value = '';
        alert('Network error. Please try again.');
    });
}

function switchDrawerTab(el, targetId){
    document.querySelectorAll('.drawer-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(targetId).classList.add('active');
    
    // Auto-scroll the clicked tab into center view
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
}

/* ── Theme swatches ── */
document.querySelectorAll('.theme-swatch').forEach(sw=>{
    sw.addEventListener('click',()=>{
        document.querySelectorAll('.theme-swatch').forEach(s=>s.classList.remove('selected'));
        sw.classList.add('selected');
        applyThemeColor(sw.dataset.color);
    });
});

['tog-compact','tog-sparklines','tog-pending-badges','tog-reduce-motion','sel-color-mode','sel-layout-spacing','sel-border-radius'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyDashboardPreferences);
});

/* ── Export Data ── */
document.querySelectorAll('.btn-danger-action').forEach((btn, idx) => {
    if (idx === 0) { // Export button
        btn.addEventListener('click', () => {
            fetch('export_data.php', {method: 'GET'})
                .then(res => res.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'hydromis_export_' + new Date().getTime() + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                });
        });
    } else if (idx === 1) { // Reset button
        btn.addEventListener('click', () => {
            if (confirm('Are you sure you want to reset all dashboard settings to defaults?')) {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'reset_settings'
                    })
                }).then(() => {
                    location.reload();
                });
            }
        });
    }
});

// Load settings on page load
document.addEventListener('DOMContentLoaded', function() {
    loadSettings();
    // Auto-open settings drawer if linked from another page
    if (new URLSearchParams(window.location.search).get('open_settings') === '1') {
        openSettings();
    }
    
    // Make the tab strip move naturally with a wheel, trackpad, or mouse drag.
    const tabsContainer = document.querySelector('.drawer-tabs');
    if (tabsContainer) {
        tabsContainer.addEventListener('wheel', function(e) {
            const movement = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
            if (!movement || tabsContainer.scrollWidth <= tabsContainer.clientWidth) return;

            e.preventDefault();
            const multiplier = e.deltaMode === 1 ? 18 : (e.deltaMode === 2 ? tabsContainer.clientWidth : 1);
            tabsContainer.scrollLeft += movement * multiplier;
        }, { passive: false });

        let dragging = false;
        let dragStartX = 0;
        let dragStartScroll = 0;
        let draggedDistance = 0;

        tabsContainer.addEventListener('pointerdown', function(e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            dragging = true;
            dragStartX = e.clientX;
            dragStartScroll = tabsContainer.scrollLeft;
            draggedDistance = 0;
            tabsContainer.classList.add('is-dragging');
        });

        tabsContainer.addEventListener('pointermove', function(e) {
            if (!dragging) return;
            draggedDistance = Math.max(draggedDistance, Math.abs(e.clientX - dragStartX));
            tabsContainer.scrollLeft = dragStartScroll - (e.clientX - dragStartX);
        });

        const stopTabDrag = function(e) {
            if (!dragging) return;
            dragging = false;
            tabsContainer.classList.remove('is-dragging');
        };
        tabsContainer.addEventListener('pointerup', stopTabDrag);
        tabsContainer.addEventListener('pointercancel', stopTabDrag);
        tabsContainer.addEventListener('pointerleave', function(e) {
            if (e.buttons === 0) stopTabDrag(e);
        });
        tabsContainer.addEventListener('click', function(e) {
            if (draggedDistance > 5) {
                e.preventDefault();
                e.stopPropagation();
                draggedDistance = 0;
            }
        }, true);
    }
});

/* ── Payment QR Management ── */
let qrSelectedFiles = { gcash: null, maya: null };

function previewQrFile(input, method) {
    const file = input.files[0];
    if (!file) return;

    // Validate
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        showQrStatus(method, 'error', 'Invalid file type. Use JPG, PNG, GIF or WebP.');
        input.value = '';
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showQrStatus(method, 'error', 'File too large. Maximum 5MB.');
        input.value = '';
        return;
    }

    qrSelectedFiles[method] = file;

    // Preview
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById(method + 'PreviewImg');
        const placeholder = img.nextElementSibling;
        img.src = e.target.result;
        img.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
        document.getElementById(method + 'PreviewBox').classList.add('has-image');
    };
    reader.readAsDataURL(file);
    showQrStatus(method, 'success', 'Image selected. Click Save to upload.');
}

function saveQrSettings(method) {
    const accNumber = document.getElementById(method + 'AccNumber').value.trim();
    const accName = document.getElementById(method + 'AccName').value.trim();

    if (!accNumber || !accName) {
        showQrStatus(method, 'error', 'Please fill in account number and name.');
        return;
    }

    const formData = new FormData();
    formData.append('payment_method', method);
    formData.append('account_number', accNumber);
    formData.append('account_name', accName);

    if (qrSelectedFiles[method]) {
        formData.append('qr_image', qrSelectedFiles[method]);
    }

    // Show loading
    const btn = event.target.closest('.qr-upload-btn');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;

    fetch('upload_qr.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = origHtml;
        btn.disabled = false;

        if (data.success) {
            qrSelectedFiles[method] = null;
            // Update preview image with server path
            if (data.data && data.data.qr_image_path) {
                document.getElementById(method + 'PreviewImg').src = '../' + data.data.qr_image_path + '?t=' + Date.now();
            }
            // Reset file input
            document.getElementById(method + 'FileInput').value = '';
            showQrStatus(method, 'success', data.message);
        } else {
            showQrStatus(method, 'error', data.message || 'Failed to save.');
        }
    })
    .catch(err => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        showQrStatus(method, 'error', 'Network error. Please try again.');
    });
}

function showQrStatus(method, type, message) {
    const el = document.getElementById(method + 'SaveStatus');
    el.className = 'qr-save-status ' + type;
    el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'circle-check' : 'circle-xmark') + '"></i> ' + message;
    el.style.display = 'flex';
    setTimeout(() => el.style.display = 'none', 4000);
}

// Auto-refresh functionality
let autoRefreshInterval = null;

function startAutoRefresh() {
    // Auto-refresh disabled
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

/* ── Chart.js defaults ── */
Chart.defaults.color='#7a8a9e';
Chart.defaults.borderColor='rgba(255,255,255,0.06)';
Chart.defaults.font.family="'Plus Jakarta Sans',sans-serif";

function makeSparkline(id,data,color){
    const ctx=document.getElementById(id); if(!ctx)return;
    new Chart(ctx,{type:'line',data:{labels:data.map((_,i)=>i),datasets:[{data,borderColor:color,borderWidth:2,pointRadius:0,tension:0.4,fill:true,
        backgroundColor:(c)=>{const g=c.chart.ctx.createLinearGradient(0,0,0,44);g.addColorStop(0,color+'33');g.addColorStop(1,color+'00');return g;}}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}},scales:{x:{display:false},y:{display:false}},animation:{duration:1200}}});
}
makeSparkline('spark1',[18,22,19,31,28,35,40,38,44,52,48,56],'#2dd4bf');
makeSparkline('spark2',[10,14,12,20,18,22,25,28,26,30,32,35],'#3b82f6');
makeSparkline('spark3',[5,8,7,10,12,11,15,14,18,20,22,24],'#a78bfa');
makeSparkline('spark4',[3,5,4,7,6,8,5,9,7,6,8,5],'#f59e0b');

const datasets={
    week: revenueSeries.week.values,
    month: revenueSeries.month.values,
    year: revenueSeries.year.values,
};
const wkL = revenueSeries.week.labels;
const moL = revenueSeries.month.labels;
const yrL = revenueSeries.year.labels;
const rCtx=document.getElementById('revenueChart').getContext('2d');
const grad=rCtx.createLinearGradient(0,0,0,220);
grad.addColorStop(0,'rgba(45,212,191,0.25)');grad.addColorStop(1,'rgba(45,212,191,0.00)');
const revenueChart=new Chart(rCtx,{type:'line',data:{labels:wkL,datasets:[{label:'Revenue (PHP)',data:datasets.week,borderColor:'#2dd4bf',borderWidth:2.5,pointRadius:4,pointBackgroundColor:'#2dd4bf',pointBorderColor:'#161b24',pointBorderWidth:2,tension:0.4,fill:true,backgroundColor:grad}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
        plugins:{legend:{display:false},tooltip:{backgroundColor:'#1e2533',borderColor:'rgba(255,255,255,0.1)',borderWidth:1,padding:12,callbacks:{label:c=>' PHP '+c.parsed.y.toLocaleString('en-PH',{minimumFractionDigits:2})}}},
        scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{font:{size:11}}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{font:{size:11},callback:v=>'P'+Math.round(v/1000)+'k'}}}}});

function switchTab(el,p){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    revenueChart.data.labels=p==='week'?wkL:p==='month'?moL:yrL;
    revenueChart.data.datasets[0].data=datasets[p];
    revenueChart.update();
}

new Chart(document.getElementById('donutChart'),{type:'doughnut',
    data:{labels:['Approved','Pending','Denied'],datasets:[{data:[<?=(int)$approved?>,<?=(int)$pending?>,<?=(int)$denied?>],backgroundColor:['#22c55e','#f59e0b','#f43f5e'],borderColor:'#161b24',borderWidth:3,hoverOffset:6}]},
    options:{cutout:'72%',responsive:true,maintainAspectRatio:true,plugins:{legend:{display:false},tooltip:{backgroundColor:'#1e2533',borderColor:'rgba(255,255,255,0.1)',borderWidth:1,padding:10}},animation:{animateRotate:true,duration:1400}}});
</script>
</body>
</html>
