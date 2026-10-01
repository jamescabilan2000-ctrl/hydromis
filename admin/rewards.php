<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/reward_catalog.php';
$systemLogo = system_logo_path($conn);
$catalog = system_reward_catalog($conn);
if (empty($_SESSION['rewards_csrf'])) $_SESSION['rewards_csrf'] = bin2hex(random_bytes(32));
$error = '';
$success = $_SESSION['rewards_flash'] ?? '';
unset($_SESSION['rewards_flash']);
$newReward = ['title' => '', 'description' => '', 'points' => '', 'tag' => 'Reward'];
$editCode = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit' ? ($_POST['code'] ?? '') : ($_GET['edit'] ?? '');
$editReward = null;
if (is_string($editCode) && $editCode !== '') {
    foreach ($catalog as $item) if ($item['code'] === $editCode) $editReward = $item;
    if ($editReward) $newReward = array_intersect_key($editReward, $newReward);
    else $error = 'Reward not found.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['rewards_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        $actor = (string)($_SESSION['admin_id'] ?? 'admin');
        if (in_array($_POST['action'] ?? '', ['add', 'edit'], true)) {
            $editing = $_POST['action'] === 'edit';
            if ($editing && !$editReward) throw new InvalidArgumentException('Reward not found.');
            foreach ($newReward as $field => $value) $newReward[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
            $reward = validate_new_reward($_POST);
            $code = $editing ? $editReward['code'] : 'custom_' . bin2hex(random_bytes(12));
            $stmt = $conn->prepare('INSERT INTO custom_rewards (code,title,description,points,tag,created_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),points=VALUES(points),tag=VALUES(tag)');
            $stmt->bind_param('sssiss', $code, $reward['title'], $reward['description'], $reward['points'], $reward['tag'], $actor);
            if (!$stmt->execute()) throw new RuntimeException('Unable to add reward.');
            $stmt->close();
            $_SESSION['rewards_flash'] = $editing ? 'Reward updated. Changes apply to future redemptions.' : 'Reward added. Customers can now redeem it with their points.';
        } elseif (($_POST['action'] ?? '') === 'settings') {
            $points = $_POST['points_per_gallon'] ?? '';
            if (!is_string($points) || !preg_match('/\A(?:0|[1-9][0-9]?|100)\z/', $points)) throw new InvalidArgumentException('Points per gallon must be a whole number from 0 to 100.');
            $enabled = $_POST['enabled'] ?? [];
            if (!is_array($enabled)) throw new InvalidArgumentException('Invalid reward selection.');
            ensure_system_settings_schema($conn);
            $conn->begin_transaction();
            try {
                // Schema initialization must run before the transaction; DDL commits in MySQL.
                $stmt = $conn->prepare('INSERT INTO system_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
                $key = 'points_per_gallon'; $value = $points;
                $stmt->bind_param('sss', $key, $value, $actor);
                if (!$stmt->execute()) throw new RuntimeException('Unable to save points.');
                foreach ($catalog as $reward) {
                    $key = 'reward_enabled_' . $reward['code'];
                    $value = isset($enabled[$reward['code']]) ? '1' : '0';
                    if (!$stmt->execute()) throw new RuntimeException('Unable to save reward availability.');
                }
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $_SESSION['rewards_flash'] = 'Reward settings saved.';
        } else { throw new InvalidArgumentException('Invalid action.'); }
        header('Location: rewards.php');
        exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
      catch (Throwable $e) { error_log('Reward management: ' . $e->getMessage()); $error = 'Unable to save rewards. Please try again.'; }
}
$pointsPerGallon = system_int_setting($conn, 'points_per_gallon', 1, 0, 100);
function reward_html($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rewards &amp; Loyalty — HydroMIS</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="../css/admin-sidebar-hover.css" rel="stylesheet">
<link rel="stylesheet" href="../css/admin-theme.css"><script src="../js/admin-theme.js"></script>
<script>document.addEventListener('DOMContentLoaded',function(){var nav=document.querySelector('.sidebar nav');if(!nav||nav.querySelector('[href="activity_logs.php"]'))return;var section=document.createElement('div');section.innerHTML='<div class="nav-section-label">System</div><div class="nav-group"><a href="activity_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i>Activity Log</a><a href="dashboard.php?open_settings=1" class="nav-item"><i class="fas fa-cog"></i>Settings</a></div>';nav.appendChild(section);});</script>
<style>
:root{--bg:#0d1117;--bg2:#161b24;--bg3:#1e2533;--border:rgba(255,255,255,.08);--text:#e8edf5;--muted:#7f91a8;--aqua:#2dd4bf;--blue:#3b82f6;--amber:#f59e0b;--red:#fb4765;--sidebar-w:260px}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif}.shell{display:grid;grid-template-columns:var(--sidebar-w) minmax(0,1fr);min-height:100vh}.sidebar{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;gap:28px;padding:28px 16px 22px;background:var(--bg2);border-right:1px solid var(--border)}.brand-logo,.admin-card,.nav-item{display:flex;align-items:center}.brand-logo{gap:11px}.brand-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#06b6d4)}.brand-name{font-weight:800}.brand-sub,.admin-role{color:var(--muted);font-size:10px}.nav-section-label{margin:0 12px 8px;color:#526176;font-size:9px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.nav-group{display:flex;flex-direction:column;gap:4px}.nav-item{position:relative;gap:10px;padding:10px 12px;border-radius:9px;color:var(--muted);font-size:13px;font-weight:600;text-decoration:none}.nav-item:hover{background:var(--bg3);color:var(--text)}.nav-item.active{background:rgba(45,212,191,.12);color:var(--aqua)}.nav-item i{width:18px;text-align:center}.sidebar-footer{margin-top:auto;padding-top:18px;border-top:1px solid var(--border)}.admin-card{gap:10px}.admin-avatar{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);font-weight:800}.admin-name{max-width:115px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;font-weight:700}.logout-link{margin-left:auto;color:#fb7185}.main{min-width:0}.topbar{min-height:68px;display:flex;align-items:center;justify-content:space-between;padding:0 30px;border-bottom:1px solid var(--border);background:rgba(13,17,23,.86);backdrop-filter:blur(12px)}.breadcrumb{display:flex;align-items:center;gap:9px;color:var(--muted);font-size:12px}.live{display:flex;align-items:center;gap:7px;color:#74e8d9;font-size:11px;font-weight:700}.live i{font-size:7px}.page{padding:28px 30px 45px}.heading{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:22px}.heading h1{margin:0 0 7px;font-size:25px}.heading p{margin:0;color:var(--muted);font-size:13px}.staff-link{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border:1px solid var(--border);border-radius:10px;color:#a9bad0;background:var(--bg2);text-decoration:none;font-size:12px;font-weight:700}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:13px;margin-bottom:20px}.stat,.panel{border:1px solid var(--border);border-radius:15px;background:var(--bg2);box-shadow:0 12px 28px rgba(0,0,0,.13)}.stat{padding:17px}.stat i{color:var(--blue)}.stat strong{display:block;margin:10px 0 3px;font-size:22px}.stat span{color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase}.panel{overflow:hidden;margin-bottom:20px}.panel-head{display:flex;justify-content:space-between;padding:17px 19px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800}.panel-head span{color:var(--muted);font-size:10px;font-weight:600}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px 15px;border-bottom:1px solid var(--border);text-align:left;font-size:11px}th{color:var(--muted);font-size:9px;text-transform:uppercase}tbody tr:hover{background:rgba(255,255,255,.025)}.badge{display:inline-flex;padding:5px 8px;border-radius:99px;font-size:8px;font-weight:800;text-transform:uppercase}.good{color:#6ee7b7;background:rgba(16,185,129,.12)}.low{color:#fcd34d;background:rgba(245,158,11,.12)}.out{color:#fda4af;background:rgba(244,63,94,.12)}.plus{color:#6ee7b7}.minus{color:#fda4af}.muted{color:var(--muted);font-size:9px;margin-top:3px}@media(max-width:1100px){.stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:850px){.shell{grid-template-columns:1fr}.sidebar{position:static;height:auto}.sidebar nav{display:grid!important;grid-template-columns:repeat(3,1fr);gap:10px!important}.sidebar-footer{display:none}.page{padding:20px 16px}.stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.heading{align-items:flex-start;flex-direction:column}.staff-link{width:100%;justify-content:center}.sidebar nav{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}.topbar{padding:0 16px}.table-wrap{overflow:hidden}table,tbody,tr,td{display:block;width:100%}thead{display:none}tr{padding:10px 14px;border-bottom:1px solid var(--border)}td{display:grid;grid-template-columns:105px 1fr;gap:10px;padding:7px 0;border:0}td::before{color:var(--muted);font-size:8px;font-weight:800;text-transform:uppercase}td:nth-child(1)::before{content:'Item'}td:nth-child(2)::before{content:'Category'}td:nth-child(3)::before{content:'Stock'}td:nth-child(4)::before{content:'Minimum'}td:nth-child(5)::before{content:'Unit price'}td:nth-child(6)::before{content:'Status'}td:nth-child(7)::before{content:'Updated by'}}
</style></head><body><div class="shell"><aside class="sidebar"><div class="brand-logo"><div class="brand-icon"><img src="../imagess/logosystem.png" alt="" width="24" height="24"></div><div><div class="brand-name">HydroMIS</div><div class="brand-sub">Admin</div></div></div><nav style="display:flex;flex-direction:column;gap:24px"><div><div class="nav-section-label">Main</div><div class="nav-group"><a href="dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i>Dashboard</a><a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i>Transactions</a><a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i>Reports</a><a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i>Inventory</a>
<a href="rewards.php" class="nav-item active"><i class="fas fa-gift"></i> Rewards &amp; Loyalty</a></div></div><div><div class="nav-section-label">People</div><div class="nav-group"><a href="users.php" class="nav-item"><i class="fas fa-users"></i>Users</a><a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i>Staff Account</a><a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i>Riders</a></div></div></nav><div class="sidebar-footer"><div class="admin-card"><div class="admin-avatar"><?php include __DIR__ . "/profile_avatar.php"; ?></div><div><div class="admin-name"><?php echo htmlspecialchars($_SESSION['full_name']??'Admin'); ?></div><div class="admin-role">Super Admin</div></div><a href="../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i></a></div></div></aside><main class="main"><header class="topbar"><div class="breadcrumb"><i class="fas fa-home"></i><i class="fas fa-chevron-right"></i><span>Rewards &amp; Loyalty</span></div><div class="live"><i class="fas fa-circle"></i></div></header>
<style>
.reward-edit{display:inline-block;padding:10px;color:var(--aqua);font-size:13px;font-weight:700;border-radius:8px}.reward-edit:focus-visible{outline:2px solid var(--aqua)}.reward-form{padding:20px}.reward-field{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;font-size:13px;font-weight:600}.reward-field input,.reward-field textarea{width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg3);color:var(--text);font:inherit}.reward-field textarea{resize:vertical;min-height:90px}.reward-field small,.reward-option small{color:var(--muted);font-weight:400;line-height:1.5}.reward-option{display:flex;gap:12px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--border);font-size:13px}.reward-option input{width:20px;height:20px;accent-color:var(--aqua);flex-shrink:0}.reward-option small{display:block;margin-top:5px}.reward-option strong{display:block}.reward-save{padding:12px 18px;border:0;border-radius:10px;background:var(--aqua);color:#082f2d;font-weight:800;cursor:pointer;margin-top:18px}.reward-columns{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:20px;align-items:start}.reward-notice{padding:14px;border:1px solid var(--aqua);border-radius:10px;margin-bottom:18px}.reward-notice.error{border-color:var(--red)}@media(max-width:1000px){.reward-columns{grid-template-columns:1fr}}
</style>
<section class="page">
<div class="heading"><div><h1>Rewards &amp; Loyalty</h1><p>Manage loyalty points and the rewards customers can redeem.</p></div></div>
<?php if ($error): ?><div class="reward-notice error" role="alert"><?= reward_html($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="reward-notice" role="status"><?= reward_html($success) ?></div><?php endif; ?>
<div class="reward-columns">
<section class="panel"><div class="panel-head">Reward settings</div>
<form method="post" class="reward-form">
<input type="hidden" name="csrf" value="<?= reward_html($_SESSION['rewards_csrf']) ?>"><input type="hidden" name="action" value="settings">
<label class="reward-field">Points earned per gallon<input type="number" name="points_per_gallon" min="0" max="100" step="1" required value="<?= $pointsPerGallon ?>"><small>Applies to new orders. Existing points are unchanged.</small></label>
<p>Choose the rewards available for redemption.</p>
<?php foreach ($catalog as $reward): ?>
<div class="reward-option"><label style="display:flex;gap:12px;flex:1"><input type="checkbox" name="enabled[<?= reward_html($reward['code']) ?>]" value="1" <?= system_int_setting($conn, 'reward_enabled_' . $reward['code'], 1, 0, 1) ? 'checked' : '' ?>><span><strong><?= reward_html($reward['title']) ?> &middot; <?= (int)$reward['points'] ?> points</strong><small><?= reward_html($reward['description']) ?></small></span></label><a class="reward-edit" href="rewards.php?edit=<?= rawurlencode($reward['code']) ?>#reward-editor" aria-label="Edit <?= reward_html($reward['title']) ?>">Edit</a></div>
<?php endforeach; ?>
<button class="reward-save" type="submit">Save reward settings</button>
</form></section>
<section class="panel" id="reward-editor"><div class="panel-head"><?= $editReward ? 'Edit redemption reward' : 'Add redemption reward' ?></div>
<form method="post" class="reward-form">
<input type="hidden" name="csrf" value="<?= reward_html($_SESSION['rewards_csrf']) ?>"><input type="hidden" name="action" value="<?= $editReward ? 'edit' : 'add' ?>">
<?php if ($editReward): ?><input type="hidden" name="code" value="<?= reward_html($editReward['code']) ?>"><?php endif; ?>
<label class="reward-field">Reward name<input name="title" maxlength="120" required value="<?= reward_html($newReward['title']) ?>" placeholder="e.g. Free water bottle"></label>
<label class="reward-field">Description<textarea name="description" maxlength="500" required placeholder="Describe what the customer receives and how to claim it."><?= reward_html($newReward['description']) ?></textarea></label>
<label class="reward-field">Points required<input type="number" name="points" min="1" max="999999" step="1" required value="<?= reward_html($newReward['points']) ?>"></label>
<label class="reward-field">Category<input name="tag" maxlength="40" required value="<?= reward_html($newReward['tag']) ?>" placeholder="e.g. Water Reward"></label>
<p class="muted"><?= $editReward ? 'Changes apply to future redemptions. Existing claims keep their recorded reward name and points cost. Delivery benefits remain tied to the original reward type.' : 'New rewards are available immediately. Staff approve and release them at the station.' ?></p>
<button class="reward-save" type="submit"><?= $editReward ? 'Save reward changes' : 'Add reward' ?></button>
<?php if ($editReward): ?><a class="reward-edit" href="rewards.php">Cancel edit</a><?php endif; ?>
</form></section>
</div></section></main></div></body></html>
