<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/inventory_service.php';

ensure_inventory_schema($conn);

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
$conn->query("ALTER TABLE reward_claims ADD COLUMN IF NOT EXISTS customer_seen_at DATETIME NULL");
$seenMigration = $conn->query("SELECT setting_key FROM system_settings WHERE setting_key='reward_seen_migration_v1' LIMIT 1");
if (!$seenMigration || $seenMigration->num_rows === 0) {
    $conn->query("UPDATE reward_claims SET customer_seen_at=NOW() WHERE claim_status='claimed' AND customer_seen_at IS NULL");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key,setting_value,updated_by) VALUES ('reward_seen_migration_v1','1','SYSTEM')");
}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_transaction'])) {
    $transactionId = sanitize(trim($_POST['claim_transaction']));
    $claimAction = (string)($_POST['claim_action'] ?? 'approve');
    $staffId = sanitize($_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'STAFF');
    $claim = $conn->query("SELECT user_id, reward_code, reward_title, claim_status FROM reward_claims WHERE transaction_id = '$transactionId' LIMIT 1");
    $claimRow = $claim ? $claim->fetch_assoc() : null;
    if ($claimRow && $claimRow['claim_status'] === 'pending' && $claimAction === 'approve') {
        $updated = $conn->query("UPDATE reward_claims SET claim_status='approved',claimed_by='$staffId',claimed_at=NOW(),customer_seen_at=NULL WHERE transaction_id='$transactionId' AND claim_status='pending'");
        if ($updated === TRUE && $conn->affected_rows > 0) {
            $rewardTitle = trim((string)($claimRow['reward_title'] ?? 'Reward'));
            $isOnlineVoucher = ($claimRow['reward_code'] ?? '') === 'free_delivery';
            add_user_notification(
                $conn,
                (string)$claimRow['user_id'],
                $transactionId,
                'Reward redemption approved',
                $isOnlineVoucher
                    ? 'Your Free Delivery reward is active and will be applied automatically to your next delivery order.'
                    : 'Your reward "' . $rewardTitle . '" is approved. Please come to the HydroMIS water refilling station and show your redemption ID to claim it.',
                'reward'
            );
            $flash = $isOnlineVoucher
                ? 'Free Delivery approved. It will be used automatically on the customer\'s next delivery order.'
                : 'Reward approved and the customer was notified to visit the station.';
        } else {
            $flash = 'This reward was updated by another staff member. Please refresh the list.';
        }
    } elseif ($claimRow && $claimRow['claim_status'] === 'approved' && $claimAction === 'claim') {
        $updated = $conn->query("UPDATE reward_claims SET claim_status='claimed',claimed_by='$staffId',claimed_at=NOW() WHERE transaction_id='$transactionId' AND claim_status='approved'");
        if ($updated === TRUE && $conn->affected_rows > 0) {
            add_user_notification($conn,(string)$claimRow['user_id'],$transactionId,'Reward successfully claimed','HydroMIS staff confirmed that your reward was released and is now marked as claimed.','success');
            $flash = 'Reward marked as claimed and the customer was notified.';
        } else {
            $flash = 'This reward was updated by another staff member. Please refresh the list.';
        }
    } else {
        $flash = 'This reward is already completed or unavailable for that action.';
    }
}

