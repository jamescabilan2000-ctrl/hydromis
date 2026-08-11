<?php
require_once 'check_auth.php';
require_once '../config/database.php';

$conn->query("CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL UNIQUE,
    item_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Container',
    quantity INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 10,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    movement_type VARCHAR(20) NOT NULL,
    quantity_change INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reason VARCHAR(255) NULL,
    staff_id VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_item (item_id),
    INDEX idx_inventory_created (created_at)
)");

$defaults = [
    ['CNT-25-SLIM', '2.5 Gallon Slim Container', 20.00],
    ['CNT-5-SLIM', '5 Gallon Slim Container', 20.00],
    ['CNT-5-ROUND', '5 Gallon Round Container', 20.00],
];
$seed = $conn->prepare("INSERT IGNORE INTO inventory_items (item_code, item_name, category, quantity, minimum_stock, unit_price) VALUES (?, ?, 'Container', 0, 10, ?)");
foreach ($defaults as $default) {
    $seed->bind_param('ssd', $default[0], $default[1], $default[2]);
    $seed->execute();
}

if (empty($_SESSION['inventory_csrf'])) $_SESSION['inventory_csrf'] = bin2hex(random_bytes(32));
$success = '';
$error = '';
$showMovements = (($_GET['view'] ?? '') === 'movements');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $formAction = (string)($_POST['form_action'] ?? 'adjust_stock');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $action = (string)($_POST['stock_action'] ?? '');
    $amount = (int)($_POST['quantity'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = match ($action) {
            'stock_in' => 'Manual stock in',
            'stock_out' => 'Manual stock out',
            'set' => 'Exact stock correction',
            default => ''
        };
    }

    if (!hash_equals($_SESSION['inventory_csrf'], $token)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif ($formAction === 'add_item') {
        $categoryForCode = trim((string)($_POST['category'] ?? 'Container'));
        $codePrefix = match (strtolower($categoryForCode)) {
            'container' => 'CNT', 'water' => 'WTR', 'accessory' => 'ACC', default => 'ITM'
        };
        do {
            $itemCode = $codePrefix . '-' . strtoupper(bin2hex(random_bytes(4)));
            $codeCheck = $conn->prepare('SELECT id FROM inventory_items WHERE item_code = ? LIMIT 1');
            $codeCheck->bind_param('s', $itemCode);
            $codeCheck->execute();
            $codeExists = $codeCheck->get_result()->num_rows > 0;
        } while ($codeExists);
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'Container'));
        $initialQuantity = filter_var($_POST['initial_quantity'] ?? null, FILTER_VALIDATE_INT);
        $minimumStock = filter_var($_POST['minimum_stock'] ?? null, FILTER_VALIDATE_INT);
        $unitPrice = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,49}$/', $itemCode)) {
            $error = 'Item code must use 2–50 letters, numbers, or hyphens.';
        } elseif ($itemName === '' || strlen($itemName) > 150) {
            $error = 'Enter an item name of up to 150 characters.';
        } elseif ($category === '' || strlen($category) > 80) {
            $error = 'Enter a valid category.';
        } elseif ($initialQuantity === false || $initialQuantity < 0 || $minimumStock === false || $minimumStock < 0) {
            $error = 'Initial quantity and minimum stock must be zero or higher.';
        } elseif ($unitPrice === false || $unitPrice < 0) {
            $error = 'Enter a valid unit price.';
        } else {
            $staffId = (string)($_SESSION['staff_auth_id'] ?? $_SESSION['admin_id'] ?? 'STAFF');
            $conn->begin_transaction();
            $insert = $conn->prepare("INSERT INTO inventory_items (item_code, item_name, category, quantity, minimum_stock, unit_price, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert->bind_param('sssiids', $itemCode, $itemName, $category, $initialQuantity, $minimumStock, $unitPrice, $staffId);
            if ($insert->execute()) {
                $newItemId = $insert->insert_id;
                $saved = true;
                if ($initialQuantity > 0) {
                    $initialReason = 'Initial inventory stock';
                    $movementType = 'stock_in';
                    $zero = 0;
                    $log = $conn->prepare("INSERT INTO inventory_movements (item_id, movement_type, quantity_change, previous_quantity, new_quantity, reason, staff_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $log->bind_param('isiiiss', $newItemId, $movementType, $initialQuantity, $zero, $initialQuantity, $initialReason, $staffId);
                    $saved = $log->execute();
                }
                if ($saved) {
                    $conn->commit();
                    $success = $itemName . ' was added to inventory.';
                } else {
                    $conn->rollback();
                    $error = 'The item could not be added.';
                }
            } else {
                $conn->rollback();
                $error = $insert->errno === 1062 ? 'That item code already exists.' : 'The item could not be added.';
            }
        }
    } elseif ($itemId < 1 || !in_array($action, ['stock_in', 'stock_out', 'set'], true)) {
        $error = 'Select a valid inventory item and action.';
    } elseif ($amount < 0 || ($action !== 'set' && $amount < 1)) {
        $error = 'Enter a valid quantity.';
    } else {
        $itemQuery = $conn->prepare("SELECT quantity FROM inventory_items WHERE id = ? LIMIT 1");
        $itemQuery->bind_param('i', $itemId);
        $itemQuery->execute();
        $current = $itemQuery->get_result()->fetch_assoc();
        if (!$current) {
            $error = 'Inventory item was not found.';
        } else {
            $before = (int)$current['quantity'];
            $after = $action === 'stock_in' ? $before + $amount : ($action === 'stock_out' ? $before - $amount : $amount);
            if ($after < 0) {
                $error = 'Stock cannot be negative. Only ' . $before . ' item(s) are available.';
            } else {
                $staffId = (string)($_SESSION['staff_auth_id'] ?? $_SESSION['admin_id'] ?? 'STAFF');
                $change = $after - $before;
                $conn->begin_transaction();
                $update = $conn->prepare("UPDATE inventory_items SET quantity = ?, updated_by = ? WHERE id = ?");
                $update->bind_param('isi', $after, $staffId, $itemId);
                $log = $conn->prepare("INSERT INTO inventory_movements (item_id, movement_type, quantity_change, previous_quantity, new_quantity, reason, staff_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $log->bind_param('isiiiss', $itemId, $action, $change, $before, $after, $reason, $staffId);
                if ($update->execute() && $log->execute()) {
                    $conn->commit();
                    $success = 'Inventory updated successfully.';
                } else {
                    $conn->rollback();
                    $error = 'The inventory adjustment could not be saved.';
                }
            }
        }
    }
}

$items = $conn->query("SELECT * FROM inventory_items ORDER BY category, item_name");
$summaryResult = $conn->query("SELECT COUNT(*) total_items, COALESCE(SUM(quantity),0) total_units, SUM(quantity <= minimum_stock) low_stock, SUM(quantity = 0) out_of_stock FROM inventory_items");
$summary = $summaryResult ? $summaryResult->fetch_assoc() : [];
$movements = $conn->query("SELECT m.*, i.item_code, i.item_name, a.full_name staff_name FROM inventory_movements m LEFT JOIN inventory_items i ON i.id=m.item_id LEFT JOIN admin_users a ON a.admin_id=m.staff_id ORDER BY m.created_at DESC LIMIT 20");
$staff_active = 'inventory';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Inventory — HydroMIS</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="../css/staff-sidebar.css" rel="stylesheet"><link href="../css/staff-sidebar-size.css" rel="stylesheet"><link href="../css/staff-pages-unified.css" rel="stylesheet">
<style>
:root{--bg:#0b1120;--surface:#131d30;--surface2:#1a2847;--border:rgba(255,255,255,.09);--text:#e2efff;--muted:#8aa0c4;--blue:#3b82f6;--green:#10b981;--amber:#f59e0b;--red:#f43f5e}.page{padding:28px 30px 44px}.hero{padding:24px 26px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.hero h1{margin:0 0 6px;font-size:24px}.hero p{margin:0;color:var(--muted)}.hero-icon{width:55px;height:55px;border-radius:16px;background:rgba(59,130,246,.15);color:var(--blue);display:grid;place-items:center;font-size:23px}.flash{padding:13px 15px;border-radius:12px;margin-bottom:18px;border:1px solid}.flash.ok{color:#86efac;background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25)}.flash.err{color:#fda4af;background:rgba(244,63,94,.1);border-color:rgba(244,63,94,.25)}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}.stat{padding:18px}.stat i{color:var(--blue);font-size:18px}.stat strong{display:block;font-size:25px;margin-top:12px}.stat span{font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700}.inventory-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:22px}.item{padding:19px}.item-top{display:flex;justify-content:space-between;gap:10px}.item-code{font-size:10px;color:var(--muted);font-weight:800;letter-spacing:.8px}.item h3{font-size:14px;margin:5px 0}.stock{font-size:30px;font-weight:800;margin:18px 0 3px}.stock small{font-size:11px;color:var(--muted);font-weight:600}.status{padding:5px 8px;border-radius:99px;font-size:9px;font-weight:800;text-transform:uppercase}.status.good{color:#6ee7b7;background:rgba(16,185,129,.12)}.status.low{color:#fcd34d;background:rgba(245,158,11,.12)}.status.out{color:#fda4af;background:rgba(244,63,94,.12)}.adjust{display:grid;grid-template-columns:1fr 85px;gap:8px;margin-top:14px}.adjust select,.adjust input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:9px;border-radius:9px;font:inherit;min-width:0}.adjust .reason{grid-column:1/-1}.adjust button{grid-column:1/-1;background:var(--blue);color:#fff;border:0;border-radius:9px;padding:9px;font-weight:800;cursor:pointer}.table-card{overflow:hidden}.table-head{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:800}.table-wrap{width:100%;overflow:hidden}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{text-align:left;padding:12px 16px;font-size:12px;overflow-wrap:anywhere}th{font-size:9px;text-transform:uppercase;letter-spacing:.7px}.change.plus{color:#6ee7b7}.change.minus{color:#fda4af}.muted{color:var(--muted);font-size:10px;margin-top:3px}@media(max-width:1100px){.inventory-grid{grid-template-columns:1fr 1fr}.stats{grid-template-columns:1fr 1fr}}@media(max-width:700px){.inventory-grid,.stats{grid-template-columns:1fr}.page{padding:18px}.hero-icon{display:none}}
/* Keep inventory controls visible beside the desktop sidebar. */
.inventory-grid{
    grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));
    align-items:stretch;
    gap:18px;
}
.stats{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.inventory-grid,.stats,.item,.item-top>div{min-width:0}
.item{
    display:flex;
    flex-direction:column;
    min-height:285px;
    padding:22px;
    overflow:hidden;
}
.item-top{align-items:flex-start;flex-wrap:nowrap}
.item-top>div{flex:1}
.item h3{font-size:16px;line-height:1.35}
.status{
    flex:0 0 auto;
    max-width:46%;
    padding:7px 10px;
    white-space:normal;
    text-align:center;
    line-height:1.25;
}
.stock{margin:22px 0 16px;line-height:1.15}
.stock small{display:block;margin-top:7px;line-height:1.45}
.adjust{
    grid-template-columns:minmax(0,1fr) 100px;
    width:100%;
    max-width:100%;
    margin-top:auto;
    gap:10px;
}
.adjust select,.adjust input,.adjust button{
    box-sizing:border-box;
    width:100%;
    max-width:100%;
    min-height:43px;
}
.adjust button{transition:filter .2s ease,transform .2s ease}
.adjust button:hover{filter:brightness(1.08);transform:translateY(-1px)}
@media(max-width:480px){
    .adjust{grid-template-columns:1fr}
    .adjust .reason,.adjust button{grid-column:1}
    .item{min-height:0;padding:17px}
    .item-top{flex-wrap:wrap}
    .status{max-width:100%}
}
.table-wrap{max-width:100%;overflow-x:hidden}
.table-wrap table{width:100%;min-width:0;table-layout:fixed}
.table-wrap th,.table-wrap td{overflow-wrap:anywhere;word-break:break-word}
html,body,.layout,.main,.page{box-sizing:border-box;max-width:100%;min-width:0;overflow-x:hidden}
@media(max-width:768px){
    .staff-sidebar-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible}
    .staff-sidebar-nav a{min-width:0;justify-content:flex-start;text-align:left}
    .table-card{padding:0;background:transparent!important;border:0!important;box-shadow:none!important}
    .table-head{margin-bottom:10px;border:1px solid var(--border);border-radius:13px;background:var(--surface)}
    .table-wrap table,.table-wrap tbody,.table-wrap tr,.table-wrap td{display:block;width:100%}
    .table-wrap thead{display:none}
    .table-wrap tr{box-sizing:border-box;margin-bottom:12px;padding:8px 14px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}
    .table-wrap td{box-sizing:border-box;display:grid;grid-template-columns:105px minmax(0,1fr);gap:12px;padding:9px 0;border:0;border-bottom:1px solid var(--border);font-size:12px}
    .table-wrap td:last-child{border-bottom:0}
    .table-wrap td::before{color:var(--muted);font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
    .table-wrap td:nth-child(1)::before{content:"Date"}
    .table-wrap td:nth-child(2)::before{content:"Item"}
    .table-wrap td:nth-child(3)::before{content:"Movement"}
    .table-wrap td:nth-child(4)::before{content:"Before / After"}
    .table-wrap td:nth-child(5)::before{content:"Reason"}
    .table-wrap td:nth-child(6)::before{content:"Staff"}
}
@media(max-width:420px){
    .staff-sidebar-nav{grid-template-columns:1fr}
    .table-wrap td{grid-template-columns:90px minmax(0,1fr)}
}
.hero-actions{display:flex;align-items:center;gap:12px}.add-item-btn{display:inline-flex;align-items:center;gap:9px;padding:12px 17px;border:1px solid rgba(96,165,250,.28);border-radius:12px;background:linear-gradient(135deg,#2563eb,#3b82f6);box-shadow:0 10px 25px rgba(37,99,235,.25);color:#fff;font:800 13px inherit;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease}.add-item-btn:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(37,99,235,.34)}.inventory-modal{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(3,8,20,.72);backdrop-filter:blur(8px);opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s ease}.inventory-modal.open{opacity:1;visibility:visible}.modal-panel{width:min(100%,620px);max-height:calc(100vh - 40px);overflow:auto;padding:26px;border:1px solid rgba(255,255,255,.12);border-radius:20px;background:#131d30;box-shadow:0 30px 80px rgba(0,0,0,.45);transform:translateY(18px) scale(.98);transition:transform .28s cubic-bezier(.22,1,.36,1)}.inventory-modal.open .modal-panel{transform:none}.modal-head{display:flex;justify-content:space-between;gap:18px;margin-bottom:22px}.modal-head h2{margin:0 0 5px;font-size:21px}.modal-head p{margin:0;color:var(--muted);font-size:12px}.modal-close{display:grid;place-items:center;flex:0 0 38px;height:38px;border:1px solid var(--border);border-radius:10px;background:var(--surface2);color:var(--muted);cursor:pointer}.add-item-form{display:grid;grid-template-columns:1fr 1fr;gap:16px}.add-field{display:flex;flex-direction:column;gap:7px;min-width:0}.add-field.full{grid-column:1/-1}.add-field label{color:#a9bee0;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.add-field input,.add-field select{box-sizing:border-box;width:100%;min-width:0;height:45px;padding:0 13px;border:1px solid var(--border);border-radius:10px;background:var(--surface2);color:var(--text);font:inherit;outline:none;transition:border-color .2s ease,box-shadow .2s ease}.add-field input:focus,.add-field select:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(59,130,246,.14)}.modal-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;margin-top:4px}.modal-actions button{min-height:43px;padding:10px 16px;border-radius:10px;font-weight:800;cursor:pointer}.cancel-item{border:1px solid var(--border);background:transparent;color:var(--muted)}.save-item{border:0;background:var(--blue);color:#fff}.modal-open{overflow:hidden}@media(max-width:700px){.hero{align-items:flex-start;gap:18px}.hero-actions{width:100%}.add-item-btn{width:100%;justify-content:center}.add-item-form{grid-template-columns:1fr}.add-field.full,.modal-actions{grid-column:1}.modal-actions{flex-direction:column-reverse}.modal-actions button{width:100%}}
</style></head><body><div class="layout"><?php include 'sidebar.php'; ?><main class="main"><header class="topbar"><div><div class="topbar-title">Inventory</div><div class="topbar-subtitle">Container stock and adjustment history</div></div></header><section class="page">
<div class="hero"><div><h1>Stock Management</h1><p>Gallon products supply every order; a generic <strong>New Container</strong> item supplies the Buy new container add-on.</p></div><div class="hero-actions"><button type="button" class="add-item-btn" id="openAddItem"><i class="fas fa-plus"></i> Add Inventory Item</button><div class="hero-icon"><i class="fas fa-boxes-stacked"></i></div></div></div>
<?php if($success):?><div class="flash ok"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success);?></div><?php endif;?><?php if($error):?><div class="flash err"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>
<div class="stats"><div class="card stat"><i class="fas fa-box"></i><strong><?php echo (int)($summary['total_items']??0);?></strong><span>Inventory Items</span></div><div class="card stat"><i class="fas fa-layer-group"></i><strong><?php echo number_format((int)($summary['total_units']??0));?></strong><span>Total Units</span></div><div class="card stat"><i class="fas fa-triangle-exclamation" style="color:var(--amber)"></i><strong><?php echo (int)($summary['low_stock']??0);?></strong><span>Low Stock</span></div><div class="card stat"><i class="fas fa-circle-xmark" style="color:var(--red)"></i><strong><?php echo (int)($summary['out_of_stock']??0);?></strong><span>Out of Stock</span></div></div>
<div class="inventory-grid"><?php if($items): while($item=$items->fetch_assoc()): $qty=(int)$item['quantity'];$min=(int)$item['minimum_stock'];$state=$qty===0?'out':($qty<=$min?'low':'good');$label=$qty===0?'Out of stock':($qty<=$min?'Low stock':'In stock');?><article class="card item"><div class="item-top"><div><div class="item-code"><?php echo htmlspecialchars($item['item_code']);?></div><h3><?php echo htmlspecialchars($item['item_name']);?></h3></div><span class="status <?php echo $state;?>"><?php echo $label;?></span></div><div class="stock"><?php echo number_format($qty);?> <small>units available · minimum <?php echo $min;?></small></div><form method="post" class="adjust"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['inventory_csrf']);?>"><input type="hidden" name="item_id" value="<?php echo (int)$item['id'];?>"><select name="stock_action" aria-label="Action"><option value="stock_in">Stock in (+)</option><option value="stock_out">Stock out (-)</option><option value="set">Set exact stock</option></select><input type="number" name="quantity" min="0" required placeholder="Qty" aria-label="Quantity"><input class="reason" name="reason" maxlength="255" required placeholder="Reason (delivery, damaged, correction...)"><button type="submit"><i class="fas fa-floppy-disk"></i> Save adjustment</button></form></article><?php endwhile; endif;?></div>
<section class="card table-card"><div class="table-head"><i class="fas fa-clock-rotate-left"></i> Recent Stock Movements</div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Before → After</th><th>Reason</th><th>Staff</th></tr></thead><tbody><?php if($movements&&$movements->num_rows):while($move=$movements->fetch_assoc()):?><tr><td><?php echo date('M d, Y',strtotime($move['created_at']));?><div class="muted"><?php echo date('h:i A',strtotime($move['created_at']));?></div></td><td><strong><?php echo htmlspecialchars($move['item_name']);?></strong><div class="muted"><?php echo htmlspecialchars($move['item_code']);?></div></td><td class="change <?php echo (int)$move['quantity_change']>=0?'plus':'minus';?>"><?php echo (int)$move['quantity_change']>=0?'+':'';echo (int)$move['quantity_change'];?></td><td><?php echo (int)$move['previous_quantity'];?> → <?php echo (int)$move['new_quantity'];?></td><td><?php echo htmlspecialchars($move['reason']);?></td><td><?php echo htmlspecialchars($move['staff_name']?:$move['staff_id']);?></td></tr><?php endwhile;else:?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">No inventory movements recorded yet.</td></tr><?php endif;?></tbody></table></div></section>
</section></main></div>
<div class="inventory-modal<?php echo ($error && ($_POST['form_action'] ?? '') === 'add_item') ? ' open' : ''; ?>" id="addItemModal" aria-hidden="true">
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="addItemTitle">
    <div class="modal-head"><div><h2 id="addItemTitle">Add inventory item</h2><p>Create a new item and optionally record its starting stock.</p></div><button type="button" class="modal-close" id="closeAddItem" aria-label="Close"><i class="fas fa-xmark"></i></button></div>
    <form method="post" class="add-item-form">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['inventory_csrf']); ?>">
      <input type="hidden" name="form_action" value="add_item">
      <div class="add-field"><label for="category">Category</label><select id="category" name="category" required><option value="Container">Container</option><option value="Water">Water</option><option value="Accessory">Accessory</option><option value="Other">Other</option></select></div>
      <div class="add-field full"><label for="item_name">Item name</label><input id="item_name" name="item_name" maxlength="150" placeholder="e.g. 10 Gallon Blue Container" required value="<?php echo htmlspecialchars($_POST['item_name'] ?? ''); ?>"></div>
      <div class="add-field"><label for="initial_quantity">Starting quantity</label><input type="number" id="initial_quantity" name="initial_quantity" min="0" value="<?php echo htmlspecialchars($_POST['initial_quantity'] ?? '0'); ?>" required></div>
      <div class="add-field"><label for="minimum_stock">Low-stock level</label><input type="number" id="minimum_stock" name="minimum_stock" min="0" value="<?php echo htmlspecialchars($_POST['minimum_stock'] ?? '10'); ?>" required></div>
      <div class="add-field full"><label for="unit_price">Unit price (PHP)</label><input type="number" id="unit_price" name="unit_price" min="0" step="0.01" placeholder="0.00" value="<?php echo htmlspecialchars($_POST['unit_price'] ?? '0.00'); ?>" required></div>
      <div class="modal-actions"><button type="button" class="cancel-item" id="cancelAddItem">Cancel</button><button type="submit" class="save-item"><i class="fas fa-plus"></i> Add item</button></div>
    </form>
  </div>
