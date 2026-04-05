<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Get statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_transactions = $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'];
$total_sales = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE status='approved'")->fetch_assoc()['total'] ?? 0;

$pending = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending'")->fetch_assoc()['count'];
$approved = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved'")->fetch_assoc()['count'];
$denied = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied'")->fetch_assoc()['count'];

$pending_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'];
$approved_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='approved'")->fetch_assoc()['count'];
$denied_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status='denied'")->fetch_assoc()['count'];

// Get recent transactions
$recent_sales = $conn->query("
    SELECT t.*, u.full_name 
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    ORDER BY t.created_at DESC 
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - HydroMIS</title>
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
        .nav-item {
            margin-left: 20px;
        }
        .nav-link {
            color: white !important;
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
        .stat-card.sales {
            border-left-color: #10b981;
        }
        .stat-card.pending {
            border-left-color: #f59e0b;
        }
        .stat-card.approved {
            border-left-color: #10b981;
        }
        .stat-card.denied {
            border-left-color: #ef4444;
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
        .stat-card.sales .icon {
            color: #10b981;
        }
        .stat-card.pending .icon {
            color: #f59e0b;
        }
        .stat-card.approved .icon {
            color: #10b981;
        }
        .stat-card.denied .icon {
            color: #ef4444;
        }
        .table-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .table-card h5 {
            margin-bottom: 20px;
            color: #333;
        }
        .table {
            margin-bottom: 0;
        }
        .badge {
            padding: 5px 10px;
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
        .content {
            padding: 30px;
        }
        .header-section {
            margin-bottom: 30px;
        }
        .header-section h2 {
            color: #333;
            font-weight: bold;
            margin-bottom: 10px;
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
                        <li><a href="dashboard.php" class="active"><i class="fas fa-dashboard mr-2"></i> Dashboard</a></li>
                        <li><a href="users.php"><i class="fas fa-users mr-2"></i> Manage Users</a></li>
                        <li><a href="transactions.php"><i class="fas fa-exchange mr-2"></i> View Transactions</a></li>
                        <li><a href="reports.php"><i class="fas fa-chart-bar mr-2"></i> Reports</a></li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="content">
                    <div class="header-section">
                        <h2>📊 Dashboard Overview</h2>
                        <p class="text-muted">Monitor your system performance in real-time</p>
                    </div>

                    <!-- Statistics Row 1 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card sales">
                                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                                <h6>Total Sales</h6>
                                <div class="value">₱ <?php echo number_format($total_sales, 2); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="icon"><i class="fas fa-users"></i></div>
                                <h6>Total Users</h6>
                                <div class="value"><?php echo $total_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card pending">
                                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                                <h6>Pending Transactions</h6>
                                <div class="value"><?php echo $pending; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card approved">
                                <div class="icon"><i class="fas fa-check-circle"></i></div>
                                <h6>Approved Transactions</h6>
                                <div class="value"><?php echo $approved; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Row 2 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card denied">
                                <div class="icon"><i class="fas fa-times-circle"></i></div>
                                <h6>Denied Transactions</h6>
                                <div class="value"><?php echo $denied; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card approved">
                                <div class="icon"><i class="fas fa-user-check"></i></div>
                                <h6>Approved Users</h6>
                                <div class="value"><?php echo $approved_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card pending">
                                <div class="icon"><i class="fas fa-user-clock"></i></div>
                                <h6>Pending Users</h6>
                                <div class="value"><?php echo $pending_users; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card denied">
                                <div class="icon"><i class="fas fa-user-times"></i></div>
                                <h6>Denied Users</h6>
                                <div class="value"><?php echo $denied_users; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions Table -->
                    <div class="table-card">
                        <h5><i class="fas fa-list mr-2"></i> Recent Transactions</h5>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Customer Name</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $recent_sales->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row['transaction_id']; ?></td>
                                    <td><?php echo $row['full_name']; ?></td>
                                    <td>₱ <?php echo number_format($row['amount'], 2); ?></td>
                                    <td><?php echo $row['description']; ?></td>
                                    <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
