<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['transaction_id'])) {
    $action = strtolower(sanitize($_POST['action']));
    $transaction_id = sanitize($_POST['transaction_id']);
    $staff_id = isset($_SESSION['admin_id']) ? sanitize($_SESSION['admin_id']) : '';

    if ($action === 'approve') {
        [$approved, $message] = approve_or_cancel_order_for_stock($conn, $transaction_id, $staff_id);
        $_SESSION['staff_flash'] = $approved ? "Transaction {$transaction_id} approved successfully." : $message;
    } elseif ($action === 'deny') {
        $ok = deny_order_and_release_stock($conn, $transaction_id, $staff_id, 'The staff could not proceed with your order. Please contact the shop for assistance.');
        $_SESSION['staff_flash'] = $ok ? "Transaction {$transaction_id} denied and reserved stock returned." : "Unable to deny transaction {$transaction_id}.";
    }

    header('Location: pending.php');
    exit();
}

$pending_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number, u.qr_code_path
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'pending'
    ORDER BY t.created_at ASC
");

$total_transactions = 0;
$total_result = $conn->query("SELECT COUNT(*) AS total_count FROM transactions");
if ($total_result && $total_result->num_rows > 0) {
    $total_row = $total_result->fetch_assoc();
    $total_transactions = (int)($total_row['total_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="../css/staff-sidebar.css" rel="stylesheet">
    <link href="../css/staff-sidebar-size.css" rel="stylesheet">
    <link href="../css/staff-pages-unified.css" rel="stylesheet">
    <style>
/* ─── TOKENS ─────────────────────────────────────────────── */
:root {
    --bg: #0b1120;
    --bg-2: #0f1829;
    --surface: #131d30;
    --surface-2: #1a2847;
    --border: rgba(255,255,255,.07);
    --border-2: rgba(255,255,255,.12);
    --text: #e2efff;
    --muted: #7b90b4;
    --accent: #3b82f6;
    --green: #10b981;
    --amber: #f59e0b;
    --red: #ef4444;
    --purple: #a855f7;
    --accent-glow: rgba(59,130,246,.15);
    --green-glow: rgba(16,185,129,.15);
    --amber-glow: rgba(245,158,11,.15);
    --red-glow: rgba(239,68,68,.15);
    --purple-glow: rgba(168,85,247,.15);
    --shadow: 0 18px 40px rgba(0,0,0,.3);
    --transition: all .2s ease;
    --font-head: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'DM Sans', sans-serif;
}

/* ─── RESET ──────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: var(--font-body);
    background: linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
    color: var(--text);
    line-height: 1.5;
}

/* ─── LAYOUT ─────────────────────────────────────────────── */
.layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 100vh;
}

/* ─── SIDEBAR ────────────────────────────────────────────── */
.sidebar {
    background: linear-gradient(180deg, #132f4a 0%, #11253b 100%);
    padding: 28px 22px;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}

.sidebar h1 {
    margin: 0;
    font-family: var(--font-head);
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -1px;
    color: #fff;
}

.sidebar p {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(226, 239, 255, 0.7);
}

.sidebar nav {
    flex: 1;
    margin-top: 34px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.nav-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.nav-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(226, 239, 255, 0.5);
    padding: 0 16px;
}

.sidebar nav a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 12px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: var(--transition);
    position: relative;
}

.sidebar nav a:hover { background: rgba(255,255,255,.1); color: #fff; }
.sidebar nav a.active {
    background: #1c5075;
    color: #fff;
    font-weight: 700;
}

.sidebar nav a .badge {
    margin-left: auto;
    background: #ef4444;
    color: white;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

/* ─── MAIN ───────────────────────────────────────────────── */
.main { display: flex; flex-direction: column; }

.topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 0 28px;
    height: 64px;
    background: rgba(11,17,32,.88);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
}

.topbar-title {
    font-family: var(--font-head);
    font-size: 16px;
    font-weight: 700;
    letter-spacing: -0.4px;
}

.topbar-subtitle {
    margin-top: 2px;
    color: var(--muted);
    font-size: 12px;
}

.topbar-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    color: var(--muted);
    font-size: 13px;
}

.logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
}

.logout-btn:hover {
    background: var(--surface-2);
    border-color: var(--border-2);
}

/* ─── PAGE ───────────────────────────────────────────────── */
.page { padding: 28px; }

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 22px;
    margin-bottom: 28px;
}

.page-header h2 {
    margin: 0;
    font-family: var(--font-head);
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -1px;
}

.page-header p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 13px;
}

.header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.soft-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: var(--transition);
}

.soft-btn:hover {
    background: var(--surface-2);
    border-color: var(--border-2);
}

