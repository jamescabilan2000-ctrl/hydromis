<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
require_once '../config/cap_request.php';

if (empty($_SESSION['pricing_csrf'])) $_SESSION['pricing_csrf'] = bin2hex(random_bytes(32));
$error = '';
$saved = !empty($_SESSION['pricing_saved']);
unset($_SESSION['pricing_saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['pricing_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $error = 'Refresh the page before saving prices.';
    } else {
        try {
            $prices = validate_container_prices($_POST['prices'] ?? null);
            $newCapPrice = validate_cap_price($_POST['capPrice'] ?? '');
            $newRules = validate_order_pricing_rules($_POST['rules'] ?? null);
            $mode = $_POST['refillPricingMode'] ?? '';
            if (!in_array($mode, ['quantity', 'per_gallon'], true)) {
                throw new InvalidArgumentException('Select a valid refill pricing method.');
            }
            ensure_system_settings_schema($conn);
            $adminId = (string)$_SESSION['admin_id'];
            $priceJson = json_encode($prices);
            $capValue = number_format($newCapPrice, 2, '.', '');
            $ruleJson = json_encode($newRules);
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by)
                VALUES ('container_bundle_prices', ?, ?), ('gallon_cap_unit_price', ?, ?), ('refill_pricing_mode', ?, ?), ('order_pricing_rules', ?, ?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)");
            $stmt->bind_param('ssssssss', $priceJson, $adminId, $capValue, $adminId, $mode, $adminId, $ruleJson, $adminId);
            $ok = $stmt->execute();
            if (!$ok) {
                $error = 'Prices could not be saved. Please try again.';
            } else {
                $_SESSION['pricing_saved'] = true;
                header('Location: pricing.php', true, 303);
                exit;
            }
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Prices could not be saved. Please try again.';
        }
    }
}
$containerPrices = system_container_prices($conn);
$capPrice = cap_unit_price($conn);
$refillMode = system_refill_pricing_mode($conn);
$pricingRules = system_order_pricing_rules($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Container Pricing - HydroMIS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-sidebar-hover.css">
    <style>
        :root{--bg:#0d1117;--bg2:#161c26;--bg3:#1e2633;--bg4:#252f3f;--border:#303b4e;--border2:#44536b;--text:#e6edf7;--muted:#99a9bf;--muted2:#7c8da6;--aqua:#33d6c5}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px system-ui,sans-serif}.shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh}.sidebar{padding:28px 16px;background:var(--bg2);border-right:1px solid var(--border)}.brand{display:flex;align-items:center;gap:10px;padding:0 12px 28px;font-weight:800;font-size:20px}.brand img{width:38px;height:38px}.nav-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;text-decoration:none;color:var(--muted)}.nav-item i{width:18px;text-align:center}.nav-item:hover,.nav-item.active{background:var(--bg3);color:var(--aqua)}.main{min-width:0;padding:32px}.main h1{margin:0 0 8px}.intro{color:var(--muted);margin-bottom:24px}.pricing-panel{max-width:850px}.settings-section{background:var(--bg2);border:1px solid var(--border);border-radius:18px;overflow:hidden}.settings-section-title{padding:18px;font-weight:700}.settings-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px;border-top:1px solid var(--border)}.settings-row-info{flex:1;min-width:0}.settings-row-label{font-weight:700}.settings-row-desc{font-size:12px;color:var(--muted);line-height:1.6}.settings-section>p{padding:0 18px}.settings-select{padding:10px;border:1px solid var(--border2);border-radius:10px;background:var(--bg4);color:var(--text);font:inherit;width:180px!important;max-width:45%}.settings-select:focus-visible{outline:2px solid var(--aqua);outline-offset:3px}.btn-save{margin-top:20px;padding:13px 24px;border:0;border-radius:10px;background:var(--aqua);color:#082c2a;font:700 14px system-ui;cursor:pointer}.notice{max-width:850px;padding:14px 18px;margin-bottom:20px;border-radius:10px;background:var(--bg3)}.notice.error{color:#ffb4b4}.notice.success{color:var(--aqua)}@media(max-width:850px){.shell{grid-template-columns:1fr}.sidebar{padding:16px}.brand{padding-bottom:12px}.sidebar nav{display:flex;gap:6px;overflow:auto}.nav-item{white-space:nowrap}.main{padding:24px 16px}}@media(max-width:480px){.settings-row{flex-wrap:wrap}.settings-row-info{flex-basis:100%}.settings-select{max-width:100%;width:100%!important}.btn-save{width:100%}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><img src="../imagess/hydromis-logo-v2.png" alt="HydroMIS logo">HydroMIS</div>
        <nav aria-label="Admin navigation">
            <a class="nav-item" href="dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a class="nav-item" href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a>
            <a class="nav-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            <a class="nav-item" href="inventory.php"><i class="fas fa-boxes-stacked"></i> Inventory</a>
            <a class="nav-item active" href="pricing.php" aria-current="page"><i class="fas fa-tags"></i> Container Pricing</a>
            <a class="nav-item" href="users.php"><i class="fas fa-users"></i> Users</a>
            <a class="nav-item" href="dashboard.php?open_settings=1"><i class="fas fa-cog"></i> Settings</a>
        </nav>
    </aside>
    <main class="main">
        <h1>Container Pricing</h1>
        <p class="intro">Manage regular refills, new containers, and gallon caps.</p>
        <?php if ($error): ?><div class="notice error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($saved): ?><div class="notice success" role="status">Prices saved successfully.</div><?php endif; ?>
        <form method="POST" action="pricing.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['pricing_csrf']) ?>">
            <?php require __DIR__ . '/pricing-fields.php'; ?>
            <button class="btn-save" type="submit"><i class="fas fa-floppy-disk"></i> Save Prices</button>
        </form>
        <section class="pricing-panel" style="margin-top:28px" aria-labelledby="price-list-title">
            <h2 id="price-list-title">Price list: 1–30 gallons</h2>
            <p class="intro">Updates as you edit. Refill totals exclude caps; delivery shows the standard fee before rewards. Save Prices to apply changes. Quantities above 30 use the same formula.</p>
            <label for="preview-container">Container type</label>
            <select class="settings-select" id="preview-container">
                <option value="2.5gal-slim">9.5 Liters Half Slim</option>
                <option value="5gal-slim">19 Liters Slim</option>
                <option value="5gal-round">19 Liters Round</option>
            </select>
            <div style="overflow-x:auto;margin-top:16px">
                <table style="width:100%;border-collapse:collapse;text-align:left">
                    <thead><tr><th scope="col">Gallons</th><th scope="col">Refill total</th><th scope="col">Delivery</th><th scope="col">Total with delivery</th></tr></thead>
                    <tbody id="pricing-preview"></tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<script src="../js/water-pricing.js"></script>
<script src="../js/admin-pricing.js"></script>
</body>
</html>
