<?php
include 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);

// Initialize variables to prevent undefined variable warnings
$riders = null;
$total_count = 0;
$active_count = 0;
$inactive_count = 0;
$error = null;
$success_message = '';
$editing_rider = null;

$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    age INT,
    address TEXT,
    contact_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS age INT");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS address TEXT");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50) NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) DEFAULT 'pending'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS assigned_rider VARCHAR(255) NULL");

// Handle rider actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    
    if ($action === 'add') {
        $username = sanitize(trim($_POST['username'] ?? ''));
        $name = sanitize(trim($_POST['full_name'] ?? ''));
        $age_raw = trim($_POST['age'] ?? '');
        $age = is_numeric($age_raw) ? (int) $age_raw : 0;
        $address = sanitize(trim($_POST['address'] ?? ''));
        $phone = sanitize(trim($_POST['contact_number'] ?? ''));
        $password = $_POST['password'] ?? '';
        
        if ($username === '' || $name === '' || $age <= 0 || $address === '' || $phone === '' || $password === '') {
            $error = 'All fields are required.';
        } else {
            $username_lookup = sensitive_lookup(htmlspecialchars_decode($username));
            $exists = $conn->query("SELECT rider_id FROM rider_users WHERE username_lookup = '$username_lookup' LIMIT 1");
            if ($exists && $exists->num_rows > 0) {
                $error = 'Username already exists.';
            } else {
                $rider_id = generateID('RID');
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $status = 'active';
                $enc_username = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($username)));
                $enc_name = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($name)));
                $enc_address = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($address)));
                $enc_phone = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($phone)));
                $contact_lookup = sensitive_lookup(htmlspecialchars_decode($phone));
                $sql = "INSERT INTO rider_users (rider_id, username, username_lookup, password, full_name, age, address, contact_number, contact_lookup, status)
                        VALUES ('$rider_id', '$enc_username', '$username_lookup', '$hashed_password', '$enc_name', $age, '$enc_address', '$enc_phone', '$contact_lookup', '$status')";
                if ($conn->query($sql)) {
                    $success_message = 'Rider account created successfully.';
                } else {
                    $error = 'Failed to create rider: ' . $conn->error;
                }
            }
        }
    } elseif ($action === 'update') {
        $rider_id = sanitize(trim($_POST['rider_id'] ?? ''));
        $username = sanitize(trim($_POST['username'] ?? ''));
        $name = sanitize(trim($_POST['full_name'] ?? ''));
        $age_raw = trim($_POST['age'] ?? '');
        $age = is_numeric($age_raw) ? (int) $age_raw : 0;
        $address = sanitize(trim($_POST['address'] ?? ''));
        $phone = sanitize(trim($_POST['contact_number'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($rider_id === '' || $username === '' || $name === '' || $age <= 0 || $address === '' || $phone === '') {
            $error = 'All fields except password are required for update.';
        } else {
            $username_lookup = sensitive_lookup(htmlspecialchars_decode($username));
            $exists = $conn->query("SELECT rider_id FROM rider_users WHERE username_lookup = '$username_lookup' AND rider_id != '$rider_id' LIMIT 1");
            if ($exists && $exists->num_rows > 0) {
                $error = 'Username already exists.';
            } else {
                $enc_username = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($username)));
                $enc_name = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($name)));
                $enc_address = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($address)));
                $enc_phone = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($phone)));
                $contact_lookup = sensitive_lookup(htmlspecialchars_decode($phone));
                $sql = "UPDATE rider_users SET username = '$enc_username', username_lookup = '$username_lookup', full_name = '$enc_name', age = $age, address = '$enc_address', contact_number = '$enc_phone', contact_lookup = '$contact_lookup'";
                if ($password !== '') {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql .= ", password = '$hashed_password'";
                }
                $sql .= " WHERE rider_id = '$rider_id'";

                if ($conn->query($sql)) {
                    $success_message = 'Rider account updated successfully.';
                } else {
                    $error = 'Failed to update rider: ' . $conn->error;
                }
            }
        }
    } elseif ($action === 'toggle') {
        $rider_id = sanitize($_POST['rider_id'] ?? '');
        $next_status = sanitize($_POST['next_status'] ?? '');
        if ($rider_id !== '' && ($next_status === 'active' || $next_status === 'inactive')) {
            if ($conn->query("UPDATE rider_users SET status = '$next_status' WHERE rider_id = '$rider_id'")) {
                $success_message = 'Rider status updated.';
            } else {
                $error = 'Failed to update rider status: ' . $conn->error;
            }
        }
    } elseif ($action === 'delete') {
        $rider_id = sanitize($_POST['rider_id'] ?? '');
        if ($rider_id !== '') {
            $active_jobs = $conn->query("SELECT COUNT(*) AS count FROM transactions WHERE rider_id = '$rider_id' AND delivery_status IN ('pending', 'on_way')");
            $active_job_count = 0;
            if ($active_jobs && $active_jobs->num_rows > 0) {
                $active_job_count = (int) $active_jobs->fetch_assoc()['count'];
            }

            if ($active_job_count > 0) {
                $error = 'Cannot delete rider with active assigned deliveries. Reassign or complete deliveries first.';
            } else {
                if ($conn->query("DELETE FROM rider_users WHERE rider_id = '$rider_id'")) {
                    $success_message = 'Rider account deleted successfully.';
                } else {
                    $error = 'Failed to delete rider: ' . $conn->error;
                }
            }
        }
    }
}

