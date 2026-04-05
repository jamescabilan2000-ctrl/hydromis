<?php
/**
 * HydroMIS Database Setup
 * Run this once to initialize the database with all required tables
 */

$setup_complete = false;
$setup_errors = [];
$setup_messages = [];

// Create connection to MySQL server (without database specified)
$conn_setup = new mysqli('127.0.0.1', 'root', '');

if ($conn_setup->connect_error) {
    $setup_errors[] = "Connection failed: " . $conn_setup->connect_error;
} else {
    // First, check if database exists with valid tables
    $tables_check = $conn_setup->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'hydromis' AND TABLE_NAME = 'admin_users' LIMIT 1");
    
    if ($tables_check && $tables_check->num_rows > 0) {
        $setup_complete = true;
        $setup_messages[] = "✓ Database already initialized";
        $setup_messages[] = "✓ All required tables exist";
    } else {
        // Read schema.sql
        $schema_file = 'database/schema.sql';
        
        if (!file_exists($schema_file)) {
            $setup_errors[] = "Schema file not found: " . $schema_file;
        } else {
            $sql_content = file_get_contents($schema_file);
            
            // Split by semicolon and clean up
            $queries = [];
            $lines = explode("\n", $sql_content);
            $current_query = "";
            
            foreach ($lines as $line) {
                $line = trim($line);
                // Skip empty lines and comments
                if (empty($line) || substr($line, 0, 2) === '--') {
                    continue;
                }
                $current_query .= " " . $line;
                if (substr($line, -1) === ';') {
                    $queries[] = $current_query;
                    $current_query = "";
                }
            }
            
            // Execute queries
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) continue;
                
                // Remove semicolon if present, we'll add it back
                $query = rtrim($query, ";");
                
                if (empty($query)) continue;
                
                $success = false;
                
                // Handle CREATE DATABASE specially
                if (stripos($query, 'CREATE DATABASE') === 0) {
                    $success = $conn_setup->query($query);
                    if ($success) {
                        $setup_messages[] = "✓ Database 'hydromis' created";
                    } else {
                        // Database might already exist - that's OK
                        $setup_messages[] = "✓ Database 'hydromis' ready";
                    }
                    $success = true; // Don't stop on database creation errors
                } 
                // Handle USE statement
                else if (stripos($query, 'USE') === 0) {
                    $success = $conn_setup->query($query);
                    if ($success) {
                        $setup_messages[] = "✓ Selected database 'hydromis'";
                    }
                }
                // Handle other statements
                else {
                    if ($conn_setup->query($query)) {
                        // Extract what was created/inserted
                        if (stripos($query, 'CREATE TABLE') === 0) {
                            if (preg_match('/CREATE TABLE.*?(\w+)\s*\(/i', $query, $matches)) {
                                $setup_messages[] = "✓ Table created: " . $matches[1];
                            }
                        } else if (stripos($query, 'INSERT INTO') === 0) {
                            if (preg_match('/INSERT INTO\s+(\w+)/i', $query, $matches)) {
                                $setup_messages[] = "✓ Data inserted into: " . $matches[1];
                            }
                        }
                        $success = true;
                    } else {
                        // Some errors are OK (like duplicate inserts)
                        $error = $conn_setup->error;
                        
                        // Check if this is a non-critical error
                        if (strpos($error, 'Duplicate entry') !== false || 
                            strpos($error, 'already exists') !== false) {
                            // These are OK - just skip
                            if (stripos($query, 'INSERT INTO') === 0) {
                                if (preg_match('/INSERT INTO\s+(\w+)/i', $query, $matches)) {
                                    $setup_messages[] = "✓ Data already exists in: " . $matches[1];
                                }
                            }
                            $success = true;
                        } else {
                            // This is a real error
                            $setup_errors[] = "Error: " . $error;
                            $success = false;
                            break;
                        }
                    }
                }
            }
            
            $setup_complete = count($setup_errors) == 0;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Database Setup</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
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
        .setup-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .setup-header h1 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .setup-header p {
            color: #6b7280;
            font-size: 14px;
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
        .message-list li:last-child {
            border-bottom: none;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 30px;
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
        .btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
            border: none;
        }
        .btn-secondary:hover {
            background: #d1d5db;
            color: #1f2937;
            text-decoration: none;
        }
        .credentials {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 13px;
        }
        .credentials strong {
            color: #1f2937;
            display: block;
            margin-bottom: 8px;
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
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1><i class="fas fa-database mr-2"></i>HydroMIS Database Setup</h1>
            <p>Initialize your database with required tables and sample data</p>
        </div>

        <?php if ($setup_complete): ?>
            <div class="status-box status-success">
                <strong><i class="fas fa-check-circle mr-2"></i>Setup Complete!</strong>
                <ul class="message-list">
                    <li>✓ Database 'hydromis' created successfully</li>
                    <li>✓ All tables initialized</li>
                    <li>✓ Sample data inserted</li>
                    <li>✓ Admin user ready to login</li>
                </ul>
            </div>

            <div class="credentials">
                <strong><i class="fas fa-lock mr-2"></i>Default Login Credentials:</strong>
                <code>Username: admin</code>
                <code>Password: admin123</code>
                <code>Role: Admin</code>
            </div>

            <div class="credentials" style="background: #eff6ff; border-color: #3b82f6;">
                <strong><i class="fas fa-info-circle mr-2" style="color: #3b82f6;"></i>Staff Account:</strong>
                <code>Username: staff1</code>
                <code>Password: admin123</code>
                <code>Role: Staff</code>
            </div>

            <div class="action-buttons">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                </a>
            </div>
        <?php else: ?>
            <div class="status-box status-error">
                <strong><i class="fas fa-exclamation-circle mr-2"></i>Setup Errors</strong>
                <ul class="message-list">
                    <?php foreach ($setup_errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div style="padding: 20px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px; color: #78350f;">
                <strong>Setup Messages:</strong>
                <ul class="message-list">
                    <?php foreach ($setup_messages as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="action-buttons">
                <a href="setup.php" class="btn btn-primary">
                    <i class="fas fa-redo mr-2"></i>Retry Setup
                </a>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px;">
            <p>✓ HydroMIS v1.0 | Database Setup Tool</p>
            <p style="margin: 0;">For support, check the documentation in the root directory.</p>
        </div>
    </div>
</body>
</html>
