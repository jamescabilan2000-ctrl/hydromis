<?php
require_once 'check_auth.php';
require_once '../config/database.php';

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

// Handle AJAX settings save/load
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    header('Content-Type: application/json');
    $admin_id = $_SESSION['admin_id'] ?? 'admin';
    $settings = json_decode($_POST['settings'] ?? '{}', true);
    
    // Save settings
    $settings_json = json_encode($settings);
    $sql = "INSERT INTO admin_settings (admin_id, settings) VALUES ('$admin_id', '$settings_json')
            ON DUPLICATE KEY UPDATE settings = '$settings_json'";
    
    // Save profile info if provided
    if (!empty($settings['profileFirstName']) || !empty($settings['profileLastName']) || 
        !empty($settings['profileEmail']) || !empty($settings['profilePhone'])) {
        
        $firstName = $conn->real_escape_string($settings['profileFirstName'] ?? '');
        $lastName = $conn->real_escape_string($settings['profileLastName'] ?? '');
        $email = $conn->real_escape_string($settings['profileEmail'] ?? '');
        $phone = $conn->real_escape_string($settings['profilePhone'] ?? '');
        
        $profile_sql = "INSERT INTO admin_profiles (admin_id, first_name, last_name, email, phone) 
                        VALUES ('$admin_id', '$firstName', '$lastName', '$email', '$phone')
                        ON DUPLICATE KEY UPDATE first_name='$firstName', last_name='$lastName', email='$email', phone='$phone'";
        
        $conn->query($profile_sql);
    }
    
    if ($conn->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Settings saved']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving settings']);
    }
    exit;
}

// Get user settings
$admin_id = $_SESSION['admin_id'] ?? 'admin';
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
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 99px; }

.shell { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: 100vh; }