$edit_id = sanitize($_GET['edit'] ?? '');
if ($edit_id !== '') {
    $edit_res = $conn->query("SELECT rider_id, username, full_name, age, address, contact_number FROM rider_users WHERE rider_id = '$edit_id' LIMIT 1");
    if ($edit_res && $edit_res->num_rows > 0) {
        $editing_rider = $edit_res->fetch_assoc();
    }
}

$total_res = $conn->query("SELECT COUNT(*) AS total_count FROM rider_users");
if ($total_res && $total_res->num_rows > 0) {
    $total_count = (int) $total_res->fetch_assoc()['total_count'];
}

$active_res = $conn->query("SELECT COUNT(*) AS active_count FROM rider_users WHERE status = 'active'");
if ($active_res && $active_res->num_rows > 0) {
    $active_count = (int) $active_res->fetch_assoc()['active_count'];
}

$inactive_res = $conn->query("SELECT COUNT(*) AS inactive_count FROM rider_users WHERE status = 'inactive'");
if ($inactive_res && $inactive_res->num_rows > 0) {
    $inactive_count = (int) $inactive_res->fetch_assoc()['inactive_count'];
}

$riders = $conn->query("SELECT
        ru.rider_id,
        ru.username,
        ru.full_name,
        ru.age,
        ru.address,
        ru.contact_number,
        ru.status,
        ru.created_at,
        COUNT(t.transaction_id) AS deliveries
    FROM rider_users ru
    LEFT JOIN transactions t ON t.rider_id = ru.rider_id AND t.delivery_status = 'delivered'
    GROUP BY ru.rider_id, ru.username, ru.full_name, ru.age, ru.address, ru.contact_number, ru.status, ru.created_at
    ORDER BY ru.created_at DESC");

if (!$riders) {
    $error = 'Failed to load riders: ' . $conn->error;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Riders - HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/admin-sidebar-hover.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
:root {
    --bg:        #0d1117;
    --bg2:       #161b24;
    --bg3:       #1e2533;
    --border:    rgba(255,255,255,0.07);
    --border2:   rgba(255,255,255,0.12);
    --text:      #e8edf5;
    --muted:     #7a8a9e;
    --muted2:    #4e5c6e;
    --aqua:      #2dd4bf;
    --aqua-dim:  rgba(45,212,191,0.12);
    --blue:      #3b82f6;
    --blue-dim:  rgba(59,130,246,0.12);
    --amber:     #f59e0b;
    --amber-dim: rgba(245,158,11,0.12);
    --red:       #f43f5e;
    --red-dim:   rgba(244,63,94,0.12);
    --green:     #22c55e;
    --green-dim: rgba(34,197,94,0.12);
    --purple:    #a78bfa;
    --purple-dim:rgba(167,139,250,0.12);
    --sidebar-w: 260px;
    --radius:    14px;
    --radius-lg: 20px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 14px;
    -webkit-font-smoothing: antialiased;
}

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 99px; }

.shell {
    display: grid;
    grid-template-columns: var(--sidebar-w) 1fr;
    min-height: 100vh;
}

.sidebar {
    background: var(--bg2);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    padding: 28px 16px 24px;
    gap: 32px;
}

.brand {
    padding: 0 8px;
}
.brand-logo {
    display: flex;
    align-items: center;
    gap: 10px;
}
.brand-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: transparent;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; color: #fff;
    box-shadow: none;
    flex-shrink: 0;
}
.brand-name {
    font-size: 18px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.3px;
}
.brand-sub {
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1.2px;
    text-transform: uppercase;
    margin-top: 2px;
}

