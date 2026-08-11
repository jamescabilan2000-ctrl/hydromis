<?php
require_once '../config/database.php';
require_once '../config/system_settings.php';
require_once '../config/inventory_service.php';
require_once '../config/system_settings.php';
ensure_inventory_schema($conn);
$systemLogo = system_logo_path($conn);

function savePurchasePaymentProof($fieldName, $paymentId) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, 'Payment proof screenshot is required.'];
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Unable to upload payment proof. Please try again.'];
    }

    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        return [null, 'Payment proof must be 5MB or smaller.'];
    }

    $tmpName = $_FILES[$fieldName]['tmp_name'];
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return [null, 'Payment proof must be a valid image file.'];
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mimeType = $imageInfo['mime'] ?? '';
    if (!isset($allowedMimeTypes[$mimeType])) {
        return [null, 'Payment proof must be a JPG, PNG, or WEBP image.'];
    }

    $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return [null, 'Payment proof storage is not available.'];
    }

    $fileName = $paymentId . '-' . time() . '.' . $allowedMimeTypes[$mimeType];
    $destination = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        return [null, 'Unable to save payment proof. Please try again.'];
    }

    return ['uploads/payment_proofs/' . $fileName, null];
}

$error = '';
$transaction_success = false;
$transaction_data = null;
$profile_success = '';
$scanned_data = null;

// Get user from URL parameter or POST
$user_id = null;
if (isset($_GET['user_id'])) {
    $user_id = sanitize($_GET['user_id']);
} elseif (isset($_POST['user_id'])) {
    $user_id = sanitize($_POST['user_id']);
}

if (!$user_id) {
    header('Location: scan_qr.php');
    exit;
}

// Fetch user data
$sql = "SELECT * FROM users WHERE user_id = '$user_id'";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    $scanned_data = $result->fetch_assoc();
} else {
    header('Location: scan_qr.php');
    exit;
}

// Purchasing is available only after an administrator approves the account.
if (strtolower((string)($scanned_data['status'] ?? 'pending')) !== 'approved') {
    $accountStatus = strtolower((string)($scanned_data['status'] ?? 'pending'));
    header('Location: scan_qr.php?approval_required=' . urlencode($accountStatus));
    exit;
}