</div>
<script>
const inventoryRevisionStyles=document.createElement('style');inventoryRevisionStyles.textContent=`
.adjust{grid-template-columns:minmax(0,1fr) 108px;gap:11px}.adjust-field{display:flex;flex-direction:column;gap:6px;min-width:0}.adjust-field.reason-field{grid-column:1/-1}.adjust-field label{color:#8fa7ca;font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.adjust select,.adjust input{height:45px;padding:0 12px;border-color:rgba(255,255,255,.12);outline:none;transition:border-color .2s ease,box-shadow .2s ease}.adjust select:focus,.adjust input:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(59,130,246,.14)}.adjust .adjust-preview{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:34px;padding:7px 10px;border:1px dashed rgba(96,165,250,.25);border-radius:9px;background:rgba(59,130,246,.07);color:#8fa7ca;font-size:10px}.adjust .adjust-preview strong{color:#bfdbfe}.adjust button{min-height:46px;border-radius:10px;font-size:12px}.adjust button:disabled{cursor:not-allowed;filter:none;opacity:.55;transform:none}@media(max-width:480px){.adjust{grid-template-columns:1fr}.adjust-field,.adjust-field.reason-field{grid-column:1}}
`;document.head.appendChild(inventoryRevisionStyles);

document.querySelectorAll('.adjust').forEach(form=>{
  form.autocomplete='off';
  const itemCard=form.closest('.item'),stockNote=itemCard?.querySelector('.stock small'),itemName=itemCard?.querySelector('h3')?.textContent.trim().toLowerCase();
  if(stockNote&&itemName==='new container')stockNote.textContent=stockNote.textContent.replace('units available','new containers available');
  const action=form.querySelector('[name="stock_action"]'),quantity=form.querySelector('[name="quantity"]'),reason=form.querySelector('[name="reason"]'),button=form.querySelector('button[type="submit"]');
  const wrap=(control,label,className='')=>{const field=document.createElement('div');field.className='adjust-field '+className;const caption=document.createElement('label');caption.textContent=label;field.append(caption);control.before(field);field.append(control);return field};
  wrap(action,'Adjustment type');wrap(quantity,'Quantity');reason.required=false;reason.remove();
  quantity.inputMode='numeric';
  const preview=document.createElement('div');preview.className='adjust-preview';form.insertBefore(preview,button);
  const current=parseInt(form.closest('.item').querySelector('.stock').textContent.replace(/,/g,''),10)||0;
  function refreshAdjustment(){
    const amount=Number.parseInt(quantity.value,10);let next=current;let wording='Enter a quantity to preview the new stock';
    quantity.placeholder=action.value==='set'?'New total':'Units';
    if(Number.isInteger(amount)&&amount>=0){next=action.value==='stock_in'?current+amount:action.value==='stock_out'?current-amount:amount;wording=next<0?'Not enough stock for this adjustment':'Stock after saving';}
    preview.innerHTML='<span>'+wording+'</span><strong>'+current+' &rarr; '+Math.max(0,next)+'</strong>';
    const quantityValid=Number.isInteger(amount)&&amount>=0&&(action.value==='set'||amount>0)&&next>=0;
    button.disabled=!quantityValid;
  }
  action.addEventListener('change',refreshAdjustment);quantity.addEventListener('input',refreshAdjustment);refreshAdjustment();
});

