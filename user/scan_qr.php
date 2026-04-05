<?php
require_once '../config/database.php';

$scanned_data = null;
$error = '';
$transaction_success = false;
$transaction_data = null;
$profile_success = '';

// Handle QR Scan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $qr_raw_data = $_POST['qr_data'];
    
    // Try to decode JSON
    $qr_decoded = json_decode($qr_raw_data, true);
    
    if ($qr_decoded && isset($qr_decoded['user_id'])) {
        // Get user from database
        $user_id = sanitize($qr_decoded['user_id']);
        $sql = "SELECT * FROM users WHERE user_id = '$user_id'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $scanned_data = $result->fetch_assoc();
        } else {
            $error = 'User not found in database!';
        }
    } else {
        $error = 'Invalid QR code format!';
    }
}

// Handle Buy Transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['buy_submit'])) {
    $user_id = sanitize($_POST['user_id']);
    $quantity = intval($_POST['quantity']);
    $water_type = sanitize($_POST['water_type']); // 'regular' or 'nowater'
    $amount_tendered = floatval($_POST['amount_tendered']);
    
    // Calculate price and discount
    $price_per_unit = ($water_type === 'nowater') ? 30 : 20; // Regular: 20 pesos/gallon, No-water: 30 pesos
    $total_amount = $quantity * $price_per_unit;
    $discount = 0;
    $loyalty_points = 0;
    
    // Apply discount for every 5 gallons
    if ($water_type === 'regular') {
        $discount_count = floor($quantity / 5);
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
        $description = $water_type === 'nowater' 
            ? "No-Water Purchase - $quantity units @ ₱30 each" 
            : "Water Purchase - $quantity gallons @ ₱20 each";
        
        $sql = "INSERT INTO transactions (transaction_id, user_id, amount, description, water_type, quantity, price_per_unit, discount, loyalty_points_earned, status, created_at) 
                VALUES ('$transaction_id', '$user_id', '$final_amount', '$description', '$water_type', '$quantity', '$price_per_unit', '$discount', '$loyalty_points', 'pending', NOW())";
        
        if ($conn->query($sql) === TRUE) {
            // Add loyalty points if applicable
            if ($loyalty_points > 0) {
                $sql_update = "UPDATE users SET loyalty_points = loyalty_points + $loyalty_points WHERE user_id = '$user_id'";
                $conn->query($sql_update);
            }
            
            $transaction_success = true;
            $transaction_data = [
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'quantity' => $quantity,
                'water_type' => $water_type,
                'price_per_unit' => $price_per_unit,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'amount_tendered' => $amount_tendered,
                'change' => $change,
                'loyalty_points' => $loyalty_points
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
                if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $tmp_file = $_FILES['profile_image']['tmp_name'];
                    $original_name = $_FILES['profile_image']['name'];
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];

                    if (!in_array($ext, $allowed_ext, true)) {
                        $error = 'Invalid image format. Use JPG, PNG, or WEBP.';
                    } else {
                        $image_info = @getimagesize($tmp_file);
                        if ($image_info === false) {
                            $error = 'Uploaded file is not a valid image.';
                        } else {
                            $upload_dir = '../uploads/profile_photos/';
                            if (!is_dir($upload_dir)) {
                                @mkdir($upload_dir, 0755, true);
                            }

                            foreach (glob($upload_dir . $user_id . '.*') as $old_file) {
                                @unlink($old_file);
                            }

                            $target_file = $upload_dir . $user_id . '.' . $ext;
                            if (!move_uploaded_file($tmp_file, $target_file)) {
                                $error = 'Failed to upload profile image.';
                            } else {
                                $profile_success = 'Profile and image updated successfully.';
                            }
                        }
                    }
                } else {
                    $error = 'Error uploading image. Please try again.';
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
    <title>Scan QR Code - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        }
        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
            color: white !important;
            letter-spacing: 1px;
        }
        .nav-link {
            color: white !important;
            margin-left: 20px;
            transition: all 0.3s ease;
        }
        .nav-link:hover {
            color: #ffc107 !important;
            transform: translateY(-2px);
        }
        .container-main {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 90vh;
            padding: 30px 20px;
            flex-wrap: wrap;
            gap: 30px;
        }
        .nav-links {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            margin-bottom: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
        }
        .card-body {
            padding: 30px;
        }
        .card-title {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 25px;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #video-container {
            background: #000;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 4/3;
            max-width: 100%;
            margin-bottom: 25px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }
        #scanner {
            width: 100%;
            height: 100%;
            display: block;
        }
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 260px;
            height: 260px;
            border: 4px solid #10b981;
            border-radius: 15px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.7), 0 0 30px rgba(16, 185, 129, 0.3);
            animation: scannerPulse 2s infinite;
        }
        @keyframes scannerPulse {
            0%, 100% {
                box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.7), 0 0 30px rgba(16, 185, 129, 0.3);
            }
            50% {
                box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.7), 0 0 50px rgba(16, 185, 129, 0.5);
            }
        }
        .scanner-corner {
            position: absolute;
            width: 24px;
            height: 24px;
            border: 3px solid #10b981;
        }
        .corner-tl {
            top: -3px;
            left: -3px;
            border-right: none;
            border-bottom: none;
        }
        .corner-tr {
            top: -3px;
            right: -3px;
            border-left: none;
            border-bottom: none;
        }
        .corner-bl {
            bottom: -3px;
            left: -3px;
            border-right: none;
            border-top: none;
        }
        .corner-br {
            bottom: -3px;
            right: -3px;
            border-left: none;
            border-top: none;
        }
        .status {
            text-align: center;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-waiting {
            background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
            color: #3730a3;
            box-shadow: 0 4px 12px rgba(55, 48, 163, 0.15);
        }
        .status-success {
            background: linear-gradient(135deg, #d1fae5 0%, #d1f4f0 100%);
            color: #065f46;
            box-shadow: 0 4px 12px rgba(6, 95, 70, 0.15);
        }
        .status-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fed7d7 100%);
            color: #7f1d1d;
            box-shadow: 0 4px 12px rgba(127, 29, 29, 0.15);
        }
        .btn-toggle {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 12px;
            width: 100%;
            cursor: pointer;
            font-weight: 700;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-toggle:hover {
            background: linear-gradient(135deg, #5568d3 0%, #764ba2 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(102, 126, 234, 0.4);
        }
        .user-info {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border-radius: 24px;
            box-shadow: 0 25px 70px rgba(102, 126, 234, 0.3);
            padding: 0;
            max-width: 520px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.6s ease;
        }
        .user-info::before {
            content: '';
            display: block;
            height: 8px;
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 50%, #fcd34d 100%);
            width: 100%;
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .user-info h5 {
            display: none;
        }
        .user-info-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .user-info-header h5 {
            display: block;
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .loyalty-points-display {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            margin: 0 -30px 30px -30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        .loyalty-points-display i {
            font-size: 24px;
        }
        .user-info-body {
            padding: 30px;
        }
        .loyalty-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            border-radius: 12px;
            padding: 16px 24px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .loyalty-badge i {
            font-size: 20px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f0f1f3;
            transition: background 0.3s ease;
        }
        .info-item:hover {
            background: rgba(102, 126, 234, 0.05);
            padding: 16px 12px;
            border-radius: 8px;
            margin: 0 -12px;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 16px;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-denied {
            background: #fee2e2;
            color: #7f1d1d;
        }
        .btn-back {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            margin-top: 25px;
            transition: all 0.3s ease;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-back:hover {
            background: linear-gradient(135deg, #5568d3 0%, #764ba2 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(102, 126, 234, 0.4);
        }
        .hidden {
            display: none;
        }
        
        /* Additional Enhancement Styles */
        .card-title i {
            animation: iconFloat 3s ease-in-out infinite;
        }
        @keyframes iconFloat {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-5px);
            }
        }
        
        small {
            color: #6b7280;
            font-weight: 500;
        }
        
        /* Change Display Styling */
        .change-display {
            margin-top: 15px;
        }
        
        .change-item {
            padding: 12px 16px;
            background: linear-gradient(135deg, #d1fae5 0%, #d1f4f0 100%);
            border-radius: 10px;
            border-left: 4px solid #10b981;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            color: #047857;
        }
        
        /* Create Account Link */
        hr {
            border-color: #e5e7eb;
            margin: 25px 0;
        }
        
        .card-body p {
            color: #6b7280;
            text-align: center;
        }
        
        .card-body a {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .card-body a:hover {
            color: #764ba2;
            transform: translateX(3px);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-group textarea {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 12px 16px;
            font-size: 14px;
            width: 100%;
            background: #f9fafb;
            transition: all 0.3s ease;
            resize: vertical;
            min-height: 120px;
            color: #1f2937;
        }
        .form-group textarea:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .error-message {
            color: #7f1d1d;
            padding: 14px 16px;
            background: #fee2e2;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1);
        }
        .scanner-width {
            max-width: 580px;
            width: 100%;
            background: white !important;
        }
        .scanner-width .card-body {
            padding: 35px;
        }
        .buy-form {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 2px solid #10b981;
            padding: 25px;
            border-radius: 16px;
            margin-top: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.15);
        }
        .buy-form h6 {
            color: #047857;
            font-weight: 700;
            margin-bottom: 20px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .buy-form .form-control {
            border: 2px solid #d1fae5;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 15px;
            margin-bottom: 12px;
            background: white;
            transition: all 0.3s ease;
            color: #1f2937;
        }
        .buy-form .form-control:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
            background: #fafafa;
        }
        .buy-form .form-control::placeholder {
            color: #9ca3af;
        }
        .btn-buy {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s ease;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-buy:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(16, 185, 129, 0.4);
        }
        .btn-buy:active {
            transform: translateY(0);
        }
        .transaction-success {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1f4f0 100%);
            border: 2px solid #10b981;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        .transaction-success h6 {
            color: #047857;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .transaction-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #d1fae5;
            font-size: 13px;
            color: #374151;
        }
        .transaction-detail:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #047857;
            font-weight: 600;
        }
        .detail-value {
            color: #065f46;
            font-weight: 500;
        }
        .price-breakdown {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border: 2px solid #e5e7eb;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .price-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            font-size: 15px;
        }
        .price-item:last-child {
            border-bottom: none;
        }
        .price-item.final {
            padding-top: 10px;
            border-top: 2px solid #10b981;
            color: #047857;
            font-weight: 700;
            margin-top: 8px;
            font-size: 16px;
        }
        .price-item.points {
            padding-top: 10px;
            color: #b45309;
            font-weight: 700;
            font-size: 15px;
        }
        .price-item.discount-row {
            color: #10b981;
            font-weight: 600;
        }
        .price-item.loyalty-row {
            color: #f59e0b;
            font-weight: 600;
        }
        .loyalty-points {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        
        /* Amount Input Styling */
        .amount-input {
            border: 2px solid #e5e7eb !important;
            padding: 14px 16px !important;
            border-radius: 10px !important;
            font-size: 16px !important;
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%) !important;
            transition: all 0.3s ease !important;
        }
        
        .amount-input:focus {
            border-color: #10b981 !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
        }
        
        .amount-input::placeholder {
            color: #d1d5db;
        }
        
        /* Status Badge Enhancements */
        .status-badge {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            animation: badgePulse 2s infinite;
        }
        @keyframes badgePulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.8;
            }
        }
        .badge-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            box-shadow: 0 4px 12px rgba(242, 158, 13, 0.15);
        }
        .badge-approved {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }
        .badge-denied {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
        }
        
        /* Receipt Styling - Premium */
        .receipt {
            background: white;
            border-radius: 16px;
            padding: 0;
            margin: 30px 0;
            max-width: 480px;
            font-family: 'Courier New', monospace;
            box-shadow: 0 15px 50px rgba(102, 126, 234, 0.2);
            overflow: hidden;
            border: 2px solid #f0f1f3;
            transition: transform 0.3s ease;
        }
        .receipt:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.25);
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 25px;
            text-align: center;
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 2px;
            box-shadow: 0 8px 16px rgba(16, 185, 129, 0.2);
        }
        
        .receipt-line {
            border-top: 2px dashed #d1d5db;
            margin: 16px 0;
        }
        
        .receipt-section {
            padding: 16px 25px;
        }
        
        .receipt-header-small {
            font-weight: 700;
            color: #047857;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 12px;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .receipt-label {
            color: #374151;
            font-weight: 500;
            flex: 1;
        }
        
        .receipt-value {
            color: #1f2937;
            font-weight: 600;
            text-align: right;
            flex: 1;
        }
        
        .receipt-item-row {
            display: flex;
            flex-wrap: wrap;
            padding: 8px 0;
            font-size: 12px;
            border-bottom: 1px solid #f3f4f6;
            line-height: 1.8;
        }
        
        .receipt-item-row:last-child {
            border-bottom: none;
        }
        
        .receipt-item-desc {
            flex: 0 0 50%;
            color: #374151;
            font-weight: 600;
        }
        
        .receipt-item-qty {
            flex: 0 0 15%;
            text-align: center;
            color: #6b7280;
        }
        
        .receipt-item-unit {
            flex: 0 0 25%;
            color: #6b7280;
        }
        
        .receipt-item-total {
            flex: 0 0 100%;
            text-align: right;
            color: #1f2937;
            font-weight: 700;
            margin-top: 3px;
        }
        
        .amount-due {
            background: #f0fdf4;
            padding: 8px 12px;
            border-radius: 6px;
            margin: 8px 0;
            font-weight: 700;
        }
        
        .amount-due .receipt-value {
            color: #10b981;
            font-size: 14px;
        }
        
        .change-row {
            background: linear-gradient(90deg, #dbeafe 0%, #d1fae5 100%);
            padding: 10px 12px;
            border-radius: 6px;
            margin: 8px 0;
            font-weight: 700;
        }
        
        .change-row .receipt-value {
            color: #047857;
            font-size: 15px;
        }
        
        .discount-row {
            color: #10b981;
            font-weight: 600;
        }
        
        .loyalty-row {
            background: #fffbeb;
            padding: 8px 12px;
            border-radius: 6px;
            color: #b45309;
            font-weight: 700;
        }
        
        .receipt-footer {
            background: #f9fafb;
            padding: 15px 20px;
            text-align: center;
            border-radius: 0 0 10px 10px;
            border-top: 1px solid #e5e7eb;
        }
        
        .receipt-footer p {
            margin: 4px 0;
            font-size: 12px;
            color: #6b7280;
        }
        
        .receipt-footer p:first-child {
            color: #10b981;
            font-weight: 700;
            font-size: 13px;
        }
        
        /* Water Type Button Styling */
        .btn-water-type {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
            border: 2px solid #d1d5db;
            padding: 14px 18px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .btn-water-type:hover {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #047857;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        
        .btn-water-type.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-color: #047857;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.35);
            transform: scale(1.02);
        }
        
        .btn-water-type.active:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
        }
        
        .btn-water-type i {
            font-size: 18px;
        }

        /* Realistic purchase area inspired by app rewards UI */
        .purchase-shell {
            width: 100%;
            max-width: 1080px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 24px;
            align-items: start;
            background: #f3f4f6;
            border-radius: 24px;
            padding: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }
        .rewards-panel {
            background: #f8fafc;
            border-radius: 18px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        .points-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .points-strip-title {
            color: #111827;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .points-chip {
            background: #111827;
            color: #f9fafb;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .qr-points-card {
            background: #fbbf24;
            border: 2px solid #f59e0b;
            border-radius: 18px;
            padding: 18px;
            text-align: center;
            box-shadow: 0 10px 24px rgba(180, 83, 9, 0.18);
        }
        .qr-frame {
            margin: 0 auto 12px;
            width: 200px;
            height: 200px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            box-shadow: inset 0 0 0 2px #f9fafb;
        }
        .qr-frame img {
            width: 170px;
            height: 170px;
            object-fit: contain;
        }
        .qr-caption {
            color: #3f2a00;
            font-weight: 700;
            font-size: 20px;
            margin-top: 4px;
        }
        .qr-subtext {
            color: #4b5563;
            font-size: 14px;
            margin-top: 4px;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 4px;
            margin-bottom: 18px;
        }
        .profile-editor-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        .profile-editor-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .profile-editor-head h6 {
            margin: 0;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .profile-chip {
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            text-transform: uppercase;
        }
        .profile-edit-btn {
            border: none;
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: #ffffff;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 11px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            box-shadow: 0 6px 14px rgba(79, 70, 229, 0.25);
        }
        .profile-edit-btn:hover {
            filter: brightness(1.05);
        }
        .profile-summary {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .profile-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 0;
        }
        .profile-summary-row:last-child {
            border-bottom: none;
        }
        .profile-summary-row span {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .profile-summary-row strong {
            color: #111827;
            font-size: 13px;
            font-weight: 700;
            text-align: right;
        }
        .profile-edit-form {
            margin-top: 4px;
        }
        .profile-photo-block {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding: 10px;
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            background: #f8fafc;
        }
        .profile-photo-avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.25);
        }
        .profile-photo-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .profile-photo-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 100%;
            flex: 1;
            min-width: 0;
        }
        .profile-user-name {
            margin: 0;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .profile-user-code {
            margin: 0;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1.35;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .photo-upload-label {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            padding: 8px 10px;
            cursor: pointer;
            text-align: center;
            margin: 0;
        }
        .photo-upload-hint {
            color: #6b7280;
            font-size: 11px;
            margin: 0;
            line-height: 1.3;
        }
        .profile-field {
            margin-bottom: 10px;
        }
        .profile-field:last-of-type {
            margin-bottom: 12px;
        }
        .profile-field label {
            display: block;
            margin-bottom: 5px;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .profile-input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #f9fafb;
            color: #111827;
            font-size: 13px;
            padding: 10px 11px;
            transition: all 0.25s ease;
        }
        .profile-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.14);
            background: #ffffff;
        }
        .profile-save {
            width: 100%;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
            padding: 10px 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.28);
            transition: all 0.25s ease;
        }
        .profile-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.35);
        }
        .profile-alert {
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .profile-alert-success {
            color: #065f46;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
        }
        .profile-alert-error {
            color: #7f1d1d;
            border: 1px solid #fecaca;
            background: #fef2f2;
        }
        .rewards-header {
            margin-top: 22px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .rewards-header h6 {
            margin: 0;
            font-size: 32px;
            color: #111827;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .rewards-header span {
            color: #2563eb;
            font-weight: 700;
            font-size: 14px;
        }
        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .reward-item {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px;
            min-height: 132px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }
        .reward-item strong {
            color: #111827;
            font-size: 14px;
            line-height: 1.35;
        }
        .reward-item p {
            margin: 6px 0 0;
            color: #6b7280;
            font-size: 12px;
            text-align: left;
        }
        .reward-pill {
            align-self: flex-start;
            margin-top: 10px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #d1d5db;
        }
        .reward-item.unlocked {
            border-color: #10b981;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        .reward-item.unlocked .reward-pill {
            background: #ecfdf5;
            border-color: #6ee7b7;
            color: #047857;
        }
        .checkout-panel {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e5e7eb;
            padding: 18px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }
        .member-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
        }
        .member-title {
            margin: 0 0 10px;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .member-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-top: 1px dashed #d1d5db;
            padding-top: 10px;
            margin-top: 8px;
        }
        .member-meta span {
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
        }
        .member-meta strong {
            display: block;
            color: #1f2937;
            font-size: 13px;
            margin-top: 3px;
        }
        .checkout-panel .buy-form,
        .checkout-panel .receipt {
            margin-top: 0;
            margin-bottom: 0;
        }

        .water-type-grid {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 992px) {
            .container-main {
                padding: 18px 12px;
                gap: 16px;
            }
            .purchase-shell {
                grid-template-columns: 1fr;
                padding: 14px;
                border-radius: 18px;
            }
            .checkout-panel {
                padding: 14px;
            }
            .rewards-panel {
                padding: 14px;
            }
            .rewards-header h6 {
                font-size: 26px;
            }
            .receipt {
                max-width: 100%;
                margin: 16px 0;
            }
            .btn-back {
                margin-top: 16px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 10px 0;
            }
            .navbar .container-fluid {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                padding-left: 12px;
                padding-right: 12px;
            }
            .navbar-brand {
                font-size: 21px;
            }
            .ml-auto.nav-links {
                margin-left: 0 !important;
                width: 100%;
                justify-content: flex-start;
                gap: 4px;
            }
            .nav-link {
                margin-left: 0;
                margin-right: 10px;
                font-size: 13px;
                padding: 4px 2px;
            }
            .points-strip {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .points-chip {
                font-size: 13px;
                padding: 7px 12px;
            }
            .rewards-header {
                margin-top: 16px;
                margin-bottom: 10px;
            }
            .rewards-header h6 {
                font-size: 22px;
            }
            .profile-editor-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .profile-edit-btn {
                width: 100%;
                justify-content: center;
            }
            .water-type-grid {
                flex-direction: column;
            }
            .buy-form {
                padding: 14px;
                margin-top: 14px;
                margin-bottom: 14px;
            }
            .buy-form h6 {
                font-size: 14px;
                margin-bottom: 12px;
            }
            .btn-buy,
            .btn-back,
            .btn-toggle {
                min-height: 48px;
                font-size: 14px;
                letter-spacing: 0.3px;
            }
            .price-item {
                font-size: 14px;
            }
            .price-item.final {
                font-size: 15px;
            }
            .scanner-width .card-body {
                padding: 18px;
            }
            #video-container {
                margin-bottom: 16px;
            }
            .scanner-frame {
                width: 210px;
                height: 210px;
            }
            .receipt-section {
                padding: 12px 14px;
            }
            .receipt-row {
                font-size: 12px;
            }
            .receipt-item-row {
                font-size: 11px;
            }
        }

        @media (max-width: 600px) {
            .container-main {
                padding: 14px 8px;
            }
            .purchase-shell {
                padding: 10px;
                border-radius: 14px;
            }
            .rewards-panel,
            .checkout-panel {
                border-radius: 12px;
                padding: 10px;
            }
            .rewards-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .hero-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .profile-editor-card {
                padding: 12px;
            }
            .profile-editor-head h6 {
                font-size: 14px;
            }
            .profile-edit-btn {
                font-size: 11px;
                padding: 7px 9px;
                width: 100%;
                justify-content: center;
            }
            .profile-photo-block {
                gap: 8px;
                padding: 8px;
            }
            .profile-photo-avatar {
                width: 56px;
                height: 56px;
            }
            .profile-user-name {
                font-size: 12px;
            }
            .profile-user-code {
                font-size: 10px;
                letter-spacing: 0.15px;
            }
            .qr-points-card {
                padding: 12px;
            }
            .qr-frame {
                width: 120px;
                height: 120px;
            }
            .qr-frame img {
                width: 104px;
                height: 104px;
            }
            .qr-caption {
                font-size: 14px;
            }
            .qr-subtext {
                font-size: 11px;
            }
            .points-strip-title {
                font-size: 17px;
            }
            .reward-item {
                min-height: 110px;
                padding: 12px;
            }
            .reward-item strong {
                font-size: 13px;
            }
            .reward-item p {
                font-size: 11px;
            }
            .price-breakdown {
                padding: 12px;
            }
            .form-group label {
                font-size: 13px;
            }
            .scanner-frame {
                width: 180px;
                height: 180px;
            }
            .scanner-corner {
                width: 18px;
                height: 18px;
            }
        }

        @media (max-width: 380px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .profile-photo-avatar {
                width: 60px;
                height: 60px;
            }
            .profile-user-name {
                font-size: 14px;
            }
            .profile-user-code {
                font-size: 11px;
            }
            .rewards-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <span class="navbar-brand">HydroMIS</span>
            <div class="ml-auto nav-links">
                <a href="../home.php" class="nav-link"><i class="fas fa-home mr-1"></i> Home</a>
                <a href="../create_account.php" class="nav-link"><i class="fas fa-user-plus mr-1"></i> Create Account</a>
                <a href="../login.php" class="nav-link"><i class="fas fa-sign-in-alt mr-1"></i> Admin Login</a>
            </div>
        </div>
    </nav>

    <div class="container-main">
        <?php if ($scanned_data): ?>
        <!-- Realistic Purchasing View -->
        <div class="purchase-shell">
            <div class="rewards-panel">
                <div class="points-strip">
                    <div class="points-strip-title">Hydro Rewards</div>
                    <div class="points-chip">
                        <?php echo intval($scanned_data['loyalty_points'] ?? 0); ?> pts
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="hero-grid">
                        <div class="qr-points-card">
                            <div class="qr-frame">
                                <?php
                                $qr_image_src = !empty($scanned_data['qr_code_path'])
                                    ? '../' . ltrim($scanned_data['qr_code_path'], './')
                                    : 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode(json_encode(['user_id' => $scanned_data['user_id']]));
                                ?>
                                <img src="<?php echo htmlspecialchars($qr_image_src); ?>" alt="Customer QR" onerror="this.onerror=null;this.src='https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode(json_encode(['user_id' => $scanned_data['user_id']])); ?>';">
                            </div>
                            <div class="qr-caption"><?php echo htmlspecialchars($scanned_data['user_id']); ?></div>
                            <div class="qr-subtext">Scan code to collect points.</div>
                        </div>

                        <div class="profile-editor-card">
                            <div class="profile-editor-head">
                                <h6><i class="fas fa-id-card"></i> User Profile</h6>
                                <button type="button" class="profile-edit-btn" id="editProfileBtn" onclick="toggleProfileEdit(true)">
                                    <i class="fas fa-pen"></i> Edit Profile
                                </button>
                            </div>

                            <?php
                                $profile_initials = strtoupper(substr(preg_replace('/[^A-Z]/', '', $scanned_data['full_name']), 0, 2));
                                if (strlen($profile_initials) < 2) {
                                    $profile_initials = strtoupper(substr($scanned_data['full_name'], 0, 2));
                                }
                                $profile_image_src = '';
                                foreach (['jpg', 'jpeg', 'png', 'webp'] as $img_ext) {
                                    $candidate = '../uploads/profile_photos/' . $scanned_data['user_id'] . '.' . $img_ext;
                                    if (file_exists($candidate)) {
                                        $profile_image_src = $candidate;
                                        break;
                                    }
                                }
                            ?>

                            <?php $show_edit_form = isset($_POST['profile_submit']) && !empty($error); ?>

                            <?php if (!empty($error) && isset($_POST['profile_submit'])): ?>
                                <div class="profile-alert profile-alert-error">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <div id="profileView" style="display: <?php echo $show_edit_form ? 'none' : 'block'; ?>;">
                                <div class="profile-photo-block">
                                    <div class="profile-photo-avatar">
                                        <?php if (!empty($profile_image_src)): ?>
                                            <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="User Photo">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($profile_initials); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="profile-photo-actions">
                                        <p class="profile-user-name"><?php echo htmlspecialchars($scanned_data['full_name']); ?></p>
                                        <p class="profile-user-code"><?php echo htmlspecialchars($scanned_data['user_id']); ?></p>
                                    </div>
                                </div>
                            </div>

                            <form id="profileEditForm" class="profile-edit-form" method="POST" enctype="multipart/form-data" style="display: <?php echo $show_edit_form ? 'block' : 'none'; ?>;">
                                <input type="hidden" name="profile_submit" value="1">
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($scanned_data['user_id']); ?>">

                                <div class="profile-photo-block">
                                    <div class="profile-photo-avatar">
                                        <?php if (!empty($profile_image_src)): ?>
                                            <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="User Photo">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($profile_initials); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="profile-photo-actions">
                                        <label for="profile_image" class="photo-upload-label"><i class="fas fa-image"></i> Choose Photo</label>
                                        <input id="profile_image" type="file" name="profile_image" accept="image/png,image/jpeg,image/webp" style="display:none;">
                                        <p class="photo-upload-hint">PNG, JPG, or WEBP. Save Profile to apply.</p>
                                    </div>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_full_name">Full Name</label>
                                    <input id="profile_full_name" class="profile-input" type="text" name="full_name" value="<?php echo htmlspecialchars($scanned_data['full_name']); ?>" required>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_contact_number">Contact Number</label>
                                    <input id="profile_contact_number" class="profile-input" type="text" name="contact_number" value="<?php echo htmlspecialchars($scanned_data['contact_number'] ?? ''); ?>" required>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_address">Address</label>
                                    <input id="profile_address" class="profile-input" type="text" name="address" value="<?php echo htmlspecialchars($scanned_data['address'] ?? ''); ?>" required>
                                </div>

                                <button class="profile-save" type="submit">
                                    <i class="fas fa-save"></i> Save Profile
                                </button>
                                <button class="profile-save" type="button" onclick="toggleProfileEdit(false)" style="margin-top:8px;background:linear-gradient(135deg,#9ca3af 0%,#6b7280 100%);box-shadow:0 8px 16px rgba(107,114,128,.25);">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </form>
                        </div>
                </div>

                <div class="rewards-header">
                    <h6>Rewards</h6>
                </div>

                <?php $user_points = intval($scanned_data['loyalty_points'] ?? 0); ?>
                <div class="rewards-grid">
                    <div class="reward-item <?php echo $user_points >= 50 ? 'unlocked' : ''; ?>">
                        <strong>Free 1 Gallon Regular Water</strong>
                        <p>Instantly redeem at cashier after purchase.</p>
                        <span class="reward-pill"><i class="fas fa-lock"></i> 50 pts</span>
                    </div>
                    <div class="reward-item <?php echo $user_points >= 100 ? 'unlocked' : ''; ?>">
                        <strong>Discount Voucher</strong>
                        <p>Get ₱20 off on your next refill order.</p>
                        <span class="reward-pill"><i class="fas fa-lock"></i> 100 pts</span>
                    </div>
                    <div class="reward-item <?php echo $user_points >= 150 ? 'unlocked' : ''; ?>">
                        <strong>Free 1 Gallons Bundle</strong>
                        <p>Fast-lane service on your next visit.</p>
                        <span class="reward-pill"><i class="fas fa-lock"></i> 150 pts</span>
                    </div>
                    <div class="reward-item <?php echo $user_points >= 250 ? 'unlocked' : ''; ?>">
                        <strong>Free 2 Gallons Bundle</strong>
                        <p>Best value bundle for loyal customers.</p>
                        <span class="reward-pill"><i class="fas fa-lock"></i> 250 pts</span>
                    </div>
                </div>
            </div>

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
                    <div class="receipt-header-small">Items</div>
                    <div class="receipt-item-row">
                        <span class="receipt-item-desc"><?php echo $transaction_data['water_type'] === 'nowater' ? 'No-Water' : 'Regular Water'; ?></span>
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
                    <div class="receipt-row discount-row">
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
                
                <div class="receipt-footer">
                    <p>Thank you for your purchase!</p>
                    <p style="font-size: 11px; color: #666;">Status: Pending Approval</p>
                </div>
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
                <form method="POST" onchange="calculatePrice()">
                    <input type="hidden" name="user_id" value="<?php echo $scanned_data['user_id']; ?>">
                    <input type="hidden" name="buy_submit" value="1">
                    
                    <div class="form-group">
                        <label for="water_type" style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;"><i class="fas fa-droplet mr-2"></i>Water Type</label>
                        <div class="water-type-grid">
                            <button type="button" class="btn-water-type active" id="btn-regular" onclick="selectWaterType('regular'); calculatePrice();" style="flex: 1;">
                                <i class="fas fa-water"></i> Regular (₱20/gal)
                            </button>
                            <button type="button" class="btn-water-type" id="btn-nowater" onclick="selectWaterType('nowater'); calculatePrice();" style="flex: 1;">
                                <i class="fas fa-ban"></i> No-Water (₱30/unit)
                            </button>
                        </div>
                        <input type="hidden" name="water_type" id="water_type" value="regular">
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity"><i class="fas fa-cube mr-2"></i>Quantity</label>
                        <input type="number" class="form-control" name="quantity" id="quantity" placeholder="5" min="1" value="5" required onchange="calculatePrice()">
                        <small class="form-text text-muted">Regular: gallons | No-Water: units</small>
                    </div>
                    
                    <div class="price-breakdown" id="priceBreakdown">
                        <div class="price-item">
                            <span>Subtotal (<span id="displayQty">5</span> × ₱<span id="displayPrice">20</span>):</span>
                            <span>₱<strong id="subtotal">100.00</strong></span>
                        </div>
                        <div class="price-item" id="discountRow" style="display: none;">
                            <span>Discount (per 5 units):</span>
                            <span>-₱<strong id="discount">0.00</strong></span>
                        </div>
                        <div class="price-item final">
                            <span>Final Amount:</span>
                            <span>₱<strong id="finalAmount">100.00</strong></span>
                        </div>
                        <div class="price-item points" id="pointsRow" style="display: none;">
                            <span><i class="fas fa-star mr-1"></i>Loyalty Points:</span>
                            <span>+<strong id="loyaltyPointsCalc">0</strong></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="amount_tendered"><i class="fas fa-money-bill mr-2"></i>Amount Tendered (₱)</label>
                        <input type="number" class="form-control amount-input" name="amount_tendered" id="amount_tendered" placeholder="0.00" step="0.01" min="0" required onchange="calculateChange()">
                        <div id="changeDisplay" class="change-display" style="display: none;">
                            <div class="change-item">
                                <span>Change:</span>
                                <span>₱<strong id="changeAmount">0.00</strong></span>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-buy">
                        <i class="fas fa-check mr-2"></i> Confirm Purchase
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <button type="button" class="btn-back" onclick="location.href='scan_qr.php';">
                <i class="fas fa-scanner mr-2"></i> Scan Another QR
            </button>
            </div>
        </div>

        <?php else: ?>
        <!-- Scanner -->
        <div class="card scanner-width">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                </h5>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="status status-waiting" id="status">
                    <i class="fas fa-video mr-2"></i> Point your camera at a QR code
                </div>

                <div id="video-container">
                    <video id="scanner"></video>
                    <div class="scanner-frame">
                        <div class="scanner-corner corner-tl"></div>
                        <div class="scanner-corner corner-tr"></div>
                        <div class="scanner-corner corner-bl"></div>
                        <div class="scanner-corner corner-br"></div>
                    </div>
                </div>

                <button type="button" class="btn-toggle" onclick="toggleCamera();">
                    <i class="fas fa-camera mr-2"></i> Start Camera
                </button>

                <p style="color: #666; font-size: 12px; text-align: center;">
                    <i class="fas fa-info-circle"></i> By scanning your QR code, your information will be displayed
                </p>

                <form id="qr-form" method="POST" style="display: none;">
                    <div class="form-group">
                        <label>QR Data:</label>
                        <textarea name="qr_data" id="qr_data"></textarea>
                    </div>
                    <button type="submit" style="display: none;">Submit</button>
                </form>

                <hr>

                <div style="text-align: center;">
                    <p style="color: #666; margin-bottom: 15px;">Don't have an account yet?</p>
                    <a href="create_account.php" style="color: #667eea; text-decoration: none; font-weight: 600;">
                        <i class="fas fa-user-plus mr-2"></i> Create New Account
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let video;
        let cameraActive = false;

        async function toggleCamera() {
            const button = document.querySelector('.btn-toggle');
            
            if (!cameraActive) {
                try {
                    video = document.getElementById('scanner');
                    const stream = await navigator.mediaDevices.getUserMedia({ 
                        video: { facingMode: "environment" }
                    });
                    video.srcObject = stream;
                    video.play();
                    cameraActive = true;
                    button.textContent = '⏹ Stop Camera';
                    startScanning();
                } catch (err) {
                    alert('Unable to access camera: ' + err.message);
                    updateStatus('Camera access denied', false);
                }
            } else {
                video.srcObject.getTracks().forEach(track => track.stop());
                cameraActive = false;
                button.textContent = '📷 Start Camera';
                updateStatus('Camera stopped', false);
            }
        }

        function startScanning() {
            function scan() {
                if (!cameraActive) return;

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                video.width = video.clientWidth;
                video.height = video.clientHeight;
                
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const code = jsQR(imageData.data, canvas.width, canvas.height, {
                        inversionAttempts: "dontInvert",
                    });

                    if (code) {
                        try {
                            const data = JSON.parse(code.data);
                            if (data.user_id) {
                                updateStatus('QR Code detected! Processing...', 'success');
                                document.getElementById('qr_data').value = code.data;
                                setTimeout(() => {
                                    document.getElementById('qr-form').submit();
                                }, 500);
                                return;
                            }
                        } catch (e) {
                            // Not a valid JSON QR code
                        }
                    }
                }
                
                requestAnimationFrame(scan);
            }
            
            updateStatus('Scanning...', 'waiting');
            scan();
        }

        function updateStatus(message, type = 'waiting') {
            const status = document.getElementById('status');
            status.textContent = message;
            const classes = ['status-waiting', 'status-success', 'status-error'];
            status.className = 'status status-' + type;
        }

        // Price Calculation Function
        function calculatePrice() {
            const waterType = document.getElementById('water_type').value;
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const pricePerUnit = waterType === 'nowater' ? 30 : 20;
            
            // Calculate subtotal
            const subtotal = quantity * pricePerUnit;
            
            // Calculate discount (5 pesos per 5 units, only for regular water)
            let discount = 0;
            let loyaltyPoints = 0;
            if (waterType === 'regular') {
                const discountCount = Math.floor(quantity / 5);
                discount = discountCount * 5;
                loyaltyPoints = discountCount;
            }
            
            // Calculate final amount
            const finalAmount = subtotal - discount;
            
            // Update display
            document.getElementById('displayQty').textContent = quantity;
            document.getElementById('displayPrice').textContent = pricePerUnit;
            document.getElementById('subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('finalAmount').textContent = finalAmount.toFixed(2);
            
            // Show/hide discount row
            const discountRow = document.getElementById('discountRow');
            if (discount > 0) {
                discountRow.style.display = 'flex';
                document.getElementById('discount').textContent = discount.toFixed(2);
            } else {
                discountRow.style.display = 'none';
            }
            
            // Show/hide loyalty points row
            const pointsRow = document.getElementById('pointsRow');
            if (loyaltyPoints > 0) {
                pointsRow.style.display = 'flex';
                document.getElementById('loyaltyPointsCalc').textContent = loyaltyPoints;
            } else {
                pointsRow.style.display = 'none';
            }
        }
        
        // Water Type Selection
        function selectWaterType(type) {
            document.getElementById('water_type').value = type;
            
            // Update button styles
            const regularBtn = document.getElementById('btn-regular');
            const nowaterBtn = document.getElementById('btn-nowater');
            
            if (type === 'regular') {
                regularBtn.classList.add('active');
                nowaterBtn.classList.remove('active');
            } else {
                nowaterBtn.classList.add('active');
                regularBtn.classList.remove('active');
            }
        }
        
        // Calculate Change Function
        function calculateChange() {
            const amountTenderedInput = document.getElementById('amount_tendered');
            const changeDisplay = document.getElementById('changeDisplay');
            
            if (!amountTenderedInput || !changeDisplay) return;
            
            const amountTendered = parseFloat(amountTenderedInput.value) || 0;
            const finalAmountText = document.getElementById('finalAmount').textContent;
            const finalAmount = parseFloat(finalAmountText) || 0;
            
            if (amountTendered > 0 && finalAmount > 0) {
                const change = amountTendered - finalAmount;
                
                if (change >= 0) {
                    changeDisplay.innerHTML = `
                        <div style="padding: 12px; background: #d1fae5; border-radius: 8px; border-left: 4px solid #10b981; margin-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #047857; font-weight: 600;">Change:</span>
                                <span style="color: #10b981; font-weight: 700; font-size: 16px;">₱${change.toFixed(2)}</span>
                            </div>
                        </div>
                    `;
                } else {
                    const shortage = Math.abs(change);
                    changeDisplay.innerHTML = `
                        <div style="padding: 12px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #ef4444; margin-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #7f1d1d; font-weight: 600;">Still Need:</span>
                                <span style="color: #ef4444; font-weight: 700; font-size: 16px;">₱${shortage.toFixed(2)}</span>
                            </div>
                        </div>
                    `;
                }
            } else {
                changeDisplay.innerHTML = '';
            }
        }

        function toggleProfileEdit(showEdit) {
            const profileView = document.getElementById('profileView');
            const profileForm = document.getElementById('profileEditForm');
            const editBtn = document.getElementById('editProfileBtn');

            if (!profileView || !profileForm || !editBtn) return;

            if (showEdit) {
                profileView.style.display = 'none';
                profileForm.style.display = 'block';
                editBtn.style.display = 'none';
            } else {
                profileView.style.display = 'block';
                profileForm.style.display = 'none';
                editBtn.style.display = 'inline-flex';
            }
        }
        
        // Initialize price calculation on page load
        window.addEventListener('DOMContentLoaded', function() {
            calculatePrice();

            const profileForm = document.getElementById('profileEditForm');
            const editBtn = document.getElementById('editProfileBtn');
            if (profileForm && editBtn && profileForm.style.display === 'block') {
                editBtn.style.display = 'none';
            }
        });
    </script>
</body>
</html>