.nav-section-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--muted2);
    letter-spacing: 1.4px;
    text-transform: uppercase;
    padding: 0 12px;
    margin-bottom: 6px;
}

.nav-group { display: flex; flex-direction: column; gap: 2px; }

.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: var(--radius);
    color: var(--muted);
    text-decoration: none;
    font-weight: 500;
    transition: all .2s ease;
    position: relative;
}
.nav-item i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
.nav-item:hover { background: var(--bg3); color: var(--text); }
.nav-item.active {
    background: var(--bg3);
    color: var(--aqua);
    font-weight: 600;
}
.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--aqua);
    border-radius: 0 3px 3px 0;
}

.sidebar-footer {
    margin-top: auto;
}
.admin-card {
    display: flex; align-items: center; gap: 10px;
    padding: 12px;
    border-radius: var(--radius);
    background: var(--bg3);
}
.admin-avatar {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}
.admin-name { font-size: 13px; font-weight: 600; color: var(--text); }
.admin-role { font-size: 11px; color: var(--muted); margin-top: 1px; }
.logout-link {
    margin-left: auto;
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
    transition: color .2s;
}
.logout-link:hover { color: var(--red); }

.main { min-width: 0; display: flex; flex-direction: column; }

.topbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 16px 24px;
    border-bottom: 1px solid var(--border);
    background: var(--bg2);
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left { display: flex; align-items: center; gap: 12px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }
.breadcrumb span { color: var(--text); font-weight: 600; }
.topbar-right { display: flex; align-items: center; gap: 10px; }

.topbar-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 12px;
    color: var(--muted);
    background: transparent;
}

.live-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--aqua);
    animation: pulse 2s infinite;
}

.icon-btn {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--muted);
    text-decoration: none;
    transition: all .2s;
    background: transparent;
    cursor: pointer;
}
.icon-btn:hover { color: var(--text); border-color: var(--border2); }

.page-content { padding: 28px 32px; display: flex; flex-direction: column; gap: 24px; }

.page-title { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
.page-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: all .2s;
}

.card:hover {
    border-color: var(--border2);
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
}

.stat-card {
    display: flex;
    flex-direction: column;
}

.stat-value { margin-top: 12px; font-size: 24px; font-weight: 800; }
.stat-label { margin-top: 4px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }

.stat-icon-wrap {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}

.icon-aqua   { background: var(--aqua-dim);   color: var(--aqua); }
.icon-blue   { background: var(--blue-dim);   color: var(--blue); }
.icon-purple { background: var(--purple-dim); color: var(--purple); }
.icon-amber  { background: var(--amber-dim);  color: var(--amber); }
.icon-red    { background: var(--red-dim);    color: var(--red); }
.icon-green  { background: var(--green-dim);  color: var(--green); }

.form-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
}

.form-card h3 {
    margin: 0 0 16px;
    font-size: 18px;
    font-weight: 700;
    color: #fff;
}

.form-row {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}

.form-control {
    padding: 10px 12px;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 13px;
    transition: all .2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--aqua);
    box-shadow: 0 0 0 3px var(--aqua-dim);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    border: none;
    transition: all .2s;
}

