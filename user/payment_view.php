<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - HydroMIS</title>
    <link href="../css/payment.css" rel="stylesheet">
    <link href="../css/professional-theme.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <script src="../js/ui-protection.js" defer></script>
</head>
<body>
    <div class="payment-container">
        <div class="payment-card">
            <!-- Header -->
            <div class="payment-header">
                <h2><img src="../imagess/hydromis-logo-v2.png" alt="HydroMIS Logo" style="width: 24px; height: 24px; object-fit: contain; margin-right: 8px;"> HydroMIS Payment</h2>
                <p>Secure Online Payment Portal</p>
            </div>

            <?php if ($payment_success): ?>
                <!-- Success Message -->
                <div class="success-container">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Payment Submitted Successfully!</h3>
                    <p>Your payment is being processed.</p>
                    <div class="transaction-info">
                        <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($payment_transaction_id); ?></p>
                        <p><?php echo (($transaction['payment_method'] ?? '') === 'cash') ? 'Staff will mark cash as paid after collection.' : 'Staff will verify your uploaded proof of payment.'; ?></p>
                    </div>
                    <div class="success-actions">
                        <a href="track_order.php" class="btn btn-primary"><i class="fas fa-search"></i> Track Transaction</a>
                        <a href="payment.php" class="btn btn-secondary"><i class="fas fa-plus"></i> New Payment</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Payment Form -->
                <?php if ($payment_error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $payment_error; ?>
                    </div>
                <?php endif; ?>

                <!-- User Info -->
                <div class="user-info-card">
                    <h5><i class="fas fa-user"></i> Customer Information</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Name:</label>
                            <span><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                        </div>
                        <div class="info-item">
                            <label>User ID:</label>
                            <span><?php echo htmlspecialchars($user['user_id'] ?? ''); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Contact:</label>
                            <span><?php echo htmlspecialchars($user['contact_number'] ?? ''); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Address:</label>
                            <span><?php echo htmlspecialchars($user['address'] ?? ''); ?></span>
                        </div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="paymentForm">
                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($transaction['transaction_id'] ?? ''); ?>">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($transaction['user_id'] ?? ''); ?>">
                    <!-- Amount & Description -->
                    <div class="form-section">
                        <h5><i class="fas fa-receipt"></i> Payment Details</h5>
                        
                        <div class="form-group">
                            <label>Amount (₱) <span class="required">*</span></label>
                            <input type="text" class="form-control" value="<?php echo isset($transaction['amount']) ? number_format((float) $transaction['amount'], 2) : '0.00'; ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Description <span class="required">*</span></label>
                            <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($transaction['description'] ?? 'HydroMIS order payment'); ?></textarea>
                        </div>
                    </div>

                    <!-- Payment Method Selection -->
                    <div class="form-section">
                        <h5><i class="fas fa-wallet"></i> Select Payment Method</h5>
                        
                        <div class="payment-methods">
                            <label class="payment-method-card">
                                <input type="radio" name="payment_method" value="gcash" required>
                                <div class="method-content">
                                    <div class="method-icon gcash-icon">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/72/GCash_logo.svg/512px-GCash_logo.svg.png" alt="GCash Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-mobile-alt\'></i>';">
                                    </div>
                                    <div class="method-info">
                                        <h6>GCash</h6>
                                        <p>Fast & Secure</p>
                                    </div>
                                </div>
                            </label>

                            <label class="payment-method-card">
                                <input type="radio" name="payment_method" value="maya" required>
                                <div class="method-content">
                                    <div class="method-icon maya-icon">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/Maya_%28app%29_logo.svg/512px-Maya_%28app%29_logo.svg.png" alt="Maya Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-wallet\'></i>';">
                                    </div>
                                    <div class="method-info">
                                        <h6>Maya</h6>
                                        <p>PayMaya Wallet</p>
                                    </div>
                                </div>
                            </label>

                            <label class="payment-method-card">
                                <input type="radio" name="payment_method" value="cash" required>
                                <div class="method-content">
                                    <div class="method-icon cash-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="method-info">
                                        <h6>Cash</h6>
                                        <p>Pay on Delivery</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- GCash Details (Hidden by default) -->
                    <div class="form-section payment-details" id="gcashDetails" style="display: none;">
                        <div class="payment-instructions">
                            <h6><i class="fas fa-info-circle"></i> GCash Payment Instructions</h6>
                            <ol>
                                <li>Send the exact amount to GCash number: <strong>0917-123-4567</strong></li>
                                <li>Account Name: <strong>HydroMIS Water Services</strong></li>
                                <li>Upload screenshot as proof of payment</li>
                            </ol>
                        </div>

                        <div class="form-group">
                            <label>Your GCash Number <span class="required">*</span></label>
                            <input type="text" class="form-control" name="gcash_number" placeholder="09XX-XXX-XXXX">
                        </div>

                        <div class="form-group">
                            <label>Upload Payment Proof <span class="required">*</span></label>
                            <input type="file" class="form-control-file" name="payment_proof" accept="image/*">
                            <small class="form-text text-muted">Upload screenshot of GCash transaction</small>
                        </div>
                    </div>

                    <!-- Maya Details (Hidden by default) -->
                    <div class="form-section payment-details" id="mayaDetails" style="display: none;">
                        <div class="payment-instructions">
                            <h6><i class="fas fa-info-circle"></i> Maya Payment Instructions</h6>
                            <ol>
                                <li>Send the exact amount to Maya number: <strong>0917-987-6543</strong></li>
                                <li>Account Name: <strong>HydroMIS Water Services</strong></li>
                                <li>Upload screenshot as proof of payment</li>
                            </ol>
                        </div>

                        <div class="form-group">
                            <label>Your Maya Number <span class="required">*</span></label>
                            <input type="text" class="form-control" name="maya_number" placeholder="09XX-XXX-XXXX">
                        </div>

                        <div class="form-group">
                            <label>Upload Payment Proof <span class="required">*</span></label>
                            <input type="file" class="form-control-file" name="payment_proof" accept="image/*">
                            <small class="form-text text-muted">Upload screenshot of Maya transaction</small>
                        </div>
                    </div>

                    <!-- Cash Details (Hidden by default) -->
                    <div class="form-section payment-details" id="cashDetails" style="display: none;">
                        <div class="payment-instructions cash-instructions">
                            <h6><i class="fas fa-info-circle"></i> Cash Payment</h6>
                            <p>You will pay in cash upon delivery. Staff must confirm collection before this payment is marked paid.</p>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="form-actions">
                        <button type="submit" name="submit_payment" class="btn btn-primary btn-lg btn-block">
                            <i class="fas fa-check-circle"></i> Submit Payment
                        </button>
                        <a href="../home.php" class="btn btn-secondary btn-block">
                            <i class="fas fa-arrow-left"></i> Back to Home
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Show/hide payment details based on selected method
        $(document).ready(function() {
            $('input[name="payment_method"]').change(function() {
                $('.payment-details').hide();
                
                if ($(this).val() === 'gcash') {
                    $('#gcashDetails').show();
                    $('#gcashDetails input').prop('required', true);
                    $('#mayaDetails input').prop('required', false);
                } else if ($(this).val() === 'maya') {
                    $('#mayaDetails').show();
                    $('#mayaDetails input').prop('required', true);
                    $('#gcashDetails input').prop('required', false);
                } else if ($(this).val() === 'cash') {
                    $('#cashDetails').show();
                    $('#gcashDetails input').prop('required', false);
                    $('#mayaDetails input').prop('required', false);
                }
            });

            // Add visual feedback to payment method cards
            $('.payment-method-card input').change(function() {
                $('.payment-method-card').removeClass('selected');
                $(this).closest('.payment-method-card').addClass('selected');
            });
        });
    </script>
</body>
</html>
