<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Get pending transactions
$pending_trans = $conn->query("
    SELECT t.*, u.full_name, u.contact_number, u.qr_code_path
    FROM transactions t 
    JOIN users u ON t.user_id = u.user_id 
    WHERE t.status = 'pending'
    ORDER BY t.created_at ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
            margin: 0;
        }
        .sidebar-menu li {
            margin: 8px 0;
        }
        .sidebar-menu a {
            color: #333;
            text-decoration: none;
            display: block;
            padding: 12px 15px;
            border-radius: 6px;
            transition: all 0.3s;
            font-weight: 500;
        }
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #667eea;
            color: white;
        }
        .table-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
        .btn-deny {
            background: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .logout-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
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
            <div class="col-md-2" style="padding: 0;">
                <div class="sidebar">
                    <ul class="sidebar-menu">
                        <li><a href="dashboard.php"><i class="fas fa-dashboard mr-2"></i> Dashboard</a></li>
                        <li><a href="pending.php" class="active"><i class="fas fa-hourglass-half mr-2"></i> Pending Approvals</a></li>
                        <li><a href="history.php"><i class="fas fa-history mr-2"></i> Transaction History</a></li>
                    </ul>
                </div>
            </div>

            <div class="col-md-10" style="padding: 30px;">
                <h2>⏳ Pending Approvals</h2>
                <p class="text-muted">Review and approve pending transactions</p>

                <div class="table-card">
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
                                            <form method="POST" action="dashboard.php" style="display: inline;">
                                                <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn-approve"><i class="fas fa-check"></i> Approve</button>
                                            </form>
                                            <form method="POST" action="dashboard.php" style="display: inline;">
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
                                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 24px;"></i>
                                        <p class="mt-2">No pending transactions!</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
