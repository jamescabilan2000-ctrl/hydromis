<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);

// Filter parameters
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_method = isset($_GET['method']) ? $_GET['method'] : 'all';

// Build WHERE clauses
$where = [];
$stat_where = [];
if ($filter_date) {
    $safe_date = $conn->real_escape_string($filter_date);
    $where[] = "DATE(t.created_at) = '$safe_date'";
    $stat_where[] = "DATE(created_at) = '$safe_date'";
}
if ($filter_method && $filter_method !== 'all') {
    $safe_method = $conn->real_escape_string($filter_method);
    $where[] = "t.payment_method = '$safe_method'";
    $stat_where[] = "payment_method = '$safe_method'";
}

$where_sql = count($where) > 0 ? ' AND ' . implode(' AND ', $where) : '';
$stat_where_sql = count($stat_where) > 0 ? ' AND ' . implode(' AND ', $stat_where) : '';

// Get filtered transactions
$transactions = $conn->query("
    SELECT t.*, u.full_name, u.contact_number
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    WHERE 1=1 $where_sql
    ORDER BY t.created_at DESC
");

$total_transactions = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE 1=1 $stat_where_sql")->fetch_assoc()['count'];
$approved_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved' $stat_where_sql")->fetch_assoc()['count'];
$pending_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending' $stat_where_sql")->fetch_assoc()['count'];
$denied_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied' $stat_where_sql")->fetch_assoc()['count'];
$total_sales = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE status='approved' $stat_where_sql")->fetch_assoc()['total'] ?? 0;

// Labels for active filter
$method_labels = ['all' => 'All Methods', 'cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya'];
$active_method_label = $method_labels[$filter_method] ?? 'All Methods';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Reports - HydroMIS</title>
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
.badge-approved { background: var(--green-dim); color: var(--green); }
.badge-approved::before { background: var(--green); }
.badge-pending  { background: var(--amber-dim); color: var(--amber); }
.badge-pending::before  { background: var(--amber); }
.badge-denied   { background: var(--red-dim);   color: var(--red); }
.badge-denied::before   { background: var(--red); }

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

/* ── Filter Bar ── */
.filter-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.filter-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.filter-date-input {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    padding: 8px 12px;
    font-size: 13px;
    font-family: inherit;
    font-weight: 600;
    outline: none;
    transition: border-color 0.2s;
    cursor: pointer;
}
.filter-date-input:hover,
.filter-date-input:focus { border-color: var(--aqua); }
.filter-date-input::-webkit-calendar-picker-indicator {
    filter: invert(0.7);
    cursor: pointer;
}
.method-tabs {
    display: flex;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    padding: 3px;
    gap: 2px;
}
.method-tab {
    padding: 7px 16px;
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
    border: none;
    background: transparent;
    border-radius: 9px;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    text-decoration: none;
}
.method-tab:hover { color: var(--text); background: var(--bg2); }
.method-tab.active {
    background: var(--aqua);
    color: #0d1117;
    box-shadow: 0 2px 8px rgba(45,212,191,0.25);
}
.method-tab .tab-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.method-tab .tab-dot.dot-all    { background: var(--text); }
.method-tab .tab-dot.dot-cash   { background: var(--green); }
.method-tab .tab-dot.dot-gcash  { background: var(--blue); }
.method-tab .tab-dot.dot-maya   { background: #22c55e; }
.method-tab.active .tab-dot { background: #0d1117; }
.filter-clear {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    text-decoration: none;
}
.filter-clear:hover { color: var(--red); border-color: var(--red); }
.filter-active-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    font-size: 11px;
    font-weight: 700;
    color: var(--aqua);
    background: var(--aqua-dim);
    border-radius: 20px;
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
                    <a href="transactions.php" class="nav-item active" style="position:relative;"><i class="fas fa-exchange-alt"></i> Transactions</a>
                    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a>
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
                <div class="admin-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($_SESSION['avatar_path']) && file_exists('../' . $_SESSION['avatar_path'])): ?>
                        <img src="../<?= htmlspecialchars($_SESSION['avatar_path']) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?>
                    <?php endif; ?>
                </div>
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
                    <span>Transactions</span>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-pill">
                    <div class="live-dot"></div>
                </div>
            </div>
        </div>

        <!-- Page -->
        <div class="page-content">

            <!-- Heading -->
            <div style="margin-bottom: 20px;">
                <div class="page-title">Transactions</div>
                <div class="page-subtitle">View and manage all customer transactions</div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <span class="filter-label"><i class="fas fa-calendar-day"></i> Date</span>
                    <input type="date" class="filter-date-input" id="filterDate" value="<?= htmlspecialchars($filter_date) ?>" onchange="applyFilters()">
                </div>
                <div class="filter-group">
                    <span class="filter-label"><i class="fas fa-wallet"></i> Method</span>
                    <div class="method-tabs" id="methodTabs">
                        <?php
                        $methods = [
                            'all'   => ['label' => 'All',    'dot' => 'dot-all'],
                            'cash'  => ['label' => 'Cash',   'dot' => 'dot-cash'],
                            'gcash' => ['label' => 'GCash',  'dot' => 'dot-gcash'],
                            'maya'  => ['label' => 'Maya',   'dot' => 'dot-maya']
                        ];
                        foreach ($methods as $key => $m):
                            $isActive = ($filter_method === $key) ? 'active' : '';
                        ?>
                        <button type="button" class="method-tab <?= $isActive ?>" data-method="<?= $key ?>" onclick="selectMethod('<?= $key ?>')">
                            <span class="tab-dot <?= $m['dot'] ?>"></span> <?= $m['label'] ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($filter_date || $filter_method !== 'all'): ?>
                <div class="filter-group" style="margin-left:auto;">
                    <?php if ($filter_date): ?>
                    <span class="filter-active-tag"><i class="fas fa-calendar-check"></i> <?= date('M j, Y', strtotime($filter_date)) ?></span>
                    <?php endif; ?>
                    <?php if ($filter_method !== 'all'): ?>
                    <span class="filter-active-tag"><i class="fas fa-wallet"></i> <?= $active_method_label ?></span>
                    <?php endif; ?>
                    <a href="transactions.php" class="filter-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stat Cards -->
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
                    <div class="stat-label">Pending Review</div>
                </div>

                <div class="card stat-card">
                    <div style="display: flex; align-items: start; justify-content: space-between;">
                        <div class="stat-icon-wrap icon-aqua"><i class="fas fa-peso-sign"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Revenue</span>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($total_sales, 0); ?></div>
                    <div class="stat-label">Total Sales</div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-panel">
                <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700;"><i class="fas fa-list"></i>
                        <?php if ($filter_date || $filter_method !== 'all'): ?>
                            Filtered Transactions
                            <span style="font-size:12px;color:var(--muted);font-weight:500;margin-left:6px;">
                                (<?= (int)$total_transactions ?> result<?= $total_transactions == 1 ? '' : 's' ?>)
                            </span>
                        <?php else: ?>
                            All Transactions
                        <?php endif; ?>
                    </h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Customer Name</th>
                                <th>Contact</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['transaction_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                <td>
                                    ₱ <?php echo number_format($row['amount'], 2); ?>
                                    <?php if (!empty($row['payment_method']) && $row['payment_method'] !== 'cash'): ?>
                                        <div style="font-size: 11px; margin-top: 4px;">
                                            <span class="badge badge-info" style="font-size: 10px; background: var(--blue-dim); color: var(--blue);"><?php echo strtoupper($row['payment_method']); ?></span>
                                            <?php if (!empty($row['payment_reference'])): ?>
                                                <div style="color: var(--muted); font-size: 10px; margin-top: 2px;">Ref: <?php echo htmlspecialchars($row['payment_reference']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['payment_proof'])): ?>
                                                <a href="../<?php echo htmlspecialchars($row['payment_proof']); ?>" target="_blank" rel="noopener" style="color: var(--aqua); font-size: 11px; font-weight: 700; display: inline-block; margin-top: 4px; text-decoration: none;"><i class="fas fa-image"></i> View Proof</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['description']); ?>
                                    <?php if (!empty($row['container_size'])): ?>
                                    <div style="margin-top:5px;font-size:10px;color:var(--muted);">
                                        <i class="fas fa-box"></i> <?php echo ($row['container_status'] ?? '') === 'new' ? 'New container' : 'Customer container'; ?>
                                        &nbsp;·&nbsp; <i class="fas <?php echo ($row['fulfillment_method'] ?? '') === 'pickup' ? 'fa-store' : 'fa-truck'; ?>"></i>
                                        <?php echo ($row['fulfillment_method'] ?? '') === 'pickup' ? 'Self pickup' : 'Delivery'; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (trim((string)($row['notes'] ?? '')) !== ''): ?>
                                    <div style="margin-top:8px;padding:8px 10px;border-left:3px solid var(--aqua);border-radius:7px;background:var(--aqua-dim);color:var(--text);font-size:11px;line-height:1.45;white-space:normal;">
                                        <strong style="display:block;margin-bottom:3px;color:var(--aqua);"><i class="fas fa-message"></i> Customer instructions</strong>
                                        <?php echo nl2br(htmlspecialchars($row['notes'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

</div>

<script>
function applyFilters() {
    const date = document.getElementById('filterDate').value;
    const activeTab = document.querySelector('.method-tab.active');
    const method = activeTab ? activeTab.dataset.method : 'all';
    const params = new URLSearchParams();
    if (date) params.set('date', date);
    if (method && method !== 'all') params.set('method', method);
    const qs = params.toString();
    window.location.href = 'transactions.php' + (qs ? '?' + qs : '');
}

function selectMethod(method) {
    document.querySelectorAll('.method-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.method-tab[data-method="' + method + '"]').classList.add('active');
    applyFilters();
}
</script>
</body>
</html>
