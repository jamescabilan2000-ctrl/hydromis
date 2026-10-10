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
    <link rel="stylesheet" href="../css/admin-pricing.css?v=20261010">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><img src="../imagess/hydromis-logo-v2.png" alt="HydroMIS logo">HydroMIS</div>
        <nav aria-label="Admin navigation">
            <a class="nav-item" href="dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a class="nav-item" href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a>
            <a class="nav-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            <a class="nav-item" href="feedback.php"><i class="fas fa-comments"></i> Customer Feedback</a>
            <a class="nav-item" href="inventory.php"><i class="fas fa-boxes-stacked"></i> Inventory</a>
            <a class="nav-item active" href="pricing.php" aria-current="page"><i class="fas fa-tags"></i> Container Pricing</a>
            <a class="nav-item" href="rewards.php"><i class="fas fa-gift"></i> Rewards &amp; Loyalty</a>
            <a class="nav-item" href="users.php"><i class="fas fa-users"></i> Users</a>
            <a class="nav-item" href="staff_account.php"><i class="fas fa-user-shield"></i> Staff Account</a>
            <a class="nav-item" href="manage_riders.php"><i class="fas fa-motorcycle"></i> Riders</a>
            <a class="nav-item" href="activity_logs.php"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
            <a class="nav-item" href="dashboard.php?open_settings=1"><i class="fas fa-cog"></i> Settings</a>
            <a class="nav-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
    </aside>
    <main class="main">
        <header class="page-heading"><div><p class="eyebrow">ADMIN / PRICING</p><h1>Container Pricing</h1><p class="intro">Set your prices. See what customers will pay.</p></div><span class="page-badge"><i class="fas fa-tags" aria-hidden="true"></i> All prices in PHP</span></header>
        <?php if ($error): ?><div class="notice error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($saved): ?><div class="notice success" role="status">Prices saved successfully.</div><?php endif; ?>
        <div class="pricing-layout">
        <form method="POST" action="pricing.php" id="pricing-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['pricing_csrf']) ?>">
            <?php require __DIR__ . '/pricing-fields.php'; ?>
            <div class="save-bar"><div><strong id="pricing-save-state" role="status">Prices up to date</strong><p>Changes apply to new or resubmitted orders.</p></div><button class="btn-save" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save Prices</button></div>
        </form>
        <section class="preview-card" aria-labelledby="price-list-title">
            <div class="preview-heading"><span class="card-icon"><i class="fas fa-list" aria-hidden="true"></i></span><div><h2 id="price-list-title">Live price preview</h2><p>All quantities from 1 to 30 gallons</p></div><span class="live-badge">Live</span></div>
            <div class="preview-controls"><label class="field-label" for="preview-container">Preview container</label><select class="price-input" id="preview-container">
                <option value="2.5gal-slim">9.5 Liters ? Half Slim</option>
                <option value="5gal-slim">19 Liters ? Slim</option>
                <option value="5gal-round">19 Liters ? Round</option>
            </select></div>
            <div class="preview-example"><div><span>5-gallon order</span><strong id="preview-example-total">?</strong></div><p id="preview-example-detail">Refill + standard delivery</p></div>
            <div class="preview-table-wrap" tabindex="0" role="region" aria-label="Price list for 1 to 30 gallons">
                <table class="preview-table"><thead><tr><th scope="col">Gallons</th><th scope="col">Refill</th><th scope="col">Delivery</th><th scope="col">Total</th></tr></thead><tbody id="pricing-preview"></tbody></table>
            </div>
            <p class="preview-footnote"><i class="fas fa-circle-info" aria-hidden="true"></i> Includes standard delivery. Caps and reward discounts are excluded. Save Prices to apply your edits.</p>
        </section>
        </div>
    </main>
</div>
<script src="../js/water-pricing.js"></script>
<script src="../js/admin-pricing.js"></script>
</body>
</html>
