<?php
// System info helper
$is_configured = file_exists('config/database.php') && 
                 is_dir('qrcodes') && 
                 is_writable('qrcodes');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Water Management System</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: rgba(0, 0, 0, 0.2);
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .navbar-brand {
            font-size: 28px;
            font-weight: bold;
            color: white;
        }
        .nav-link {
            color: white !important;
            margin: 0 15px;
            font-weight: 500;
        }
        .hero-section {
            min-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding: 20px;
        }
        .hero-content h1 {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }
        .hero-content p {
            font-size: 20px;
            margin-bottom: 30px;
            opacity: 0.95;
        }
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }
        .action-buttons a, .action-buttons button {
            padding: 15px 30px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 16px;
            transition: transform 0.2s;
        }
        .action-buttons a:hover, .action-buttons button:hover {
            transform: translateY(-2px);
        }
        .btn-primary-action {
            background: white;
            color: #667eea;
        }
        .btn-secondary-action {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
        }
        .features-section {
            background: white;
            padding: 60px 20px;
            border-radius: 15px 15px 0 0;
            margin-top: 40px;
        }
        .section-title {
            text-align: center;
            margin-bottom: 50px;
            color: #333;
        }
        .section-title h2 {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .section-title p {
            color: #666;
            font-size: 16px;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .feature-card {
            background: #f9fafb;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        .feature-card:hover {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transform: translateY(-5px);
        }
        .feature-icon {
            font-size: 40px;
            color: #667eea;
            margin-bottom: 15px;
        }
        .feature-card h4 {
            color: #333;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .feature-card p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        .stats-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 50px 20px;
            color: white;
            text-align: center;
        }
        .stat-item {
            margin: 20px 0;
        }
        .stat-number {
            font-size: 36px;
            font-weight: bold;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .roles-section {
            max-width: 1200px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .role-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .role-card h4 {
            color: #667eea;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .role-card ul {
            list-style: none;
            padding: 0;
        }
        .role-card li {
            padding: 8px 0;
            color: #666;
        }
        .role-card li:before {
            content: "✓ ";
            color: #10b981;
            font-weight: bold;
            margin-right: 8px;
        }
        .alert-info {
            background: #e0e7ff;
            border-left: 4px solid #667eea;
            color: #3730a3;
        }
        footer {
            background: rgba(0, 0, 0, 0.2);
            color: white;
            text-align: center;
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <span class="navbar-brand"><i class="fas fa-water mr-2"></i> HydroMIS</span>
            <div class="ml-auto">
                <a href="verify.php" class="nav-link" title="Check system status">
                    <i class="fas fa-check-circle mr-2"></i> Verify System
                </a>
                <a href="QUICKSTART.md" class="nav-link" title="Quick setup guide">
                    <i class="fas fa-bolt mr-2"></i> Quick Start
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-content" style="max-width: 900px;">
            <h1>🌊 HydroMIS</h1>
            <p>Water Management Information System</p>
            <p style="font-size: 16px; opacity: 0.9;">Streamlined management for water services with QR-based account creation and real-time transaction approval</p>

            <div class="action-buttons">
                <a href="index.php" class="btn-primary-action">
                    <i class="fas fa-sign-in-alt mr-2"></i> Admin/Staff Login
                </a>
                <a href="user/create_account.php" class="btn-secondary-action">
                    <i class="fas fa-user-plus mr-2"></i> Create Account
                </a>
                <a href="user/scan_qr.php" class="btn-secondary-action">
                    <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                </a>
            </div>

            <?php if (!$is_configured): ?>
            <div class="alert alert-warning" style="max-width: 500px; margin: 0 auto;">
                <i class="fas fa-exclamation-triangle mr-2"></i> 
                <strong>Setup Required!</strong> <br>
                Please <a href="verify.php" style="color: #d39e00;">verify your system setup</a> or check the QUICKSTART.md file.
            </div>
            <?php else: ?>
            <div class="alert alert-success" style="max-width: 500px; margin: 0 auto;">
                <i class="fas fa-check-circle mr-2"></i> 
                <strong>System Ready!</strong> System is properly configured and ready to use.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Features Section -->
    <div class="features-section">
        <div class="section-title">
            <h2>✨ Key Features</h2>
            <p>Comprehensive water management solution</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h4>Admin Dashboard</h4>
                <p>Complete overview of sales, users, and transactions with real-time statistics</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-check-double"></i></div>
                <h4>Staff Management</h4>
                <p>Approve or deny customer transactions with real-time monitoring capabilities</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-qrcode"></i></div>
                <h4>QR Code Generation</h4>
                <p>Automatic QR code creation for each new account with instant access</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-camera"></i></div>
                <h4>QR Code Scanning</h4>
                <p>Real-time camera-based QR scanning to retrieve customer information</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <h4>User Management</h4>
                <p>Complete user account management with approval workflow system</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-receipt"></i></div>
                <h4>Transaction Tracking</h4>
                <p>Comprehensive transaction history with detailed reporting and analytics</p>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="stats-section">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <div class="stat-item">
                        <div class="stat-number">4</div>
                        <div class="stat-label">Core Modules</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-item">
                        <div class="stat-number">3</div>
                        <div class="stat-label">User Roles</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-item">
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Functional</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Roles Section -->
    <div class="roles-section">
        <div class="section-title">
            <h2>👥 User Roles</h2>
            <p>Different levels of access and functionality</p>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="role-card">
                    <h4><i class="fas fa-user mr-2"></i> Public User</h4>
                    <ul>
                        <li>Create new account</li>
                        <li>Generate QR code</li>
                        <li>Scan QR codes</li>
                        <li>View account info</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-4">
                <div class="role-card">
                    <h4><i class="fas fa-user-tie mr-2"></i> Staff</h4>
                    <ul>
                        <li>View dashboard</li>
                        <li>Approve transactions</li>
                        <li>Deny transactions</li>
                        <li>View transaction history</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-4">
                <div class="role-card">
                    <h4><i class="fas fa-user-shield mr-2"></i> Admin</h4>
                    <ul>
                        <li>View all statistics</li>
                        <li>Manage users</li>
                        <li>Approve/deny accounts</li>
                        <li>Generate reports</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Demo Credentials -->
    <div style="max-width: 900px; margin: 40px auto; padding: 0 20px;">
        <div class="alert alert-info">
            <h5 class="alert-heading"><i class="fas fa-info-circle mr-2"></i> Demo Credentials</h5>
            <hr>
            <p><strong>Admin Account:</strong> username: <code>admin</code> | password: <code>admin123</code></p>
            <p><strong>Staff Account:</strong> username: <code>staff1</code> | password: <code>admin123</code></p>
            <p style="margin: 0;"><strong>Note:</strong> Public users can create accounts and generate QR codes without login.</p>
        </div>
    </div>

    <!-- Quick Links -->
    <div style="max-width: 900px; margin: 40px auto; padding: 0 20px; margin-bottom: 40px;">
        <div class="row">
            <div class="col-md-6">
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); margin-bottom: 20px;">
                    <h5 style="color: #667eea; margin-bottom: 15px;">
                        <i class="fas fa-book mr-2"></i> Documentation
                    </h5>
                    <ul style="list-style: none; padding: 0; color: #666;">
                        <li style="margin-bottom: 8px;">
                            <a href="README.md" download style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-file-pdf mr-2"></i> Full Documentation
                            </a>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <a href="QUICKSTART.md" download style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-bolt mr-2"></i> Quick Start Guide
                            </a>
                        </li>
                        <li>
                            <a href="verify.php" style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-check-circle mr-2"></i> System Verification
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); margin-bottom: 20px;">
                    <h5 style="color: #667eea; margin-bottom: 15px;">
                        <i class="fas fa-rocket mr-2"></i> Get Started
                    </h5>
                    <ul style="list-style: none; padding: 0; color: #666;">
                        <li style="margin-bottom: 8px;">
                            <a href="user/create_account.php" style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-user-plus mr-2"></i> Create New Account
                            </a>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <a href="user/scan_qr.php" style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                            </a>
                        </li>
                        <li>
                            <a href="index.php" style="color: #667eea; text-decoration: none;">
                                <i class="fas fa-sign-in-alt mr-2"></i> Admin/Staff Login
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <p>HydroMIS v1.0 • Water Management Information System</p>
        <p style="font-size: 12px; margin: 10px 0 0 0; opacity: 0.8;">Built with ❤️ for efficient water service management</p>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
