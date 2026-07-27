<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Handle approve/deny user accounts
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $user_id = sanitize($_POST['user_id']);
    $action = sanitize($_POST['action']);
    $status = ($action == 'approve') ? 'approved' : 'denied';
    
    $sql = "UPDATE users SET status = '$status' WHERE user_id = '$user_id'";
    if ($conn->query($sql) === TRUE) {
        $success = 'User status updated successfully!';
    }
}

// Get all users
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$approved_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='approved'")->fetch_assoc()['count'];
$pending_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'];
$denied_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='denied'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
    background: linear-gradient(135deg, #1e9e8f, #0e6d7a);
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; color: #fff;
    box-shadow: 0 4px 14px rgba(45,212,191,0.3);
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
.nav-badge {
    margin-left: auto;
    background: var(--red-dim);
    color: var(--red);
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 99px;
}

.sidebar-footer {
    margin-top: auto;
}
.admin-card {
    display: flex; align-items: center; gap: 10px;
    padding: 12px;
    border-radius: var(--radius);
    background: var(--bg3);
    transition: background .2s;
}
.admin-card:hover { background: var(--border2); }
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

.page-heading {
    display: flex; align-items: start; justify-content: space-between;
}
.page-title { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
.page-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }

.actions-row { display: flex; gap: 8px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
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
.btn-ghost { background: var(--bg3); border: 1px solid var(--border); color: var(--text); }
.btn-primary { background: var(--aqua); color: #0d1117; }
.btn-danger  { background: var(--red-dim); border: 1px solid rgba(244,63,94,0.2); color: var(--red); }

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

.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.10s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.20s; }

@keyframes fadeSlideUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.stat-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 120px; height: 120px;
    border-radius: 50%;
    opacity: 0.08;
    pointer-events: none;
}

.stat-aqua::after  { background: var(--aqua); }
.stat-blue::after  { background: var(--blue); }
.stat-purple::after{ background: var(--purple); }
.stat-amber::after { background: var(--amber); }

.stat-top { display: flex; align-items: flex-start; justify-content: space-between; }
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

.stat-value { margin-top: 12px; font-size: 24px; font-weight: 800; }
.stat-label { margin-top: 4px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }

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
    background: var(--green-dim);
    color: var(--green);
}

.btn-enable:hover {
    filter: brightness(1.2);
    box-shadow: 0 4px 12px rgba(34,197,94,0.2);
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

@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
    </style>
</head>
<body>
<div class="shell">

    <!-- ── Sidebar ── -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">
                <div class="brand-icon"><img src="../imagess/logosystem.png" alt="Logo" style="width: 24px; height: 24px; object-fit: contain;"></div>
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
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item active" style="position:relative;"><i class="fas fa-users"></i> Users<?php if($pending_users > 0): ?><span class="nav-badge"><?= (int)$pending_users ?></span><?php endif; ?></a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?></div>
                <div>
                    <div class="admin-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></div>
                    <div class="admin-role">Super Admin</div>
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
                    <span>Manage Users</span>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-pill">
                    <div class="live-dot"></div>
                    Live Data
                </div>
                <a href="reports.php" class="icon-btn" title="Reports"><i class="fas fa-chart-bar"></i></a>
            </div>
        </div>

        <!-- Page -->
        <div class="page-content">

            <!-- Heading -->
            <div class="page-heading">
                <div>
                    <div class="page-title">👥 User Management</div>
                    <div class="page-subtitle">Review and approve customer accounts</div>
                </div>
                <div class="actions-row">
                    <a href="reports.php" class="btn btn-ghost"><i class="fas fa-chart-bar"></i> Reports</a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <div class="stats-grid">
                <div class="card stat-card stat-blue">
                    <div class="stat-top">
                        <div class="stat-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Total</span>
                    </div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>

                <div class="card stat-card stat-green">
                    <div class="stat-top">
                        <div class="stat-icon-wrap icon-green"><i class="fas fa-user-check"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Active</span>
                    </div>
                    <div class="stat-value"><?php echo $approved_users; ?></div>
                    <div class="stat-label">Approved</div>
                </div>

                <div class="card stat-card stat-amber">
                    <div class="stat-top">
                        <div class="stat-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Review</span>
                    </div>
                    <div class="stat-value"><?php echo $pending_users; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>

                <div class="card stat-card stat-red">
                    <div class="stat-top">
                        <div class="stat-icon-wrap icon-red"><i class="fas fa-times-circle"></i></div>
                        <span style="font-size:11px;color:var(--muted);">Denied</span>
                    </div>
                    <div class="stat-value"><?php echo $denied_users; ?></div>
                    <div class="stat-label">Denied</div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-panel">
                <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700;"><i class="fas fa-list"></i> User Accounts</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Date Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $users->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['user_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <?php if ($row['status'] == 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($row['user_id']); ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn-action btn-enable" title="Approve">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($row['user_id']); ?>">
                                                <input type="hidden" name="action" value="deny">
                                                <button type="submit" class="btn-action btn-disable" title="Deny">
                                                    <i class="fas fa-times"></i> Deny
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
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