/* ── Sidebar ── */
.sidebar { background: var(--bg2); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 28px 16px 24px; gap: 32px; }
.brand-logo { display: flex; align-items: center; gap: 10px; padding: 0 8px; }
.brand-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #1e9e8f, #0e6d7a); display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff; box-shadow: 0 4px 14px rgba(45,212,191,0.3); flex-shrink: 0; }
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
}
.drawer-tab {
    padding: 10px 16px;
    font-size: 13px; font-weight: 600; color: var(--muted);
    cursor: pointer; border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.18s, border-color 0.18s;
    display: flex; align-items: center; gap: 7px;
    user-select: none;
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
</style>
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
        <div class="drawer-tab" onclick="switchDrawerTab(this,'tab-account')"><i class="fas fa-user"></i> Account</div>
    </div>

    <!-- Body -->
    <div class="drawer-body">

        <!-- ── General Tab ── -->
        <div class="tab-panel active" id="tab-general">
            <div class="settings-section">
                <div class="settings-section-title">Dashboard Behavior</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Auto-refresh data</div>
                        <div class="settings-row-desc">Automatically refresh dashboard metrics every 30 seconds</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked id="tog-autorefresh"><span class="toggle-track"></span></label>
                </div>
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
                    <select class="settings-select">
                        <option>Last 7 days</option>
                        <option selected>Last 30 days</option>
                        <option>Last 12 months</option>
                    </select>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-title">Region &amp; Format</div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Currency display</div>
                        <div class="settings-row-desc">How monetary values are formatted across the dashboard</div>
                    </div>
                    <select class="settings-select">
                        <option selected>PHP — Philippine Peso</option>
                        <option>USD — US Dollar</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Date format</div>
                    </div>
                    <select class="settings-select">
                        <option selected>MMM DD, YYYY</option>
                        <option>DD/MM/YYYY</option>
                        <option>YYYY-MM-DD</option>
                    </select>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Time format</div>
                    </div>
                    <select class="settings-select">
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
                    <label class="toggle"><input type="checkbox" checked><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Collapsed by default</div>
                        <div class="settings-row-desc">Start with sidebar minimized on smaller screens</div>
                    </div>
                    <label class="toggle"><input type="checkbox"><span class="toggle-track"></span></label>
                </div>
            </div>
        </div>

        <!-- ── Appearance Tab ── -->
        <div class="tab-panel" id="tab-appearance">
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
                    <select class="settings-select">
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
                    <select class="settings-select">
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
                    <label class="toggle"><input type="checkbox" checked><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Chart animations</div>
                        <div class="settings-row-desc">Animate chart drawing on load and data change</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Reduce motion</div>
                        <div class="settings-row-desc">Disable all non-essential animations for accessibility</div>
                    </div>
                    <label class="toggle"><input type="checkbox"><span class="toggle-track"></span></label>
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
                    <label class="toggle"><input type="checkbox" checked><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Email notifications</div>
                        <div class="settings-row-desc">Send email alerts to your admin address</div>
                    </div>
                    <label class="toggle"><input type="checkbox" checked><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">SMS alerts</div>
                        <div class="settings-row-desc">Send critical alerts via SMS (requires phone number)</div>
                    </div>
                    <label class="toggle"><input type="checkbox"><span class="toggle-track"></span></label>
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
                    <select class="settings-select">
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
                    <select class="settings-select">
                        <option>Immediately</option>
                        <option selected>Every 15 min</option>
                        <option>Hourly digest</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── Account Tab ── -->
        <div class="tab-panel" id="tab-account">
            <div class="settings-section">
                <div class="profile-avatar-row">
                    <div class="profile-avatar-big"><?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?></div>
                    <div class="profile-avatar-info">
                        <h4><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></h4>
                        <p>Super Administrator</p>
                        <button class="btn-avatar-change"><i class="fas fa-camera"></i> Change photo</button>
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
                    <label class="toggle"><input type="checkbox"><span class="toggle-track"></span></label>
                </div>
                <div class="settings-row">
                    <div class="settings-row-info">
                        <div class="settings-row-label">Session timeout</div>
                        <div class="settings-row-desc">Automatically log out after inactivity</div>
                    </div>
                    <select class="settings-select">
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
            <div class="brand-icon"><i class="fas fa-water"></i></div>
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
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users <?php if($pending_users>0): ?><span class="nav-badge"><?=(int)$pending_users?></span><?php endif; ?></a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">System</div>
                <div class="nav-group">
                    <a href="#" class="nav-item" onclick="openSettings();return false;"><i class="fas fa-cog"></i> Settings</a>
                    
                </div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar"><?=strtoupper(substr($_SESSION['full_name']??'A',0,1))?></div>
                <div>
                    <div class="admin-name"><?=htmlspecialchars($_SESSION['full_name']??'Admin')?></div>
                    <div class="admin-role">Super Admin</div>
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
                    <a href="#" class="icon-btn" title="Notifications"><i class="fas fa-bell"></i></a>
                    <?php if(($pending + $pending_users) > 0): ?><div class="notif-dot"></div><?php endif; ?>
                </div>

                <!-- Settings button -->
                <a href="#" class="icon-btn" id="settingsBtn" title="Settings" onclick="openSettings();return false;">
                    <i class="fas fa-sliders"></i>
                </a>
            </div>
        </div>

        <!-- Page -->
        <div class="page-content">
            <div class="page-heading">
                <div>
                    <div class="page-title">Operations Dashboard</div>
                    <div class="page-subtitle">All metrics are live — last refreshed <span id="last-refresh">just now</span></div>
                </div>
                <div class="actions-row">
                    <a href="transactions.php" class="btn btn-ghost"><i class="fas fa-receipt"></i> Transactions</a>
                    <a href="reports.php" class="btn btn-ghost"><i class="fas fa-download"></i> Export</a>
                    <a href="users.php?filter=pending" class="btn btn-primary"><i class="fas fa-user-check"></i> Review <?=(int)$pending_users?> Users</a>
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

            <!-- Quick Links -->
            <div class="quick-row">
                <a href="users.php" class="quick-card"><div class="stat-icon-wrap icon-blue"><i class="fas fa-users"></i></div><div class="quick-card-text"><h4>Manage Users</h4><p>Review registrations &amp; account status</p></div><div class="quick-arrow"><i class="fas fa-arrow-right"></i></div></a>
                <a href="manage_riders.php" class="quick-card"><div class="stat-icon-wrap icon-purple"><i class="fas fa-motorcycle"></i></div><div class="quick-card-text"><h4>Manage Riders</h4><p>Dispatch assignments &amp; tracking</p></div><div class="quick-arrow"><i class="fas fa-arrow-right"></i></div></a>
                <a href="transactions.php?filter=pending" class="quick-card"><div class="stat-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div><div class="quick-card-text"><h4>Pending Reviews</h4><p><?=(int)$pending?> transactions awaiting approval</p></div><div class="quick-arrow"><i class="fas fa-arrow-right"></i></div></a>
                <a href="reports.php" class="quick-card"><div class="stat-icon-wrap icon-aqua"><i class="fas fa-chart-bar"></i></div><div class="quick-card-text"><h4>Reports &amp; Analytics</h4><p>Export data &amp; view insights</p></div><div class="quick-arrow"><i class="fas fa-arrow-right"></i></div></a>
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

// Load settings on page load
function loadSettings() {
    if (!userSettings || Object.keys(userSettings).length === 0) return;
    
    // Dashboard Behavior
    if (userSettings.autorefresh !== undefined) {
        document.getElementById('tog-autorefresh').checked = userSettings.autorefresh;
    }
    if (userSettings.compact !== undefined) {
        document.getElementById('tog-compact').checked = userSettings.compact;
        if (userSettings.compact) document.body.classList.add('compact-tables');
    }
    if (userSettings.sparklines !== undefined) {
        document.getElementById('tog-sparklines').checked = userSettings.sparklines;
    }
    
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
    
    // Layout Spacing
    if (userSettings.layoutSpacing) {
        document.body.setAttribute('data-layout-spacing', userSettings.layoutSpacing);
    }
    
    // Border Radius
    if (userSettings.borderRadius) {
        document.body.setAttribute('data-border-radius', userSettings.borderRadius);
    }
    
    // Animations
    if (userSettings.pageTransitions !== undefined && !userSettings.pageTransitions) {
        document.body.style.animationDuration = '0s';
    }
    
    // Reduce Motion
    if (userSettings.reduceMotion) {
        document.body.classList.add('reduce-motion');
    }
    
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
    
    // Apply styles
    document.body.style.setProperty('--compact-padding', userSettings.layoutSpacing === 'compact' ? '8px' : userSettings.layoutSpacing === 'spacious' ? '24px' : '16px');
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
    const allSelects = document.querySelectorAll('.drawer-body select');
    const allCheckboxes = document.querySelectorAll('.drawer-body input[type="checkbox"]');
    
    const settings = {
        autorefresh: document.getElementById('tog-autorefresh').checked,
        compact: document.getElementById('tog-compact').checked,
        sparklines: document.getElementById('tog-sparklines').checked,
        themeColor: document.querySelector('.theme-swatch.selected')?.dataset.color || 'aqua',
        currency: allSelects[0]?.value || 'PHP',
        dateFormat: allSelects[1]?.value || 'MMM DD, YYYY',
        timeFormat: allSelects[2]?.value || '12-hour',
        layoutSpacing: allSelects[3]?.value || 'comfortable',
        borderRadius: allSelects[4]?.value || 'rounded',
        showPendingBadges: allCheckboxes[3]?.checked ?? true,
        collapsedByDefault: allCheckboxes[4]?.checked ?? false,
        pageTransitions: allCheckboxes[5]?.checked ?? true,
        chartAnimations: allCheckboxes[6]?.checked ?? true,
        reduceMotion: allCheckboxes[7]?.checked ?? false,
        inAppNotifications: allCheckboxes[8]?.checked ?? true,
        emailNotifications: allCheckboxes[9]?.checked ?? true,
        smsAlerts: allCheckboxes[10]?.checked ?? false,
        notifications: Array.from(document.querySelectorAll('.notif-item input[type="checkbox"]')).map(cb => cb.checked),
        pendingThreshold: allSelects[5]?.value || '10',
        notificationFreq: allSelects[6]?.value || 'Every 15 min',
        profileFirstName: document.getElementById('profileFirstName')?.value || '',
        profileLastName: document.getElementById('profileLastName')?.value || '',
        profileEmail: document.getElementById('profileEmail')?.value || '',
        profilePhone: document.getElementById('profilePhone')?.value || '',
        twoFactor: document.querySelectorAll('.drawer-tab')[3]?.querySelector('.settings-section:nth-child(2) .settings-row input[type="checkbox"]')?.checked ?? false,
        sessionTimeout: allSelects[7]?.value || '1 hour'
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
            s.style.display = 'flex';
            s.innerHTML = '<i class="fas fa-circle-check"></i> Saved!';
            setTimeout(() => s.style.display = 'none', 3000);
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
    document.getElementById('settingsBtn').classList.add('settings-active');
    document.body.style.overflow='hidden';
}
function closeSettings(){
    document.getElementById('settingsBackdrop').classList.remove('open');
    document.getElementById('settingsDrawer').classList.remove('open');
    document.getElementById('settingsBtn').classList.remove('settings-active');
    document.body.style.overflow='';
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeSettings(); });

function switchDrawerTab(el, targetId){
    document.querySelectorAll('.drawer-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(targetId).classList.add('active');
}

/* ── Theme swatches ── */
document.querySelectorAll('.theme-swatch').forEach(sw=>{
    sw.addEventListener('click',()=>{
        document.querySelectorAll('.theme-swatch').forEach(s=>s.classList.remove('selected'));
        sw.classList.add('selected');
        applyThemeColor(sw.dataset.color);
    });
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
                        action: 'save_settings',
                        settings: JSON.stringify({})
                    })
                }).then(() => {
                    location.reload();
                });
            }
        });
    }
});

// Load settings on page load
document.addEventListener('DOMContentLoaded', loadSettings);

// Auto-refresh functionality
let autoRefreshInterval = null;

function startAutoRefresh() {
    if (document.getElementById('tog-autorefresh')?.checked) {
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, 30000); // Refresh every 30 seconds
    }
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Start auto-refresh if enabled
if (userSettings.autorefresh) {
    startAutoRefresh();
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
    week:[12000,18500,14200,22000,19800,28000,31500],
    month:[8000,12000,9500,15000,11000,18000,14500,20000,17000,24000,21000,28000,19000,25000,22000,30000,26000,28500,24000,32000,28000,35000,30000,40000,35000,38000,32000,42000,38000,45000],
    year:[95000,110000,88000,125000,142000,138000,160000,155000,178000,192000,185000,210000],
};
const wkL=(['Mon','Tue','Wed','Thu','Fri','Sat','Sun']);
const moL=Array.from({length:30},(_,i)=>`${i+1}`);
const yrL=(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
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