/* ─── CARD ───────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
}

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    font-family: var(--font-head);
    font-size: 14px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.card-meta {
    font-size: 12px;
    color: var(--muted);
}

/* ─── TABLE ──────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
}

thead th {
    padding: 16px 20px;
    background: rgba(255,255,255,.02);
    border-bottom: 1px solid var(--border);
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
}

tbody td {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    font-size: 13px;
}

tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: rgba(255,255,255,.025); }

/* ─── BADGES ─────────────────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
}

.badge::before {
    content: '';
    width: 4px;
    height: 4px;
    border-radius: 50%;
    margin-right: 6px;
}

.badge-pending {
    background: var(--amber-glow);
    color: var(--amber);
    border: 1px solid rgba(245,158,11,.3);
}

.badge-pending::before { background: var(--amber); }

.badge-approved, .badge-delivered {
    background: var(--green-glow);
    color: var(--green);
    border: 1px solid rgba(16,185,129,.3);
}

.badge-approved::before, .badge-delivered::before { background: var(--green); }

.badge-denied {
    background: var(--red-glow);
    color: var(--red);
    border: 1px solid rgba(239,68,68,.3);
}

.badge-denied::before { background: var(--red); }

/* ─── ALERT BANNER ───────────────────────────────────────── */
.alert-pending {
    background: linear-gradient(135deg, rgba(245,158,11,.15) 0%, rgba(245,158,11,.08) 100%);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 28px;
}