const recentMovements=document.querySelector('.table-card');
if(recentMovements){
  recentMovements.id='recent-stock-movements';
  const showMovements=<?php echo $showMovements ? 'true' : 'false'; ?>;
  recentMovements.hidden=!showMovements;
  if(showMovements)setTimeout(()=>recentMovements.scrollIntoView({behavior:'smooth',block:'start'}),100);
}

const addModal=document.getElementById('addItemModal'),openAdd=document.getElementById('openAddItem'),closeAdd=document.getElementById('closeAddItem'),cancelAdd=document.getElementById('cancelAddItem');
function setAddModal(show){addModal.classList.toggle('open',show);addModal.setAttribute('aria-hidden',show?'false':'true');document.body.classList.toggle('modal-open',show);if(show)setTimeout(()=>document.getElementById('item_name').focus(),120)}
openAdd.addEventListener('click',()=>setAddModal(true));closeAdd.addEventListener('click',()=>setAddModal(false));cancelAdd.addEventListener('click',()=>setAddModal(false));addModal.addEventListener('click',e=>{if(e.target===addModal)setAddModal(false)});document.addEventListener('keydown',e=>{if(e.key==='Escape')setAddModal(false)});if(addModal.classList.contains('open')){document.body.classList.add('modal-open');addModal.setAttribute('aria-hidden','false')}
</script>
</body></html>
