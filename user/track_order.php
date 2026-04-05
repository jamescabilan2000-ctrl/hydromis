<?php
require_once '../config/database.php';

$tracking_info = null;
$error = '';
$scanned_user_id = '';
$is_qr_scan = false;

// Handle QR Scan - automatically fetch transactions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $qr_raw_data = $_POST['qr_data'];
    
    // Try to decode JSON
    $qr_decoded = json_decode($qr_raw_data, true);
    
    if ($qr_decoded && isset($qr_decoded['user_id'])) {
        $scanned_user_id = sanitize($qr_decoded['user_id']);
        $is_qr_scan = true;
        
        // Automatically fetch transactions for scanned user
        $sql = "SELECT t.*, u.full_name, u.address, u.contact_number, u.loyalty_points, u.user_id
                FROM transactions t
                JOIN users u ON t.user_id = u.user_id
                WHERE u.user_id = '$scanned_user_id'
                ORDER BY t.created_at DESC";
        
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $tracking_info = array();
            while ($row = $result->fetch_assoc()) {
                $tracking_info[] = $row;
                if ($is_qr_scan) {
                    break;
                }
            }
        } else {
            $error = 'No transactions found for this customer.';
        }
    } else {
        $error = 'Invalid QR code format.';
    }
}

// Search for transactions by Mobile Number or User ID
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_submit'])) {
    $search_value = sanitize($_POST['search_value']);
    
    if (!empty($search_value)) {
        // Search by both mobile number and user ID for flexibility
        $sql = "SELECT t.*, u.full_name, u.address, u.contact_number, u.loyalty_points, u.user_id
                FROM transactions t
                JOIN users u ON t.user_id = u.user_id
                WHERE u.contact_number LIKE '%$search_value%' OR u.user_id LIKE '%$search_value%'
                ORDER BY t.created_at DESC";
        
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $tracking_info = array();
            while ($row = $result->fetch_assoc()) {
                $tracking_info[] = $row;
            }
        } else {
            $error = 'No transactions found for this mobile number or User ID.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Order - HydroMIS</title>
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
            padding: 20px;
        }
        .navbar {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            margin-bottom: 30px;
        }
        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
            color: white !important;
        }
        .nav-link {
            color: white !important;
            margin-left: 20px;
        }
        .search-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            margin: 0 auto 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .search-container h3 {
            color: #333;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
            color: #999;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            font-size: 14px;
        }
        .tab-btn.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        #video-tracker {
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 4/3;
            width: 100%;
            margin-bottom: 15px;
        }
        #scanner-tracker {
            width: 100%;
            height: 100%;
        }
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 260px;
            height: 260px;
            border: 3px solid #10b981;
            border-radius: 10px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.7);
        }
        .form-group label {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .form-control {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 12px 15px;
            font-size: 14px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-search {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .error-message {
            background: #fee2e2;
            color: #7f1d1d;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
        }
        .transaction-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }
        .transaction-id {
            font-weight: 700;
            color: #333;
            font-size: 16px;
        }
        .transaction-date {
            color: #666;
            font-size: 13px;
        }
        .delivery-status {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            text-align: center;
        }
        .status-pending {
            background: #fef3c7;
            border: 2px solid #fcd34d;
        }
        .status-pending .status-title {
            color: #92400e;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .status-pending .status-icon {
            font-size: 28px;
            color: #f59e0b;
        }
        .status-on_the_way {
            background: #93c5fd;
            border: 2px solid #3b82f6;
        }
        .status-on_the_way .status-title {
            color: #1e40af;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .status-on_the_way .status-icon {
            font-size: 28px;
            color: #3b82f6;
        }
        .status-delivered {
            background: #86efac;
            border: 2px solid #10b981;
        }
        .status-delivered .status-title {
            color: #166534;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .status-delivered .status-icon {
            font-size: 28px;
            color: #10b981;
        }
        .transaction-details {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            color: #666;
        }
        .transaction-details:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #333;
        }
        .detail-value {
            color: #666;
        }
        .timeline {
            position: relative;
            padding: 20px 0;
            margin: 15px 0;
        }
        .timeline-item {
            display: flex;
            margin-bottom: 20px;
            position: relative;
        }
        .timeline-marker {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .timeline-marker.completed {
            background: #10b981;
        }
        .timeline-marker.active {
            background: #3b82f6;
        }
        .timeline-marker.pending {
            background: #d1d5db;
        }
        .timeline-content {
            flex: 1;
            padding-top: 5px;
        }
        .timeline-content h6 {
            margin: 0;
            color: #333;
            font-weight: 600;
        }
        .timeline-content p {
            margin: 5px 0 0;
            color: #666;
            font-size: 13px;
        }
        .no-transactions {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 15px;
            max-width: 600px;
            margin: 0 auto;
            margin-top: 20px;
        }
        .no-transactions i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <span class="navbar-brand">🌊 HydroMIS</span>
            <div class="ml-auto">
                <a href="../home.php" class="nav-link"><i class="fas fa-home mr-1"></i> Home</a>
                <a href="scan_qr.php" class="nav-link"><i class="fas fa-qrcode mr-1"></i> Scanner</a>
                <a href="../create_account.php" class="nav-link"><i class="fas fa-user-plus mr-1"></i> Create Account</a>
            </div>
        </div>
    </nav>

    <div class="search-container">
        <h3><i class="fas fa-search mr-2"></i> Track Your Order</h3>
        
        <div class="tab-buttons">
            <button type="button" class="tab-btn active" onclick="switchTab('scan')">
                <i class="fas fa-qrcode mr-2"></i> Scan QR Code
            </button>
            <button type="button" class="tab-btn" onclick="switchTab('search')">
                <i class="fas fa-phone mr-2"></i> Search by Mobile
            </button>
        </div>

        <!-- Scan QR Tab -->
        <div id="scan-tab" class="tab-content active">
            <p class="text-muted mb-3">Point your camera at the QR code to scan it</p>
            <div id="video-tracker">
                <video id="scanner-tracker"></video>
                <div class="scanner-frame"></div>
            </div>
            <form method="POST" id="qr-form-tracker" style="display: none;">
                <input type="hidden" name="qr_data" id="qr-data-input">
            </form>
            <div style="text-align: center; padding: 15px; color: #667eea;">
                <i class="fas fa-spinner fa-spin" id="loader-icon" style="font-size: 24px;"></i>
                <p id="scanning-text" style="margin-top: 10px; font-size: 14px;">Point camera at QR code...</p>
            </div>
        </div>

        <!-- Search by Mobile Tab -->
        <div id="search-tab" class="tab-content">
            <p class="text-muted mb-3">Enter your mobile number to find your orders</p>
            <form method="POST">
                <div class="form-group">
                    <label for="search_value"><i class="fas fa-phone mr-2"></i>Mobile Number</label>
                    <input type="text" class="form-control" name="search_value" id="search_value" placeholder="e.g. 09171234567">
                    <small class="form-text text-muted">Enter the mobile number used when creating your account</small>
                </div>
                <button type="submit" name="search_submit" value="1" class="btn-search">
                    <i class="fas fa-search mr-2"></i> Search Orders
                </button>
            </form>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($tracking_info): ?>
        <?php foreach ($tracking_info as $transaction): ?>
        <div class="transaction-card">
            <?php if ($is_qr_scan): ?>
            <div class="transaction-header">
                <div>
                    <div class="transaction-date"><?php echo date('M d, Y h:i A', strtotime($transaction['created_at'])); ?></div>
                </div>
                <div>
                    <span class="badge" style="padding: 8px 12px; font-size: 12px; 
                        <?php 
                        if ($transaction['status'] == 'pending') echo 'background: #fef3c7; color: #92400e;';
                        elseif ($transaction['status'] == 'approved') echo 'background: #d1fae5; color: #065f46;';
                        else echo 'background: #fee2e2; color: #7f1d1d;';
                        ?>">
                        <?php echo ucfirst($transaction['status']); ?>
                    </span>
                </div>
            </div>

            <?php if ($transaction['status'] == 'approved'): ?>
            <div class="delivery-status status-<?php echo $transaction['delivery_status']; ?>">
                <div class="status-icon">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo '<i class="fas fa-clock"></i>';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo '<i class="fas fa-truck"></i>';
                    else echo '<i class="fas fa-check-circle"></i>';
                    ?>
                </div>
                <div class="status-title">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo 'Order Preparation';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo 'On the Way';
                    else echo 'Delivered';
                    ?>
                </div>
                <div style="font-size: 12px; margin-top: 5px;">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo 'Your order is being prepared for delivery';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo 'Your order is currently on the way to you';
                    else echo 'Your order has been successfully delivered';
                    ?>
                </div>
            </div>
            <?php elseif ($transaction['status'] == 'pending'): ?>
            <div style="padding: 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #fcd34d; margin-top: 15px;">
                <div style="color: #92400e; font-weight: 600; margin-bottom: 5px;">
                    <i class="fas fa-hourglass-half mr-2"></i>Awaiting Approval
                </div>
                <div style="color: #92400e; font-size: 13px;">
                    Your order is waiting for approval. You will be notified once it's approved and on the way.
                </div>
            </div>
            <?php else: ?>
            <div style="padding: 15px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #ef4444; margin-top: 15px;">
                <div style="color: #7f1d1d; font-weight: 600; margin-bottom: 5px;">
                    <i class="fas fa-times-circle mr-2"></i>Order Denied
                </div>
                <div style="color: #7f1d1d; font-size: 13px;">
                    Unfortunately, this order was denied. Please contact support for more information.
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="transaction-header">
                <div>
                    <div class="transaction-id">📦 <?php echo $transaction['transaction_id']; ?></div>
                    <div class="transaction-date"><?php echo date('M d, Y h:i A', strtotime($transaction['created_at'])); ?></div>
                </div>
                <div>
                    <span class="badge" style="padding: 8px 12px; font-size: 12px; 
                        <?php 
                        if ($transaction['status'] == 'pending') echo 'background: #fef3c7; color: #92400e;';
                        elseif ($transaction['status'] == 'approved') echo 'background: #d1fae5; color: #065f46;';
                        else echo 'background: #fee2e2; color: #7f1d1d;';
                        ?>">
                        <?php echo ucfirst($transaction['status']); ?>
                    </span>
                </div>
            </div>

            <div class="transaction-details">
                <span class="detail-label">Product:</span>
                <span class="detail-value">
                    <?php echo $transaction['water_type'] === 'nowater' ? 'No-Water' : 'Regular Water'; ?> 
                    (<?php echo $transaction['quantity']; ?> <?php echo $transaction['water_type'] === 'nowater' ? 'units' : 'gallons'; ?>)
                </span>
            </div>
            <div class="transaction-details">
                <span class="detail-label">Price Per Unit:</span>
                <span class="detail-value">₱<?php echo number_format($transaction['price_per_unit'], 2); ?></span>
            </div>
            <div class="transaction-details">
                <span class="detail-label">Subtotal:</span>
                <span class="detail-value">₱<?php echo number_format($transaction['quantity'] * $transaction['price_per_unit'], 2); ?></span>
            </div>
            <?php if ($transaction['discount'] > 0): ?>
            <div class="transaction-details">
                <span class="detail-label"><i class="fas fa-tag mr-1" style="color: #10b981;"></i>Discount:</span>
                <span class="detail-value">-₱<?php echo number_format($transaction['discount'], 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="transaction-details">
                <span class="detail-label"><strong>Total Amount:</strong></span>
                <span class="detail-value"><strong>₱<?php echo number_format($transaction['amount'], 2); ?></strong></span>
            </div>
            <?php if ($transaction['loyalty_points_earned'] > 0): ?>
            <div class="transaction-details">
                <span class="detail-label"><i class="fas fa-star mr-1" style="color: #f59e0b;"></i>Loyalty Points:</span>
                <span class="detail-value">+<?php echo $transaction['loyalty_points_earned']; ?> points</span>
            </div>
            <?php endif; ?>

            <?php if ($transaction['status'] == 'approved'): ?>
            <div class="delivery-status status-<?php echo $transaction['delivery_status']; ?>">
                <div class="status-icon">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo '<i class="fas fa-clock"></i>';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo '<i class="fas fa-truck"></i>';
                    else echo '<i class="fas fa-check-circle"></i>';
                    ?>
                </div>
                <div class="status-title">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo 'Order Preparation';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo 'On the Way';
                    else echo 'Delivered';
                    ?>
                </div>
                <div style="font-size: 12px; margin-top: 5px;">
                    <?php 
                    if ($transaction['delivery_status'] == 'pending') echo 'Your order is being prepared for delivery';
                    elseif ($transaction['delivery_status'] == 'on_the_way') echo 'Your order is currently on the way to you';
                    else echo 'Your order has been successfully delivered';
                    ?>
                </div>
            </div>

            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-marker completed"><i class="fas fa-check"></i></div>
                    <div class="timeline-content">
                        <h6>Order Confirmed</h6>
                        <p><?php echo date('M d, Y h:i A', strtotime($transaction['created_at'])); ?></p>
                    </div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-marker <?php echo ($transaction['delivery_status'] == 'pending' ? 'active' : 'completed'); ?>">
                        <i class="fas fa-<?php echo ($transaction['delivery_status'] == 'pending' ? 'circle' : 'check'); ?>"></i>
                    </div>
                    <div class="timeline-content">
                        <h6>Order Prepared</h6>
                        <p><?php echo $transaction['delivery_status'] == 'pending' ? 'Preparation in progress...' : 'Order ready for delivery'; ?></p>
                    </div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-marker <?php echo ($transaction['delivery_status'] != 'pending' ? 'active' : 'pending'); ?>">
                        <i class="fas fa-<?php echo ($transaction['delivery_status'] != 'pending' ? 'circle' : 'circle'); ?>" style="color: inherit;"></i>
                    </div>
                    <div class="timeline-content">
                        <h6>On the Way</h6>
                        <p><?php echo $transaction['delivery_status'] != 'pending' ? 'Your order is being delivered' : 'Waiting for dispatch'; ?></p>
                    </div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-marker <?php echo ($transaction['delivery_status'] == 'delivered' ? 'completed' : 'pending'); ?>">
                        <i class="fas fa-<?php echo ($transaction['delivery_status'] == 'delivered' ? 'check' : 'circle'); ?>"></i>
                    </div>
                    <div class="timeline-content">
                        <h6>Delivered</h6>
                        <p><?php echo $transaction['delivery_status'] == 'delivered' ? 'Order delivered successfully' : 'Awaiting delivery'; ?></p>
                    </div>
                </div>
            </div>
            <?php elseif ($transaction['status'] == 'pending'): ?>
            <div style="padding: 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #fcd34d; margin-top: 15px;">
                <div style="color: #92400e; font-weight: 600; margin-bottom: 5px;">
                    <i class="fas fa-hourglass-half mr-2"></i>Awaiting Approval
                </div>
                <div style="color: #92400e; font-size: 13px;">
                    Your order is waiting for approval. You will be notified once it's approved and on the way.
                </div>
            </div>
            <?php elseif ($transaction['status'] == 'denied'): ?>
            <div style="padding: 15px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #ef4444; margin-top: 15px;">
                <div style="color: #7f1d1d; font-weight: 600; margin-bottom: 5px;">
                    <i class="fas fa-times-circle mr-2"></i>Order Denied
                </div>
                <div style="color: #7f1d1d; font-size: 13px;">
                    Unfortunately, this order was denied. Please contact support for more information.
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php elseif (!$error && $_SERVER['REQUEST_METHOD'] != 'POST'): ?>
        <div class="no-transactions">
            <i class="fas fa-box"></i>
            <p style="color: #999;">Enter your User ID to view your orders</p>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
        // Tab Switching
        function switchTab(tab) {
            // Hide all tabs
            document.getElementById('scan-tab').classList.remove('active');
            document.getElementById('search-tab').classList.remove('active');
            
            // Remove active from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            if (tab === 'scan') {
                document.getElementById('scan-tab').classList.add('active');
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                startScanner();
            } else {
                document.getElementById('search-tab').classList.add('active');
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
                stopScanner();
            }
        }

        // QR Scanner Variables
        let video = null;
        let canvasElement = null;
        let canvas = null;
        let scannerActive = false;

        // Start QR Scanner
        function startScanner() {
            if (scannerActive) return;
            
            video = document.getElementById('scanner-tracker');
            canvasElement = document.createElement('canvas');
            canvas = canvasElement.getContext('2d', { willReadFrequently: true });
            
            navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' }
            }).then(stream => {
                video.srcObject = stream;
                video.play();
                scannerActive = true;
                scanQRCode();
            }).catch(err => {
                alert('Unable to access camera: ' + err.message);
            });
        }

        // Stop QR Scanner
        function stopScanner() {
            if (video && video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
                scannerActive = false;
            }
        }

        // Scan QR Code
        function scanQRCode() {
            if (!scannerActive) return;
            
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvasElement.hidden = true;
                canvasElement.height = video.videoHeight;
                canvasElement.width = video.videoWidth;
                canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
                
                const imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: 'dontInvert',
                });
                
                if (code) {
                    // QR code detected, show loading state and automatically submit
                    document.getElementById('scanning-text').textContent = '✓ QR Code Detected! Loading your orders...';
                    document.getElementById('scanning-text').style.color = '#10b981';
                    document.getElementById('loader-icon').style.color = '#10b981';
                    
                    document.getElementById('qr-data-input').value = code.data;
                    stopScanner();
                    
                    // Small delay to show the success message before submitting
                    setTimeout(() => {
                        document.getElementById('qr-form-tracker').submit();
                    }, 500);
                    return;
                }
            }
            
            requestAnimationFrame(scanQRCode);
        }

        // Start scanner on page load if first tab is active
        window.addEventListener('DOMContentLoaded', function() {
            startScanner();
        });

        // Stop scanner when page is closed
        window.addEventListener('beforeunload', function() {
            stopScanner();
        });
    </script>
</body>
</html>
