<?php
/**
 * System Verification Page
 * Check if all requirements are met for HydroMIS
 */

$checks = array();

// Check PHP Version
$checks['PHP Version'] = array(
    'status' => version_compare(PHP_VERSION, '7.4', '>='),
    'required' => '7.4+',
    'actual' => phpversion(),
    'icon' => version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : '❌'
);

// Check MySQL Extension
$checks['MySQL Extension'] = array(
    'status' => extension_loaded('mysqli'),
    'required' => 'MySQLi',
    'actual' => extension_loaded('mysqli') ? 'Installed' : 'Not Found',
    'icon' => extension_loaded('mysqli') ? '✅' : '❌'
);

// Check GD Library (for images)
$checks['GD Library'] = array(
    'status' => extension_loaded('gd'),
    'required' => 'GD Image Library',
    'actual' => extension_loaded('gd') ? 'Installed' : 'Not Found',
    'icon' => extension_loaded('gd') ? '✅' : '❌'
);

// Check File Uploads
$checks['File Uploads'] = array(
    'status' => ini_get('file_uploads') == 1,
    'required' => 'Enabled',
    'actual' => ini_get('file_uploads') ? 'Enabled' : 'Disabled',
    'icon' => ini_get('file_uploads') ? '✅' : '❌'
);

// Check qrcodes folder
$qrcodes_writable = is_dir('qrcodes') && is_writable('qrcodes');
$checks['QR Codes Folder'] = array(
    'status' => $qrcodes_writable,
    'required' => 'Writable Directory',
    'actual' => $qrcodes_writable ? 'Writable' : 'Not Writable',
    'icon' => $qrcodes_writable ? '✅' : '❌'
);

// Check Database Connection
$db_connection = false;
$db_message = 'Not Connected';
try {
    $conn = @new mysqli('127.0.0.1', 'root', '', 'hydromis');
    if (!$conn->connect_error) {
        $db_connection = true;
        $db_message = 'Connected Successfully';
    } else {
        $db_message = 'Connection Error: ' . $conn->connect_error;
    }
} catch (Exception $e) {
    $db_message = 'MySQL not running or Database not found';
}

$checks['MySQL Database'] = array(
    'status' => $db_connection,
    'required' => 'hydromis Database',
    'actual' => $db_message,
    'icon' => $db_connection ? '✅' : '❌'
);

// Check required files exist
$required_files = array(
    'config/database.php',
    'database/schema.sql',
    'admin/dashboard.php',
    'staff/dashboard.php',
    'user/create_account.php',
    'user/scan_qr.php'
);

$files_check = true;
$missing_files = array();
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        $files_check = false;
        $missing_files[] = $file;
    }
}

$checks['Required Files'] = array(
    'status' => $files_check,
    'required' => '6 Core Files',
    'actual' => $files_check ? 'All Present' : count($missing_files) . ' Missing',
    'icon' => $files_check ? '✅' : '❌'
);

