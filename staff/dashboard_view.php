<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - HydroMIS</title>
            <link href="../css/staff_dashboard.css" rel="stylesheet">
    <link href="../css/professional-theme.css" rel="stylesheet">
</head>
<body>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2" style="padding: 0;">
                <div class="sidebar">
                    <ul class="sidebar-menu">
                        <li><a href="dashboard.php" class="active"><i class="fas fa-dashboard mr-2"></i> Dashboard</a></li>
                        <li><a href="pending.php"><i class="fas fa-hourglass-half mr-2"></i> Pending Approvals</a></li>
                        <li><a href="payments.php"><i class="fas fa-money-bill-wave mr-2"></i> Payments</a></li>
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
                            <div class="stat-card" style="border-left-color: #1f9b5d;">
                                <div class="icon" style="color: #1f9b5d;"><i class="fas fa-check-circle"></i></div>
                                <h6>Approved Transactions</h6>
                                <div class="value"><?php echo $approved; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card" style="border-left-color: #d92f42;">
                                <div class="icon" style="color: #d92f42;"><i class="fas fa-times-circle"></i></div>
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
                                            <i class="fas fa-check-circle" style="color: #1f9b5d; font-size: 24px; margin-bottom: 10px;"></i>
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
                                    <th>Assigned Rider</th>
                                    <th>Assign Rider</th>
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
                                            <?php if (!empty($row['rider_id'])): ?>
                                                <span class="badge badge-approved"><?php echo htmlspecialchars($row['rider_name'] ?: $row['rider_id']); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($rider_list)): ?>
                                            <form method="POST" style="display: flex; gap: 6px; align-items: center; min-width: 260px;">
                                                <input type="hidden" name="transaction_id" value="<?php echo $row['transaction_id']; ?>">
                                                <input type="hidden" name="action" value="assign_rider">
                                                <select name="rider_id" class="form-control" style="min-height: 36px; padding: 6px 10px; font-size: 12px;">
                                                    <?php foreach ($rider_list as $rider): ?>
                                                        <option value="<?php echo htmlspecialchars($rider['rider_id']); ?>" <?php echo ($row['rider_id'] === $rider['rider_id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($rider['full_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn-view" style="padding: 7px 10px; font-size: 12px; border: none;">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                                <small class="text-muted">No active riders</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="fas fa-check-circle" style="color: #1f9b5d; font-size: 24px; margin-bottom: 10px;"></i>
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
