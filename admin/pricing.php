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
$systemLogo = system_logo_path($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Container Pricing - HydroMIS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-pricing.css?v=20261010b">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="../css/admin-pricing-sidebar.css">
    <link rel="stylesheet" href="../css/admin-sidebar-hover.css">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand-logo">
            <div class="brand-icon"><img src="<?= htmlspecialchars(hydromis_asset_url($systemLogo, '../')) ?>" alt="HydroMIS logo" style="width:24px;height:24px;object-fit:contain;"></div>
            <div>
                <div class="brand-name">HydroMIS</div>
                <div class="brand-sub">Admin Portal</div>
            </div>
        </div>
        <nav style="display:flex;flex-direction:column;gap:24px;">
            <div>
                <div class="nav-section-label">Main</div>
                <div class="nav-group">
                    <a href="dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
                    <a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i> Transactions </a>
                    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a>
                    <a href="feedback.php" class="nav-item"><i class="fas fa-comments"></i> Customer Feedback</a>
                    <a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Inventory</a>
<a href="pricing.php" class="nav-item active" aria-current="page"><i class="fas fa-tags"></i> Container Pricing</a>
<a href="rewards.php" class="nav-item"><i class="fas fa-gift"></i> Rewards &amp; Loyalty</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users </a>
                    <a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i> Staff Account</a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">System</div>
                <div class="nav-group">
                    <a href="activity_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
                    <a href="dashboard.php?open_settings=1" class="nav-item"><i class="fas fa-cog"></i> Settings</a>

                </div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar" id="sidebarAvatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($_SESSION['avatar_path']) && hydromis_object_exists($_SESSION['avatar_path'])): ?>
                        <img src="<?= htmlspecialchars(hydromis_storage_url($_SESSION['avatar_path'])) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="admin-name"><?=htmlspecialchars($_SESSION['full_name']??'Admin')?></div>
                    <div class="admin-role">Administrator</div>
                </div>
                <a href="../logout.php" class="logout-link" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="page-heading"><div><p class="eyebrow">ADMIN / PRICING</p><h1>Container Pricing</h1><p class="intro">Set your prices. See what customers will pay.</p></div></header>
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