$pendingCountResult = $conn->query("SELECT COUNT(*) AS total FROM reward_claims WHERE claim_status = 'pending'");
$pendingCount = $pendingCountResult ? (int)($pendingCountResult->fetch_assoc()['total'] ?? 0) : 0;
$historyView = (($_GET['view'] ?? '') === 'history');
$rewardSearch = trim((string)($_GET['q'] ?? ''));
$claimViewWhere = $historyView ? '' : "WHERE rc.claim_status = 'pending'";
$claims = $conn->query("SELECT rc.id, rc.transaction_id, rc.user_id, rc.reward_code, CONCAT(rc.reward_title, ' — ', CASE rc.reward_code WHEN 'free_1_gallon' THEN 'Give 1 gallon of regular water' WHEN 'voucher_20' THEN 'Apply a ₱20 discount to the next refill order' WHEN 'delivery_discount' THEN 'Apply a ₱20 discount to the delivery fee' WHEN 'bundle_fast_lane' THEN 'Provide fast-lane service on the next visit' WHEN 'free_delivery' THEN 'Waive the delivery fee on the next eligible order' WHEN 'bundle_2_gallons' THEN 'Give 2 gallons of regular water' ELSE 'Release the listed reward' END) AS reward_title, rc.points_used, rc.claim_status, rc.claimed_by, rc.claimed_at, rc.created_at, u.full_name, u.contact_number FROM reward_claims rc LEFT JOIN users u ON u.user_id = rc.user_id $claimViewWhere ORDER BY FIELD(rc.claim_status, 'pending', 'claimed'), rc.created_at DESC");
$claimsData = [];
if ($claims) {
    while ($claimRow = $claims->fetch_assoc()) $claimsData[] = $claimRow;
}
if ($rewardSearch !== '') {
    $needle = strtolower($rewardSearch);
    $claimsData = array_values(array_filter($claimsData, function ($claim) use ($needle) {
        return str_contains(strtolower(implode(' ', [
            $claim['transaction_id'] ?? '', $claim['user_id'] ?? '',
            $claim['full_name'] ?? '', $claim['contact_number'] ?? '',
            $claim['reward_title'] ?? '', $claim['claim_status'] ?? ''
        ])), $needle);
    }));
}
$visibleClaimCount = count($claimsData);
$claims = new class($claimsData) {
    private array $rows;
    private int $position = 0;
    public int $num_rows;
    public function __construct(array $rows) { $this->rows = $rows; $this->num_rows = count($rows); }
    public function fetch_assoc(): ?array { return $this->rows[$this->position++] ?? null; }
};
$claimInstructions = [
    'free_1_gallon' => 'Give 1 gallon of regular water',
    'voucher_20' => 'Apply a ₱20 discount to the next refill order',
    'delivery_discount' => 'Apply a ₱20 discount to the delivery fee',
    'bundle_fast_lane' => 'Provide fast-lane service on the next visit',
    'free_delivery' => 'Waive the delivery fee on the next eligible order',
    'bundle_2_gallons' => 'Give 2 gallons of regular water'
];
$staff_active = 'rewards';
ob_start();
include 'sidebar.php';
$sharedStaffSidebar = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reward Claims — HydroMIS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#0b1120; --surface:#131d30; --surface2:#1a2847; --border:rgba(255,255,255,.09); --text:#e2efff; --muted:#8aa0c4; --blue:#3b82f6; --green:#10b981; --amber:#f59e0b; }
        * { box-sizing:border-box; } body { margin:0; background:linear-gradient(145deg,#0b1120,#101c32); color:var(--text); font:14px 'Plus Jakarta Sans',sans-serif; min-height:100vh; }
        .shell { display:grid; grid-template-columns:250px 1fr; min-height:100vh; } .sidebar { padding:28px 16px; background:rgba(10,18,34,.8); border-right:1px solid var(--border); }
        .brand { display:flex; align-items:center; gap:10px; padding:7px 10px 28px; font-size:20px; font-weight:800; } .brand i { color:#66c7ff; }
        .nav-label { margin:16px 10px 8px; color:#60789d; font-size:10px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; } .nav a { display:flex; align-items:center; gap:11px; margin:4px 0; padding:12px 11px; border-radius:11px; color:#9fb3d3; text-decoration:none; font-weight:650; } .nav a:hover,.nav a.active { color:#fff; background:var(--surface2); } .nav a.active { box-shadow:inset 3px 0 var(--green); } .nav-badge { margin-left:auto; min-width:21px; padding:3px 6px; border-radius:99px; background:#124b40; color:#6ee7b7; font-size:11px; text-align:center; }
        .main { padding:32px; max-width:1300px; width:100%; margin:auto; } .top { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:26px; } h1 { margin:0; font-size:28px; letter-spacing:-.04em; } .sub { margin:8px 0 0; color:var(--muted); } .back { color:#c9dcfa; text-decoration:none; border:1px solid var(--border); border-radius:10px; padding:11px 14px; }
        .hero { display:flex; align-items:center; justify-content:space-between; gap:22px; padding:25px 28px; margin-bottom:22px; overflow:hidden; border:1px solid rgba(66,153,225,.26); border-radius:19px; background:linear-gradient(120deg,#102f60,#1456a6); } .hero small { color:#b4e3ff; text-transform:uppercase; letter-spacing:.12em; font-weight:800; } .hero strong { display:block; margin-top:7px; font-size:32px; } .hero i { font-size:58px; color:#a5f3fc; opacity:.8; }
        .flash { margin-bottom:18px; padding:13px 15px; border-radius:11px; color:#a7f3d0; background:#103d36; border:1px solid #1a6459; }.card { overflow:hidden; border:1px solid var(--border); border-radius:16px; background:var(--surface); box-shadow:0 18px 45px rgba(0,0,0,.18); } table { width:100%; border-collapse:collapse; } th,td { padding:16px; border-bottom:1px solid var(--border); text-align:left; } th { color:#7e95ba; font-size:11px; letter-spacing:.08em; text-transform:uppercase; } td { color:#dbeafe; } .muted { color:var(--muted); font-size:12px; margin-top:4px; } .reward { font-weight:700; } .points { color:#6ee7b7; font-weight:800; } .status { display:inline-block; padding:6px 10px; border-radius:99px; font-size:11px; font-weight:800; text-transform:uppercase; } .pending { background:#4b3511; color:#fcd34d; } .claimed { background:#123d37; color:#6ee7b7; } .claim-btn { border:0; border-radius:9px; padding:9px 12px; color:#062b25; background:#34d399; font-weight:800; cursor:pointer; } .claim-btn:hover { background:#6ee7b7; } .empty { padding:45px; color:var(--muted); text-align:center; } @media(max-width:800px){.shell{grid-template-columns:1fr}.sidebar{padding:14px}.nav{display:flex;gap:6px;overflow:auto}.nav-label{display:none}.nav a{white-space:nowrap}.main{padding:22px 15px}.top{align-items:flex-start;flex-direction:column}.hero strong{font-size:26px}table,tbody,tr,td{display:block}thead{display:none}tr{padding:10px 0;border-bottom:1px solid var(--border)}td{padding:7px 16px;border:0}td:before{content:attr(data-label);display:block;color:#7e95ba;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:3px}}
        /* Match the dashboard staff navigation. */
        .shell { grid-template-columns: 270px minmax(0,1fr); }
        .sidebar { padding: 28px 20px 22px; background:#101827; border-right:1px solid rgba(255,255,255,.1); }
        .brand { display:flex; align-items:center; gap:14px; padding:0 8px 36px; border-bottom:1px solid rgba(255,255,255,.1); font:800 29px/1 'Plus Jakarta Sans',sans-serif; letter-spacing:-1.5px; color:#fff; }
        .brand i { display:grid; place-items:center; width:54px; height:54px; border-radius:16px; background:linear-gradient(135deg,#3b82f6,#2157dc); box-shadow:0 8px 18px rgba(37,99,235,.28); color:#fff; font-size:23px; }
        .nav { display:flex; flex-direction:column; gap:7px; padding-top:26px; }
        .nav-label { margin:7px 0 5px 14px; color:#7da4d5; font:800 11px/1 'Plus Jakarta Sans',sans-serif; letter-spacing:1.6px; text-transform:uppercase; }
        .nav a { display:flex; align-items:center; gap:14px; padding:14px 17px; border-radius:14px; color:#8eadd6; font:700 16px 'Plus Jakarta Sans',sans-serif; }
        .nav a i { width:20px; text-align:center; font-size:16px; }
        .nav a:hover,.nav a.active { color:#fff; background:linear-gradient(110deg,#1a3158,#162c50); }
        .nav a.active { box-shadow:inset 3px 0 #3b82f6; }
        .nav a.active i { display:grid; place-items:center; width:40px; height:40px; margin:-7px -3px -7px -10px; border-radius:10px; background:#24498b; color:#5ba4ff; }
        .nav-badge { margin-left:auto; min-width:24px; padding:4px 7px; border-radius:99px; background:#ff4d58; color:#fff; text-align:center; font-size:11px; }
        .sidebar::after { content:'Staff Account'; display:block; margin-top:auto; padding:19px 14px; border-top:1px solid rgba(255,255,255,.1); color:#82aee5; font:700 12px 'Plus Jakarta Sans',sans-serif; }
        @media(max-width:800px){ .shell{grid-template-columns:1fr}.sidebar{min-height:auto}.sidebar::after{display:none} }
        .reward-search{display:flex;align-items:center;gap:10px;margin-bottom:18px;padding:10px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}.reward-search .search-field{position:relative;min-width:0;flex:1}.reward-search .search-field i{position:absolute;left:14px;top:50%;color:#7792ba;transform:translateY(-50%)}.reward-search input{width:100%;height:44px;padding:0 14px 0 41px;border:1px solid var(--border);border-radius:10px;background:#0e1728;color:var(--text);font:600 13px 'Plus Jakarta Sans',sans-serif;outline:none}.reward-search input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(59,130,246,.14)}.reward-search button{height:44px;padding:0 18px;border:0;border-radius:10px;background:var(--blue);color:#fff;font-weight:800;cursor:pointer}.reward-search .clear-search{display:grid;place-items:center;width:42px;height:42px;border-radius:10px;background:var(--surface2);color:var(--muted);text-decoration:none}@media(max-width:600px){.reward-search{flex-wrap:wrap}.reward-search .search-field{flex-basis:100%}.reward-search button{flex:1}}
        .approved{background:rgba(59,130,246,.16);color:#93c5fd}
        body.staff-light .main{color:#17243a}body.staff-light .hero{border-color:#cbdcf0!important;background:linear-gradient(120deg,#e8f2ff,#dbeafe)!important}body.staff-light .hero small{color:#315f9b}body.staff-light .hero strong{color:#17243a}body.staff-light .hero i{color:#2563eb}body.staff-light .card{border-color:#d8e2ee!important;background:#fff!important;box-shadow:0 12px 30px rgba(30,64,100,.08)!important}body.staff-light th{color:#526984;border-color:#d8e2ee}body.staff-light td{color:#243650;border-color:#e1e8f0}body.staff-light .muted{color:#64748b}body.staff-light .reward-search{border-color:#d8e2ee;background:#fff}body.staff-light .reward-search input{background:#f7f9fc!important;color:#17243a!important}body.staff-light .back{color:#315f9b;border-color:#cbd8e7}body.staff-light .pending{background:#fff3cd;color:#9a6700}body.staff-light .claimed{background:#dcfce7;color:#087a55}body.staff-light .approved{background:#dbeafe;color:#1d4ed8}
    </style>
    <link href="../css/staff-sidebar.css" rel="stylesheet">
    <link href="../css/staff-sidebar-size.css" rel="stylesheet">
    <link href="../css/staff-pages-unified.css" rel="stylesheet">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const legacySidebar = document.querySelector('.shell > .sidebar');
            if (legacySidebar) legacySidebar.outerHTML = <?php echo json_encode($sharedStaffSidebar); ?>;
            const themeToggle = document.getElementById('staffThemeToggle');
            const applyRewardTheme = theme => {
                const light = theme === 'light';
                document.body.classList.toggle('staff-light', light);
                themeToggle?.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode');
                if (themeToggle) themeToggle.innerHTML = light
                    ? '<i class="fas fa-moon"></i><span>Dark mode</span>'
                    : '<i class="fas fa-sun"></i><span>Light mode</span>';
            };
            applyRewardTheme(localStorage.getItem('hydromis-staff-theme') || 'dark');
            themeToggle?.addEventListener('click', () => {
                const next = document.body.classList.contains('staff-light') ? 'dark' : 'light';
                localStorage.setItem('hydromis-staff-theme', next);
                applyRewardTheme(next);
            });
            const pageTitle = document.querySelector('.top h1');
            const pageSubtitle = document.querySelector('.top .sub');
            const historyView = <?php echo $historyView ? 'true' : 'false'; ?>;
            if (pageTitle) pageTitle.textContent = historyView ? 'Point Conversion History' : 'Reward Claims';
            if (pageSubtitle) pageSubtitle.textContent = historyView ? 'Complete archive of customer point conversions and claimed rewards.' : 'Review converted rewards and fulfill pending customer claims.';
            const historyButton = document.querySelector('.top .back');
            const claimsTable = document.querySelector('.main .card');
            if (claimsTable) claimsTable.id = 'conversion-history';
            if (claimsTable) {
                const searchForm = document.createElement('form');
                searchForm.method = 'get';
                searchForm.className = 'reward-search';
                const searchField = document.createElement('div');
                searchField.className = 'search-field';
                searchField.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
                const searchInput = document.createElement('input');
                searchInput.type = 'search';
                searchInput.name = 'q';
                searchInput.value = <?php echo json_encode($rewardSearch); ?>;
                searchInput.placeholder = 'Search customer, contact, reward, or redemption ID';
                searchInput.setAttribute('aria-label', 'Search reward claims');
                searchField.appendChild(searchInput);
                searchForm.appendChild(searchField);
                if (historyView) {
                    const viewInput = document.createElement('input');
                    viewInput.type = 'hidden'; viewInput.name = 'view'; viewInput.value = 'history';
                    searchForm.appendChild(viewInput);
                }
                const searchButton = document.createElement('button');
                searchButton.type = 'submit';
                searchButton.innerHTML = '<i class="fas fa-search"></i> Search';
                searchForm.appendChild(searchButton);
                if (searchInput.value !== '') {
                    const clearSearch = document.createElement('a');
                    clearSearch.className = 'clear-search';
                    clearSearch.href = historyView ? 'rewards.php?view=history' : 'rewards.php';
                    clearSearch.setAttribute('aria-label', 'Clear search');
                    clearSearch.innerHTML = '<i class="fas fa-xmark"></i>';
                    searchForm.appendChild(clearSearch);
                }
                claimsTable.before(searchForm);
            }
            if (historyButton) {
                historyButton.href = historyView ? 'rewards.php' : 'rewards.php?view=history';
                historyButton.innerHTML = historyView ? '<i class="fas fa-gift"></i> Pending Claims' : '<i class="fas fa-clock-rotate-left"></i> Conversion History';
            }
            if (historyView) {
                const heroLabel = document.querySelector('.hero small');
                const heroValue = document.querySelector('.hero strong');
                if (heroLabel) heroLabel.textContent = 'Conversion archive';
                if (heroValue) heroValue.textContent = <?php echo json_encode($visibleClaimCount . ' conversion' . ($visibleClaimCount === 1 ? '' : 's')); ?>;
            }
            document.querySelectorAll('.claim-btn').forEach(button => {
                const form = button.closest('form');
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden'; actionInput.name = 'claim_action'; actionInput.value = 'approve';
                form.appendChild(actionInput);
                button.innerHTML = '<i class="fas fa-check"></i> Approve';
                const rewardName = form.closest('tr')?.querySelector('td:nth-child(2) .reward')?.textContent || '';
                form.onsubmit = () => confirm(
                    rewardName.toLowerCase().includes('free delivery')
                        ? 'Approve this one-use Free Delivery voucher for the customer\'s next delivery order?'
                        : 'Approve this redemption and notify the customer to claim it at the station?'
                );
            });
            document.querySelectorAll('.status.claimed').forEach(status => { status.textContent = 'Claimed'; });
            document.querySelectorAll('.status.approved').forEach(status => {
                status.textContent = 'Approved';
                const row = status.closest('tr');
                const rewardMeta = row?.querySelector('td:nth-child(2) .muted')?.textContent || '';
                const transactionId = rewardMeta.split('·')[0].trim();
                const actionCell = row?.lastElementChild;
                if (!actionCell || !transactionId) return;
                const rewardName = row?.querySelector('td:nth-child(2) .reward')?.textContent || '';
                if (rewardName.toLowerCase().includes('free delivery')) {
                    actionCell.innerHTML = '<span class="muted"><i class="fas fa-truck-fast"></i> Waiting for customer delivery order</span>';
                    return;
                }
                const form = document.createElement('form'); form.method = 'post';
                const transactionInput = document.createElement('input'); transactionInput.type = 'hidden'; transactionInput.name = 'claim_transaction'; transactionInput.value = transactionId;
                const actionInput = document.createElement('input'); actionInput.type = 'hidden'; actionInput.name = 'claim_action'; actionInput.value = 'claim';
                const button = document.createElement('button'); button.type = 'submit'; button.className = 'claim-btn'; button.innerHTML = '<i class="fas fa-box-open"></i> Mark as claimed';
                form.append(transactionInput, actionInput, button);
                form.onsubmit = () => confirm('Confirm that this approved reward has been released to the customer?');
                actionCell.replaceChildren(form);
            });
        });
    </script>
</head>
<body><div class="shell"><aside class="sidebar"><div class="brand"><i class="fas fa-droplet"></i> HydroMIS</div><nav class="nav"><div class="nav-label">Main</div><a href="dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a><a href="pending.php"><i class="fas fa-hourglass-half"></i> Pending orders</a><div class="nav-label">Rewards</div><a class="active" href="rewards.php"><i class="fas fa-gift"></i> Reward claims <span class="nav-badge"><?php echo $pendingCount; ?></span></a><div class="nav-label">Finance</div><a href="history.php"><i class="fas fa-clock-rotate-left"></i> History</a></nav></aside><main class="main"><header class="top"><div><h1>Reward claims</h1><p class="sub">Verify customer conversions and mark rewards as collected at the shop.</p></div><a class="back" href="dashboard.php"><i class="fas fa-arrow-left"></i> Dashboard</a></header><section class="hero"><div><small>Awaiting collection</small><strong><?php echo $pendingCount; ?> reward<?php echo $pendingCount === 1 ? '' : 's'; ?> pending</strong></div><i class="fas fa-gift"></i></section><?php if ($flash): ?><div class="flash"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?><section class="card"><table><thead><tr><th>Customer</th><th>Reward</th><th>Points</th><th>Conversion</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if ($claims && $claims->num_rows > 0): ?><?php while($row=$claims->fetch_assoc()): ?><tr><td data-label="Customer"><strong><?php echo htmlspecialchars($row['full_name'] ?: 'Unknown user'); ?></strong><div class="muted"><?php echo htmlspecialchars($row['contact_number'] ?: $row['user_id']); ?></div></td><td data-label="Reward"><div class="reward"><?php echo htmlspecialchars($row['reward_title']); ?></div><div class="muted"><?php echo htmlspecialchars($row['transaction_id']); ?> · <?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></div></td><td data-label="Points" class="points"><?php echo (int)$row['points_used']; ?> pts</td><td data-label="Converted by customer"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td><td data-label="Status"><span class="status <?php echo htmlspecialchars($row['claim_status']); ?>"><?php echo htmlspecialchars($row['claim_status']); ?></span><?php if ($row['claimed_at']): ?><div class="muted"><?php echo date('M d, h:i A', strtotime($row['claimed_at'])); ?></div><?php endif; ?></td><td data-label="Action"><?php if ($row['claim_status'] === 'pending'): ?><form method="post" onsubmit="return confirm('Mark this reward as claimed by the customer?');"><input type="hidden" name="claim_transaction" value="<?php echo htmlspecialchars($row['transaction_id']); ?>"><button class="claim-btn" type="submit"><i class="fas fa-check"></i> Mark claimed</button></form><?php else: ?><span class="muted">Collected</span><?php endif; ?></td></tr><?php endwhile; ?><?php else: ?><tr><td colspan="6" class="empty"><i class="fas fa-gift"></i><p>No reward conversions yet.</p></td></tr><?php endif; ?></tbody></table></section></main></div></body></html>
