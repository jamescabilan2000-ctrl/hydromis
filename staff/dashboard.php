<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Handle approval/denial
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $transaction_id = sanitize($_POST['transaction_id']);
    $action = sanitize($_POST['action']);
    
    if ($action == 'approve') {
        $status = 'approved';
        $sql = "UPDATE transactions SET status = '$status', delivery_status = 'pending', approved_by = '{$_SESSION['admin_id']}' WHERE transaction_id = '$transaction_id'";
    } elseif ($action == 'deny') {
        $status = 'denied';
        $sql = "UPDATE transactions SET status = '$status', approved_by = '{$_SESSION['admin_id']}' WHERE transaction_id = '$transaction_id'";
    } elseif ($action == 'on_the_way') {
        $sql = "UPDATE transactions SET delivery_status = 'on_the_way' WHERE transaction_id = '$transaction_id'";
    } elseif ($action == 'delivered') {
        $sql = "UPDATE transactions SET delivery_status = 'delivered' WHERE transaction_id = '$transaction_id'";
    }
    
    $conn->query($sql);
    
    header('Location: dashboard.php');
    exit();
}

// Get statistics
$pending = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='pending'")->fetch_assoc()['count'];
$approved = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='approved'")->fetch_assoc()['count'];
$denied = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status='denied'")->fetch_assoc()['count'];

// Get pending transactions
$pending_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number, u.qr_code_path
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    WHERE t.status = 'pending'
    ORDER BY t.created_at ASC
");

// Get all transactions
$all_trans = $conn->query("
    SELECT t.*, u.full_name
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    ORDER BY t.created_at DESC 
    LIMIT 20
");
// Get approved transactions for delivery tracking
$approved_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    WHERE t.status = 'approved'
    ORDER BY t.created_at DESC
    LIMIT 20
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - HydroMIS</title>
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
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .btn-approve {
            background: #10b981;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-approve:hover {
            background: #059669;
        }
        .btn-deny {
            background: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-deny:hover {
            background: #dc2626;
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
        .alert-pending {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .badge-on_the_way {
            background: #93c5fd;
            color: #1e40af;
        }
        .badge-delivered {
            background: #86efac;
            color: #166534;
        }
        .btn-on-the-way {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin: 2px;
        }
        .btn-on-the-way:hover {
            background: #2563eb;
        }
        .btn-delivered {
            background: #10b981;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin: 2px;
        }
        .btn-delivered:hover {
            background: #059669;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">🌊 HydroMIS - Staff</span>
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
                        <li><a href="pending.php"><i class="fas fa-hourglass-half mr-2"></i> Pending Approvals</a></li>
                        <li><a href="history.php"><i class="fas fa-history mr-2"></i> Transaction History</a></li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="content">
                    <div class="header-section">
                        <h2>👨‍💼 Staff Dashboard</h2>
                        <p class="text-muted">Manage pending transactions and approvals</p>
                    </div>

                    <?php if ($pending > 0): ?>
                    <div class="alert-pending">
                        <strong><i class="fas fa-exclamation-triangle"></i> You have <?php echo $pending; ?> pending transaction(s) to review!</strong>
                    </div>
                    <?php endif; ?>

                    <!-- Statistics Row -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                                <h6>Pending Transactions</h6>
                                <div class="value"><?php echo $pending; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card" style="border-left-color: #10b981;">
                                <div class="icon" style="color: #10b981;"><i class="fas fa-check-circle"></i></div>
                                <h6>Approved Transactions</h6>
                                <div class="value"><?php echo $approved; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card" style="border-left-color: #ef4444;">
                                <div class="icon" style="color: #ef4444;"><i class="fas fa-times-circle"></i></div>
                                <h6>Denied Transactions</h6>
                                <div class="value"><?php echo $denied; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Transactions -->
                    <div class="table-card">
                        <h5><i class="fas fa-list mr-2"></i> Pending Approvals</h5>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pending_trans->num_rows > 0): ?>
                                    <?php while ($row = $pending_trans->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $row['transaction_id']; ?></td>
                                        <td><?php echo $row['full_name']; ?></td>
                                        <td><?php echo $row['contact_number']; ?></td>
                                        <td>₱ <?php echo number_format($row['amount'], 2); ?></td>
                                        <td><?php echo $row['description']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn-approve"><i class="fas fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                    <input type="hidden" name="action" value="deny">
                                                    <button type="submit" class="btn-deny"><i class="fas fa-times"></i> Deny</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-check-circle" style="color: #10b981; font-size: 24px; margin-bottom: 10px;"></i>
                                            <p class="mt-2">No pending transactions!</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Delivery Tracking -->
                    <div class="table-card">
                        <h5><i class="fas fa-truck mr-2"></i> Delivery Tracking</h5>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Amount</th>
                                    <th>Delivery Status</th>
                                    <th>Actions</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($approved_trans->num_rows > 0): ?>
                                    <?php while ($row = $approved_trans->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $row['transaction_id']; ?></strong></td>
                                        <td><?php echo $row['full_name']; ?></td>
                                        <td><?php echo $row['contact_number']; ?></td>
                                        <td>₱ <?php echo number_format($row['amount'], 2); ?></td>
                                        <td>
                                            <?php 
                                            $delivery_status = $row['delivery_status'];
                                            echo '<span class="badge badge-' . $delivery_status . '">';
                                            if ($delivery_status == 'pending') echo '<i class="fas fa-clock mr-1"></i>Pending';
                                            elseif ($delivery_status == 'on_the_way') echo '<i class="fas fa-truck mr-1"></i>On the Way';
                                            elseif ($delivery_status == 'delivered') echo '<i class="fas fa-check-circle mr-1"></i>Delivered';
                                            echo '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($delivery_status != 'delivered'): ?>
                                            <div class="action-buttons" style="flex-direction: column;">
                                                <?php if ($delivery_status == 'pending'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                    <input type="hidden" name="action" value="on_the_way">
                                                    <button type="submit" class="btn-on-the-way"><i class="fas fa-truck"></i> On the Way</button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($delivery_status != 'pending'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                    <input type="hidden" name="action" value="delivered">
                                                    <button type="submit" class="btn-delivered"><i class="fas fa-check-circle"></i> Delivered</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-check-circle" style="color: #10b981; font-size: 24px; margin-bottom: 10px;"></i>
                                            <p class="mt-2">All deliveries completed!</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="table-card">
                        <h5><i class="fas fa-history mr-2"></i> Recent Transactions</h5>
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
                                <?php while ($row = $all_trans->fetch_assoc()): ?>
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
