<?php
require_once '../config/database.php';

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

$allowed_sizes = ['5gal-round', '2.5gal-slim', '5gal-slim'];
$allowed_status = ['new', 'pickup'];

$container_size = isset($_POST['container_size']) ? sanitize($_POST['container_size']) : '5gal-round';
$container_status = isset($_POST['container_status']) ? sanitize($_POST['container_status']) : 'new';
$quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
$amount_tendered = isset($_POST['amount_tendered']) ? floatval($_POST['amount_tendered']) : 0;

if (!in_array($container_size, $allowed_sizes, true)) {
    $container_size = '5gal-round';
}
if (!in_array($container_status, $allowed_status, true)) {
    $container_status = 'new';
}
if ($quantity < 1) {
    $quantity = 1;
}

$size_map = [
    '5gal-round' => '5.00 Gal',
    '2.5gal-slim' => '2.50 Gal',
    '5gal-slim' => '5.00 Gal'
];

$type_map = [
    '5gal-round' => 'round container',
    '2.5gal-slim' => 'slim container',
    '5gal-slim' => 'slim container'
];

$price_map = [
    '5gal-round' => ['new' => 50, 'pickup' => 45],
    '2.5gal-slim' => ['new' => 30, 'pickup' => 25],
    '5gal-slim' => ['new' => 50, 'pickup' => 45]
];

$pickup_base_map = [
    '5gal-round' => 45,
    '2.5gal-slim' => 25,
    '5gal-slim' => 45
];

// Get user's registered address
$user_address = isset($scanned_data['address']) ? $scanned_data['address'] : 'No address on file';
$user_contact = isset($scanned_data['contact_number']) ? $scanned_data['contact_number'] : '';

$price_per_unit = $price_map[$container_size][$container_status];
$pickup_base = $pickup_base_map[$container_size];
$water_total = $pickup_base * $quantity;
$new_container = $container_status === 'new' ? ($price_per_unit - $pickup_base) * $quantity : 0;
$item_total = $price_per_unit * $quantity;

$discount_count = floor($quantity / 5);
$discount = $discount_count > 0 ? ($discount_count * 5) : 0;
$final_total = $item_total - $discount;

$delivery_fee = 0;
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
        }
    </style>
</head>
<body class="public-ui">
    <nav class="navbar">
        <div class="container-fluid">
            <a href="../home.php" class="navbar-brand">
                <i class="fas fa-droplet"></i> HydroMIS
            </a>
        </div>
    </nav>

    <div class="checkout-wrap">
        <!-- Error Box -->
        <div class="error-box" id="errorBox">
            <div class="error-box-title">
                <i class="fas fa-exclamation-circle"></i> Oops! Something's missing
            </div>
            <ul class="error-box-list" id="errorList"></ul>
        </div>

        <!-- Delivery Section -->
        <div class="panel">
            <div class="panel-body">
                <div class="delivery-section">
                    <div class="delivery-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="delivery-info" style="flex: 1;">
                        <h3 class="delivery-title">Door-to-door delivery</h3>
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Summary -->
        <div class="panel">
            <div class="panel-header">Order summary</div>
            <div class="panel-body">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <div style="font-size: 14px; font-weight: 700; color: #1f2937; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($size_map[$container_size] . ' - ' . ucfirst($type_map[$container_size])); ?>
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">
                            ₱<?php echo number_format($price_per_unit, 2); ?> per unit
                        </div>
                    </div>
                    <div style="text-align: right;">
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
                        <span class="summary-label">Delivery fee</span>
                        <span class="summary-value">Free</span>
                    </div>
                </div>
                <div class="total-section">
                    <span>Total (Incl. Vat)</span>
                    <span class="final-amount" id="summaryFinalTotal">₱<?php echo number_format($final_total, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Payment Section -->
        <form method="POST" action="purchase.php" style="margin-bottom: 20px;" id="checkoutForm" onsubmit="return validateCheckout(event);">
            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
            <input type="hidden" name="buy_submit" value="1">
            <input type="hidden" name="container_size" value="<?php echo htmlspecialchars($container_size); ?>">
            <input type="hidden" name="container_status" value="<?php echo htmlspecialchars($container_status); ?>">
            <input type="hidden" name="quantity" id="hiddenQuantity" value="<?php echo $quantity; ?>">
            <input type="hidden" name="amount_tendered" id="hiddenAmount" value="<?php echo number_format($final_total, 2); ?>">
            <input type="hidden" name="delivery_address" id="hiddenAddress" value="<?php echo htmlspecialchars($user_address); ?>">
            <input type="hidden" name="delivery_date" id="hiddenDate" value="">
            <input type="hidden" name="delivery_time" id="hiddenTime" value="">

            <!-- Comment Section -->
            <div class="comment-section">
                <label class="comment-label">
                    <i class="fas fa-comment-alt" style="color: #2563eb;"></i> Special Instructions (Optional)
                </label>
                <textarea class="comment-textarea" name="customer_notes" id="customerNotes" placeholder="e.g., Please leave at front door, handle with care, etc."></textarea>
                <div class="comment-hint">Let the driver know any special instructions for your delivery</div>
            </div>

            <div style="padding: 0 16px 16px;">
                <button type="submit" class="checkout-btn" id="checkoutBtn">Order</button>
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
        let currentQuantity = <?php echo $quantity; ?>;
        let selectedDate = '';
        let selectedTime = '';

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

        function increaseQty() {
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
            const finalAmount = newTotal - discount;

            document.getElementById('itemTotalDisplay').textContent = '₱' + newTotal.toFixed(2);
            document.getElementById('summaryItemTotal').textContent = '₱' + newTotal.toFixed(2);
            document.getElementById('summaryFinalTotal').textContent = '₱' + finalAmount.toFixed(2);
            document.getElementById('hiddenAmount').value = finalAmount.toFixed(2);

            <?php if ($container_status === 'new'): ?>
            const newContainerCost = (pricePerUnit - pickupBase) * currentQuantity;
            document.getElementById('newContainerCost').textContent = '₱' + newContainerCost.toFixed(2);
            <?php endif; ?>
        }

        function validateCheckout(event) {
            event.preventDefault();

            const errorBox = document.getElementById('errorBox');
            const errorList = document.getElementById('errorList');
            errorList.innerHTML = '';

            let isValid = true;
            const errors = [];

            if (!selectedDate) {
                errors.push('Select a delivery date');
                isValid = false;
            }

            if (!selectedTime) {
                errors.push('Select a time slot');
                isValid = false;
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
