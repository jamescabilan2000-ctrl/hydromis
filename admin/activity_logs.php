<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';

ensure_activity_log_schema($conn);
$systemLogo = system_logo_path($conn);

$role = strtolower(trim((string)($_GET['role'] ?? 'all')));
$allowedRoles = ['all', 'admin', 'staff', 'rider', 'customer', 'guest'];
if (!in_array($role, $allowedRoles, true)) $role = 'all';
$actionFilter = trim((string)($_GET['action'] ?? ''));
$dateFilter = trim((string)($_GET['date'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;

$conditions = ['1=1'];
if ($role !== 'all') $conditions[] = "actor_role = '" . $conn->real_escape_string($role) . "'";
if ($actionFilter !== '') $conditions[] = "action = '" . $conn->real_escape_string($actionFilter) . "'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) $conditions[] = "DATE(created_at) = '" . $conn->real_escape_string($dateFilter) . "'";
if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $conditions[] = "(actor_id LIKE '%{$safeSearch}%' OR actor_name LIKE '%{$safeSearch}%' OR action LIKE '%{$safeSearch}%' OR description LIKE '%{$safeSearch}%')";
}
$where = implode(' AND ', $conditions);

$countResult = $conn->query("SELECT COUNT(*) AS total FROM system_activity_logs WHERE {$where}");
$totalRows = (int)(($countResult && ($countRow = $countResult->fetch_assoc())) ? $countRow['total'] : 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$logs = $conn->query("SELECT * FROM system_activity_logs WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$actions = $conn->query("SELECT action, COUNT(*) AS uses FROM system_activity_logs GROUP BY action ORDER BY uses DESC, action ASC LIMIT 50");

function audit_scalar($conn, string $sql): int {
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    return (int)($row['value'] ?? 0);
}
function audit_device(string $agent): string {
    if (stripos($agent, 'Mobile') !== false || stripos($agent, 'Android') !== false) return 'Mobile';
    if (stripos($agent, 'Tablet') !== false || stripos($agent, 'iPad') !== false) return 'Tablet';
    if ($agent === '' || $agent === 'Unknown') return 'Unknown';
    return 'Desktop';
}
function audit_query(array $changes): string {
    $query = array_merge($_GET, $changes);
    foreach ($query as $key => $value) if ($value === '' || $value === null || $value === 'all') unset($query[$key]);
    return '?' . http_build_query($query);
}

$todayCount = audit_scalar($conn, "SELECT COUNT(*) AS value FROM system_activity_logs WHERE DATE(created_at) = CURRENT_DATE");
$weekCount = audit_scalar($conn, "SELECT COUNT(*) AS value FROM system_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$actorCount = audit_scalar($conn, "SELECT COUNT(DISTINCT CONCAT(actor_role, ':', COALESCE(actor_id, ip_address))) AS value FROM system_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activity Log — HydroMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="../css/admin-sidebar-hover.css" rel="stylesheet">
<style>
:root{--navy:#121e2b;--blue:#1878d1;--teal:#0f8b80;--ink:#182638;--muted:#6b7d90;--line:#e3eaf0;--paper:#f4f7f9;--card:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,sans-serif}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{position:sticky;top:0;height:100vh;padding:25px 16px;background:var(--navy);color:#fff}.brand{display:flex;align-items:center;gap:11px;margin:0 8px 30px}.brand img{width:42px;height:42px;object-fit:contain}.brand b{display:block;font:700 21px 'Barlow Condensed',sans-serif}.brand span{color:#8fcde9;font-size:11px}.nav-label{margin:22px 10px 8px;color:#718398;font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.nav a{display:flex;align-items:center;gap:11px;margin:4px 0;padding:11px 12px;border-radius:10px;color:#b9c6d4;font-size:12px;text-decoration:none}.nav a:hover,.nav a.active{background:rgba(45,212,191,.12);color:#fff}.nav a.active{box-shadow:inset 3px 0 #2dd4bf}.nav i{width:17px;text-align:center}.main{min-width:0;padding:30px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}.top h1{margin:0;font:700 30px 'Barlow Condensed',sans-serif}.top p{margin:5px 0 0;color:var(--muted);font-size:12px}.admin-chip{padding:9px 12px;border:1px solid var(--line);border-radius:12px;background:#fff;font-size:11px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.stat{padding:17px;border:1px solid var(--line);border-radius:15px;background:#fff}.stat i{color:var(--teal)}.stat strong{display:block;margin-top:12px;font:700 25px 'Barlow Condensed',sans-serif}.stat span{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.07em}.filter{display:grid;grid-template-columns:minmax(180px,1fr) repeat(3,minmax(120px,auto)) auto;gap:9px;padding:13px;margin-bottom:16px;border:1px solid var(--line);border-radius:15px;background:#fff}.filter input,.filter select{width:100%;min-height:39px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:#f9fbfc;color:var(--ink);font:inherit;font-size:11px}.filter button,.clear{display:flex;align-items:center;justify-content:center;gap:6px;min-height:39px;padding:9px 14px;border:0;border-radius:9px;background:var(--teal);color:#fff;font-size:11px;font-weight:700;text-decoration:none}.clear{background:#edf2f5;color:#526579}.panel{overflow:hidden;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 7px 24px rgba(20,42,60,.04)}table{width:100%;border-collapse:collapse}th{padding:12px 14px;background:#f7fafb;color:#708297;font-size:9px;letter-spacing:.08em;text-align:left;text-transform:uppercase}td{padding:13px 14px;border-top:1px solid #edf1f4;font-size:11px;vertical-align:top}.actor b{display:block;font-size:12px}.actor span,.sub{color:var(--muted);font-size:10px}.role,.method{display:inline-flex;padding:4px 7px;border-radius:20px;background:#edf6ff;color:#1769aa;font-size:9px;font-weight:700;text-transform:uppercase}.role.staff{background:#f3e8ff;color:#7e22ce}.role.rider{background:#fff7df;color:#a15c00}.role.customer{background:#e8faf4;color:#087961}.role.guest{background:#f0f2f4;color:#687583}.action{font-weight:700}.description{max-width:360px;line-height:1.45}.meta details{max-width:260px}.meta summary{color:var(--blue);cursor:pointer;font-size:10px}.meta pre{overflow:auto;max-width:280px;margin:7px 0 0;padding:8px;border-radius:8px;background:#f5f7f9;color:#536678;font:9px/1.4 monospace}.empty{padding:50px;text-align:center;color:var(--muted)}.pagination{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;border-top:1px solid var(--line);color:var(--muted);font-size:10px}.pages{display:flex;gap:5px}.pages a{padding:6px 9px;border:1px solid var(--line);border-radius:7px;color:var(--ink);text-decoration:none}.pages a.active{background:var(--navy);color:#fff}
@media(max-width:1000px){.layout{grid-template-columns:1fr}.sidebar{position:static;height:auto}.stats{grid-template-columns:repeat(2,1fr)}.filter{grid-template-columns:1fr 1fr}.panel{overflow-x:auto}.main{padding:18px}}@media(max-width:560px){.stats,.filter{grid-template-columns:1fr}.top{flex-direction:column}.main{padding:12px}}
</style>
<style>
/* Admin Dashboard design system */
:root{--bg:#0d1117;--bg2:#161b24;--bg3:#1e2533;--bg4:#252e40;--border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);--text:#e8edf5;--muted:#7a8a9e;--muted2:#4e5c6e;--aqua:#2dd4bf;--aqua-dim:rgba(45,212,191,.12);--blue:#3b82f6;--blue-dim:rgba(59,130,246,.12);--amber:#f59e0b;--amber-dim:rgba(245,158,11,.12);--purple:#a78bfa;--purple-dim:rgba(167,139,250,.12);--red:#f43f5e;--green:#22c55e;--sidebar-w:260px;--radius:14px;--radius-lg:20px}
html,body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px}.layout{grid-template-columns:var(--sidebar-w) 1fr}.sidebar{display:flex;flex-direction:column;gap:32px;width:auto;height:100vh;padding:28px 16px 24px;background:var(--bg2);border-right:1px solid var(--border)}.brand{gap:10px;margin:0;padding:0 8px}.brand img{width:38px;height:38px}.brand b{font:800 18px 'Plus Jakarta Sans',sans-serif;letter-spacing:-.3px}.brand span{color:var(--muted);font-size:10px;letter-spacing:1.2px;text-transform:uppercase}.nav{display:flex;flex-direction:column;gap:2px}.nav-label{margin:22px 0 6px;padding:0 12px;color:var(--muted2);font-size:10px;letter-spacing:1.4px}.nav-label:first-child{margin-top:0}.nav a{position:relative;gap:10px;margin:0;padding:10px 12px;border-radius:var(--radius);color:var(--muted);font-size:13.5px;font-weight:500}.nav a:hover{background:var(--bg3);color:var(--text)}.nav a.active{background:var(--aqua-dim);color:var(--aqua);font-weight:700;box-shadow:none}.nav a.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 3px 3px 0;background:var(--aqua)}.sidebar-user{display:flex;align-items:center;gap:10px;margin-top:auto;padding:10px 12px;border-top:1px solid var(--border);border-radius:var(--radius);background:var(--bg3)}.sidebar-avatar{display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);font-size:12px;font-weight:800}.sidebar-user b{display:block;font-size:12px}.sidebar-user span{color:var(--muted);font-size:10px}.sidebar-user a{margin-left:auto;color:var(--muted)}
.main{padding:0;background:var(--bg)}.activity-topbar{position:sticky;z-index:40;top:0;display:flex;align-items:center;justify-content:space-between;padding:18px 32px;border-bottom:1px solid var(--border);background:rgba(13,17,23,.85);backdrop-filter:blur(12px)}.activity-topbar .crumb{color:var(--muted);font-size:13px}.activity-topbar .crumb b{color:var(--text)}.activity-topbar .live{display:flex;align-items:center;gap:7px;padding:6px 14px;border:1px solid var(--border);border-radius:99px;background:var(--bg3);color:var(--muted);font-size:12px}.activity-topbar .dot{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 0 4px rgba(34,197,94,.1)}.activity-content{padding:28px 32px}.top{margin:0 0 24px}.top h1{color:#fff;font:800 26px 'Plus Jakarta Sans',sans-serif;letter-spacing:-.5px}.top p{margin-top:4px;color:var(--muted);font-size:13px}.admin-chip{padding:7px 14px;border:1px solid var(--border);border-radius:99px;background:var(--bg3);color:var(--muted);font-size:12px}.stats{gap:16px;margin-bottom:20px}.stat{position:relative;overflow:hidden;padding:22px 24px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg2);transition:.2s}.stat:hover{transform:translateY(-2px);border-color:var(--border2)}.stat::before{content:'';position:absolute;inset:0 0 auto;height:2px;background:var(--aqua)}.stat:nth-child(2)::before{background:var(--blue)}.stat:nth-child(3)::before{background:var(--purple)}.stat:nth-child(4)::before{background:var(--amber)}.stat i{color:var(--aqua);font-size:17px}.stat:nth-child(2) i{color:var(--blue)}.stat:nth-child(3) i{color:var(--purple)}.stat:nth-child(4) i{color:var(--amber)}.stat strong{margin-top:14px;color:#fff;font:800 26px 'Plus Jakarta Sans',sans-serif}.stat span{color:var(--muted);font-size:10px;font-weight:600}
.filter{padding:14px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg2)}.filter input,.filter select{border:1px solid var(--border);border-radius:var(--radius);background:var(--bg3);color:var(--text)}.filter input:focus,.filter select:focus{outline:0;border-color:rgba(45,212,191,.45)}.filter button{border-radius:var(--radius);background:var(--aqua);color:#0d1117}.clear{border-radius:var(--radius);background:var(--bg3);color:var(--muted)}.panel{border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg2);box-shadow:none}th{padding:13px 14px;background:var(--bg3);color:var(--muted);font-size:10px}td{border-top:1px solid var(--border);color:var(--text)}tbody tr:hover{background:rgba(255,255,255,.018)}.actor span,.sub{color:var(--muted)}.role{background:var(--blue-dim);color:var(--blue)}.role.staff{background:var(--purple-dim);color:var(--purple)}.role.rider{background:var(--amber-dim);color:var(--amber)}.role.customer{background:var(--aqua-dim);color:var(--aqua)}.role.guest{background:var(--bg4);color:var(--muted)}.meta summary{color:var(--aqua)}.meta pre{background:var(--bg3);color:var(--muted)}.pagination{border-color:var(--border)}.pages a{border-color:var(--border);color:var(--muted)}.pages a:hover{border-color:var(--border2);color:var(--text)}.pages a.active{border-color:rgba(45,212,191,.3);background:var(--aqua-dim);color:var(--aqua)}
@media(max-width:1000px){.layout{grid-template-columns:1fr}.sidebar{position:static;height:auto}.activity-content{padding:20px}.sidebar-user{margin-top:12px}}@media(max-width:560px){.activity-topbar,.activity-content{padding:16px 12px}.stats{grid-template-columns:1fr}.filter{grid-template-columns:1fr}}
@media(min-width:1001px){.layout{grid-template-columns:10px minmax(0,1fr)!important}.sidebar{width:var(--sidebar-w);position:sticky;left:0;top:0}}
</style>
    <link rel="stylesheet" href="../css/admin-theme.css">
    <script src="../js/admin-theme.js"></script>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <div class="brand"><img src="<?=htmlspecialchars(hydromis_asset_url($systemLogo, '../'))?>" alt="HydroMIS logo"><div><b>HydroMIS</b><span>Admin Portal</span></div></div>
  <nav class="nav">
    <div class="nav-label">Main</div>
    <a href="dashboard.php"><i class="fas fa-chart-pie"></i>Dashboard</a>
    <a href="transactions.php"><i class="fas fa-exchange-alt"></i>Transactions</a>
    <a href="reports.php"><i class="fas fa-chart-column"></i>Reports</a>
    <a href="inventory.php"><i class="fas fa-boxes-stacked"></i>Inventory</a>
    <div class="nav-label">People</div>
    <a href="users.php"><i class="fas fa-users"></i>Users</a>
    <a href="staff_account.php"><i class="fas fa-user-shield"></i>Staff Account</a>
    <a href="manage_riders.php"><i class="fas fa-motorcycle"></i>Riders</a>
    <div class="nav-label">System</div>
    <a class="active" href="activity_logs.php"><i class="fas fa-clock-rotate-left"></i>Activity Log</a>
    <a href="dashboard.php?open_settings=1"><i class="fas fa-cog"></i>Settings</a>
  </nav>
  <div class="sidebar-user">
    <div class="sidebar-avatar"><?=strtoupper(substr($_SESSION['full_name'] ?? 'A',0,1))?></div>
    <div><b><?=htmlspecialchars($_SESSION['full_name'] ?? 'Admin')?></b><span>Administrator</span></div>
    <a href="../logout.php" aria-label="Log out" title="Log out"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</aside>
<main class="main">
  <div class="activity-topbar"><div class="crumb"><i class="fas fa-house"></i> Admin / <b>Activity Log</b></div></div>
  <div class="activity-content">
  <header class="top"><div><h1>System Activity Log</h1><p>Audit page visits and actions performed across HydroMIS.</p></div></header>
  <section class="stats">
    <div class="stat"><i class="fas fa-calendar-day"></i><strong><?=number_format($todayCount)?></strong><span>Events today</span></div>
    <div class="stat"><i class="fas fa-calendar-week"></i><strong><?=number_format($weekCount)?></strong><span>Last 7 days</span></div>
    <div class="stat"><i class="fas fa-user-group"></i><strong><?=number_format($actorCount)?></strong><span>Active actors</span></div>
    <div class="stat"><i class="fas fa-database"></i><strong><?=number_format($totalRows)?></strong><span>Filtered records</span></div>
  </section>
  <form class="filter" method="get">
    <input type="search" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search actor, ID, action or details">
    <select name="role"><?php foreach($allowedRoles as $option): ?><option value="<?=$option?>" <?=$role===$option?'selected':''?>><?=ucfirst($option)?></option><?php endforeach; ?></select>
    <select name="action"><option value="">All actions</option><?php if($actions): while($item=$actions->fetch_assoc()): ?><option value="<?=htmlspecialchars($item['action'])?>" <?=$actionFilter===$item['action']?'selected':''?>><?=htmlspecialchars(ucwords(str_replace('_',' ',$item['action'])))?> (<?=$item['uses']?>)</option><?php endwhile; endif; ?></select>
    <input type="date" name="date" value="<?=htmlspecialchars($dateFilter)?>">
    <div style="display:flex;gap:7px"><button type="submit"><i class="fas fa-filter"></i>Filter</button><a class="clear" href="activity_logs.php"><i class="fas fa-xmark"></i></a></div>
  </form>
  <section class="panel">
    <?php if(!$logs || $logs->num_rows===0): ?><div class="empty"><i class="fas fa-clipboard-list" style="font-size:28px"></i><p>No activity matches these filters.</p></div>
    <?php else: ?><table><thead><tr><th>Date & time</th><th>Actor</th><th>Action</th><th>Description</th><th>Page / device</th><th>Context</th></tr></thead><tbody>
    <?php while($log=$logs->fetch_assoc()): $metadata=json_decode((string)$log['metadata'],true) ?: []; ?>
      <tr>
        <td><b><?=date('M d, Y',strtotime($log['created_at']))?></b><div class="sub"><?=date('h:i:s A',strtotime($log['created_at']))?></div></td>
        <td class="actor"><b><?=htmlspecialchars($log['actor_name'] ?: 'Unknown')?></b><span><?=htmlspecialchars($log['actor_id'] ?: $log['ip_address'])?></span><br><span class="role <?=htmlspecialchars($log['actor_role'])?>"><?=htmlspecialchars($log['actor_role'])?></span></td>
        <td><span class="action"><?=htmlspecialchars(ucwords(str_replace('_',' ',$log['action'])))?></span><div class="sub"><?=htmlspecialchars($log['request_method'])?></div></td>
        <td class="description"><?=htmlspecialchars($log['description'])?></td>
        <td><b><?=htmlspecialchars(basename((string)$log['page']) ?: 'Unknown')?></b><div class="sub"><i class="fas fa-<?=audit_device($log['user_agent'])==='Mobile'?'mobile-screen':'desktop'?>"></i> <?=audit_device($log['user_agent'])?> · <?=htmlspecialchars($log['ip_address'])?></div></td>
        <td class="meta"><?php if($metadata): ?><details><summary>View context</summary><pre><?=htmlspecialchars(json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre></details><?php else: ?><span class="sub">—</span><?php endif; ?></td>
      </tr>
    <?php endwhile; ?></tbody></table><?php endif; ?>
    <div class="pagination"><span>Showing <?=number_format(min($totalRows,$offset+1))?>–<?=number_format(min($totalRows,$offset+$perPage))?> of <?=number_format($totalRows)?></span><div class="pages"><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?><a class="<?=$p===$page?'active':''?>" href="<?=htmlspecialchars(audit_query(['p'=>$p]))?>"><?=$p?></a><?php endfor; ?></div></div>
  </section>
  </div>
</main>
</div>
</body>
</html>
