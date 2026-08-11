<?php
require_once __DIR__ . '/../config/system_settings.php';
$systemLogo = isset($conn) ? system_logo_path($conn) : 'imagess/hydromis-logo-v2.png';
$staffProfileImage = '';
if (isset($conn) && !empty($_SESSION['admin_id'])) {
    $profileStaffId = $conn->real_escape_string((string)$_SESSION['admin_id']);
    $profileResult = $conn->query("SELECT profile_image FROM admin_users WHERE admin_id='$profileStaffId' AND role='staff' LIMIT 1");
    if ($profileResult && ($profileRow = $profileResult->fetch_assoc())) $staffProfileImage = (string)($profileRow['profile_image'] ?? '');
}
$staff_active = $staff_active ?? 'dashboard';
$pending_nav = 0;
$rewards_nav = 0;
$pickup_nav = 0;
$delivery_nav = 0;
if (isset($conn)) {
    $r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'pending'");
    $pending_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
    $r = $conn->query("SELECT COUNT(*) AS total FROM reward_claims WHERE claim_status = 'pending'");
    $rewards_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
    $r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'approved' AND fulfillment_method = 'pickup' AND COALESCE(delivery_status, 'pending') <> 'delivered'");
    $pickup_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
    $r = $conn->query("SELECT COUNT(*) AS total FROM transactions
        WHERE status = 'approved'
          AND COALESCE(fulfillment_method, 'delivery') = 'delivery'
          AND COALESCE(NULLIF(delivery_status, ''), 'pending') = 'pending'
          AND transaction_id NOT LIKE 'RWD-%'
          AND COALESCE(description, '') NOT LIKE 'Reward Redemption - %'");
    $delivery_nav = $r ? (int)($r->fetch_assoc()['total'] ?? 0) : 0;
}
?>
<link href="../css/animations.css" rel="stylesheet">
<style>.staff-inventory-menu,.staff-delivery-menu{position:relative}.staff-inventory-menu>a,.staff-delivery-menu>a{padding-right:46px!important}.staff-inventory-toggle,.staff-delivery-toggle{position:absolute;z-index:2;right:9px;top:24px;display:grid;place-items:center;width:30px;height:30px;border:0;border-radius:9px;background:rgba(59,130,246,.1);color:#8fb9ec;cursor:pointer;transform:translateY(-50%)}.staff-inventory-toggle i,.staff-delivery-toggle i{transition:transform .22s ease}.staff-inventory-menu.open .staff-inventory-toggle i,.staff-delivery-menu.open .staff-delivery-toggle i{transform:rotate(180deg)}.staff-inventory-submenu,.staff-delivery-submenu{display:none;margin:3px 0 7px 28px}.staff-inventory-menu.open .staff-inventory-submenu,.staff-delivery-menu.open .staff-delivery-submenu{display:block}.staff-sidebar-nav .staff-inventory-submenu a,.staff-sidebar-nav .staff-delivery-submenu a{display:flex;align-items:center;gap:8px;padding:9px 11px!important;border-left:2px solid rgba(96,165,250,.38);border-radius:0 10px 10px 0!important;background:rgba(59,130,246,.07)!important;color:#91b9ea!important;font-size:11px!important}.staff-sidebar-nav .staff-inventory-submenu a:hover,.staff-sidebar-nav .staff-delivery-submenu a:hover{border-left-color:#60a5fa;background:rgba(59,130,246,.14)!important;color:#dbeafe!important}.staff-inventory-submenu i,.staff-delivery-submenu i{font-size:11px}.staff-nav-redmark{display:inline-grid;place-items:center;min-width:18px;height:18px;margin-left:auto;padding:0 5px;border-radius:999px;background:#ef4444;color:#fff;font-size:9px;font-weight:900;box-shadow:0 0 0 3px rgba(239,68,68,.14)}.staff-delivery-menu>a .staff-nav-redmark{position:absolute;right:48px;top:50%;transform:translateY(-50%)}.staff-theme-toggle{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;margin:14px 0 0;padding:10px 12px;border:1px solid rgba(148,163,184,.2);border-radius:11px;background:rgba(59,130,246,.08);color:#9fc2ed;font:700 12px 'Plus Jakarta Sans',sans-serif;cursor:pointer}.staff-theme-toggle:hover{background:rgba(59,130,246,.15);color:#fff}body.staff-light{color-scheme:light;--staff-bg:#eef4fb;--staff-bg-2:#f8fafc;--staff-surface:#fff;--staff-surface-2:#edf3fa;--staff-border:#d8e2ee;--staff-text:#17243a;--staff-muted:#64748b;--bg:#eef4fb;--bg-2:#f8fafc;--surface:#fff;--surface-2:#edf3fa;--border:#d8e2ee;--border-2:#c7d4e3;--text:#17243a;--muted:#64748b}body.staff-light .staff-sidebar{background:#fff;color:#243650;border-right:1px solid #dce5ef}body.staff-light .staff-sidebar-brand{color:#14233a;border-color:#dce5ef}body.staff-light .staff-sidebar-nav>span{color:#55708f}body.staff-light .staff-sidebar-nav a{color:#58708f}body.staff-light .staff-sidebar-nav a:hover,body.staff-light .staff-sidebar-nav a.active{color:#1d4ed8;background:#e7f0ff}body.staff-light .staff-sidebar-profile{border-color:#d6e0ec;background:#f5f8fc}body.staff-light .staff-sidebar-profile strong{color:#17243a}body.staff-light .staff-sidebar-footer{border-color:#dce5ef}body.staff-light .staff-theme-toggle{border-color:#d3deeb;background:#edf4ff;color:#315f9b}body.staff-light .topbar,body.staff-light .top{background:rgba(255,255,255,.9)!important}body.staff-light .welcome-section,body.staff-light .page-header,body.staff-light .hero{background:#fff!important;box-shadow:0 10px 28px rgba(30,64,100,.08)!important}body.staff-light .card,body.staff-light .table-card,body.staff-light .stat-card,body.staff-light .quick-card,body.staff-light .history-block{box-shadow:0 8px 24px rgba(30,64,100,.06)}body.staff-light input,body.staff-light select,body.staff-light textarea{color:#17243a!important;background:#f7f9fc!important;border-color:#ccd8e6!important}body.staff-light thead,body.staff-light thead th{background:#edf3f9!important;color:#526984!important}body.staff-light tbody tr:hover{background:#f4f8fc!important}</style>
<style>body.staff-light .staff-sidebar-nav .staff-inventory-submenu a,body.staff-light .staff-sidebar-nav .staff-delivery-submenu a{background:#eef4fc!important;color:#315f9b!important;border-left-color:#7eb2f2!important}body.staff-light .staff-sidebar-nav .staff-inventory-submenu a:hover,body.staff-light .staff-sidebar-nav .staff-delivery-submenu a:hover{background:#dfeeff!important;color:#174ea6!important}body.staff-light .item-code,body.staff-light .stock small,body.staff-light .stat span,body.staff-light .muted{color:#526984!important}body.staff-light .adjust-field label,body.staff-light .add-field label{color:#4b6380!important}body.staff-light .adjust .adjust-preview{color:#52719a!important;background:#edf5ff!important;border-color:#9bc2f3!important}body.staff-light .adjust .adjust-preview strong{color:#2563a9!important}body.staff-light input::placeholder,body.staff-light textarea::placeholder{color:#7186a0!important;opacity:1}body.staff-light .status.good{color:#087a55!important;background:#d9f7eb!important}body.staff-light .status.low{color:#9a6700!important;background:#fff1c2!important}body.staff-light .status.out{color:#b4233b!important;background:#ffe1e7!important}body.staff-light .adjust button:disabled{opacity:.72!important;color:#fff!important;background:#5f96e8!important}</style>
<script src="../js/ui-protection.js" defer></script>
<style>
.staff-sidebar-profile{min-height:72px;overflow:hidden}
.staff-sidebar-profile>b{flex:0 0 46px!important;width:46px!important;height:46px!important;min-width:46px!important;max-width:46px!important;overflow:hidden}
.staff-sidebar-profile>b img{display:block!important;width:46px!important;height:46px!important;min-width:46px!important;max-width:46px!important;border-radius:50%!important;object-fit:cover!important;object-position:center!important}
.staff-sidebar-profile>div{min-width:0;overflow:hidden}
.staff-sidebar-profile strong,.staff-sidebar-profile span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
<aside class="staff-sidebar">
    <div class="staff-sidebar-brand"><b><img src="../<?php echo htmlspecialchars($systemLogo); ?>" alt="HydroMIS logo" style="width:30px;height:30px;object-fit:contain;"></b><div>HydroMIS<span>Water Refilling</span></div></div>
    <nav class="staff-sidebar-nav">
        <span>Main</span>
        <a class="<?php echo $staff_active === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
        <div class="staff-delivery-menu" id="staffDeliveryMenu">
            <a class="<?php echo $staff_active === 'deliveries' ? 'active' : ''; ?>" href="dashboard.php?view=deliveries"><i class="fas fa-truck-fast"></i>Delivery Operations<?php if ($delivery_nav > 0): ?><span class="staff-nav-redmark"><?php echo $delivery_nav; ?></span><?php endif; ?></a>
            <button type="button" class="staff-delivery-toggle" id="staffDeliveryToggle" aria-expanded="false" aria-label="Toggle Delivery Operations menu"><i class="fas fa-chevron-down"></i></button>
            <div class="staff-delivery-submenu">
                <a href="dashboard.php?view=deliveries&amp;section=pickups"><i class="fas fa-store"></i>Pickup Orders<?php if ($pickup_nav > 0): ?><span class="staff-nav-redmark"><?php echo $pickup_nav; ?></span><?php endif; ?></a>
                <a href="dashboard.php?view=deliveries&amp;section=feedbacks"><i class="fas fa-comments"></i>Feedbacks</a>
            </div>
        </div>
        <a class="<?php echo $staff_active === 'pending' ? 'active' : ''; ?>" href="pending.php"><i class="fas fa-hourglass-half"></i>Pending Approvals<?php if ($pending_nav): ?><b><?php echo $pending_nav; ?></b><?php endif; ?></a>
        <div class="staff-inventory-menu" id="staffInventoryMenu">
            <a class="<?php echo $staff_active === 'inventory' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes-stacked"></i>Inventory</a>
            <button type="button" class="staff-inventory-toggle" id="staffInventoryToggle" aria-expanded="false" aria-label="Toggle Inventory menu"><i class="fas fa-chevron-down"></i></button>
            <div class="staff-inventory-submenu"><a href="inventory.php?view=movements#recent-stock-movements"><i class="fas fa-clock-rotate-left"></i>Recent Stock Movements</a></div>
        </div>
        <span>Finance</span>
        <a class="<?php echo $staff_active === 'history' ? 'active' : ''; ?>" href="history.php"><i class="fas fa-history"></i>Payment History</a>
        <a class="<?php echo $staff_active === 'rewards' ? 'active' : ''; ?>" href="rewards.php"><i class="fas fa-gift"></i>Reward Claims<?php if ($rewards_nav): ?><b><?php echo $rewards_nav; ?></b><?php endif; ?></a>
    </nav>
    <button type="button" class="staff-theme-toggle" id="staffThemeToggle" aria-label="Switch to light mode"><i class="fas fa-sun"></i><span>Light mode</span></button>
    <div class="staff-sidebar-footer">
        <div class="staff-sidebar-profile"><b><?php if ($staffProfileImage !== ''): ?><img src="../<?php echo htmlspecialchars($staffProfileImage); ?>" alt="Staff profile" style="width:100%;height:100%;border-radius:inherit;object-fit:cover"><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'S', 0, 1))); ?><?php endif; ?></b><div><strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?></strong><span>Staff Account</span></div></div>
        <a href="../logout.php" class="staff-sidebar-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
    </div>
