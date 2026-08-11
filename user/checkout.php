<?php
require_once '../config/database.php';
require_once '../config/system_settings.php';
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);
$systemLogo = system_logo_path($conn);

$user_id = null;
if (isset($_POST['user_id'])) {
    $user_id = sanitize($_POST['user_id']);
} elseif (isset($_GET['user_id'])) {
    $user_id = sanitize($_GET['user_id']);
}

if (!$user_id) {
    header('Location: scan_qr.php');
    exit;
}

$sql = "SELECT * FROM users WHERE user_id = '$user_id'";
$result = $conn->query($sql);
if (!$result || $result->num_rows === 0) {
    header('Location: scan_qr.php');
    exit;
}
$scanned_data = $result->fetch_assoc();
if (strtolower((string)($scanned_data['status'] ?? 'pending')) !== 'approved') {
    header('Location: scan_qr.php?approval_required=' . urlencode((string)($scanned_data['status'] ?? 'pending')));
    exit;
}
$free_delivery_reward = null;
$safe_reward_user = $conn->real_escape_string((string)$user_id);
$free_delivery_result = $conn->query("SELECT id,transaction_id FROM reward_claims WHERE user_id='$safe_reward_user' AND reward_code='free_delivery' AND claim_status='approved' ORDER BY created_at ASC LIMIT 1");
if ($free_delivery_result) $free_delivery_reward = $free_delivery_result->fetch_assoc();

// Load dynamic GCash/Maya QR Settings from DB
$qr_gcash = ['qr_image_path' => 'imagess/cashg.jpg', 'account_number' => '0993 909 3915', 'account_name' => 'James C.'];
$qr_maya  = ['qr_image_path' => 'imagess/ayam.jpg', 'account_number' => '0993 909 3915', 'account_name' => 'James C.'];
$qrResult = $conn->query("SELECT payment_method, qr_image_path, account_number, account_name FROM payment_qr_settings");
if ($qrResult) {
    while ($qrRow = $qrResult->fetch_assoc()) {
        if ($qrRow['payment_method'] === 'gcash') $qr_gcash = $qrRow;
        if ($qrRow['payment_method'] === 'maya')  $qr_maya  = $qrRow;
    }
}

$allowed_sizes = ['5gal-round', '2.5gal-slim', '5gal-slim'];
$allowed_status = ['new', 'existing'];
$allowed_fulfillment = ['delivery', 'pickup'];

$container_size = isset($_POST['container_size']) ? sanitize($_POST['container_size']) : '2.5gal-slim';
$container_status = isset($_POST['container_status']) ? sanitize($_POST['container_status']) : 'new';
$fulfillment_method = isset($_POST['fulfillment_method']) ? sanitize($_POST['fulfillment_method']) : 'delivery';
$quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
$amount_tendered = isset($_POST['amount_tendered']) ? floatval($_POST['amount_tendered']) : 0;

if (!in_array($container_size, $allowed_sizes, true)) {
    $container_size = '2.5gal-slim';
}
if (!in_array($container_status, $allowed_status, true)) {
    $container_status = 'new';
}
if (!in_array($fulfillment_method, $allowed_fulfillment, true)) {
    $fulfillment_method = 'delivery';
}
if ($quantity < 1) {
    $quantity = 1;
}

// Every order uses the selected gallon stock. "Buy new container" additionally
// requires the generic New Container inventory item.
$available_stock = null;
$stock_blocked = false;
$inventory_code = inventory_code_for_container($container_size);
$stock_stmt = $conn->prepare('SELECT quantity FROM inventory_items WHERE item_code = ? LIMIT 1');
if ($stock_stmt && $inventory_code !== null) {
    $stock_stmt->bind_param('s', $inventory_code);
    $stock_stmt->execute();
    $stock_row = $stock_stmt->get_result()->fetch_assoc();
    $available_stock = max(0, (int)($stock_row['quantity'] ?? 0));
} else {
    $available_stock = 0;
}
$new_container_stock = null;
if ($container_status === 'new') {
    $new_container_item = new_container_inventory_item($conn);
    $new_container_stock = max(0, (int)($new_container_item['quantity'] ?? 0));
}
$stock_blocked = $quantity > $available_stock || ($container_status === 'new' && $quantity > $new_container_stock);

$size_map = [
    '5gal-round' => '5 Gallon',
    '2.5gal-slim' => '2.5 Gallon',
    '5gal-slim' => '5 Gallon'
];

$type_map = [
    '5gal-round' => 'round',
    '2.5gal-slim' => 'slim',
    '5gal-slim' => 'slim'
];

$price_map = [
    '5gal-round' => ['new' => 20, 'pickup' => 20],
    '2.5gal-slim' => ['new' => 35, 'pickup' => 15],
    '5gal-slim' => ['new' => 50, 'pickup' => 40]
];

$pickup_base_map = [
    '5gal-round' => 20,
    '2.5gal-slim' => 15,
    '5gal-slim' => 40
];

$container_image_map = [
    '5gal-round' => '../imagess/water5.webp',
    '2.5gal-slim' => '../imagess/water3.jpg',
    '5gal-slim' => '../imagess/water4.webp'
];

// Get user's registered address
$user_address = isset($scanned_data['address']) ? $scanned_data['address'] : 'No address on file';
$user_contact = isset($scanned_data['contact_number']) ? $scanned_data['contact_number'] : '';

$pickup_base = $pickup_base_map[$container_size];
$new_container = $container_status === 'new' ? 20 * $quantity : 0;
$water_total = $pickup_base * $quantity;
$price_per_unit = $pickup_base + ($container_status === 'new' ? 20 : 0);
$item_total = $price_per_unit * $quantity;

