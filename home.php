<?php
session_start();
require_once 'config/database.php';

// Check if database is initialized
$db_initialized = true;
$check_table = $conn->query("SHOW TABLES LIKE 'users'");
if (!$check_table || $check_table->num_rows == 0) {
    $db_initialized = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Home</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            padding: 15px 30px;
            position: fixed;
            top: 0;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
        .navbar-brand {
            color: #667eea !important;
            font-weight: bold;
            font-size: 24px;
        }
        .nav-link {
            color: #333 !important;
            margin-left: 20px;
            transition: color 0.3s;
        }
        .nav-link:hover {
            color: #667eea !important;
        }
        .container-main {
            margin-top: 80px;
            max-width: 900px;
            background: white;
            border-radius: 15px;
            padding: 50px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            text-align: center;
        }
        .header {
            margin-bottom: 50px;
        }
        .header h1 {
            font-size: 48px;
            color: #667eea;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 18px;
            color: #666;
            margin: 0;
        }
        .options-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 40px;
        }
        .option-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            color: white;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 300px;
        }
        .option-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
            text-decoration: none;
            color: white;
        }
        .option-card.secondary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .option-card.tertiary {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .option-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        .option-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .option-description {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.6;
        }
        .login-btn {
            position: fixed;
            top: 20px;
            right: 30px;
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s;
        }
        .login-btn:hover {
            background: #5568d3;
            color: white;
            text-decoration: none;
        }
        @media (max-width: 768px) {
            .options-container {
                grid-template-columns: 1fr;
            }
            .container-main {
                padding: 30px;
            }
            .header h1 {
                font-size: 36px;
            }
            .navbar-custom {
                padding: 10px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <div class="navbar-custom d-flex justify-content-between align-items-center">
        <div>
            <span class="navbar-brand">🌊 HydroMIS</span>
        </div>
        <a href="login.php" class="login-btn">
            <i class="fas fa-sign-in-alt mr-2"></i> login
        </a>
    </div>

    <!-- Main Container -->
    <div class="container-main">
        <div class="header">
            <h1>Welcome to HydroMIS</h1>
            <p>Water Management Information System</p>
        </div>

        <!-- Options -->
        <div class="options-container">
            <!-- Create Account Card -->
            <a href="create_account.php" class="option-card">
                <div class="option-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="option-title">Create Account</div>
                <div class="option-description">
                    Register as a new customer and get your unique QR code
                </div>
            </a>

            <!-- Scan QR Code Card -->
            <a href="user/scan_qr.php" class="option-card secondary">
                <div class="option-icon">
                    <i class="fas fa-qrcode"></i>
                </div>
                <div class="option-title">Scan QR Code to Buy</div>
                <div class="option-description">
                    Use your camera to scan a QR code and record purchases
                </div>
            </a>

            <!-- Track Order Card -->
            <a href="user/track_order.php" class="option-card tertiary">
                <div class="option-icon">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="option-title">Track Order</div>
                <div class="option-description">
                    Check your order status and delivery information
                </div>
            </a>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
