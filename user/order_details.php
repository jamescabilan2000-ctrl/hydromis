<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
</head>
<body>
    <main class="page-shell">
        <a href="track_order.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Orders
        </a>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif ($transaction): ?>
            <div class="page-header">
                <h1><i class="fas fa-receipt mr-2"></i>Order Details</h1>
                <p>Transaction ID: <strong><?php echo htmlspecialchars($transaction['transaction_id']); ?></strong></p>
            </div>

            <!-- Order Status & Summary -->
            <div class="order-card">
                <div class="card-section-title">
                    <i class="fas fa-info-circle"></i> Order Summary
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Order ID</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['transaction_id']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="status-badge <?php echo strtolower($transaction['status']); ?>">
                            <?php echo ucfirst($transaction['status']); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Order Date</span>
                        <span class="info-value"><?php echo date('M d, Y H:i A', strtotime($transaction['created_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Water Type</span>
                        <span class="info-value"><?php echo ucfirst($transaction['water_type']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Container</span>
                        <span class="info-value"><?php echo ($transaction['container_status'] ?? '') === 'new' ? 'New container' : 'Customer-owned container'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fulfillment</span>
                        <span class="info-value"><?php echo ($transaction['fulfillment_method'] ?? '') === 'pickup' ? 'Self pickup' : 'Delivery'; ?></span>
                    </div>
                </div>

                <div class="product-breakdown">
                    <div class="breakdown-row">
                        <span class="breakdown-label">Quantity</span>
                        <span class="breakdown-value"><?php 
                            $size_display = $transaction['water_type'] === 'regular' ? 'Gallons' : 'Units';
                            if (preg_match('/×\s*(.*?)\s*\((?:New|Pickup & Deliver)\)/', $transaction['description'], $m)) {
                                $size_display = trim($m[1]);
                            }
                            echo $transaction['quantity'] . ' × ' . htmlspecialchars($size_display); 
                        ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span class="breakdown-label">Price per Unit</span>
                        <span class="breakdown-value">₱<?php echo number_format($transaction['price_per_unit'], 2); ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span class="breakdown-label">Subtotal</span>
                        <span class="breakdown-value">₱<?php echo number_format($transaction['quantity'] * $transaction['price_per_unit'], 2); ?></span>
                    </div>
                    <?php if ($transaction['discount'] > 0): ?>
                    <div class="breakdown-row">
                        <span class="breakdown-label">Discount</span>
                        <span class="breakdown-value">-₱<?php echo number_format($transaction['discount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="breakdown-row">
                        <span class="breakdown-label"><?php echo ($transaction['fulfillment_method'] ?? '') === 'pickup' ? 'Pickup Fee' : 'Delivery Fee'; ?></span>
                        <span class="breakdown-value"><?php echo ($transaction['fulfillment_method'] ?? '') === 'pickup' ? 'Free' : '₱' . number_format(10 * (int)$transaction['quantity'], 2); ?></span>
                    </div>
                    <div class="breakdown-row total">
                        <span class="breakdown-label">Total Amount</span>
                        <span class="breakdown-value">₱<?php echo number_format($transaction['amount'], 2); ?></span>
                    </div>
                </div>

                <?php if ($transaction['loyalty_points_earned'] > 0): ?>
                <div>
                    <i class="fas fa-star"></i>
                    Earned <?php echo $transaction['loyalty_points_earned']; ?> loyalty points
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment Details -->
            <div class="order-card">
                <div class="card-section-title">
                    <i class="fas fa-credit-card"></i> Payment Information
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Payment Method</span>
                        <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $transaction['payment_method'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Payment Status</span>
                        <span class="status-badge <?php echo strtolower($transaction['payment_status']); ?>">
                            <?php echo ucfirst($transaction['payment_status']); ?>
                        </span>
                    </div>
                    <?php if (!empty($transaction['payment_reference'])): ?>
                    <div class="info-item">
                        <span class="info-label">Reference No.</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['payment_reference']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="order-card">
                <div class="card-section-title">
                    <i class="fas fa-user"></i> Customer Information
                </div>

                <div class="customer-info">
                    <div class="customer-avatar">
                        <?php 
                            $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', trim($transaction['full_name'])), 0, 2))));
                            echo $initials;
                        ?>
                    </div>
                    <div class="customer-details">
                        <div class="customer-name"><?php echo htmlspecialchars($transaction['full_name']); ?></div>
                        <div class="customer-meta">
                            <span><strong>User ID:</strong> <?php echo htmlspecialchars($transaction['user_id']); ?></span>
                            <span><strong>Phone:</strong> <?php echo htmlspecialchars($transaction['contact_number']); ?></span>
                            <span><strong>Address:</strong> <?php echo htmlspecialchars($transaction['address']); ?></span>
                            <span><strong>Loyalty Points:</strong> <?php echo $transaction['loyalty_points']; ?> pts</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="btn-action secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <button class="btn-action primary" onclick="window.location.href='track_order.php'">
                    <i class="fas fa-list"></i> View All Orders
                </button>
            </div>

        <?php endif; ?>
    </main>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
