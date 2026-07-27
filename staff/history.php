<?php
require_once 'check_auth.php';
require_once '../config/database.php';

$period = $_GET['period'] ?? 'all';
$allowedPeriods = ['all', 'day', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) $period = 'all';
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterYear = $_GET['year'] ?? date('Y');
$where = "WHERE t.transaction_id NOT LIKE 'RWD-%' AND t.description NOT LIKE 'Reward Redemption - %'";
$filterLabel = 'All time';
if ($period === 'day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $safe = sanitize($filterDate);
    $where .= " AND DATE(t.created_at) = '$safe'";
    $filterLabel = date('F d, Y', strtotime($filterDate));
} elseif ($period === 'month' && preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $safe = sanitize($filterMonth);
    $where .= " AND DATE_FORMAT(t.created_at, '%Y-%m') = '$safe'";
    $filterLabel = date('F Y', strtotime($filterMonth . '-01'));
} elseif ($period === 'year' && preg_match('/^\d{4}$/', $filterYear)) {
    $safe = (int)$filterYear;
    $where .= " AND YEAR(t.created_at) = $safe";
    $filterLabel = (string)$safe;
}

$all_trans = $conn->query("
    SELECT t.*, u.full_name
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    $where
    ORDER BY t.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - HydroMIS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <link href="../css/staff-sidebar.css" rel="stylesheet">
    <link href="../css/staff-sidebar-size.css" rel="stylesheet">
    <link href="../css/staff-pages-unified.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
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
}

.sidebar nav a:hover { background: rgba(255,255,255,.1); color: #fff; }
.sidebar nav a.active {
    background: #1c5075;
    color: #fff;
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

.quick-card.blue .quick-icon {
    background: var(--accent-glow);
    color: var(--accent);
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
}
    </style>
    <link href="../css/staff-sidebar.css" rel="stylesheet">
    <link href="../css/staff-sidebar-size.css" rel="stylesheet">
    <link href="../css/staff-pages-unified.css" rel="stylesheet">
</head>
<body>
    <div class="layout">
        <?php $staff_active = 'history'; include 'sidebar.php'; ?>

        <main class="main">
            <div class="topbar">
                <div>
                    <div class="topbar-title">Transaction History</div>
                    <div class="topbar-subtitle">Full record of transactions and their latest status</div>
                </div>
            </div>

            <section class="page">
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="welcome-header">
                        <div class="welcome-content">
                            <h1>Transaction History 📋</h1>
                            <p>Purchase and order records only. Point conversions are available under Reward Claims.</p>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <form class="history-filter" method="get">
                    <div><label for="period">View history by</label><select id="period" name="period"><option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All time</option><option value="day" <?php echo $period === 'day' ? 'selected' : ''; ?>>Day</option><option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Month</option><option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Year</option></select></div>
                    <div class="filter-value" data-period="day"><label for="filter-date">Select day</label><input id="filter-date" type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>"></div>
                    <div class="filter-value" data-period="month"><label for="filter-month">Select month</label><input id="filter-month" type="month" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>"></div>
                    <div class="filter-value" data-period="year"><label for="filter-year">Select year</label><input id="filter-year" type="number" min="2020" max="<?php echo date('Y') + 1; ?>" name="year" value="<?php echo htmlspecialchars($filterYear); ?>"></div>
                    <a href="history.php">Reset</a>
                    <span class="filter-live"><i class="fas fa-bolt"></i> Updates automatically</span>
                </form>
                <div class="stats-row">
                    <div class="stat-card green">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-content">
                            <h4>Total Transactions</h4>
                            <div class="stat-value"><?php echo $all_trans ? $all_trans->num_rows : 0; ?></div>
                            <div class="stat-label"><?php echo htmlspecialchars($filterLabel); ?></div>
                        </div>
                    </div>
                </div>

                <!-- History Table -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-history"></i> Transactions — <?php echo htmlspecialchars($filterLabel); ?></span>
                        <span class="card-meta"><?php echo $all_trans ? $all_trans->num_rows : 0; ?> records</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Customer Name</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($all_trans && $all_trans->num_rows > 0): ?>
                                    <?php while ($row = $all_trans->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['transaction_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td>₱<?php echo number_format((float)$row['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td><span class="badge badge-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6"><div class="empty-state"><i class="fas fa-inbox"></i><p>No transactions recorded yet.</p></div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
<script>
const periodSelect = document.getElementById('period');
function updateHistoryFilter() {
    document.querySelectorAll('.filter-value').forEach(el => el.hidden = el.dataset.period !== periodSelect.value);
}
periodSelect.addEventListener('change', function () {
    updateHistoryFilter();
    this.form.submit();
});
document.querySelectorAll('.filter-value input').forEach(input => {
    input.addEventListener('change', () => input.form.submit());
});
updateHistoryFilter();
</script>
</html>