.btn:hover { opacity: 0.88; transform: translateY(-1px); }
.soft-btn { background: var(--bg3); border: 1px solid var(--border); color: var(--text); }
.btn-primary { background: var(--aqua); color: #0d1117; }

.table-panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }

.data-table { width: 100%; border-collapse: collapse; }
.data-table thead th {
    padding: 12px 16px;
    background: var(--bg3);
    border-bottom: 1px solid var(--border);
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.data-table tbody tr:hover { background: var(--bg3); }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
.badge-active { background: var(--green-dim); color: var(--green); }
.badge-active::before { background: var(--green); }
.badge-inactive { background: var(--red-dim); color: var(--red); }
.badge-inactive::before { background: var(--red); }

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all .2s;
}

.btn-enable {
    background: var(--blue-dim);
    color: var(--blue);
}

.btn-enable:hover {
    filter: brightness(1.2);
    box-shadow: 0 4px 12px rgba(59,130,246,0.2);
}

.btn-disable {
    background: var(--red-dim);
    color: var(--red);
}

.btn-disable:hover {
    filter: brightness(1.2);
    box-shadow: 0 4px 12px rgba(244,63,94,0.2);
}

.success-message {
    background: var(--green-dim);
    border: 1px solid rgba(34,197,94,0.2);
    color: var(--green);
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
    animation: slideInDown 0.4s ease;
}

.error-message {
    background: var(--red-dim);
    border: 1px solid rgba(244,63,94,0.2);
    color: var(--red);
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
    animation: slideInDown 0.4s ease;
}

@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .form-row { flex-wrap: wrap; }
    .form-group { min-width: 150px; }
}

@media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; }
    .page-content { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr; }
    .form-row { flex-direction: column; }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
    </style>
    <script src="../js/ui-protection.js" defer></script>
    <link rel="stylesheet" href="../css/admin-theme.css">
    <script src="../js/admin-theme.js"></script>
