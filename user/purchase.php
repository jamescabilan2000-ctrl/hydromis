<?php
require_once '../config/database.php';

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

// Handle Buy Transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['buy_submit'])) {
    $user_id = sanitize($_POST['user_id']);
    $quantity = intval($_POST['quantity']);
    $container_size = sanitize($_POST['container_size']); // '5gal-round', '2.5gal-slim', '5gal-slim'
    $container_status = sanitize($_POST['container_status']); // 'new' or 'pickup'
    $amount_tendered = floatval($_POST['amount_tendered']);
    $customer_notes = isset($_POST['customer_notes']) ? sanitize($_POST['customer_notes']) : '';
    
    // Price mapping based on container size and status
    $price_map = [
        '5gal-round' => ['new' => 50, 'pickup' => 45],
        '2.5gal-slim' => ['new' => 30, 'pickup' => 25],
        '5gal-slim' => ['new' => 50, 'pickup' => 45]
    ];
    
    $price_per_unit = $price_map[$container_size][$container_status];
    $total_amount = $quantity * $price_per_unit;
    $discount = 0;
    $loyalty_points = 0;
    
    // Apply discount for every 5 containers
    $discount_count = floor($quantity / 5);
    if ($discount_count > 0) {
        $discount = $discount_count * 5;
        $loyalty_points = $discount_count;
    }
    
    $final_amount = $total_amount - $discount;
    $change = $amount_tendered - $final_amount;
    
    if ($quantity <= 0) {
        $error = 'Quantity must be at least 1!';
    } elseif ($amount_tendered < $final_amount) {
        $error = 'Amount tendered is insufficient! Amount needed: ₱' . number_format($final_amount, 2);
    } else {
        $transaction_id = generateID('TXN');
        $container_label = str_replace('-', ' ', $container_size);
        $status_label = ($container_status === 'new') ? 'New' : 'Pickup & Deliver';
        $description = "Container Order - $quantity × $container_label ($status_label) @ ₱$price_per_unit each";
        
        $sql = "INSERT INTO transactions (transaction_id, user_id, amount, description, water_type, quantity, price_per_unit, discount, loyalty_points_earned, notes, status, created_at) 
                VALUES ('$transaction_id', '$user_id', '$final_amount', '$description', 'regular', '$quantity', '$price_per_unit', '$discount', '$loyalty_points', '$customer_notes', 'pending', NOW())";
        
        if ($conn->query($sql) === TRUE) {
            // Add loyalty points if applicable
            if ($loyalty_points > 0) {
                $sql_update_points = "UPDATE users SET loyalty_points = loyalty_points + $loyalty_points WHERE user_id = '$user_id'";
                $conn->query($sql_update_points);
            }
            
            $transaction_success = true;
            $transaction_data = [
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'water_type' => 'regular',
                'container_size' => $container_size,
                'quantity' => $quantity,
                'price_per_unit' => $price_per_unit,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'amount_tendered' => $amount_tendered,
                'change' => $change,
                'loyalty_points' => $loyalty_points,
                'customer_notes' => $customer_notes
            ];
            // Get updated scanned data
            $sql = "SELECT * FROM users WHERE user_id = '$user_id'";
            $result = $conn->query($sql);
            if ($result->num_rows > 0) {
                $scanned_data = $result->fetch_assoc();
            }
        } else {
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
        $sql_update_profile = "UPDATE users SET full_name = '$full_name', contact_number = '$contact_number', address = '$address' WHERE user_id = '$user_id'";
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
            display: none;
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
            display: none;
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
    </style>
</head>
<body class="public-ui">
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <a href="../home.php" class="navbar-brand">
                <i class="fas fa-droplet"></i> HydroMIS
            </a>
        </div>
    </nav>

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
                        </div>
                        
                        <div class="receipt-line"></div>
                        
                        <div class="receipt-section">
                            <div class="receipt-header-small" style="font-weight: 700; margin-bottom: 8px;">Items</div>
                            <div class="receipt-item-row">
                                <span class="receipt-item-desc"><?php 
                                    $container_map = [
                                        '5gal-round' => '5.00 Gal (Round)',
                                        '2.5gal-slim' => '2.50 Gal (Slim)',
                                        '5gal-slim' => '5.00 Gal (Slim)'
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
                            <div class="receipt-row amount-due">
                                <span class="receipt-label">Amount Due:</span>
                                <span class="receipt-value">₱<?php echo number_format($transaction_data['final_amount'], 2); ?></span>
                            </div>
                            <div class="receipt-row">
                                <span class="receipt-label">Amount Tendered:</span>
                                <span class="receipt-value">₱<?php echo number_format($transaction_data['amount_tendered'], 2); ?></span>
                            </div>
                            <div class="receipt-row change-row">
                                <span class="receipt-label"><strong>Change:</strong></span>
                                <span class="receipt-value"><strong>₱<?php echo number_format($transaction_data['change'], 2); ?></strong></span>
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
                            <p>Thank you for your purchase!</p>
                            <p style="font-size: 11px; color: #666;">Status: Pending Approval</p>
                        </div>
                    </div>

                    <!-- Success Message & Home Button -->
                    <div style="text-align: center; padding: 40px 20px; background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border-radius: 12px; margin-top: 20px; border: 2px solid #0d9488;">
                        <div style="font-size: 48px; color: #0d9488; margin-bottom: 16px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2 style="font-size: 28px; font-weight: 800; color: #1f2937; margin: 0 0 12px 0;">Thank You!</h2>
                        <p style="font-size: 16px; color: #6b7280; margin-bottom: 24px; line-height: 1.6;">
                            Your order has been successfully placed.<br>
                            A rider will be assigned shortly to deliver your order.
                        </p>
                        <a href="track_order.php?user_id=<?php echo urlencode($transaction_data['user_id']); ?>" style="display: inline-block; padding: 14px 32px; margin-right: 10px; background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 800; font-size: 16px; transition: all 0.3s ease; box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(37, 99, 235, 0.35)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 16px rgba(37, 99, 235, 0.25)';">
                            Track Order
                        </a>
                        <a href="../home.php" style="display: inline-block; padding: 14px 32px; background: linear-gradient(180deg, #0d9488 0%, #059669 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 800; font-size: 16px; transition: all 0.3s ease; box-shadow: 0 6px 16px rgba(13, 148, 136, 0.25);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(13, 148, 136, 0.35)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 16px rgba(13, 148, 136, 0.25)';">
                            ← Back to Home
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Buy Form -->
                    <div class="buy-form">
                        <h6><i class="fas fa-shopping-bag mr-2"></i> Record Purchase</h6>
                        <?php if ($error): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="order_review.php">
                            <input type="hidden" name="user_id" value="<?php echo $scanned_data['user_id']; ?>">
                            <input type="hidden" name="proceed_submit" value="1">
                            
                            <!-- Container Size Selection -->
                            <div class="form-section">
                                <label class="section-label"><i class="fas fa-cube"></i> Size</label>
                                <div class="container-grid">
                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="5gal-round" checked onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water3.jpg" alt="5.00 Gal Round Container">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">5.00 Gal</div>
                                            <div class="container-type">round container</div>
                                            <div class="container-pricing">
                                                <span class="price-chip" data-status="new">New: ₱50</span>
                                                <span class="price-chip" data-status="pickup">Pickup: ₱45</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>

                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="2.5gal-slim" onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water4.webp" alt="2.50 Gal Slim Container">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">2.50 Gal</div>
                                            <div class="container-type">slim container</div>
                                            <div class="container-pricing">
                                                <span class="price-chip" data-status="new">New: ₱30</span>
                                                <span class="price-chip" data-status="pickup">Pickup: ₱25</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>

                                    <label class="container-card">
                                        <input type="radio" name="container_size" value="5gal-slim" onchange="calculatePrice()">
                                        <div class="container-image">
                                            <img src="../imagess/water5.webp" alt="5.00 Gal Slim Container">
                                        </div>
                                        <div class="container-info">
                                            <div class="container-size">5.00 Gal</div>
                                            <div class="container-type">slim container</div>
                                            <div class="container-pricing">
                                                <span class="price-chip" data-status="new">New: ₱50</span>
                                                <span class="price-chip" data-status="pickup">Pickup: ₱45</span>
                                            </div>
                                        </div>
                                        <div class="radio-circle"></div>
                                    </label>
                                </div>
                                <input type="hidden" name="water_type" id="water_type" value="regular">
                            </div>

                            <!-- Container Status Selection -->
                            <div class="form-section">
                                <label class="section-label"><i class="fas fa-box"></i> Container status</label>
                                <div class="status-options">
                                    <label class="status-card">
                                        <input type="radio" name="container_status" value="new" checked onchange="calculatePrice()">
                                        <div class="status-header">
                                            <div class="status-title">New containers</div>
                                            <div class="radio-circle-small"></div>
                                        </div>
                                        <div class="status-desc">Supplier will deliver water in their containers. In most cases there will be a surcharge.</div>
                                    </label>

                                    <label class="status-card">
                                        <input type="radio" name="container_status" value="pickup" onchange="calculatePrice()">
                                        <div class="status-header">
                                            <div class="status-title">Pickup & deliver containers</div>
                                            <div class="radio-circle-small"></div>
                                        </div>
                                        <div class="status-desc">Supplier will pick up your container(s), refill, and return them.</div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <button type="submit" class="btn-purchase">
                                <i class="fas fa-check"></i> Proceed
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
            const statusInput = document.querySelector('input[name="container_status"]:checked');
            const quantityEl = document.getElementById('quantity');
            const subtotalEl = document.getElementById('subtotal');
            const finalAmountEl = document.getElementById('finalAmount');

            if (!containerInput || !statusInput || !quantityEl || !subtotalEl || !finalAmountEl) {
                return;
            }

            const containerSize = containerInput.value;
            const containerStatus = statusInput.value;
            const quantity = parseFloat(quantityEl.value) || 0;
            
            // Price mapping based on container size and status
            const priceMap = {
                '5gal-round': { new: 50, pickup: 45 },
                '2.5gal-slim': { new: 30, pickup: 25 },
                '5gal-slim': { new: 50, pickup: 45 }
            };
            
            const price = priceMap[containerSize][containerStatus];
            const subtotal = quantity * price;
            
            // Calculate discount (per 5 containers)
            let discount = 0;
            let loyaltyPoints = 0;
            const discountCount = Math.floor(quantity / 5);
            if (discountCount > 0) {
                discount = discountCount * 5;
                loyaltyPoints = discountCount;
            }
            
            const finalAmount = subtotal - discount;
            
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
            
            if (discount > 0 && discountRow && pointsRow) {
                discountRow.style.display = 'flex';
                const discountEl = document.getElementById('discount');
                const loyaltyPointsEl = document.getElementById('loyaltyPointsCalc');
                if (discountEl) {
                    discountEl.textContent = discount.toFixed(2);
                }
                pointsRow.style.display = 'flex';
                if (loyaltyPointsEl) {
                    loyaltyPointsEl.textContent = loyaltyPoints;
                }
            } else if (discountRow && pointsRow) {
                discountRow.style.display = 'none';
                pointsRow.style.display = 'none';
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
        });
    </script>
</body>
</html>
