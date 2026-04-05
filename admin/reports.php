<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Get statistics for reports
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$approved_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='approved'")->fetch_assoc()['count'];
$pending_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'];
$denied_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='denied'")->fetch_assoc()['count'];

$total_transactions = $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'];
$approved_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved'")->fetch_assoc()['count'];
$pending_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending'")->fetch_assoc()['count'];
$denied_trans = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied'")->fetch_assoc()['count'];

$total_sales = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE status='approved'")->fetch_assoc()['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
        }
        .sidebar {
            background: white;
            height: 100vh;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        .sidebar-menu li {
            margin: 10px 0;
        }
        .sidebar-menu a {
            color: #333;
            text-decoration: none;
            display: block;
            padding: 10px 15px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #667eea;
            color: white;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #667eea;
        }
        .stat-card h6 {
            color: #666;
            font-size: 12px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        .stat-card .icon {
            font-size: 30px;
            color: #667eea;
            margin-right: 10px;
            float: right;
        }
        .content {
            padding: 30px;
        }
        .header-section {
            margin-bottom: 30px;
        }
        .header-section h2 {
            color: #333;
            font-weight: bold;
        }
        .section-title {
            color: #333;
            font-weight: bold;
            margin-top: 30px;
            margin-bottom: 20px;
            font-size: 18px;
        }
        .logout-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .logout-btn:hover {
            background: #dc2626;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">🌊 HydroMIS - Admin</span>
            <div class="ml-auto">
                <span class="text-white mr-20">Welcome, <?php echo $_SESSION['full_name']; ?></span>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2" style="padding: 0;">
                <div class="sidebar">
                    <ul class="sidebar-menu">
                        <li><a href="dashboard.php"><i class="fas fa-dashboard mr-2"></i> Dashboard</a></li>
                        <li><a href="users.php"><i class="fas fa-users mr-2"></i> Manage Users</a></li>
                        <li><a href="transactions.php"><i class="fas fa-exchange mr-2"></i> View Transactions</a></li>
                        <li><a href="reports.php" class="active"><i class="fas fa-chart-bar mr-2"></i> Reports</a></li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="content">
                    <div class="header-section">
                        <h2>📈 System Reports</h2>
                        <p class="text-muted">Comprehensive statistics and analysis</p>
                    </div>

                    <!-- User Statistics -->
                    <h5 class="section-title">👥 User Statistics</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="icon"><i class="fas fa-users"></i></div>
                                <h6>Total Users</h6>
                                <div class="value"><?php echo $total_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #10b981;">
                                <div class="icon" style="color: #10b981;"><i class="fas fa-user-check"></i></div>
                                <h6>Approved Users</h6>
                                <div class="value"><?php echo $approved_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #f59e0b;">
                                <div class="icon" style="color: #f59e0b;"><i class="fas fa-user-clock"></i></div>
                                <h6>Pending Users</h6>
                                <div class="value"><?php echo $pending_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #ef4444;">
                                <div class="icon" style="color: #ef4444;"><i class="fas fa-user-times"></i></div>
                                <h6>Denied Users</h6>
                                <div class="value"><?php echo $denied_users; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Statistics -->
                    <h5 class="section-title">💳 Transaction Statistics</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="icon"><i class="fas fa-receipt"></i></div>
                                <h6>Total Transactions</h6>
                                <div class="value"><?php echo $total_transactions; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #10b981;">
                                <div class="icon" style="color: #10b981;"><i class="fas fa-check-circle"></i></div>
                                <h6>Approved Transactions</h6>
                                <div class="value"><?php echo $approved_trans; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #f59e0b;">
                                <div class="icon" style="color: #f59e0b;"><i class="fas fa-hourglass-half"></i></div>
                                <h6>Pending Transactions</h6>
                                <div class="value"><?php echo $pending_trans; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card" style="border-left-color: #ef4444;">
                                <div class="icon" style="color: #ef4444;"><i class="fas fa-times-circle"></i></div>
                                <h6>Denied Transactions</h6>
                                <div class="value"><?php echo $denied_trans; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <h5 class="section-title">💰 Financial Summary</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="stat-card" style="border-left-color: #10b981;">
                                <div class="icon" style="color: #10b981;"><i class="fas fa-money-bill-wave"></i></div>
                                <h6>Total Approved Sales</h6>
                                <div class="value">₱ <?php echo number_format($total_sales, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
