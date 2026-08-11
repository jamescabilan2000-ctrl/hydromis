<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);

// Get statistics for reports
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$approved_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='approved'")->fetch_assoc()['count'];
$pending_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'];
$denied_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='denied'")->fetch_assoc()['count'];

$total_transactions = $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'];
$approved_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved'")->fetch_assoc()['count'];
$pending_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending'")->fetch_assoc()['count'];
$denied_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied'")->fetch_assoc()['count'];

$total_sales = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE status='approved'")->fetch_assoc()['total'] ?? 0;

// Reward conversion reporting. Keep this available even on older installations
// where the rewards page has not yet been opened to initialize its table.
$conn->query("CREATE TABLE IF NOT EXISTS reward_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL UNIQUE,
    user_id VARCHAR(50) NOT NULL,
    reward_code VARCHAR(80) NOT NULL,
    reward_title VARCHAR(255) NOT NULL,
    points_used INT NOT NULL,
    claim_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    claimed_by VARCHAR(80) NULL,
    claimed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reward_claim_status (claim_status),
    INDEX idx_reward_claim_user (user_id)
)");

$reward_summary_result = $conn->query("SELECT
    COUNT(*) AS total_conversions,
    COALESCE(SUM(points_used), 0) AS total_points,
    SUM(claim_status = 'pending') AS pending_claims,
    SUM(claim_status = 'claimed') AS completed_claims
    FROM reward_claims");
$reward_summary = $reward_summary_result ? $reward_summary_result->fetch_assoc() : [];
$total_reward_conversions = (int)($reward_summary['total_conversions'] ?? 0);
$total_points_converted = (int)($reward_summary['total_points'] ?? 0);
$pending_reward_claims = (int)($reward_summary['pending_claims'] ?? 0);
$completed_reward_claims = (int)($reward_summary['completed_claims'] ?? 0);

$reward_conversions = $conn->query("SELECT rc.transaction_id, rc.user_id,
    rc.reward_title, rc.points_used, rc.claim_status, rc.created_at, rc.claimed_at,
    u.full_name, u.contact_number
    FROM reward_claims rc
    LEFT JOIN users u ON u.user_id = rc.user_id
    ORDER BY rc.created_at DESC
    LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - HydroMIS</title>
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
    grid-template-columns: repeat(4, minmax(0, 1fr));
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

.report-table-wrap { overflow-x: auto; }
.report-table { width: 100%; border-collapse: collapse; min-width: 760px; }
.report-table th {
    padding: 12px 14px; text-align: left; color: var(--muted); font-size: 10px;
    letter-spacing: .8px; text-transform: uppercase; border-bottom: 1px solid var(--border);
}
.report-table td { padding: 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.report-table tbody tr:last-child td { border-bottom: 0; }
.report-table tbody tr:hover { background: rgba(255,255,255,.02); }
.table-primary { color: var(--text); font-weight: 700; }
.table-secondary { color: var(--muted); font-size: 11px; margin-top: 3px; }
.points-value { color: var(--purple); font-weight: 800; white-space: nowrap; }
.claim-status {
    display: inline-flex; align-items: center; padding: 5px 9px; border-radius: 99px;
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px;
}
.claim-status.pending { color: var(--amber); background: var(--amber-dim); }
.claim-status.claimed { color: var(--green); background: var(--green-dim); }
.empty-report { padding: 36px 16px; text-align: center; color: var(--muted); }
.empty-report i { display: block; font-size: 28px; color: var(--purple); margin-bottom: 10px; }

@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; }
    .page-content { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr; }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
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
                <div class="brand-icon"><img src="../<?= htmlspecialchars($systemLogo) ?>" alt="Logo" style="width: 24px; height: 24px; object-fit: contain;"></div>
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
                    <a href="reports.php" class="nav-item active" style="position:relative;"><i class="fas fa-chart-bar"></i> Reports</a>
                    <a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Inventory</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
                    <a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i> Staff Account</a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
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
                    <span>Reports</span>
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
                <div class="page-title">📈 System Reports</div>
                <div class="page-subtitle">Comprehensive statistics and analysis</div>
            </div>

            <!-- User Statistics -->
            <div style="margin-bottom: 32px;">
                <h3 style="margin: 0 0 16px; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-users"></i> User Statistics</h3>
                <div class="stats-grid">
                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Total</span>
                        </div>
                        <div class="stat-value"><?php echo $total_users; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-green"><i class="fas fa-user-check"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Approved</span>
                        </div>
                        <div class="stat-value"><?php echo $approved_users; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Pending</span>
                        </div>
                        <div class="stat-value"><?php echo $pending_users; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-red"><i class="fas fa-times-circle"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Denied</span>
                        </div>
                        <div class="stat-value"><?php echo $denied_users; ?></div>
                        <div class="stat-label">Denied</div>
                    </div>
                </div>
            </div>

            <!-- Transaction Statistics -->
            <div style="margin-bottom: 32px;">
                <h3 style="margin: 0 0 16px; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-receipt"></i> Transaction Statistics</h3>
                <div class="stats-grid">
                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-blue"><i class="fas fa-receipt"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Total</span>
                        </div>
                        <div class="stat-value"><?php echo $total_transactions; ?></div>
                        <div class="stat-label">All Transactions</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Approved</span>
                        </div>
                        <div class="stat-value"><?php echo $approved_trans; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Pending</span>
                        </div>
                        <div class="stat-value"><?php echo $pending_trans; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>

                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-red"><i class="fas fa-times-circle"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Denied</span>
                        </div>
                        <div class="stat-value"><?php echo $denied_trans; ?></div>
                        <div class="stat-label">Denied</div>
                    </div>
                </div>
            </div>

            <!-- Financial Summary -->
            <div>
                <h3 style="margin: 0 0 16px; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-money-bill-wave"></i> Financial Summary</h3>
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                    <div class="card stat-card">
                        <div style="display: flex; align-items: start; justify-content: space-between;">
                            <div class="stat-icon-wrap icon-green"><i class="fas fa-money-bill-wave"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Revenue</span>
                        </div>
                        <div class="stat-value">₱<?php echo number_format($total_sales, 0); ?></div>
                        <div class="stat-label">Total Approved Sales</div>
                    </div>
                </div>
            </div>

            <!-- Rewards and Point Conversions -->
            <div style="margin-top: 32px;">
                <h3 style="margin: 0 0 16px; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-gift"></i> Rewards &amp; Point Conversions</h3>
                <div class="stats-grid">
                    <div class="card stat-card">
                        <div style="display:flex;align-items:start;justify-content:space-between;">
                            <div class="stat-icon-wrap icon-purple"><i class="fas fa-right-left"></i></div>
                            <span style="font-size:11px;color:var(--muted);">All time</span>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_reward_conversions); ?></div>
                        <div class="stat-label">Reward Conversions</div>
                    </div>
                    <div class="card stat-card">
                        <div style="display:flex;align-items:start;justify-content:space-between;">
                            <div class="stat-icon-wrap icon-blue"><i class="fas fa-coins"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Redeemed</span>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_points_converted); ?></div>
                        <div class="stat-label">Points Converted</div>
                    </div>
                    <div class="card stat-card">
                        <div style="display:flex;align-items:start;justify-content:space-between;">
                            <div class="stat-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div>
                            <span style="font-size:11px;color:var(--muted);">For collection</span>
                        </div>
                        <div class="stat-value"><?php echo number_format($pending_reward_claims); ?></div>
                        <div class="stat-label">Pending Rewards</div>
                    </div>
                    <div class="card stat-card">
                        <div style="display:flex;align-items:start;justify-content:space-between;">
                            <div class="stat-icon-wrap icon-green"><i class="fas fa-circle-check"></i></div>
                            <span style="font-size:11px;color:var(--muted);">Released</span>
                        </div>
                        <div class="stat-value"><?php echo number_format($completed_reward_claims); ?></div>
                        <div class="stat-label">Claimed Rewards</div>
                    </div>
                </div>

                <div class="card" style="padding:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--border);">
                        <div>
                            <div style="font-weight:800;">Recent user conversions</div>
                            <div class="table-secondary">Latest 50 reward redemptions</div>
                        </div>
                        <i class="fas fa-clock-rotate-left" style="color:var(--muted);"></i>
                    </div>
                    <?php if ($reward_conversions && $reward_conversions->num_rows > 0): ?>
                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead><tr><th>User</th><th>Reward</th><th>Points</th><th>Converted</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php while ($conversion = $reward_conversions->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="table-primary"><?php echo htmlspecialchars($conversion['full_name'] ?: 'Unknown user'); ?></div>
                                        <div class="table-secondary"><?php echo htmlspecialchars($conversion['contact_number'] ?: $conversion['user_id']); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-primary"><?php echo htmlspecialchars($conversion['reward_title']); ?></div>
                                        <div class="table-secondary"><?php echo htmlspecialchars($conversion['transaction_id']); ?></div>
                                    </td>
                                    <td><span class="points-value"><?php echo number_format((int)$conversion['points_used']); ?> pts</span></td>
                                    <td>
                                        <div class="table-primary"><?php echo date('M d, Y', strtotime($conversion['created_at'])); ?></div>
                                        <div class="table-secondary"><?php echo date('h:i A', strtotime($conversion['created_at'])); ?></div>
                                    </td>
                                    <td><span class="claim-status <?php echo $conversion['claim_status'] === 'claimed' ? 'claimed' : 'pending'; ?>"><?php echo htmlspecialchars($conversion['claim_status']); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-report"><i class="fas fa-gift"></i>No users have converted points into rewards yet.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
</body>
</html>
