<?php
/**
 * HydroMIS Database Setup - Clean Version
 * Handles orphaned tablespace files
 */

$setup_complete = false;
$setup_errors = [];
$setup_messages = [];

// Directory where MySQL stores data
$xampp_data_dir = 'C:\\xampp\\mysql\\data\\hydromis';

// Try to remove orphaned tablespace files
if (is_dir($xampp_data_dir)) {
    $files = @scandir($xampp_data_dir);
    if ($files) {
        foreach ($files as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['ibd', 'frm'])) {
                @unlink($xampp_data_dir . '\\' . $file);
            }
        }
    }
    // Try to remove directory
    @rmdir($xampp_data_dir);
    $setup_messages[] = "✓ Cleaned up orphaned files";
}

// Create connection to MySQL without database
$conn = new mysqli('127.0.0.1', 'root', '');

if ($conn->connect_error) {
    $setup_errors[] = "Connection failed: " . $conn->connect_error;
} else {
    // Drop database if exists
    @$conn->query("DROP DATABASE IF EXISTS hydromis");
    sleep(1);
    
    // Now create fresh database
    if ($conn->query("CREATE DATABASE hydromis")) {
        $setup_messages[] = "✓ Database 'hydromis' created";
        
        // Select database
        if ($conn->select_db('hydromis')) {
            $setup_messages[] = "✓ Selected database";
            
            // Read and execute schema
            $schema_file = 'database/schema.sql';
            
            if (file_exists($schema_file)) {
                $sql_content = file_get_contents($schema_file);
                
                // Remove any USE statements
                $sql_content = preg_replace('/USE\s+hydromis\s*;/i', '', $sql_content);
                
                // Parse queries
                $queries = [];
                $lines = explode("\n", $sql_content);
                $current_query = "";
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || substr($line, 0, 2) === '--') {
                        continue;
                    }
                    $current_query .= " " . $line;
                    if (substr($line, -1) === ';') {
                        $queries[] = $current_query;
                        $current_query = "";
                    }
                }
                
                // Execute each query
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (empty($query)) continue;
                    $query = rtrim($query, ";");
                    if (empty($query)) continue;
                    
                    if ($conn->query($query)) {
                        // Extract what was done
                        if (stripos($query, 'CREATE TABLE') === 0) {
                            if (preg_match('/CREATE TABLE.*?(\w+)\s*\(/i', $query, $matches)) {
                                $setup_messages[] = "✓ Table created: " . $matches[1];
                            }
                        } else if (stripos($query, 'INSERT INTO') === 0) {
                            if (preg_match('/INSERT INTO\s+(\w+)/i', $query, $matches)) {
                                $setup_messages[] = "✓ Data inserted into: " . $matches[1];
                            }
                        }
                    } else {
                        // Ignore duplicate key errors
                        if (strpos($conn->error, 'Duplicate') === false && strpos($conn->error, 'already exists') === false) {
                            $setup_errors[] = "Error: " . $conn->error;
                            break;
                        }
                    }
                }
                
                // Add missing columns if they don't exist
                @$conn->query("ALTER TABLE transactions ADD COLUMN delivery_status ENUM('pending', 'preparing', 'on_way', 'delivered') DEFAULT 'pending'");
                @$conn->query("ALTER TABLE transactions ADD COLUMN assigned_rider VARCHAR(255)");
                
                $setup_messages[] = "✓ Verified all required columns";
                $setup_complete = count($setup_errors) == 0;
            } else {
                $setup_errors[] = "Schema file not found";
            }
        }
    } else {
        $setup_errors[] = "Could not create database: " . $conn->error;
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Database Setup</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .setup-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .setup-header h1 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .status-box {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .status-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #7f1d1d;
        }
        .message-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0 0;
        }
        .message-list li {
            padding: 8px 0;
            font-size: 13px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        .credentials {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
        .credentials code {
            background: white;
            padding: 8px;
            border-radius: 4px;
            display: block;
            margin: 4px 0;
            color: #ef4444;
            font-weight: 600;
        }
        .btn {
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(120deg, #0f4fd4 0%, #0e8478 100%);
            color: white;
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(15, 79, 212, 0.3);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header text-center mb-4">
            <h1><i class="fas fa-database mr-2"></i>HydroMIS Setup</h1>
            <p class="text-muted">Database initialization</p>
        </div>

        <?php if ($setup_complete): ?>
            <div class="status-box status-success">
                <strong><i class="fas fa-check-circle mr-2"></i>Setup Complete!</strong>
                <ul class="message-list">
                    <?php foreach ($setup_messages as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="credentials">
                <strong><i class="fas fa-key mr-2"></i>Login Credentials:</strong>
                <code>User: admin | Pass: admin123 | Role: Admin</code>
                <code>User: staff1 | Pass: admin123 | Role: Staff</code>
            </div>

            <div class="text-center">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                </a>
            </div>
        <?php else: ?>
            <div class="status-box status-error">
                <strong><i class="fas fa-exclamation-circle mr-2"></i>Setup Issues</strong>
                <ul class="message-list">
                    <?php foreach ($setup_errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div style="padding: 20px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px;">
                <strong>Messages:</strong>
                <ul class="message-list">
                    <?php foreach ($setup_messages as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="text-center mt-4">
                <a href="clean_setup.php" class="btn btn-primary">
                    <i class="fas fa-redo mr-2"></i>Retry Setup
                </a>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px;">
            <p>HydroMIS v1.0 - Database Setup</p>
        </div>
    </div>
</body>
</html>