.alert-pending-content {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.alert-pending-icon {
    color: var(--amber);
    font-size: 20px;
    flex-shrink: 0;
}

.alert-pending strong {
    color: var(--amber);
    font-weight: 700;
    font-size: 14px;
}

.alert-pending-btn {
    background: linear-gradient(135deg, var(--amber) 0%, #d97706 100%);
    color: #000;
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
    flex-shrink: 0;
}

.alert-pending-btn:hover { filter: brightness(1.1); }

/* ─── WELCOME SECTION ────────────────────────────────────── */
.welcome-section {
    margin-bottom: 32px;
}

.welcome-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.welcome-content h1 {
    margin: 0;
    font-family: var(--font-head);
    font-size: 36px;
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1.2;
}

.welcome-content p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 14px;
}

.welcome-actions {
    display: flex;
    gap: 10px;
}

.primary-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    background: linear-gradient(135deg, var(--accent) 0%, #1d4ed8 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: var(--transition);
}

.primary-btn:hover { filter: brightness(1.1); }

/* ─── QUICK ACTIONS GRID ─────────────────────────────────── */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.quick-card {
    background: linear-gradient(135deg, var(--surface) 0%, rgba(26,40,71,.8) 100%);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    cursor: pointer;
    transition: var(--transition);
}

.quick-card:hover {
    border-color: var(--border-2);
    transform: translateY(-4px);
    background: linear-gradient(135deg, rgba(26,40,71,.9) 0%, rgba(26,40,71,1) 100%);
}

.quick-icon {
    width: 48px;
    height: 48px;
    background: var(--accent-glow);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-size: 24px;
    flex-shrink: 0;
}

.quick-card.amber .quick-icon {
    background: var(--amber-glow);
    color: var(--amber);
}

.quick-card.green .quick-icon {
    background: var(--green-glow);
    color: var(--green);
}

.quick-copy h3 {
    margin: 0;
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
}

.quick-copy p {
    margin: 6px 0 0;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.4;
}

/* ─── STATS ROW ──────────────────────────────────────────── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}

.stat-card {
    background: linear-gradient(135deg, var(--surface) 0%, rgba(26,40,71,.8) 100%);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.stat-card:hover {
    border-color: var(--border-2);
    transform: translateY(-2px);
}

.stat-icon {
    width: 40px;
    height: 40px;
    background: var(--accent-glow);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-size: 18px;
    flex-shrink: 0;
}

.stat-card.green .stat-icon {
    background: var(--green-glow);
    color: var(--green);
}

.stat-card.amber .stat-icon {
    background: var(--amber-glow);
    color: var(--amber);
}

.stat-content h4 {
    margin: 0;
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 4px;
}

.stat-label {
    font-size: 11px;
    color: var(--muted);
    margin-top: 2px;
}

.btn-approve,
.btn-deny {
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
}

.btn-approve {
    background: linear-gradient(135deg, var(--green) 0%, #059669 100%);
}

.btn-approve:hover { filter: brightness(1.1); }

.btn-deny {
    background: linear-gradient(135deg, var(--red) 0%, #dc2626 100%);
}

.btn-deny:hover { filter: brightness(1.1); }

/* ─── EMPTY STATE ────────────────────────────────────────── */
.empty-state {
    padding: 40px 20px;
    text-align: center;
    color: var(--muted);
}

.empty-state i {
    font-size: 32px;
    margin-bottom: 12px;
    display: block;
    opacity: .4;
}

/* ─── SCROLLBAR ──────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--surface); }
::-webkit-scrollbar-thumb { background: var(--surface-2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 768px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar {
        position: static;
        height: auto;
        border-bottom: 1px solid var(--border);
    }
    .topbar, .page { padding: 16px; }
    .page-header { flex-direction: column; }
    .header-actions { width: 100%; }
    .soft-btn { width: 100%; }
    thead { display: none; }
    table, tbody, tr, td { display: block; width: 100%; }
    tbody tr { border-top: 1px solid var(--border); }
    tbody td { padding: 12px 16px; border-top: none; }
    .action-buttons { flex-direction: column; width: 100%; }
    .btn-approve, .btn-deny { width: 100%; }
}
    </style>
    <link href="../css/staff-sidebar.css" rel="stylesheet">
    <link href="../css/staff-sidebar-size.css" rel="stylesheet">
    <link href="../css/staff-pages-unified.css" rel="stylesheet">
</head>
<body>
    <div class="layout">
        <?php $staff_active = 'pending'; include 'sidebar.php'; ?>

        <main class="main">
            <div class="topbar">
                <div>
                    <div class="topbar-title">Order Approvals</div>
                    <div class="topbar-subtitle">Review pending orders before they enter the delivery or pickup queue</div>
                </div>
            </div>

            <section class="page">
                <?php $pending_count = $pending_trans ? $pending_trans->num_rows : 0; ?>
                <?php if (!empty($_SESSION['staff_flash'])): ?>
                <div class="alert-pending" style="background: linear-gradient(135deg, rgba(16,185,129,.15) 0%, rgba(16,185,129,.08) 100%); border-color: rgba(16,185,129,.3);">
                    <div class="alert-pending-content">
                        <div class="alert-pending-icon" style="color: var(--green);"><i class="fas fa-check-circle"></i></div>
                        <strong><?php echo htmlspecialchars($_SESSION['staff_flash']); unset($_SESSION['staff_flash']); ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card amber">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-content">
                            <h4>Pending Orders</h4>
                            <div class="stat-value"><?php echo $pending_count; ?></div>
                            <div class="stat-label">require staff review</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                        <div class="stat-content">
                            <h4>Total Transactions</h4>
                            <div class="stat-value"><?php echo $total_transactions; ?></div>
                            <div class="stat-label">all time</div>
                        </div>
                    </div>
                </div>

                <!-- Approval Queue Table -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-list-check"></i> Orders Awaiting Review</span>
                        <span class="card-meta"><?php echo $pending_count; ?> order<?php echo $pending_count === 1 ? '' : 's'; ?> waiting</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pending_trans && $pending_trans->num_rows > 0): ?>
                                    <?php while ($row = $pending_trans->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['transaction_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                        <td>
                                            ₱<?php echo number_format((float)$row['amount'], 2); ?>
                                            <?php if (!empty($row['payment_method']) && $row['payment_method'] !== 'cash'): ?>
                                                <div style="font-size: 11px; margin-top: 4px;">
                                                    <span class="badge badge-info" style="font-size: 10px;"><?php echo strtoupper($row['payment_method']); ?></span>
                                                    <?php if (!empty($row['payment_reference'])): ?>
                                                        <div class="text-muted" style="font-size: 10px; margin-top: 2px;">Ref: <?php echo htmlspecialchars($row['payment_reference']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['payment_proof'])): ?>
                                                        <a href="../<?php echo htmlspecialchars($row['payment_proof']); ?>" target="_blank" rel="noopener" class="text-success" style="font-size: 11px; font-weight: 700; display: inline-block; margin-top: 4px;"><i class="fas fa-image"></i> View Proof</a>
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
                                            <div style="margin-top:8px;padding:8px 10px;border-left:3px solid var(--accent);border-radius:7px;background:rgba(59,130,246,.10);color:var(--text);font-size:11px;line-height:1.45;white-space:normal;">
                                                <strong style="display:block;margin-bottom:3px;color:#7db5ff;"><i class="fas fa-message"></i> Customer instructions</strong>
                                                <?php echo nl2br(htmlspecialchars($row['notes'])); ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" action="pending.php">
                                                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn-approve" onclick="return confirm('Approve this transaction?')">Approve</button>
                                                </form>
                                                <form method="POST" action="pending.php">
                                                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                                                    <input type="hidden" name="action" value="deny">
                                                    <button type="submit" class="btn-deny" onclick="return confirm('Deny this transaction?')">Deny</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="7"><div class="empty-state"><i class="fas fa-check-circle"></i><p>All caught up! No pending transactions.</p></div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
