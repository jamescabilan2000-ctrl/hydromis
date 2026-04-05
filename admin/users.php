<?php
require_once 'check_auth.php';
require_once '../config/database.php';

// Handle approve/deny user accounts
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $user_id = sanitize($_POST['user_id']);
    $action = sanitize($_POST['action']);
    $status = ($action == 'approve') ? 'approved' : 'denied';
    
    $sql = "UPDATE users SET status = '$status' WHERE user_id = '$user_id'";
    if ($conn->query($sql) === TRUE) {
        $success = 'User status updated successfully!';
    }
}

// Get all users
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - HydroMIS</title>
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
        .table-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
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
        .btn-view {
            background: #667eea;
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
            font-size: 14px;
        }
        .logout-btn:hover {
            background: #dc2626;
        }
        .success-message {
            background: #d1fae5;
            border: 1px solid #6ee7b7;
            color: #065f46;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
                        <li><a href="users.php" class="active"><i class="fas fa-users mr-2"></i> Manage Users</a></li>
                        <li><a href="transactions.php"><i class="fas fa-exchange mr-2"></i> View Transactions</a></li>
                        <li><a href="reports.php"><i class="fas fa-chart-bar mr-2"></i> Reports</a></li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="content">
                    <div class="header-section">
                        <h2>👥 Manage Users</h2>
                        <p class="text-muted">Review and approve user accounts</p>
                    </div>

                    <?php if (isset($success)): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
                    </div>
                    <?php endif; ?>

                    <div class="table-card">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Full Name</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Date Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row['user_id']; ?></td>
                                    <td><?php echo $row['full_name']; ?></td>
                                    <td><?php echo $row['contact_number']; ?></td>
                                    <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($row['status'] == 'pending'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn-approve"><i class="fas fa-check"></i></button>
                                                </form>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                                    <input type="hidden" name="action" value="deny">
                                                    <button type="submit" class="btn-deny"><i class="fas fa-times"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <button class="btn-view" data-toggle="modal" data-target="#userModal<?php echo $row['id']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- User Details Modal -->
                                <div class="modal fade" id="userModal<?php echo $row['id']; ?>" tabindex="-1" role="dialog">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">User Details - <?php echo $row['full_name']; ?></h5>
                                                <button type="button" class="close" data-dismiss="modal">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <p><strong>User ID:</strong> <?php echo $row['user_id']; ?></p>
                                                <p><strong>Full Name:</strong> <?php echo $row['full_name']; ?></p>
                                                <p><strong>Address:</strong> <?php echo $row['address']; ?></p>
                                                <p><strong>Contact:</strong> <?php echo $row['contact_number']; ?></p>
                                                <p><strong>Status:</strong> <span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></p>
                                                <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></p>
                                                <?php if ($row['qr_code_path']): ?>
                                                <p><strong>QR Code:</strong></p>
                                                <img src="<?php echo $row['qr_code_path']; ?>" style="max-width: 200px; border-radius: 5px;">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