// Overall status
$all_good = true;
foreach ($checks as $check) {
    if (!$check['status']) {
        $all_good = false;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Verification - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .container-verify {
            max-width: 700px;
            margin: 20px auto;
        }
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        .header h1 {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        .verify-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 30px;
            margin-bottom: 20px;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .check-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .check-item.pass {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
        }
        .check-item.fail {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
        }
        .check-icon {
            font-size: 24px;
            margin-right: 15px;
            width: 30px;
            text-align: center;
        }
        .check-info {
            flex: 1;
        }
        .check-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .check-details {
            font-size: 12px;
            color: #666;
        }
        .check-details span {
            display: inline-block;
            margin-right: 20px;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pass {
            background: #d1fae5;
            color: #065f46;
        }
        .status-fail {
            background: #fee2e2;
            color: #7f1d1d;
        }
        .success-panel {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success-panel h5 {
            color: #10b981;
            margin-bottom: 10px;
        }
        .success-panel p {
            color: #065f46;
            margin: 0;
        }
        .error-panel {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .error-panel h5 {
            color: #ef4444;
            margin-bottom: 10px;
        }
        .error-panel p {
            color: #7f1d1d;
            margin: 5px 0;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .action-buttons button, .action-buttons a {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }
        .btn-next {
            background: #667eea;
            color: white;
        }
        .btn-next:hover {
            background: #5568d3;
            color: white;
        }
        .btn-help {
            background: #e5e7eb;
            color: #333;
        }
        .btn-help:hover {
            background: #d1d5db;
            color: #333;
        }
        .verification-complete {
            text-align: center;
            padding: 40px 20px;
        }
        .complete-icon {
            font-size: 80px;
            color: #10b981;
            margin-bottom: 20px;
        }
        .complete-text {
            color: #333;
            font-size: 18px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="container-verify">
        <div class="header">
            <h1>🌊 HydroMIS</h1>
            <p>System Verification</p>
        </div>

        <div class="verify-card">
            <?php if ($all_good): ?>
            <!-- Success State -->
            <div class="success-panel">
                <h5><i class="fas fa-check-circle mr-2"></i> All Systems Ready!</h5>
                <p>Your HydroMIS system is properly configured and ready to use.</p>
            </div>

            <div class="verification-complete">
                <div class="complete-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <p class="complete-text">Everything is configured correctly!</p>
                <div class="action-buttons">
                    <a href="index.php" class="btn-next">
                        <i class="fas fa-arrow-right mr-2"></i> Go to Login
                    </a>
                    <a href="QUICKSTART.md" class="btn-help" download>
                        <i class="fas fa-download mr-2"></i> Quick Start
                    </a>
                </div>
            </div>

            <?php else: ?>
            <!-- Error State -->
            <div class="error-panel">
                <h5><i class="fas fa-exclamation-circle mr-2"></i> Configuration Issues Found</h5>
                <p>Please fix the issues below before using HydroMIS:</p>
            </div>
            <?php endif; ?>

            <h6 style="margin: 30px 0 20px 0; color: #333;">System Requirements Check:</h6>

            <!-- Checks -->
            <?php foreach ($checks as $name => $check): ?>
            <div class="check-item <?php echo $check['status'] ? 'pass' : 'fail'; ?>">
                <div class="check-icon"><?php echo $check['icon']; ?></div>
                <div class="check-info">
                    <div class="check-name"><?php echo $name; ?></div>
                    <div class="check-details">
                        <span><strong>Required:</strong> <?php echo $check['required']; ?></span>
                        <span><strong>Actual:</strong> <?php echo $check['actual']; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Help Section -->
            <div style="margin-top: 30px; padding: 20px; background: #f0f4ff; border-radius: 8px;">
                <h6 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-lightbulb mr-2"></i> Need Help?
                </h6>
                <ul style="margin: 0; color: #666; font-size: 14px;">
                    <li style="margin-bottom: 8px;">Make sure <strong>Apache</strong> and <strong>MySQL</strong> are running in XAMPP</li>
                    <li style="margin-bottom: 8px;">Verify that database <strong>hydromis</strong> was created and schema.sql was imported</li>
                    <li style="margin-bottom: 8px;">Ensure the <strong>qrcodes</strong> folder exists and is writable</li>
                    <li style="margin-bottom: 8px;">Check that the project is placed in <strong>C:\xampp\htdocs\HydroMIS-1</strong></li>
                    <li style="margin-bottom: 8px;">Refresh this page after making changes</li>
                </ul>
            </div>
        </div>

        <!-- Refresh Button -->
        <div style="text-align: center; margin-top: 20px;">
            <button onclick="location.reload();" style="
                background: white;
                color: #667eea;
                border: 2px solid white;
                padding: 10px 30px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
            ">
                <i class="fas fa-sync-alt mr-2"></i> Refresh Verification
            </button>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