</aside>
<script>document.addEventListener('DOMContentLoaded',()=>{const themeToggle=document.getElementById('staffThemeToggle');const applyTheme=theme=>{const light=theme==='light';document.body.classList.toggle('staff-light',light);themeToggle?.setAttribute('aria-label',light?'Switch to dark mode':'Switch to light mode');if(themeToggle)themeToggle.innerHTML=light?'<i class="fas fa-moon"></i><span>Dark mode</span>':'<i class="fas fa-sun"></i><span>Light mode</span>'};applyTheme(localStorage.getItem('hydromis-staff-theme')||'dark');themeToggle?.addEventListener('click',()=>{const next=document.body.classList.contains('staff-light')?'dark':'light';localStorage.setItem('hydromis-staff-theme',next);applyTheme(next)});const menu=document.getElementById('staffInventoryMenu'),toggle=document.getElementById('staffInventoryToggle');if(new URLSearchParams(location.search).get('view')==='movements'){menu?.classList.add('open');toggle?.setAttribute('aria-expanded','true')}toggle?.addEventListener('click',()=>{const open=menu.classList.toggle('open');toggle.setAttribute('aria-expanded',open?'true':'false');if(!open&&new URLSearchParams(location.search).get('view')==='movements'){const movements=document.getElementById('recent-stock-movements');if(movements)movements.hidden=true;const cleanUrl=new URL(location.href);cleanUrl.searchParams.delete('view');cleanUrl.hash='';history.replaceState({},'',cleanUrl.pathname+(cleanUrl.searchParams.toString()?'?'+cleanUrl.searchParams.toString():''))}});const deliveryMenu=document.getElementById('staffDeliveryMenu'),deliveryToggle=document.getElementById('staffDeliveryToggle'),params=new URLSearchParams(location.search);if(params.get('view')==='deliveries'&&params.get('section')){deliveryMenu?.classList.add('open');deliveryToggle?.setAttribute('aria-expanded','true')}deliveryToggle?.addEventListener('click',()=>{const open=deliveryMenu.classList.toggle('open');deliveryToggle.setAttribute('aria-expanded',open?'true':'false')})});</script>