</head>
<body>
<div class="shell">

    <!-- ── Sidebar ── -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">
                <div class="brand-icon"><img src="<?= htmlspecialchars(hydromis_asset_url($systemLogo, '../')) ?>" alt="Logo" style="width: 24px; height: 24px; object-fit: contain;"></div>
                <div>
                    <div class="brand-name">HydroMIS</div>
                    <div class="brand-sub">Admin</div>
                </div>
            </div>
        </div>

        <nav style="display:flex;flex-direction:column;gap:24px;">
            <div>
                <div class="nav-section-label">Main</div>
                <div class="nav-group">
                    <a href="dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
                    <a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i> Transactions</a>
                    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a>
                    <a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Inventory</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
                    <a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i> Staff Account</a>
                    <a href="manage_riders.php" class="nav-item active" style="position:relative;"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">System</div>
                <div class="nav-group">
                    <a href="activity_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
                    <a href="dashboard.php?open_settings=1" class="nav-item"><i class="fas fa-cog"></i> Settings</a>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?></div>
                <div>
                    <div class="admin-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
                <a href="../logout.php" class="logout-link" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>

    <!-- ── Main ── -->
    <main class="main">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="breadcrumb">
                    <i class="fas fa-home" style="font-size:12px;"></i>
                    <i class="fas fa-chevron-right" style="font-size:10px;opacity:.4;"></i>
                    <span>Riders</span>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-pill">
                    <div class="live-dot"></div>
                    Live Data
                </div>
            </div>
        </div>

        <!-- Page -->
        <div class="page-content">
            <!-- Heading -->
            <div style="margin-bottom: 24px;">
                <div class="page-title">🏍️ Rider Management</div>
                <div class="page-subtitle">Create and manage delivery rider accounts</div>
            </div>

                <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

            <!-- Stat Cards -->
            <div class="stats-grid">
                <div class="card stat-card">
                    <div style="display: flex; align-items: start; justify-content: space-between;">
                        <div class="stat-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Total</span>
                    </div>
                    <div class="stat-value"><?php echo (int)$total_count; ?></div>
                    <div class="stat-label">Total Riders</div>
                </div>

                <div class="card stat-card">
                    <div style="display: flex; align-items: start; justify-content: space-between;">
                        <div class="stat-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Active</span>
                    </div>
                    <div class="stat-value"><?php echo (int)$active_count; ?></div>
                    <div class="stat-label">Active</div>
                </div>

                <div class="card stat-card">
                    <div style="display: flex; align-items: start; justify-content: space-between;">
                        <div class="stat-icon-wrap icon-red"><i class="fas fa-ban"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Inactive</span>
                    </div>
                    <div class="stat-value"><?php echo (int)$inactive_count; ?></div>
                    <div class="stat-label">Inactive</div>
                </div>
            </div>

            <!-- Create / Edit Rider Form -->
            <div class="form-card">
                <h3>
                    <?php if ($editing_rider): ?>
                        <i class="fas fa-user-edit"></i> Edit Rider Account
                    <?php else: ?>
                        <i class="fas fa-user-plus"></i> Create Rider Account
                    <?php endif; ?>
                </h3>
                <form method="POST">
                    <?php if ($editing_rider): ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="rider_id" value="<?php echo htmlspecialchars($editing_rider['rider_id']); ?>">
                    <?php else: ?>
                        <input type="hidden" name="action" value="add">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($editing_rider['username'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label for="full_name">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($editing_rider['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group" style="flex: 0.8; min-width: 100px;">
                            <label for="age">Age</label>
                            <input type="number" class="form-control" id="age" name="age" min="18" max="70" value="<?php echo htmlspecialchars((string)($editing_rider['age'] ?? '')); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1.5; min-width: 150px;">
                            <label for="address">Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($editing_rider['address'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label for="contact_number">Contact Number</label>
                            <input type="tel" class="form-control" id="contact_number" name="contact_number" placeholder="" value="<?php echo htmlspecialchars($editing_rider['contact_number'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label for="password"><?php echo $editing_rider ? 'New Password (Optional)' : 'Password'; ?></label>
                            <input type="password" class="form-control" id="password" name="password" <?php echo $editing_rider ? '' : 'required'; ?>>
                        </div>
                        <div style="flex: 0.6; display: flex; align-items: flex-end; gap: 6px;">
                            <button type="submit" class="btn soft-btn" style="flex: 1;">
                                <?php if ($editing_rider): ?>
                                    <i class="fas fa-save"></i> Update
                                <?php else: ?>
                                    <i class="fas fa-save"></i> Create
                                <?php endif; ?>
                            </button>
                            <?php if ($editing_rider): ?>
                                <a href="manage_riders.php" class="btn soft-btn" style="flex: 1; text-decoration: none; margin: 0;">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Rider Accounts Table -->
            <div class="table-panel">
                <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700;"><i class="fas fa-motorcycle"></i> Rider Accounts</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Rider ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Age</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $has_riders = false;
                            if ($riders) {
                                while ($row = $riders->fetch_assoc()): 
                                    $has_riders = true;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['rider_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['age'] ?: '-')); ?></td>
                                <td><?php echo htmlspecialchars($row['address'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['contact_number'] ?: '-'); ?></td>
                                <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <a href="manage_riders.php?edit=<?php echo urlencode($row['rider_id']); ?>" class="btn-action btn-enable">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="rider_id" value="<?php echo htmlspecialchars($row['rider_id']); ?>">
                                            <input type="hidden" name="next_status" value="<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" name="action" value="toggle" class="btn-action <?php echo $row['status'] === 'active' ? 'btn-disable' : 'btn-enable'; ?>">
                                                <?php echo $row['status'] === 'active' ? '<i class="fas fa-ban"></i>' : '<i class="fas fa-check"></i>'; ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this rider account?');">
                                            <input type="hidden" name="rider_id" value="<?php echo htmlspecialchars($row['rider_id']); ?>">
                                            <button type="submit" name="action" value="delete" class="btn-action btn-disable">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            }
                            if (!$has_riders): 
                            ?>
                            <tr><td colspan="9" style="text-align: center; padding: 24px; color: var(--muted);">No rider accounts yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

</div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
</body>
</html>
