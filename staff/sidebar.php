<?php
require_once __DIR__ . '/../config/system_settings.php';
$systemLogo = isset($conn) ? system_logo_path($conn) : 'imagess/logosystem.png';
$staff_active = $staff_active ?? 'dashboard';
$pending_nav = 0;
$rewards_nav = 0;
if (isset($conn)) {
    $r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'pending'");
    $pending_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
    $r = $conn->query("SELECT COUNT(*) AS total FROM reward_claims WHERE claim_status = 'pending'");
    $rewards_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
}
?>
<aside class="staff-sidebar">
    <div class="staff-sidebar-brand"><b><img src="../<?php echo htmlspecialchars($systemLogo); ?>" alt="HydroMIS logo" style="width:30px;height:30px;object-fit:contain;"></b><div>HydroMIS<span>Water Refilling</span></div></div>
    <nav class="staff-sidebar-nav">
        <span>Main</span>
        <a class="<?php echo $staff_active === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
        <a class="<?php echo $staff_active === 'deliveries' ? 'active' : ''; ?>" href="dashboard.php?view=deliveries"><i class="fas fa-truck-fast"></i>Delivery Operations</a>
        <a class="<?php echo $staff_active === 'pending' ? 'active' : ''; ?>" href="pending.php"><i class="fas fa-hourglass-half"></i>Pending Approvals<?php if ($pending_nav): ?><b><?php echo $pending_nav; ?></b><?php endif; ?></a>
        <a class="<?php echo $staff_active === 'inventory' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes-stacked"></i>Inventory</a>
        <span>Finance</span>
        <a class="<?php echo $staff_active === 'payments' ? 'active' : ''; ?>" href="payments.php"><i class="fas fa-money-bill-wave"></i>Payments</a>
        <a class="<?php echo $staff_active === 'history' ? 'active' : ''; ?>" href="history.php"><i class="fas fa-history"></i>History</a>
        <a class="<?php echo $staff_active === 'rewards' ? 'active' : ''; ?>" href="rewards.php"><i class="fas fa-gift"></i>Reward Claims<?php if ($rewards_nav): ?><b><?php echo $rewards_nav; ?></b><?php endif; ?></a>
    </nav>
    <div class="staff-sidebar-footer">
        <div class="staff-sidebar-profile"><b><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'S', 0, 1))); ?></b><div><strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?></strong><span>Staff Account</span></div></div>
        <a href="../logout.php" class="staff-sidebar-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
    </div>
</aside>