// Keep one active order per customer. A new order is allowed only after the
// current order is delivered or cancelled/denied.
$activeOrderStmt = $conn->prepare("SELECT transaction_id FROM transactions
    WHERE user_id = ?
      AND transaction_id NOT LIKE 'RWD-%'
      AND (status = 'pending' OR (status = 'approved' AND LOWER(COALESCE(delivery_status, 'pending')) <> 'delivered'))
    LIMIT 1");
$activeOrderStmt->bind_param('s', $user_id);
$activeOrderStmt->execute();
if ($activeOrderStmt->get_result()->fetch_assoc()) {
    header('Location: track_order.php?user_id=' . urlencode($user_id) . '&active_order=1');
    exit;
}

// Handle Buy Transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['buy_submit'])) {
    $user_id = sanitize($_POST['user_id']);
    $quantity = intval($_POST['quantity']);
    $container_size = sanitize($_POST['container_size']); // '5gal-round', '2.5gal-slim', '5gal-slim'
    $container_status = sanitize($_POST['container_status']); // 'new' or 'existing'
    $fulfillment_method = sanitize($_POST['fulfillment_method'] ?? 'delivery');
    $amount_tendered = floatval($_POST['amount_tendered']);
    $customer_notes = isset($_POST['customer_notes']) ? sanitize($_POST['customer_notes']) : '';
    $payment_method = isset($_POST['payment_method']) ? sanitize($_POST['payment_method']) : 'cash';
    
    // Validate payment method
    $allowed_payment_methods = ['cash', 'gcash', 'maya'];
    if (!in_array($payment_method, $allowed_payment_methods, true)) {
        $payment_method = 'cash';
    }

    $payment_reference = null;
    $wallet_number = null;
    $payment_proof_path = null;
    $payment_status = 'pending';

    if ($payment_method === 'gcash' || $payment_method === 'maya') {
        $wallet_number = sanitize($_POST[$payment_method . '_number'] ?? '');
        $file_field_name = $payment_method . '_proof';

        if (empty($wallet_number) || !preg_match('/^(09)\d{9}$/', $wallet_number)) {
            $error = 'A valid 11-digit mobile number starting with 09 is required for ' . ucfirst($payment_method) . '.';
        } else {
            $temp_pay_id = generateID('PAY');
            list($payment_proof_path, $upload_error) = savePurchasePaymentProof($file_field_name, $temp_pay_id);
            if ($upload_error) {
                $error = $upload_error;
            } else {
                $payment_status = 'processing';
            }
        }
    }
    
    $water_price_map = ['5gal-round' => 20, '2.5gal-slim' => 15, '5gal-slim' => 40];
    if (!isset($water_price_map[$container_size]) || !in_array($container_status, ['new', 'existing'], true) || !in_array($fulfillment_method, ['delivery', 'pickup'], true)) {
        $error = 'Please select a valid container and order type.';
        $price_per_unit = 0;
    } else {
        $price_per_unit = $water_price_map[$container_size] + ($container_status === 'new' ? 20 : 0);
    }
    $total_amount = $quantity * $price_per_unit;
    $discount = 0;
    // Points are awarded only after staff approves the pending order.
    $loyalty_points = 0;
    
    // Apply discount for every 5 containers
    $discount_count = floor($quantity / 5);
    if ($discount_count > 0) {
        $discount = $discount_count * 5;
    }
    
    $free_delivery_claim_id = 0;
    $free_delivery_redemption_id = '';
    if ($fulfillment_method === 'delivery') {
        $safe_reward_user = $conn->real_escape_string((string)$user_id);
        $reward_result = $conn->query("SELECT id,transaction_id FROM reward_claims WHERE user_id='$safe_reward_user' AND reward_code='free_delivery' AND claim_status='approved' ORDER BY created_at ASC LIMIT 1");
        $reward_claim = $reward_result ? $reward_result->fetch_assoc() : null;
        if ($reward_claim) {
            $free_delivery_claim_id = (int)$reward_claim['id'];
            $free_delivery_redemption_id = (string)$reward_claim['transaction_id'];
        }
    }
    $delivery_fee = $fulfillment_method === 'delivery' && $free_delivery_claim_id === 0 ? 10 * $quantity : 0;
    $final_amount = $total_amount + $delivery_fee - $discount;
    $change = $amount_tendered - $final_amount;
    
    if ($quantity <= 0) {
        $error = 'Quantity must be at least 1!';
    } elseif ($amount_tendered < $final_amount) {
        $error = 'Amount tendered is insufficient! Amount needed: ₱' . number_format($final_amount, 2);
    } elseif (empty($error)) {
        $transaction_id = generateID('TXN');
        $db_container_map = [
            '5gal-round' => '5 Gallon (Round)',
            '2.5gal-slim' => '2.5 Gallon (Slim)',
            '5gal-slim' => '5 Gallon (Slim)'
        ];
        $container_label = $db_container_map[$container_size] ?? $container_size;
        $container_label_text = $container_status === 'new' ? 'New Container' : 'Customer Container';
        $fulfillment_label = $fulfillment_method === 'delivery' ? 'Delivery' : 'Self Pickup';
        $description = "Water Order - $quantity × $container_label ($container_label_text, $fulfillment_label) @ ₱$price_per_unit each";
        
        $safe_reference = $payment_reference !== null ? "'" . $conn->real_escape_string($payment_reference) . "'" : "NULL";
        $safe_proof = $payment_proof_path !== null ? "'" . $conn->real_escape_string($payment_proof_path) . "'" : "NULL";

        if ($free_delivery_claim_id > 0) {
            $reward_note = 'Free Delivery reward applied: ' . $free_delivery_redemption_id;
            $customer_notes = trim($customer_notes . ($customer_notes !== '' ? "\n" : '') . $reward_note);
        }

        $inventory_item_id = null;
        $inventory_reserved = 0;
        $inventory_transaction_open = false;
        $item_code = inventory_code_for_container($container_size);
        $safe_item_code = $conn->real_escape_string((string)$item_code);
        $conn->begin_transaction();
        $inventory_transaction_open = true;
        if ($free_delivery_claim_id > 0) {
            $locked_reward = $conn->query("SELECT id FROM reward_claims WHERE id=$free_delivery_claim_id AND user_id='" . $conn->real_escape_string((string)$user_id) . "' AND reward_code='free_delivery' AND claim_status='approved' FOR UPDATE");
            if (!$locked_reward || $locked_reward->num_rows === 0 || !$conn->query("UPDATE reward_claims SET claim_status='claimed',claimed_by='ONLINE-ORDER',claimed_at=NOW(),customer_seen_at=NOW() WHERE id=$free_delivery_claim_id AND claim_status='approved'")) {
                $conn->rollback();
                $inventory_transaction_open = false;
                $error = 'Your Free Delivery reward is no longer available. Please return to checkout and try again.';
            }
        }
        $new_container_inventory_item_id = null;
        $new_container_inventory_reserved = 0;
        if (empty($error) && $container_status === 'new') {
            $new_container_item = new_container_inventory_item($conn, true);
            if (!$new_container_item || (int)$new_container_item['quantity'] < $quantity) {
                $available_new = (int)($new_container_item['quantity'] ?? 0);
                $conn->rollback();
                $inventory_transaction_open = false;
                $error = 'New containers are out of stock. Available quantity: ' . $available_new . '. Your order was not placed.';
            } else {
                $new_container_inventory_item_id = (int)$new_container_item['id'];
                $new_before = (int)$new_container_item['quantity'];
                $new_after = $new_before - $quantity;
                $conn->query("UPDATE inventory_items SET quantity=$new_after,updated_by='ONLINE-ORDER' WHERE id=$new_container_inventory_item_id");
                $new_reason = "New containers reserved for online order $transaction_id";
                $new_movement = $conn->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_change,previous_quantity,new_quantity,reason,staff_id) VALUES (?,'order',?,?,?,?, 'ONLINE-ORDER')");
                $new_change = -$quantity;
                if ($new_movement) { $new_movement->bind_param('iiiis', $new_container_inventory_item_id, $new_change, $new_before, $new_after, $new_reason); $new_movement->execute(); }
                $new_container_inventory_reserved = 1;
            }
        }
        $stock_result = empty($error) && $item_code ? $conn->query("SELECT id, item_name, quantity FROM inventory_items WHERE item_code='$safe_item_code' FOR UPDATE") : false;
        $stock_item = $stock_result ? $stock_result->fetch_assoc() : null;
        if (!empty($error)) {
            // Reward validation supplied the customer-facing error.
        } elseif (!$stock_item || (int)$stock_item['quantity'] < $quantity) {
            $available = (int)($stock_item['quantity'] ?? 0);
            $conn->rollback();
            $inventory_transaction_open = false;
            $error = 'This gallon size is out of stock. Available quantity: ' . $available . '. Your order was not placed.';
        } else {
            $inventory_item_id = (int)$stock_item['id'];
            $stock_before = (int)$stock_item['quantity'];
            $stock_after = $stock_before - $quantity;
            $conn->query("UPDATE inventory_items SET quantity=$stock_after, updated_by='ONLINE-ORDER' WHERE id=$inventory_item_id");
            $movement_reason = "Reserved for online order $transaction_id";
            $movement = $conn->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_change,previous_quantity,new_quantity,reason,staff_id) VALUES (?,'order',?,?,?,?, 'ONLINE-ORDER')");
            $quantity_change = -$quantity;
            if ($movement) { $movement->bind_param('iiiis', $inventory_item_id, $quantity_change, $stock_before, $stock_after, $movement_reason); $movement->execute(); }
            $inventory_reserved = 1;
        }

        $inventory_item_sql = $inventory_item_id === null ? 'NULL' : (string)$inventory_item_id;
        $new_container_inventory_item_sql = $new_container_inventory_item_id === null ? 'NULL' : (string)$new_container_inventory_item_id;
        $sql = "INSERT INTO transactions (transaction_id, user_id, amount, description, water_type, quantity, price_per_unit, discount, loyalty_points_earned, notes, status, payment_method, payment_reference, payment_status, payment_proof, container_size, container_status, fulfillment_method, inventory_item_id, inventory_reserved, new_container_inventory_item_id, new_container_inventory_reserved, created_at)
                VALUES ('$transaction_id', '$user_id', '$final_amount', '$description', 'regular', '$quantity', '$price_per_unit', '$discount', '$loyalty_points', '$customer_notes', 'pending', '$payment_method', $safe_reference, '$payment_status', $safe_proof, '$container_size', '$container_status', '$fulfillment_method', $inventory_item_sql, $inventory_reserved, $new_container_inventory_item_sql, $new_container_inventory_reserved, NOW())";

        if (!empty($error)) {
            // Stock validation already supplied the customer-facing message.
        } elseif ($conn->query($sql) === TRUE) {
            if ($inventory_transaction_open) {
                $conn->commit();
                $inventory_transaction_open = false;
            }
            // Record payment in payments table if e-wallet
            if ($payment_method === 'gcash' || $payment_method === 'maya') {
                $payment_id = generateID('PAY');
                $gcash_num_val = ($payment_method === 'gcash') ? "'" . $conn->real_escape_string($wallet_number) . "'" : "NULL";
                $maya_num_val = ($payment_method === 'maya') ? "'" . $conn->real_escape_string($wallet_number) . "'" : "NULL";
                $payment_notes = "'Manual wallet payment submitted during checkout.'";
                
                $conn->query("INSERT INTO payments (
                    payment_id, transaction_id, user_id, amount, payment_method, payment_reference,
                    payment_status, payment_proof, gcash_number, maya_number, notes
                ) VALUES (
                    '$payment_id', '$transaction_id', '$user_id', '$final_amount', '$payment_method', $safe_reference,
                    '$payment_status', $safe_proof, $gcash_num_val, $maya_num_val, $payment_notes
                )");
            }
            
            $transaction_success = true;
            $transaction_data = [
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'water_type' => 'regular',
                'container_size' => $container_size,
                'container_status' => $container_status,
                'fulfillment_method' => $fulfillment_method,
                'quantity' => $quantity,
                'price_per_unit' => $price_per_unit,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'delivery_fee' => $delivery_fee,
                'final_amount' => $final_amount,
                'amount_tendered' => $amount_tendered,
                'change' => $change,
                'loyalty_points' => $loyalty_points,
                'customer_notes' => $customer_notes,
                'payment_method' => $payment_method
            ];
            // Get updated scanned data
            $sql = "SELECT * FROM users WHERE user_id = '$user_id'";
            $result = $conn->query($sql);
            if ($result->num_rows > 0) {
                $scanned_data = $result->fetch_assoc();
            }
        } else {
            if ($inventory_transaction_open) $conn->rollback();
            $error = 'Error recording transaction: ' . $conn->error;
        }
    }
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['profile_submit'])) {
    $user_id = sanitize($_POST['user_id']);
    $full_name = sanitize(trim($_POST['full_name']));
    $contact_number = sanitize(trim($_POST['contact_number']));
    $address = sanitize(trim($_POST['address']));

    if (empty($full_name) || empty($contact_number) || empty($address)) {
        $error = 'Please complete all profile fields before saving.';
    } else {
        $enc_full_name = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($full_name)));
        $enc_contact = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($contact_number)));
        $enc_address = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($address)));
        $contact_lookup = sensitive_lookup(htmlspecialchars_decode($contact_number));
        $sql_update_profile = "UPDATE users SET full_name = '$enc_full_name', contact_number = '$enc_contact', contact_lookup = '$contact_lookup', address = '$enc_address' WHERE user_id = '$user_id'";
        if ($conn->query($sql_update_profile) === TRUE) {
            $profile_success = 'Profile updated successfully.';

            // Optional profile image upload (stored by user_id, no DB column needed)
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $max_size = 5 * 1024 * 1024;
                $allowed_types = ['image/png', 'image/jpeg', 'image/webp'];
                
                if ($_FILES['profile_image']['size'] > $max_size) {
                    $error = 'Image is too large. Max size: 5MB.';
                } elseif (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
                    $error = 'Invalid image type. Please use PNG, JPG, or WEBP.';
                } else {
                    $file_ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    $upload_dir = '../uploads/profile_photos/';
                    $file_name = $user_id . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $file_path)) {
                        $profile_success = 'Profile photo updated successfully.';
                    } else {
                        $error = 'Failed to upload profile photo.';
                    }
                }
            }
        } else {
            $error = 'Unable to update profile: ' . $conn->error;
        }
    }

    $sql = "SELECT * FROM users WHERE user_id = '$user_id'";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $scanned_data = $result->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="hydromis-user-id" content="<?php echo htmlspecialchars($user_id ?? ''); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase - HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/public-ui.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(to bottom right, #f0f9ff, #f0fdf4, #f0fdfa);
            min-height: 100vh;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
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
        }
        .navbar-brand i {
            color: #2563eb;
        }
        .nav-link {
            color: #4b5563 !important;
            margin-left: 20px;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 15px;
        }
        .nav-link:hover {
            color: #2563eb !important;
        }
        .container-main {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px 16px;
        }
        .purchase-shell {
            display: flex;
            flex-direction: column;
            gap: 0;
            max-width: 500px;
            width: 100%;
        }
        .checkout-panel {
            background: linear-gradient(180deg, #ffffff 0%, #fcfdff 100%);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.05);
            padding: 40px;
            animation: slideUp 0.6s ease;
            max-width: 540px;
            width: 100%;
        }
        .buy-form h6 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 28px;
        }

        .section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 14px;
            text-transform: capitalize;
            letter-spacing: -0.3px;
        }

        .section-label i {
            color: #2563eb;
            font-size: 17px;
        }

        /* Container Grid */
        .container-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .container-card {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 0;
            border: 2px solid transparent;
            border-radius: 18px;
            background: transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: none;
            -webkit-tap-highlight-color: transparent;
        }

        .container-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }

        .container-card:hover {
            border-color: #d1d5db;
            transform: translateY(-1px);
        }

        .container-card input[type="radio"]:checked + .container-image {
            box-shadow: 0 0 0 2px #4cc9ef;
            border-color: #4cc9ef;
        }

        .container-card input[type="radio"]:checked + .container-image ~ .radio-circle {
            border-color: #4cc9ef;
            background: #ffffff;
        }

        .container-card input[type="radio"]:checked + .container-image ~ .radio-circle::after {
            opacity: 1;
        }

        .container-card input[type="radio"]:checked {
            border-color: #2563eb;
        }

        .container-card:has(input[type="radio"]:checked) {
            border-color: #4cc9ef;
        }

        .container-card:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }

        .container-image {
            width: 100%;
            height: 210px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
        }

        .container-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 10px;
            filter: none;
        }

        .container-info {
            flex: 1;
        }

        .container-size {
            font-weight: 800;
            font-size: 15px;
            color: #0f172a;
            margin-bottom: 2px;
            letter-spacing: -0.2px;
            line-height: 1;
        }

        .container-type {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 0;
            text-transform: lowercase;
        }

        .container-pricing {
            display: none;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .price-chip {
            font-size: 10px;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 8px;
            border: 1px solid #d1d5db;
            background: #f8fafc;
            color: #475569;
            transition: all 0.2s ease;
        }

        .price-chip.active {
            border-color: #0891b2;
            background: #ecfeff;
            color: #0f766e;
            box-shadow: 0 0 0 1px rgba(8, 145, 178, 0.15);
        }

        .radio-circle {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 30px;
            height: 30px;
            border: 3px solid #b6bcc5;
            border-radius: 50%;
            background: #ffffff;
            transition: all 0.3s ease;
            flex-shrink: 0;
            box-shadow: none;
        }

        .radio-circle::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #4cc9ef;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Status Options */
        .status-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .status-card {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
            -webkit-tap-highlight-color: transparent;
        }

        .status-card input[type="radio"] {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .status-card:hover {
            border-color: #cbd5e1;
            background: #f9fafb;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .status-card input[type="radio"]:checked + .status-header {
            color: #2563eb;
        }

        .status-card:has(input[type="radio"]:checked) {
            border-color: #2563eb;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.02) 0%, rgba(37, 99, 235, 0.04) 100%);
            box-shadow: 0 0 0 2px #2563eb, 0 4px 16px rgba(37, 99, 235, 0.12);
        }

        .status-card:has(input[type="radio"]:checked) .radio-circle-small {
            background: #2563eb;
            border-color: #2563eb;
        }

        .status-card:has(input[type="radio"]:checked) .radio-circle-small::after {
            opacity: 1;
        }

        .status-header {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .status-title {
            font-weight: 800;
            font-size: 15px;
            color: #0f172a;
            margin-bottom: 3px;
            letter-spacing: -0.2px;
        }

        .status-desc {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
            font-weight: 500;
        }

        .radio-circle-small {
            width: 24px;
            height: 24px;
            border: 2.5px solid #d1d5db;
            border-radius: 50%;
            background: #ffffff;
            flex-shrink: 0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .radio-circle-small::after {
            content: '✓';
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Make the full fulfillment option clickable and keep all copy readable. */
        .status-card{
            display:grid;
            grid-template-columns:minmax(125px,.7fr) minmax(0,1.3fr) 26px;
            grid-template-areas:"title description radio";
            align-items:center;
            column-gap:16px;
        }
        .status-header{display:contents}
        .status-title{grid-area:title;margin:0;line-height:1.45}
        .status-desc{grid-area:description;margin:0;line-height:1.55}
        .radio-circle-small{grid-area:radio;margin:0}
        .status-card:focus-within{outline:3px solid rgba(37,99,235,.2);outline-offset:2px}


        /* Quantity Input */
        .quantity-input {
            width: 100%;
            padding: 14px 16px;
            font-size: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: #f9fafb;
            min-height: 50px;
            -webkit-appearance: none;
            appearance: none;
            color: #0f172a;
        }

        .quantity-input:focus {
            outline: none;
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1), 0 2px 8px rgba(37, 99, 235, 0.1);
            font-size: 16px;
        }

        .quantity-hint {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
            font-weight: 500;
        }

        /* Price Breakdown Card */
        .price-breakdown-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #f3f4f6 100%);
            border: 1.5px solid #dbeafe;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 11px;
            box-shadow: 0 1px 4px rgba(37, 99, 235, 0.06);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 12px;
        }

        .breakdown-label {
            color: #6b7280;
            font-weight: 600;
        }

        .breakdown-value {
            color: #0f172a;
            font-weight: 700;
        }

        .discount-item {
            padding-top: 8px;
            border-top: 1px solid #cbd5e1;
        }

        .discount-value {
            color: #dc2626;
        }

        .final-amount-item {
            padding-top: 6px;
            font-size: 14px;
        }

        .final-value {
            color: #0d9488;
            font-size: 18px;
            font-weight: 800;
        }

        .points-item {
            padding-top: 6px;
            border-top: 1px solid #cbd5e1;
        }

        .points-value {
            color: #f59e0b;
            font-weight: 700;
        }

        .points-item i {
            color: #f59e0b;
            margin-right: 4px;
        }

        /* Amount Input */
        .amount-input {
            width: 100%;
            padding: 14px 16px;
            font-size: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: #f9fafb;
            min-height: 50px;
            -webkit-appearance: none;
            appearance: none;
            color: #0f172a;
        }

        .amount-input:focus {
            outline: none;
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1), 0 2px 8px rgba(37, 99, 235, 0.1);
            font-size: 16px;
        }

        /* Change Display Card */
        .change-display-card {
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
            border: 2px solid #6ee7b7;
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1);
        }

        .change-row {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            color: #0d9488;
            font-size: 15px;
        }

        /* Purchase Button */
        .btn-purchase {
            width: 100%;
            padding: 18px 24px;
            background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 800;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.25);
            min-height: 52px;
            letter-spacing: -0.3px;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-purchase:hover {
            background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(13, 148, 136, 0.35);
        }

        .btn-purchase:active {
            transform: translateY(0);
        }

        @media (hover: none) and (pointer: coarse) {
            .btn-purchase:active {
                background: linear-gradient(135deg, #047857 0%, #065f46 100%);
                transform: scale(0.98);
            }
        }
        .btn-back {
            width: 100%;
            padding: 16px 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05) 0%, rgba(37, 99, 235, 0.02) 100%);
            color: #2563eb;
            border: 2.5px solid #2563eb;
            border-radius: 12px;
            font-weight: 800;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 50px;
            letter-spacing: -0.3px;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
        }

        .btn-back:active {
            transform: scale(0.98);
        }

        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
            border: 2px solid #fca5a5;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
        }

        .error-message i {
            font-size: 16px;
            flex-shrink: 0;
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

        @media (max-width: 480px) {
            .checkout-panel {
                padding: 20px;
                border-radius: 18px;
            }

            .buy-form h6 {
                font-size: 17px;
                margin-bottom: 20px;
            }

            .container-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .container-card {
                gap: 8px;
            }

            .container-image {
                height: 170px;
            }

            .container-size {
                font-size: 14px;
            }

            .container-type {
                font-size: 10px;
            }

            .price-chip {
                font-size: 9px;
                padding: 3px 7px;
            }

            .status-card {
                padding: 12px;
                gap: 10px;
            }

            .status-title {
                font-size: 14px;
            }

            .status-desc {
                font-size: 11px;
            }

            .price-breakdown-card {
                padding: 14px;
                gap: 10px;
            }

            .breakdown-item {
                font-size: 11px;
            }

            .final-value {
                font-size: 15px;
            }

            .btn-purchase {
                padding: 14px 16px;
                font-size: 14px;
                min-height: 46px;
            }

            .btn-back {
                padding: 12px 16px;
                font-size: 13px;
                min-height: 44px;
            }

            .form-section {
                margin-bottom: 20px;
            }
        }
        .receipt {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .receipt-header {
            text-align: center;
            font-weight: 800;
            font-size: 18px;
            margin-bottom: 18px;
            color: #1f2937;
            letter-spacing: -0.3px;
        }
        .receipt-line {
            border-bottom: 1px dashed #d1d5db;
            margin: 12px 0;
        }
        .receipt-section {
            margin: 12px 0;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            color: #374151;
        }
        .receipt-label {
            flex: 1;
        }
        .receipt-value {
            text-align: right;
            font-weight: 600;
            color: #1f2937;
        }
        .receipt-item-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 12px;
        }
        .receipt-item-desc {
            flex: 1;
        }
        .receipt-item-total {
            text-align: right;
            font-weight: 600;
        }
        .amount-due {
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            margin-top: 8px;
            font-weight: 700;
            color: #059669;
        }
        .change-row {
            color: #059669;
            font-weight: 700;
        }
        .loyalty-row {
            color: #f59e0b;
            font-weight: 700;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 12px;
            font-size: 12px;
        }
        .receipt-footer p {
            margin: 4px 0;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .container-main {
                padding: 20px 16px;
            }
            .checkout-panel {
                padding: 20px;
            }
        }

        /* Premium product selection experience */
        :root{--purchase-blue:#1769d2;--purchase-aqua:#09b4c8;--purchase-ink:#10263a;--purchase-ease:cubic-bezier(.22,1,.36,1)}
        body.public-ui{background:radial-gradient(circle at 10% 18%,rgba(9,180,200,.14),transparent 27%),radial-gradient(circle at 90% 82%,rgba(23,105,210,.12),transparent 30%),linear-gradient(145deg,#f1f9fd,#fbfdff 55%,#edf6fb)}
        .navbar{position:relative;z-index:5;background:rgba(255,255,255,.78);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:0 8px 28px rgba(15,52,78,.06);animation:purchaseNavIn .6s var(--purchase-ease) both}.navbar-brand img{width:36px;height:36px;padding:4px;border-radius:11px;background:linear-gradient(135deg,#1769d2,#09b4c8);box-shadow:0 8px 20px rgba(9,130,170,.2)}
        .container-main{min-height:100vh;align-items:flex-start;padding:42px 20px 70px}.purchase-shell{max-width:920px}.checkout-panel{max-width:none;padding:34px;border-radius:28px;border:1px solid rgba(255,255,255,.9);box-shadow:0 30px 80px rgba(14,55,85,.15),0 4px 12px rgba(14,55,85,.05);animation:purchaseCardIn .8s .08s var(--purchase-ease) both}.buy-form{padding:0;border:0;background:transparent;box-shadow:none}.purchase-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:27px}.purchase-heading-main{display:flex;align-items:center;gap:13px}.purchase-heading-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;background:linear-gradient(145deg,#1769d2,#09b4c8);color:#fff;box-shadow:0 10px 23px rgba(23,105,210,.22)}.purchase-heading h6{margin:0 0 4px;font-size:20px}.purchase-heading p{margin:0;color:#71869a;font-size:12px}.purchase-step{padding:7px 10px;border:1px solid #d7e7f0;border-radius:999px;background:#f4f9fc;color:#547086;font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.form-section{padding-top:22px;margin-bottom:24px;border-top:1px solid #e9f0f4}.section-label{margin-bottom:15px}.section-label i{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#eaf5ff;font-size:13px}.container-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.container-card{gap:0;padding:8px;border:1px solid #e1e9ef;border-radius:18px;background:#f9fbfc;box-shadow:0 5px 14px rgba(16,38,58,.04);overflow:hidden;transition:transform .24s var(--purchase-ease),border-color .24s ease,box-shadow .24s ease}.container-card:hover{transform:translateY(-4px);border-color:#aed9e7;box-shadow:0 15px 30px rgba(18,79,112,.1)}.container-card:has(input:checked){border-color:var(--purchase-aqua);background:linear-gradient(145deg,#f8feff,#eefbfc);box-shadow:0 0 0 2px rgba(9,180,200,.2),0 15px 30px rgba(9,130,160,.12)}.container-image{height:150px;border:0;border-radius:13px;background:radial-gradient(circle at 50% 45%,#fff 0%,#f1f6f9 72%);box-shadow:none!important}.container-image img{padding:12px;transition:transform .35s var(--purchase-ease),filter .3s ease;mix-blend-mode:multiply}.container-card:hover .container-image img,.container-card:has(input:checked) .container-image img{transform:scale(1.045)}.container-info{padding:12px 8px 8px}.container-size{font-size:14px;line-height:1.25}.container-type{margin-top:3px;color:#7890a2;text-transform:capitalize}.radio-circle{top:17px;right:17px;width:25px;height:25px;border-width:2px;box-shadow:0 4px 10px rgba(16,38,58,.1)}.radio-circle::after{width:11px;height:11px;background:var(--purchase-aqua)}.container-card input[type=radio]:checked + .container-image{border:0;box-shadow:none}.status-options{display:grid;grid-template-columns:1fr 1fr;gap:13px}.status-card{min-height:106px;padding:17px;border-radius:15px}.status-card:has(input:checked){border-color:var(--purchase-blue);background:linear-gradient(145deg,#f8fbff,#edf5ff);box-shadow:0 0 0 2px rgba(23,105,210,.18),0 10px 22px rgba(23,105,210,.09)}.status-card:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(16,38,58,.08)}.btn-purchase{position:relative;min-height:56px;border-radius:14px;overflow:hidden;background:linear-gradient(120deg,#0b967f,#09b4a2,#087e73);background-size:180% 180%;box-shadow:0 15px 32px rgba(8,145,125,.25);animation:purchaseGradient 6s ease infinite;transition:transform .2s ease,box-shadow .2s ease}.btn-purchase::after{content:'';position:absolute;inset:0;transform:translateX(-120%) skewX(-20deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.28),transparent);transition:transform .7s var(--purchase-ease)}.btn-purchase:hover::after{transform:translateX(120%) skewX(-20deg)}.btn-purchase:hover{transform:translateY(-2px);box-shadow:0 19px 38px rgba(8,145,125,.32)}.btn-purchase:active{transform:scale(.988)}.btn-purchase.is-loading{pointer-events:none;opacity:.84}.notification-permission-button{right:16px!important;bottom:16px!important;padding:9px 13px!important;font-size:11px!important;background:#173f65!important;box-shadow:0 9px 24px rgba(0,0,0,.2)!important}
        @keyframes purchaseNavIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}@keyframes purchaseCardIn{from{opacity:0;transform:translateY(26px) scale(.988)}to{opacity:1;transform:none}}@keyframes purchaseGradient{0%,100%{background-position:0 50%}50%{background-position:100% 50%}}
        @media(max-width:720px){.container-main{padding:22px 12px 64px}.checkout-panel{padding:22px 18px;border-radius:22px}.purchase-heading{align-items:flex-start}.purchase-step{display:none}.container-grid{grid-template-columns:1fr}.container-card{display:grid;grid-template-columns:118px minmax(0,1fr);align-items:center;min-height:118px;padding:8px}.container-image{width:110px;height:100px}.container-info{padding:10px}.radio-circle{top:14px;right:14px}.status-options{grid-template-columns:1fr}.status-card{grid-template-columns:minmax(110px,.7fr) minmax(0,1.3fr) 26px;min-height:0}.form-section{padding-top:19px}.notification-permission-button{top:82px!important;right:12px!important;bottom:auto!important;max-width:205px}}
        @media(max-width:430px){.status-card{grid-template-columns:minmax(0,1fr) 26px;grid-template-areas:"title radio" "description description";row-gap:8px}.status-desc{padding-right:4px}}
        @media(max-width:390px){.container-card{grid-template-columns:96px minmax(0,1fr)}.container-image{width:90px;height:86px}.purchase-heading-icon{width:42px;height:42px}.purchase-heading h6{font-size:18px}}
        /* Professional order confirmation */
        .receipt{max-width:620px;margin:0 auto 20px;border:1px solid #dde7ee;border-radius:20px;background:#fff;box-shadow:0 18px 42px rgba(14,55,85,.1);font-family:'Manrope',sans-serif;animation:purchaseCardIn .7s var(--purchase-ease) both}.receipt-header{padding:18px 22px;background:linear-gradient(145deg,#f8fcfe,#eef6fa);color:var(--purchase-ink);font-size:14px;font-weight:800;letter-spacing:.08em}.receipt-section{padding:15px 22px}.receipt-row{gap:18px;font-size:12px}.receipt-label{color:#71869a}.receipt-value{color:#18344b;font-weight:700;overflow-wrap:anywhere;text-align:right}.receipt-line{margin:0 22px;border-color:#e6eef3}.receipt-item-row{font-size:12px}.receipt-footer{padding:15px 22px;border-top:1px solid #e6eef3;background:#f9fcfd}.receipt-footer p:first-child{color:#16866f;font-weight:800}.order-success-panel{max-width:620px;margin:0 auto;padding:30px 24px;border:1px solid #bde8dc;border-radius:20px;background:linear-gradient(145deg,#f3fdf9,#eafaf4);box-shadow:0 18px 40px rgba(9,122,99,.1);animation:purchaseCardIn .7s .12s var(--purchase-ease) both}.order-success-icon{display:grid;place-items:center;width:58px;height:58px;margin:0 auto 17px;border-radius:18px;background:linear-gradient(145deg,#0b9b80,#09b4a2);color:#fff;font-size:24px;box-shadow:0 12px 25px rgba(8,145,125,.24)}.order-success-panel h2{margin:0 0 8px;color:var(--purchase-ink);font-size:24px;font-weight:800}.order-success-panel>p{max-width:430px;margin:0 auto 22px;color:#667f91;font-size:13px;line-height:1.6}.confirmation-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}.confirmation-action{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:12px 20px;border-radius:12px;color:#fff!important;font-size:13px;font-weight:800;text-decoration:none!important;transition:transform .2s ease,box-shadow .2s ease}.confirmation-action:hover{transform:translateY(-2px)}.confirmation-action.track{background:linear-gradient(135deg,#1769d2,#168ec8);box-shadow:0 11px 24px rgba(23,105,210,.22)}.confirmation-action.home{background:#fff;color:#41627a!important;border:1px solid #d5e4ec;box-shadow:0 8px 18px rgba(16,55,80,.07)}
        .order-success-panel{text-align:center}
        @media(max-width:560px){.receipt-section{padding:13px 16px}.receipt-line{margin:0 16px}.receipt-row{align-items:flex-start}.order-success-panel{padding:25px 17px}.confirmation-actions{display:grid}.confirmation-action{width:100%}}
        @media(max-width:720px){html,body{scrollbar-width:none}html::-webkit-scrollbar,body::-webkit-scrollbar{display:none}.container-main{min-height:100dvh;padding:10px 8px 16px;align-items:center}.checkout-panel{padding:16px 14px;border-radius:20px}.purchase-heading{margin-bottom:14px}.purchase-heading-icon{width:40px;height:40px}.purchase-heading h6{font-size:17px}.purchase-heading p{font-size:10px}.form-section{padding-top:13px;margin-bottom:14px}.section-label{margin-bottom:10px;font-size:13px}.section-label i{width:27px;height:27px}.container-grid{gap:10px}.container-card{grid-template-columns:96px minmax(0,1fr);min-height:100px;padding:6px;border-radius:15px}.container-image{width:90px;height:86px}.container-image img{padding:9px}.container-info{padding:8px}.container-size{font-size:12px}.container-type{font-size:9px}.radio-circle{top:12px;right:12px;width:22px;height:22px}.radio-circle::after{width:9px;height:9px}.btn-purchase{min-height:49px;border-radius:12px;font-size:12px}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
    </style>
<script src="../js/ui-protection.js" defer></script>
</head>
<body class="public-ui">
    <div class="container-main">
        <div class="purchase-shell">
            <!-- Checkout Panel -->
            <div class="checkout-panel">
                <?php if ($transaction_success): ?>
                    <!-- Transaction Receipt -->
                    <div class="receipt">
                        <div class="receipt-header">
                            <i class="fas fa-receipt mr-2"></i> RECEIPT
                        </div>
                        
                        <div class="receipt-line"></div>
                        
                        <div class="receipt-section">
                            <div class="receipt-row">
                                <span class="receipt-label">Transaction ID:</span>
                                <span class="receipt-value"><?php echo $transaction_data['transaction_id']; ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Date & Time:</span>
                                <span class="receipt-value"><?php echo date('M d, Y h:i A'); ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Customer:</span>
                                <span class="receipt-value"><?php echo htmlspecialchars($scanned_data['full_name']); ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Customer ID:</span>
                                <span class="receipt-value"><?php echo $scanned_data['user_id']; ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Container:</span>
                                <span class="receipt-value"><?php echo $transaction_data['container_status'] === 'new' ? 'New container' : 'Customer-owned container'; ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Fulfillment:</span>
                                <span class="receipt-value"><i class="fas <?php echo $transaction_data['fulfillment_method'] === 'pickup' ? 'fa-store' : 'fa-truck'; ?> mr-1"></i><?php echo $transaction_data['fulfillment_method'] === 'pickup' ? 'Self pickup' : 'Delivery'; ?></span>
                            </div>
                        </div>
                        
                        <div class="receipt-line"></div>
                        
                        <div class="receipt-section">
                            <div class="receipt-header-small" style="font-weight: 700; margin-bottom: 8px;">Items</div>
                            <div class="receipt-item-row">
                                <span class="receipt-item-desc"><?php 
                                    $container_map = [
                                        '5gal-round' => '5 Gallon (Round)',
                                        '2.5gal-slim' => '2.5 Gallon (Slim)',
                                        '5gal-slim' => '5 Gallon (Slim)'
                                    ];
                                    echo $container_map[$transaction_data['container_size']] ?? 'Container';
                                ?></span>
                                <span class="receipt-item-qty"><?php echo $transaction_data['quantity']; ?></span>
                                <span class="receipt-item-unit">@ ₱<?php echo number_format($transaction_data['price_per_unit'], 2); ?></span>
                                <span class="receipt-item-total">₱<?php echo number_format($transaction_data['total_amount'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="receipt-line"></div>
                        
                        <div class="receipt-section">
                            <div class="receipt-row">
                                <span class="receipt-label">Subtotal:</span>
                                <span class="receipt-value">₱<?php echo number_format($transaction_data['total_amount'], 2); ?></span>
                            </div>
                            <?php if ($transaction_data['discount'] > 0): ?>
                            <div class="receipt-row" style="color: #059669;">
                                <span class="receipt-label"><i class="fas fa-tag mr-1"></i>Discount:</span>
                                <span class="receipt-value">-₱<?php echo number_format($transaction_data['discount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="receipt-row">
                                <span class="receipt-label"><?php echo $transaction_data['fulfillment_method'] === 'pickup' ? 'Pickup fee:' : 'Delivery fee:'; ?></span>
                                <span class="receipt-value"><?php echo $transaction_data['delivery_fee'] > 0 ? '₱' . number_format($transaction_data['delivery_fee'], 2) : 'Free'; ?></span>
                            </div>
                            <div class="receipt-row amount-due">
                                <span class="receipt-label">Order Total:</span>
                                <span class="receipt-value">₱<?php echo number_format($transaction_data['final_amount'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="receipt-line"></div>
                        
                        <?php if ($transaction_data['loyalty_points'] > 0): ?>
                        <div class="receipt-section">
                            <div class="receipt-row loyalty-row">
                                <span class="receipt-label"><i class="fas fa-star mr-1"></i>Loyalty Points Earned:</span>
                                <span class="receipt-value">+<?php echo $transaction_data['loyalty_points']; ?></span>
                            </div>
                        </div>
                        <div class="receipt-line"></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($transaction_data['customer_notes'])): ?>
                        <div class="receipt-section">
                            <div class="receipt-header-small" style="font-weight: 700; margin-bottom: 8px; color: #2563eb;"><i class="fas fa-comment-alt mr-1"></i> Special Instructions</div>
                            <div style="font-size: 13px; color: #1f2937; line-height: 1.5; padding: 8px 0; border-left: 3px solid #2563eb; padding-left: 10px;">
                                <?php echo nl2br(htmlspecialchars($transaction_data['customer_notes'])); ?>
                            </div>
                        </div>
                        <div class="receipt-line"></div>
                        <?php endif; ?>
                        
                        <div class="receipt-footer">
                            <p>Order submitted successfully</p>
                            <p>Awaiting staff confirmation and payment verification.</p>
                        </div>
                    </div>

                    <div class="order-success-panel">
                        <div class="order-success-icon"><i class="fas fa-check"></i></div>
                        <h2>Order received</h2>
                        <p>Your order is pending staff confirmation. Follow its progress from the tracking page.</p>
                        <div class="confirmation-actions">
                            <a class="confirmation-action track" href="track_order.php?user_id=<?php echo urlencode($transaction_data['user_id']); ?>">
                                <i class="fas fa-location-dot"></i> Track Order
                            </a>
                            <a class="confirmation-action home" href="../home.php">
                                <i class="fas fa-house"></i> Back to Home
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Buy Form -->
                    <div class="buy-form">
                        <div class="purchase-heading"><div class="purchase-heading-main"><div class="purchase-heading-icon"><i class="fas fa-bag-shopping"></i></div><div><h6>Build your order</h6><p>Choose a container and delivery option.</p></div></div><span class="purchase-step">Step 1 of 2</span></div>
                        <?php if ($error): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="order_review.php" id="purchaseForm">
                            <input type="hidden" name="user_id" value="<?php echo $scanned_data['user_id']; ?>">
                            <input type="hidden" name="proceed_submit" value="1">
                            
                            <!-- Container Size Selection -->
                            <div class="form-section">
                                <label class="section-label"><i class="fas fa-cube"></i> Size</label>
                                <div class="container-grid">
                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="2.5gal-slim" checked onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water3.jpg" alt="2.5 Gallon Slim">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">2.5 Gallon</div>
                                            <div class="container-type">slim</div>
                                            <div class="container-pricing">
                                                <span class="price-chip">Water: ₱15</span>
                                                <span class="price-chip">New container: +₱20</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>

                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="5gal-slim" onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water4.webp" alt="5 Gallon Slim">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">5 Gallon</div>
                                            <div class="container-type">slim</div>
                                            <div class="container-pricing">
                                                <span class="price-chip">Water: ₱40</span>
                                                <span class="price-chip">New container: +₱20</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>

                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="5gal-round" onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water5.webp" alt="5 Gallon Round">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">5 Gallon</div>
                                            <div class="container-type">round</div>
                                            <div class="container-pricing">
                                                <span class="price-chip">Water: ₱20</span>
                                                <span class="price-chip">New container: +₱20</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>
                                </div>
                                <input type="hidden" name="water_type" id="water_type" value="regular">
                            </div>

                            <input type="hidden" name="container_status" value="new">
                            <input type="hidden" name="fulfillment_method" value="delivery">
                            
                            <!-- Action Buttons -->
                            <button type="submit" class="btn-purchase">
                                <i class="fas fa-arrow-right"></i> Review order
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script>
        function toggleProfileEdit(showEdit) {
            const profileView = document.getElementById('profileView');
            const profileEditForm = document.getElementById('profileEditForm');
            const editBtn = document.getElementById('editProfileBtn');
            if (showEdit) {
                profileView.style.display = 'none';
                profileEditForm.style.display = 'flex';
                editBtn.style.display = 'none';
            } else {
                profileView.style.display = 'block';
                profileEditForm.style.display = 'none';
                editBtn.style.display = 'block';
            }
        }

        function calculatePrice() {
            const containerInput = document.querySelector('input[name="container_size"]:checked');
            const statusInput = document.querySelector('input[name="container_status"]');
            const fulfillmentInput = document.querySelector('input[name="fulfillment_method"]');
            const quantityEl = document.getElementById('quantity');
            const subtotalEl = document.getElementById('subtotal');
            const finalAmountEl = document.getElementById('finalAmount');

            if (!containerInput || !statusInput || !fulfillmentInput || !quantityEl || !subtotalEl || !finalAmountEl) {
                return;
            }

            const containerSize = containerInput.value;
            const containerStatus = statusInput.value;
            const fulfillmentMethod = fulfillmentInput.value;
            const quantity = parseFloat(quantityEl.value) || 0;
            
            // Price mapping based on container size and status
            const waterPriceMap = {'5gal-round':20,'2.5gal-slim':15,'5gal-slim':40};
            const price = waterPriceMap[containerSize] + (containerStatus === 'new' ? 20 : 0);
            const subtotal = quantity * price;
            
            // Calculate discount (per 5 containers)
            let discount = 0;
            let loyaltyPoints = quantity;
            const discountCount = Math.floor(quantity / 5);
            if (discountCount > 0) {
                discount = discountCount * 5;
            }
            
            const deliveryFee = fulfillmentMethod === 'delivery' ? 10 * quantity : 0;
            const finalAmount = subtotal + deliveryFee - discount;
            
            // Update display
            const displayQtyEl = document.getElementById('displayQty');
            const displayPriceEl = document.getElementById('displayPrice');
            const discountRow = document.getElementById('discountRow');
            const pointsRow = document.getElementById('pointsRow');

            if (displayQtyEl) {
                displayQtyEl.textContent = quantity;
            }
            if (displayPriceEl) {
                displayPriceEl.textContent = price.toFixed(2);
            }
            subtotalEl.textContent = subtotal.toFixed(2);
            finalAmountEl.textContent = finalAmount.toFixed(2);
            
            if (discount > 0 && discountRow) {
                discountRow.style.display = 'flex';
                const discountEl = document.getElementById('discount');
                if (discountEl) {
                    discountEl.textContent = discount.toFixed(2);
                }
            } else if (discountRow) {
                discountRow.style.display = 'none';
            }

            const loyaltyPointsEl = document.getElementById('loyaltyPointsCalc');
            if (pointsRow) {
                pointsRow.style.display = 'flex';
                if (loyaltyPointsEl) {
                    loyaltyPointsEl.textContent = loyaltyPoints;
                }
            }
        }
        
        function selectWaterType(type) {
            document.getElementById('water_type').value = type;
            const btnRegular = document.getElementById('btn-regular');
            const btnNowater = document.getElementById('btn-nowater');
            
            if (type === 'regular') {
                btnRegular.classList.add('active');
                btnNowater.classList.remove('active');
            } else {
                btnRegular.classList.remove('active');
                btnNowater.classList.add('active');
            }
        }
        
        function calculateChange() {
            const finalAmount = parseFloat(document.getElementById('finalAmount').textContent) || 0;
            const amountTendered = parseFloat(document.getElementById('amount_tendered').value) || 0;
            const change = amountTendered - finalAmount;
            
            const changeDisplay = document.getElementById('changeDisplay');
            if (amountTendered >= finalAmount) {
                changeDisplay.style.display = 'block';
                document.getElementById('changeAmount').textContent = change.toFixed(2);
            } else {
                changeDisplay.style.display = 'none';
            }
        }

        function confirmRewardConvert(rewardTitle, requiredPoints, currentPoints) {
            if (currentPoints < requiredPoints) {
                alert('Insufficient points to redeem this reward.');
                return false;
            }
            return confirm('Convert ' + requiredPoints + ' points for ' + rewardTitle + '?');
        }

        window.addEventListener('DOMContentLoaded', function() {
            calculatePrice();
            const purchaseForm = document.getElementById('purchaseForm');
            if (purchaseForm) purchaseForm.addEventListener('submit', function() {
                const button = this.querySelector('.btn-purchase');
                button.classList.add('is-loading');
                button.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Preparing review...';
            });
        });
    </script>
<script src="../js/user-notifications.js"></script>
</body>
</html>