$discount_count = floor($quantity / 5);
$discount = $discount_count > 0 ? ($discount_count * 5) : 0;
$delivery_fee = $fulfillment_method === 'delivery' && !$free_delivery_reward ? 10 * $quantity : 0;
$final_total = $item_total + $delivery_fee - $discount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/public-ui.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background: #f0f2f5;
            color: #2b3b4f;
        }

        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 12px 0;
        }

        .navbar-brand {
            font-size: 24px;
            font-weight: 800;
            color: #1f2937 !important;
            letter-spacing: -0.4px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .navbar-brand i {
            color: #2563eb;
        }

        .checkout-wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 16px;
        }

        .panel {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .panel-header {
            background: linear-gradient(135deg, #f8fafb 0%, #f3f4f6 100%);
            padding: 22px 32px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 16px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .panel-body {
            padding: 16px;
        }

        .delivery-section {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .delivery-icon {
            font-size: 20px;
            color: #0891b2;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .delivery-info {
            flex: 1;
        }

        .delivery-title {
            font-size: 16px;
            font-weight: 800;
            color: #1f2937;
            margin: 0 0 4px;
        }

        .delivery-date {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .delivery-time {
            font-size: 13px;
            color: #9ca3af;
            margin-bottom: 8px;
        }

        .change-link {
            color: #f97316;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }

        .change-link:hover {
            text-decoration: underline;
        }

        .address-box {
            background: #fdf4ec;
            border: 1px dashed #f9a16f;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .address-box i {
            color: #f97316;
            font-size: 14px;
        }

        .address-btn {
            background: transparent;
            border: 1px solid #f9a16f;
            color: #f97316;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .address-btn:hover {
            background: #fdf4ec;
            border-color: #f97316;
        }

        .summary-table {
            width: 100%;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: #6b7280;
            font-weight: 500;
        }

        .summary-value {
            color: #1f2937;
            font-weight: 600;
            text-align: right;
        }

        .summary-value.blue {
            color: #2563eb;
        }

        .total-section {
            background: #f8fafb;
            padding: 12px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 16px;
            color: #1f2937;
        }

        .quantity-inline {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #6b7280;
        }

        .qty-compact {
            display: inline-flex;
            align-items: center;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            overflow: hidden;
            background: #f9fafb;
        }

        .qty-compact button {
            border: none;
            background: transparent;
            color: #2563eb;
            font-size: 14px;
            font-weight: 600;
            width: 24px;
            height: 26px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .qty-compact button:hover {
            background: #f3f4f6;
        }

        .qty-compact span {
            width: 30px;
            text-align: center;
            font-weight: 600;
            color: #1f2937;
        }

        .clear-cart {
            color: #f97316;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }

        .clear-cart:hover {
            text-decoration: underline;
        }

        .final-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            font-size: 16px;
            font-weight: 800;
            color: #1f2937;
        }

        .final-amount {
            font-size: 18px;
            color: #059669;
        }

        .payment-input-section {
            padding: 16px;
            background: #f8fafb;
            border-top: 1px solid #e5e7eb;
        }

        .payment-label {
            font-size: 13px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 8px;
            display: block;
        }

        .payment-input {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }

        .payment-input:focus {
            outline: none;
            border-color: #2563eb;
            background: #ffffff;
        }

        .checkout-btn {
            width: 100%;
            padding: 14px 16px;
            background: linear-gradient(180deg, #fb8500 0%, #f97316 100%);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 6px 16px rgba(249, 115, 22, 0.25);
        }

        .checkout-btn:hover:not(:disabled) {
            background: linear-gradient(180deg, #f97316 0%, #ea580c 100%);
            transform: translateY(-1px);
        }

        .checkout-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .comment-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .comment-label {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .comment-textarea {
            width: 100%;
            min-height: 80px;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Manrope', sans-serif;
            resize: vertical;
            transition: all 0.2s ease;
            color: #1f2937;
        }

        .comment-textarea:focus {
            outline: none;
            border-color: #2563eb;
            background: #f0f9ff;
        }

        .comment-hint {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 6px;
        }

        .validation-error {
            color: #ef4663;
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .address-box.filled {
            background: #ecfdf5;
            border: 1px solid #0d9488;
        }

        .address-box.filled i {
            color: #0d9488;
        }

        .address-text {
            color: #1f2937;
            font-weight: 600;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 18px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 11px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Manrope', sans-serif;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: #f0f9ff;
        }

        .form-control.error {
            border-color: #ef4663;
        }

        .form-control.success {
            border-color: #0d9488;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .btn-primary {
            flex: 1;
            padding: 11px 14px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-secondary {
            flex: 1;
            padding: 11px 14px;
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .validation-message {
            font-size: 12px;
            margin-top: 6px;
            display: none;
        }

        .validation-message.error {
            color: #ef4663;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .validation-message.success {
            color: #0d9488;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .validation-message.success {
            color: #0d9488;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .error-box {
            display: none;
            background: #fef2f2;
            border: 2px solid #ef4663;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 16px;
            animation: slideDown 0.3s ease;
        }

        .error-box.active {
            display: block;
        }

        .error-box-title {
            font-weight: 700;
            color: #dc2626;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .error-box-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .error-box-list li {
            color: #991b1b;
            font-size: 13px;
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .address-box:hover {
            background: #fff7f0;
            border-color: #fb8500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        /* Payment Method Styles */
        .payment-method-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .payment-method-label {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .payment-method-label i {
            color: #2563eb;
        }

        .payment-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .payment-option {
            position: relative;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
            background: #fafbfc;
            user-select: none;
        }

        .payment-option:hover {
            border-color: #c7d2fe;
            background: #f5f7ff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
        }

        .payment-option.selected {
            border-color: #2563eb;
            background: linear-gradient(135deg, #eff4ff 0%, #e8f0fe 100%);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.12);
        }

        .payment-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .payment-radio-circle {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #d1d5db;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .payment-option.selected .payment-radio-circle {
            border-color: #2563eb;
            background: #2563eb;
        }

        .payment-radio-circle::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: transparent;
            transition: all 0.2s ease;
        }

        .payment-option.selected .payment-radio-circle::after {
            background: #ffffff;
        }

        .payment-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
        }

        .payment-icon-box.cash {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #059669;
        }

        .payment-icon-box.gcash {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #2563eb;
        }

        .payment-icon-box.maya {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #16a34a;
        }

        .payment-details-text {
            flex: 1;
        }

        .payment-details-text h4 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
        }

        .payment-details-text p {
            margin: 2px 0 0;
            font-size: 12px;
            color: #9ca3af;
            font-weight: 500;
        }

        .payment-option.selected .payment-details-text h4 {
            color: #1e40af;
        }

        .payment-check {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #2563eb;
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }

        .payment-option.selected .payment-check {
            display: flex;
        }

        .payment-error-msg {
            color: #ef4663;
            font-size: 12px;
            margin-top: 8px;
            display: none;
            align-items: center;
            gap: 4px;
        }

        .payment-error-msg.active {
            display: flex;
        }

        /* GCash QR Details Panel */
        .gcash-qr-panel {
            display: none;
            background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
            border: 2px solid #93c5fd;
            border-radius: 12px;
            padding: 20px;
            margin-top: 10px;
            text-align: center;
            animation: slideDown 0.3s ease;
        }

        .gcash-qr-panel.active {
            display: block;
        }

        .gcash-qr-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .gcash-qr-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #1e40af;
        }

        .gcash-qr-header i {
            color: #2563eb;
            font-size: 16px;
        }

        .gcash-qr-image {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            display: inline-block;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.1);
            margin-bottom: 14px;
        }

        .gcash-qr-image img {
            width: 200px;
            height: 200px;
            object-fit: contain;
            border-radius: 8px;
        }

        .gcash-account-info {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 18px;
            border: 1px solid #bfdbfe;
        }

        .gcash-account-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }

        .gcash-account-row:not(:last-child) {
            border-bottom: 1px solid #e5e7eb;
        }

        .gcash-account-row .label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .gcash-account-row .value {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            letter-spacing: 0.5px;
        }

        .gcash-account-row .value.blue {
            color: #2563eb;
        }

        .gcash-note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 10px;
            font-style: italic;
        }

        /* Maya QR Details Panel */
        .maya-qr-panel {
            display: none;
            background: linear-gradient(135deg, #f0fdf4 0%, #e6faf0 100%);
            border: 2px solid #86efac;
            border-radius: 12px;
            padding: 20px;
            margin-top: 10px;
            text-align: center;
            animation: slideDown 0.3s ease;
        }

        .maya-qr-panel.active {
            display: block;
        }

        .maya-qr-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .maya-qr-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #166534;
        }

        .maya-qr-header i {
            color: #16a34a;
            font-size: 16px;
        }

        .maya-qr-image {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            display: inline-block;
            box-shadow: 0 4px 16px rgba(22, 163, 74, 0.1);
            margin-bottom: 14px;
        }

        .maya-qr-image img {
            width: 200px;
            height: 200px;
            object-fit: contain;
            border-radius: 8px;
        }

        .maya-account-info {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 18px;
            border: 1px solid #bbf7d0;
        }

        .maya-account-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }

        .maya-account-row:not(:last-child) {
            border-bottom: 1px solid #e5e7eb;
        }

        .maya-account-row .label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .maya-account-row .value {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            letter-spacing: 0.5px;
        }

        .maya-account-row .value.green {
            color: #16a34a;
        }

        .maya-note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 10px;
            font-style: italic;
        }

        @media (max-width: 480px) {
            .checkout-wrap {
                padding: 12px;
            }

            .navbar-brand {
                font-size: 21px;
            }

            .panel-body {
                padding: 14px;
            }

            .delivery-title {
                font-size: 15px;
            }

            .summary-table {
                font-size: 13px;
            }

            .total-section {
                font-size: 14px;
            }

            .final-total {
                padding: 12px 14px;
            }

            .final-amount {
                font-size: 16px;
            }

            .checkout-btn {
                padding: 12px 14px;
                font-size: 15px;
            }

            .modal-content {
                width: 95%;
            }

            .payment-option {
                padding: 12px 14px;
                gap: 10px;
            }

            .payment-icon-box {
                width: 38px;
                height: 38px;
                font-size: 17px;
            }

            .payment-details-text h4 {
                font-size: 14px;
            }
        }

        /* Premium adaptive checkout */
        :root{--checkout-blue:#1769d2;--checkout-aqua:#09b4c8;--checkout-green:#0b9b80;--checkout-ink:#10263a;--checkout-ease:cubic-bezier(.22,1,.36,1)}
        body.public-ui{background:radial-gradient(circle at 10% 15%,rgba(9,180,200,.14),transparent 27%),radial-gradient(circle at 90% 88%,rgba(23,105,210,.12),transparent 30%),linear-gradient(145deg,#f0f9fd,#fbfdff 55%,#edf7fb)}.navbar{position:relative;z-index:5;background:rgba(255,255,255,.8);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:0 8px 28px rgba(15,52,78,.06);animation:checkoutNavIn .6s var(--checkout-ease) both}.navbar-brand img{width:36px;height:36px;padding:4px;border-radius:11px;background:linear-gradient(135deg,var(--checkout-blue),var(--checkout-aqua));box-shadow:0 8px 20px rgba(9,130,170,.2)}.checkout-wrap{max-width:760px;padding:38px 18px 70px}.checkout-intro{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:19px}.checkout-intro-main{display:flex;align-items:center;gap:12px}.checkout-intro-icon{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:linear-gradient(145deg,var(--checkout-blue),var(--checkout-aqua));color:#fff;box-shadow:0 10px 22px rgba(23,105,210,.2)}.checkout-intro h1{margin:0 0 3px;color:var(--checkout-ink);font-size:21px;font-weight:800}.checkout-intro p{margin:0;color:#71869a;font-size:11px}.checkout-step{padding:7px 10px;border:1px solid #d8e7ef;border-radius:999px;background:rgba(255,255,255,.72);color:#587286;font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.panel,.payment-method-section,.comment-section{border:1px solid rgba(255,255,255,.9);border-radius:19px;background:rgba(255,255,255,.94);box-shadow:0 16px 38px rgba(14,55,85,.1);animation:checkoutPanelIn .65s var(--checkout-ease) both}.panel:nth-of-type(2){animation-delay:.08s}.panel:nth-of-type(3){animation-delay:.14s}.panel-body{padding:22px}.panel-header{padding:18px 22px;background:linear-gradient(145deg,#f9fcfe,#f2f7fa);color:#49637a;border-color:#e6eef3;font-size:12px}.delivery-section{gap:15px;margin:0}.delivery-icon{display:grid;place-items:center;width:46px;height:46px;margin:0;border-radius:14px;background:linear-gradient(145deg,#e8f7ff,#dff8f5);color:#078eac}.delivery-title{color:var(--checkout-ink);font-size:17px}.fulfillment-badge{display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;padding:5px 8px;border-radius:999px;background:#e9fbf5;color:#0b846d;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.address-box.filled{padding:14px!important;border-radius:13px!important;background:linear-gradient(145deg,#f1fcf8,#e8f9f3)!important;box-shadow:inset 0 0 0 1px rgba(13,148,136,.05)}.pickup-box{display:flex;align-items:center;gap:13px;margin-top:13px;padding:15px;border:1px solid #bcdde8;border-radius:13px;background:linear-gradient(145deg,#f3fbff,#eaf7fb)}.pickup-box>i{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:#fff;color:var(--checkout-blue);box-shadow:0 7px 15px rgba(16,68,99,.09)}.pickup-box strong,.pickup-box span{display:block}.pickup-box strong{color:var(--checkout-ink);font-size:13px}.pickup-box span{margin-top:3px;color:#70879a;font-size:10px}.qty-compact{border-radius:10px;background:#f4f8fb}.qty-compact button{color:var(--checkout-blue);transition:background .2s ease,transform .15s ease}.qty-compact button:hover{background:#e9f4ff}.qty-compact button:active{transform:scale(.85)}.summary-row{padding:11px 0;border-color:#e8eef2}.total-section{background:linear-gradient(145deg,#f7fbfd,#eef7f9);border-radius:12px}.final-amount{color:var(--checkout-green)!important}.payment-method-section,.comment-section{margin-bottom:18px;overflow:hidden}.payment-method-label,.comment-label{color:var(--checkout-ink)}.payment-option{border-radius:15px;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.payment-option:hover{transform:translateY(-2px);border-color:#b8d7e4;box-shadow:0 10px 22px rgba(16,64,94,.08)}.payment-option.selected{border-color:var(--checkout-blue);box-shadow:0 0 0 2px rgba(23,105,210,.14),0 12px 25px rgba(23,105,210,.09)}.comment-textarea{border-radius:13px}.checkout-btn{position:relative;min-height:56px;overflow:hidden;border-radius:14px;background:linear-gradient(120deg,#0b967f,#09b4a2,#087e73);background-size:180% 180%;box-shadow:0 15px 32px rgba(8,145,125,.25);animation:checkoutGradient 6s ease infinite;transition:transform .2s ease,box-shadow .2s ease}.checkout-btn::after{content:'';position:absolute;inset:0;transform:translateX(-120%) skewX(-20deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.28),transparent);transition:transform .7s var(--checkout-ease)}.checkout-btn:hover::after{transform:translateX(120%) skewX(-20deg)}.checkout-btn:hover{transform:translateY(-2px);box-shadow:0 19px 38px rgba(8,145,125,.32)}.checkout-btn.is-loading{pointer-events:none;opacity:.84}.modal-overlay{backdrop-filter:blur(8px)}.modal-content{border-radius:20px;box-shadow:0 30px 80px rgba(7,32,52,.25)}
        @keyframes checkoutNavIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}@keyframes checkoutPanelIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}@keyframes checkoutGradient{0%,100%{background-position:0 50%}50%{background-position:100% 50%}}
        @media(max-width:560px){.checkout-wrap{padding:20px 12px 55px}.checkout-step{display:none}.panel-body{padding:18px}.delivery-icon{width:42px;height:42px}.payment-method-section,.comment-section{border-radius:17px}.checkout-intro h1{font-size:19px}}
        .checkout-summary-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:12px}.checkout-product{display:flex;align-items:center;gap:12px;min-width:0;flex:1}.checkout-product-copy{min-width:0}.checkout-product-name{font-size:14px;font-weight:700;color:#1f2937;line-height:1.35}.checkout-product-price{margin-top:4px;font-size:13px;color:#6b7280}.checkout-product-image{display:grid;place-items:center;flex:0 0 62px;width:62px;height:62px;padding:6px;border:1px solid #e0e9ef;border-radius:14px;background:radial-gradient(circle,#fff,#f0f5f8);overflow:hidden}.checkout-product-image img{display:block;width:100%;height:100%;object-fit:contain;mix-blend-mode:multiply}.checkout-summary-price{flex:0 0 auto;text-align:right}
        @media(max-width:390px){.checkout-summary-row{gap:10px}.checkout-product{gap:9px}.checkout-product-image{flex-basis:52px;width:52px;height:52px}.checkout-product-name{font-size:13px}.checkout-product-price{font-size:11px}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
    </style>
    <script src="../js/ui-protection.js" defer></script>
</head>
<body class="public-ui">
    <nav class="navbar">
        <div class="container-fluid">
            <a href="../home.php" class="navbar-brand">
                <img src="../<?php echo htmlspecialchars($systemLogo); ?>" alt="HydroMIS logo"> HydroMIS
            </a>
        </div>
    </nav>

    <div class="checkout-wrap">
        <div class="checkout-intro"><div class="checkout-intro-main"><div class="checkout-intro-icon"><i class="fas fa-lock"></i></div><div><h1>Secure checkout</h1><p>Confirm fulfillment and choose how you want to pay.</p></div></div><span class="checkout-step">Final step</span></div>
        <!-- Error Box -->
        <div class="error-box" id="errorBox">
            <div class="error-box-title">
                <i class="fas fa-exclamation-circle"></i> Oops! Something's missing
            </div>
            <ul class="error-box-list" id="errorList"></ul>
        </div>

        <!-- Fulfillment Section -->
        <div class="panel">
            <div class="panel-body">
                <div class="delivery-section">
                    <div class="delivery-icon">
                        <i class="fas <?php echo $fulfillment_method === 'delivery' ? 'fa-truck-fast' : 'fa-store'; ?>"></i>
                    </div>
                    <div class="delivery-info" style="flex: 1;">
                        <div class="fulfillment-badge"><i class="fas fa-circle-check"></i> Selected by you</div>
                        <h3 class="delivery-title"><?php echo $fulfillment_method === 'delivery' ? 'Door-to-door delivery' : 'Self pickup'; ?></h3>
                        <?php if ($fulfillment_method === 'delivery'): ?>
                        <div class="delivery-date">
                            <i class="far fa-calendar"></i>
                            <span id="displayDate">Select delivery date</span>
                        </div>
                        <div class="delivery-time">
                            <span id="displayTime">Select time slot</span>
                            <a href="javascript:void(0);" class="change-link" onclick="openDateTimeModal()">Change</a>
                        </div>
                        <div class="address-box filled" id="addressBox" style="cursor: default; background: #ecfdf5; border: 1px solid #0d9488;">
                            <i class="fas fa-map-pin" style="color: #0d9488;"></i>
                            <div style="flex: 1;">
                                <span class="address-text" id="addressDisplay"><?php echo htmlspecialchars($user_address); ?></span>
                                <?php if ($user_contact): ?>
                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;"><?php echo htmlspecialchars($user_contact); ?></div>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-check-circle" style="color: #0d9488; font-size: 16px; margin-left: 8px; flex-shrink: 0;"></i>
                        </div>
                        <?php else: ?>
                        <div class="pickup-box"><i class="fas fa-location-dot"></i><div><strong>HydroMIS Water Refilling Station</strong><span>Your order will be prepared for collection. No delivery schedule is required.</span></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Summary -->
        <div class="panel">
            <div class="panel-header">Order summary</div>
            <div class="panel-body">
                <div class="checkout-summary-row">
                    <div class="checkout-product">
                        <div class="checkout-product-image"><img src="<?php echo htmlspecialchars($container_image_map[$container_size]); ?>" alt="Selected <?php echo htmlspecialchars($size_map[$container_size]); ?> container"></div>
                        <div class="checkout-product-copy">
                        <div class="checkout-product-name">
                            <?php echo htmlspecialchars($size_map[$container_size] . ' - ' . ucfirst($type_map[$container_size])); ?>
                        </div>
                        <div class="checkout-product-price">
                            ₱<?php echo number_format($price_per_unit, 2); ?> per unit
                        </div>
                        </div>
                    </div>
                    <div class="checkout-summary-price">
                        <div style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 4px;" id="itemTotalDisplay">
                            ₱<?php echo number_format($item_total, 2); ?>
                        </div>
                        <div class="qty-compact">
                            <button type="button" onclick="decreaseQty()">−</button>
                            <span id="qtyDisplay"><?php echo $quantity; ?></span>
                            <button type="button" onclick="increaseQty()">+</button>
                        </div>
                    </div>
                </div>
                <?php if ($container_status === 'new'): ?>
                <div style="display: flex; justify-content: space-between; font-size: 13px; color: #6b7280; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e5e7eb;">
                    <span>New container</span>
                    <span style="color: #1f2937; font-weight: 600;" id="newContainerCost">₱<?php echo number_format($new_container, 2); ?></span>
                </div>
                <?php endif; ?>
                
                
            </div>
        </div>

        <!-- Summary Table -->
        <div class="panel">
            <div class="panel-body">
                <div class="summary-table">
                    <div class="summary-row">
                        <span class="summary-label">Item total</span>
                        <span class="summary-value" id="summaryItemTotal">₱<?php echo number_format($item_total, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><?php echo $fulfillment_method === 'delivery' ? 'Delivery fee' : 'Pickup fee'; ?></span>
                        <span class="summary-value" id="summaryDeliveryFee"><?php echo $delivery_fee > 0 ? '₱' . number_format($delivery_fee, 2) : 'Free'; ?></span>
                    </div>
                </div>
                <?php if ($fulfillment_method === 'delivery' && $free_delivery_reward): ?>
                <div style="display:flex;align-items:center;gap:9px;margin:4px 0 13px;padding:10px 12px;border:1px solid #a7f3d0;border-radius:10px;background:#ecfdf5;color:#047857;font-size:12px;font-weight:700;"><i class="fas fa-gift"></i><span>Free Delivery reward applied to this order</span></div>
                <?php endif; ?>
                <div class="total-section">
                    <span>Total (Incl. Vat)</span>
                    <span class="final-amount" id="summaryFinalTotal">₱<?php echo number_format($final_total, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Payment Section -->
        <?php if ($stock_blocked): ?>
            <div class="alert alert-danger" id="stockAlert" role="alert" style="margin-bottom:16px; border-radius:12px;">
                <i class="fas fa-box-open" style="margin-right:6px;"></i>
                <strong>Not enough stock.</strong>
                <?php if ($quantity > $available_stock): ?>Only <?php echo (int)$available_stock; ?> of this gallon product <?php echo (int)$available_stock === 1 ? 'is' : 'are'; ?> available.<?php endif; ?>
                <?php if ($container_status === 'new' && $quantity > $new_container_stock): ?>Only <?php echo (int)$new_container_stock; ?> new container<?php echo (int)$new_container_stock === 1 ? ' is' : 's are'; ?> available.<?php endif; ?>
                Reduce the quantity or choose another container.
            </div>
        <?php endif; ?>
        <form method="POST" action="purchase.php" enctype="multipart/form-data" style="margin-bottom: 20px;" id="checkoutForm" onsubmit="return validateCheckout(event);">
            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
            <input type="hidden" name="buy_submit" value="1">
            <input type="hidden" name="container_size" value="<?php echo htmlspecialchars($container_size); ?>">
            <input type="hidden" name="container_status" value="<?php echo htmlspecialchars($container_status); ?>">
            <input type="hidden" name="fulfillment_method" value="<?php echo htmlspecialchars($fulfillment_method); ?>">
            <input type="hidden" name="quantity" id="hiddenQuantity" value="<?php echo $quantity; ?>">
            <input type="hidden" name="amount_tendered" id="hiddenAmount" value="<?php echo number_format($final_total, 2); ?>">
            <input type="hidden" name="delivery_address" id="hiddenAddress" value="<?php echo htmlspecialchars($user_address); ?>">
            <input type="hidden" name="delivery_date" id="hiddenDate" value="">
            <input type="hidden" name="delivery_time" id="hiddenTime" value="">
            <input type="hidden" name="payment_method" id="hiddenPaymentMethod" value="">

            <!-- Payment Method Section -->
            <div class="payment-method-section">
                <div class="payment-method-label">
                    <i class="fas fa-wallet"></i> Payment Method
                </div>
                <div class="payment-options">
                    <label class="payment-option" id="paymentCash" onclick="selectPayment('cash')">
                        <input type="radio" name="payment_option" value="cash">
                        <div class="payment-radio-circle"></div>
                        <div class="payment-icon-box cash">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="payment-details-text">
                            <h4><?php echo $fulfillment_method === 'delivery' ? 'Cash on Delivery' : 'Cash at Station'; ?></h4>
                            <p><?php echo $fulfillment_method === 'delivery' ? 'Pay when your order arrives' : 'Pay when collecting your order'; ?></p>
                        </div>
                        <div class="payment-check"><i class="fas fa-check"></i></div>
                    </label>
                    <label class="payment-option" id="paymentGcash" onclick="selectPayment('gcash')">
                        <input type="radio" name="payment_option" value="gcash">
                        <div class="payment-radio-circle"></div>
                        <div class="payment-icon-box gcash" style="padding: 0; background: transparent; border-radius: 10px; overflow: hidden;">
                            <img src="../imagess/gcash-logo.jpg" alt="GCash" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <div class="payment-details-text">
                            <h4>GCash</h4>
                            <p>Pay via GCash e-wallet</p>
                        </div>
                        <div class="payment-check"><i class="fas fa-check"></i></div>
                    </label>
                    <!-- GCash QR Code Details -->
                    <div class="gcash-qr-panel" id="gcashQrPanel">
                        <div class="gcash-qr-header">
                            <i class="fas fa-qrcode"></i>
                            <h5>Scan QR Code to Pay via GCash</h5>
                        </div>
                        <div class="gcash-qr-image">
                            <img src="../<?= htmlspecialchars($qr_gcash['qr_image_path']) ?>" alt="GCash QR Code">
                        </div>
                        <div class="gcash-account-info">
                            <div class="gcash-account-row">
                                <span class="label">GCash Number</span>
                                <span class="value blue"><?= htmlspecialchars($qr_gcash['account_number']) ?></span>
                            </div>
                            <div class="gcash-account-row">
                                <span class="label">Account Name</span>
                                <span class="value"><?= htmlspecialchars($qr_gcash['account_name']) ?></span>
                            </div>
                        </div>
                        <div class="gcash-note">
                            <i class="fas fa-info-circle"></i> Send the exact amount and keep your receipt for verification
                        </div>
                        <!-- GCash Inputs -->
                        <div class="gcash-inputs" style="margin-top: 15px; border-top: 1px dashed #bfdbfe; padding-top: 15px; text-align: left;">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label" style="font-size: 12px; color: #475569; font-weight: 700; text-transform: none; letter-spacing: normal;">Your GCash Mobile Number <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" name="gcash_number" id="gcashNumber" placeholder="e.g., 09939093915" maxlength="11" style="font-size: 13px; padding: 8px 10px;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label" style="font-size: 12px; color: #475569; font-weight: 700; text-transform: none; letter-spacing: normal;">Upload Proof of Payment <span style="color: #ef4444;">*</span></label>
                                <input type="file" class="form-control-file" name="gcash_proof" id="gcashProof" accept="image/*" style="font-size: 12px;">
                                <small class="form-text text-muted" style="font-size: 10px; margin-top: 4px; display: block; line-height: 1.2;">Upload screenshot of GCash transaction (Max 5MB)</small>
                            </div>
                        </div>
                    </div>
                    <label class="payment-option" id="paymentMaya" onclick="selectPayment('maya')">
                        <input type="radio" name="payment_option" value="maya">
                        <div class="payment-radio-circle"></div>
                        <div class="payment-icon-box maya" style="padding: 0; background: transparent; border-radius: 10px; overflow: hidden;">
                            <img src="../imagess/maya-logo.png" alt="Maya" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <div class="payment-details-text">
                            <h4>Maya</h4>
                            <p>Pay via Maya e-wallet</p>
                        </div>
                        <div class="payment-check"><i class="fas fa-check"></i></div>
                    </label>
                    <!-- Maya QR Code Details -->
                    <div class="maya-qr-panel" id="mayaQrPanel">
                        <div class="maya-qr-header">
                            <i class="fas fa-qrcode"></i>
                            <h5>Scan QR Code to Pay via Maya</h5>
                        </div>
                        <div class="maya-qr-image">
                            <img src="../<?= htmlspecialchars($qr_maya['qr_image_path']) ?>" alt="Maya QR Code">
                        </div>
                        <div class="maya-account-info">
                            <div class="maya-account-row">
                                <span class="label">Maya Number</span>
                                <span class="value green"><?= htmlspecialchars($qr_maya['account_number']) ?></span>
                            </div>
                            <div class="maya-account-row">
                                <span class="label">Account Name</span>
                                <span class="value"><?= htmlspecialchars($qr_maya['account_name']) ?></span>
                            </div>
                        </div>
                        <div class="maya-note">
                            <i class="fas fa-info-circle"></i> Send the exact amount and keep your receipt for verification
                        </div>
                        <!-- Maya Inputs -->
                        <div class="maya-inputs" style="margin-top: 15px; border-top: 1px dashed #a7f3d0; padding-top: 15px; text-align: left;">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label" style="font-size: 12px; color: #475569; font-weight: 700; text-transform: none; letter-spacing: normal;">Your Maya Mobile Number <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" name="maya_number" id="mayaNumber" placeholder="e.g., 09939093915" maxlength="11" style="font-size: 13px; padding: 8px 10px;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label" style="font-size: 12px; color: #475569; font-weight: 700; text-transform: none; letter-spacing: normal;">Upload Proof of Payment <span style="color: #ef4444;">*</span></label>
                                <input type="file" class="form-control-file" name="maya_proof" id="mayaProof" accept="image/*" style="font-size: 12px;">
                                <small class="form-text text-muted" style="font-size: 10px; margin-top: 4px; display: block; line-height: 1.2;">Upload screenshot of Maya transaction (Max 5MB)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="payment-error-msg" id="paymentError">
                    <i class="fas fa-exclamation-circle"></i> Please select a payment method
                </div>
            </div>

            <!-- Comment Section -->
            <div class="comment-section">
                <label class="comment-label">
                    <i class="fas fa-comment-alt" style="color: #2563eb;"></i> Special Instructions (Optional)
                </label>
                <textarea class="comment-textarea" name="customer_notes" id="customerNotes" placeholder="<?php echo $fulfillment_method === 'delivery' ? 'e.g., Please leave at front door, handle with care' : 'e.g., Preferred collection time or other notes'; ?>"></textarea>
                <div class="comment-hint"><?php echo $fulfillment_method === 'delivery' ? 'Let the driver know any special delivery instructions' : 'Add any notes for station staff preparing your pickup'; ?></div>
            </div>

            <div style="padding: 0 16px 16px;">
                <button type="submit" class="checkout-btn" id="checkoutBtn" <?php echo $stock_blocked ? 'disabled aria-disabled="true"' : ''; ?>>
                    <i class="fas fa-lock" style="margin-right: 6px;"></i> <?php echo $fulfillment_method === 'delivery' ? 'Place delivery order' : 'Place pickup order'; ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Date/Time Modal -->
    <div class="modal-overlay" id="dateTimeModal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="far fa-calendar" style="color: #2563eb; margin-right: 8px;"></i> Select Delivery Date & Time
            </div>
            <div class="form-group">
                <label class="form-label">Delivery Date</label>
                <input type="date" class="form-control" id="deliveryDate">
                <div class="validation-message" id="dateError"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Time Slot</label>
                <select class="form-control" id="timeSlot">
                    <option value="">Select time slot</option>
                    <option value="09:00-12:00">09:00 AM - 12:00 PM</option>
                    <option value="12:00-15:00">12:00 PM - 03:00 PM</option>
                    <option value="15:00-18:00">03:00 PM - 06:00 PM</option>
                </select>
                <div class="validation-message" id="timeError"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeDateTimeModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="saveDateTime()">Save Date & Time</button>
            </div>
        </div>
    </div>

    <script>
        const pricePerUnit = <?php echo $price_per_unit; ?>;
        const pickupBase = <?php echo $pickup_base_map[$container_size]; ?>;
        const isDelivery = <?php echo $fulfillment_method === 'delivery' ? 'true' : 'false'; ?>;
        let currentQuantity = <?php echo $quantity; ?>;
        let selectedDate = '';
        let selectedTime = '';
        let selectedPayment = '';

        function openDateTimeModal() {
            document.getElementById('dateTimeModal').classList.add('active');
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const minDate = tomorrow.toISOString().split('T')[0];
            document.getElementById('deliveryDate').setAttribute('min', minDate);
        }

        function closeDateTimeModal() {
            document.getElementById('dateTimeModal').classList.remove('active');
            clearDateTimeErrors();
        }

        function clearDateTimeErrors() {
            document.getElementById('dateError').textContent = '';
            document.getElementById('dateError').style.display = 'none';
            document.getElementById('timeError').textContent = '';
            document.getElementById('timeError').style.display = 'none';
        }

        function validateDateTimeForm() {
            clearDateTimeErrors();
            let isValid = true;

            const date = document.getElementById('deliveryDate').value;
            if (!date) {
                showDateTimeError('dateError', 'Please select a delivery date');
                isValid = false;
            }

            const time = document.getElementById('timeSlot').value;
            if (!time) {
                showDateTimeError('timeError', 'Please select a time slot');
                isValid = false;
            }

            return isValid;
        }

        function showDateTimeError(fieldId, message) {
            const errorEl = document.getElementById(fieldId);
            errorEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
            errorEl.style.display = 'flex';
            errorEl.classList.add('error');
        }

        function saveDateTime() {
            if (validateDateTimeForm()) {
                const date = document.getElementById('deliveryDate').value;
                const time = document.getElementById('timeSlot').value;

                const dateObj = new Date(date);
                const options = { weekday: 'long', month: 'long', day: 'numeric' };
                const formattedDate = dateObj.toLocaleDateString('en-US', options);

                selectedDate = date;
                selectedTime = time;
                document.getElementById('hiddenDate').value = date;
                document.getElementById('hiddenTime').value = time;
                document.getElementById('displayDate').textContent = formattedDate;
                document.getElementById('displayTime').textContent = time.replace('-', ' - ');

                closeDateTimeModal();
            }
        }

        const availableStock = <?php echo (int)min($available_stock, $container_status === 'new' ? $new_container_stock : $available_stock); ?>;

        function increaseQty() {
            if (availableStock !== null && currentQuantity >= availableStock) {
                return;
            }
            currentQuantity += 1;
            updateDisplay();
        }

        function decreaseQty() {
            if (currentQuantity > 1) {
                currentQuantity -= 1;
                updateDisplay();
            }
        }

        function updateDisplay() {
            document.getElementById('qtyDisplay').textContent = currentQuantity;
            document.getElementById('hiddenQuantity').value = currentQuantity;

            const newTotal = currentQuantity * pricePerUnit;
            const discountCount = Math.floor(currentQuantity / 5);
            const discount = discountCount > 0 ? (discountCount * 5) : 0;
            const deliveryFee = isDelivery && !<?php echo $free_delivery_reward ? 'true' : 'false'; ?> ? 10 * currentQuantity : 0;
            const finalAmount = newTotal + deliveryFee - discount;

            document.getElementById('itemTotalDisplay').textContent = '₱' + newTotal.toFixed(2);
            document.getElementById('summaryItemTotal').textContent = '₱' + newTotal.toFixed(2);
            document.getElementById('summaryFinalTotal').textContent = '₱' + finalAmount.toFixed(2);
            document.getElementById('hiddenAmount').value = finalAmount.toFixed(2);

            if (availableStock !== null) {
                const isBlocked = currentQuantity > availableStock;
                const checkoutButton = document.getElementById('checkoutBtn');
                checkoutButton.disabled = isBlocked;
                checkoutButton.setAttribute('aria-disabled', isBlocked ? 'true' : 'false');
                const stockAlert = document.getElementById('stockAlert');
                if (stockAlert) stockAlert.style.display = isBlocked ? '' : 'none';
            }

            <?php if ($container_status === 'new'): ?>
            const newContainerCost = 20 * currentQuantity;
            document.getElementById('newContainerCost').textContent = '₱' + newContainerCost.toFixed(2);
            <?php endif; ?>
        }

        function selectPayment(method) {
            selectedPayment = method;
            document.getElementById('hiddenPaymentMethod').value = method;

            // Update visual state
            document.querySelectorAll('.payment-option').forEach(el => {
                el.classList.remove('selected');
            });

            const optionMap = { cash: 'paymentCash', gcash: 'paymentGcash', maya: 'paymentMaya' };
            const selected = document.getElementById(optionMap[method]);
            if (selected) {
                selected.classList.add('selected');
                selected.querySelector('input[type="radio"]').checked = true;
            }

            // Clear payment error if visible
            document.getElementById('paymentError').classList.remove('active');

            // Show/hide GCash QR panel
            const gcashPanel = document.getElementById('gcashQrPanel');
            if (method === 'gcash') {
                gcashPanel.classList.add('active');
            } else {
                gcashPanel.classList.remove('active');
            }

            // Show/hide Maya QR panel
            const mayaPanel = document.getElementById('mayaQrPanel');
            if (method === 'maya') {
                mayaPanel.classList.add('active');
            } else {
                mayaPanel.classList.remove('active');
            }
        }

        function validateCheckout(event) {
            event.preventDefault();

            const errorBox = document.getElementById('errorBox');
            const errorList = document.getElementById('errorList');
            errorList.innerHTML = '';

            let isValid = true;
            const errors = [];

            if (availableStock !== null && currentQuantity > availableStock) {
                errors.push('Only ' + availableStock + ' of this gallon container are available');
                isValid = false;
            }

            if (isDelivery && !selectedDate) {
                errors.push('Select a delivery date');
                isValid = false;
            }

            if (isDelivery && !selectedTime) {
                errors.push('Select a time slot');
                isValid = false;
            }

            if (!selectedPayment) {
                errors.push('Select a payment method');
                document.getElementById('paymentError').classList.add('active');
                isValid = false;
            }

            if (selectedPayment === 'gcash') {
                const gcashNum = document.getElementById('gcashNumber').value.trim();
                const gcashFile = document.getElementById('gcashProof').files[0];

                if (!gcashNum || !/^(09)\d{9}$/.test(gcashNum)) {
                    errors.push('Enter a valid 11-digit GCash mobile number (starts with 09)');
                    isValid = false;
                }
                if (!gcashFile) {
                    errors.push('Upload GCash proof of payment screenshot');
                    isValid = false;
                }
            } else if (selectedPayment === 'maya') {
                const mayaNum = document.getElementById('mayaNumber').value.trim();
                const mayaFile = document.getElementById('mayaProof').files[0];

                if (!mayaNum || !/^(09)\d{9}$/.test(mayaNum)) {
                    errors.push('Enter a valid 11-digit Maya mobile number (starts with 09)');
                    isValid = false;
                }
                if (!mayaFile) {
                    errors.push('Upload Maya proof of payment screenshot');
                    isValid = false;
                }
                }

            if (!isValid) {
                errors.forEach(error => {
                    const li = document.createElement('li');
                    li.innerHTML = '<i class="fas fa-check" style="opacity: 0;"></i> ' + error;
                    errorList.appendChild(li);
                });
                errorBox.classList.add('active');
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return false;
            }

            errorBox.classList.remove('active');
            const checkoutButton = document.getElementById('checkoutBtn');
            checkoutButton.classList.add('is-loading');
            checkoutButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Placing order...';
            document.getElementById('checkoutForm').submit();
            return false;
        }

        // Set min date for date picker
        window.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const minDate = tomorrow.toISOString().split('T')[0];
            document.getElementById('deliveryDate').setAttribute('min', minDate);
        });
    </script>
</body>
</html>
