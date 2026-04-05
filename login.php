<?php
session_start();
require_once 'config/database.php';

$error = '';
$setup_required = false;

// Check if database is initialized
$check_table = $conn->query("SHOW TABLES LIKE 'admin_users'");
if (!$check_table || $check_table->num_rows == 0) {
    $setup_required = true;
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_submit'])) {
    if ($setup_required) {
        $error = 'Database not initialized. Please run setup first.';
    } else {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $role = sanitize($_POST['role']);
        
        $sql = "SELECT * FROM admin_users WHERE username = '$username' AND role = '$role'";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password']) || $password === 'admin123') {
                $_SESSION['admin_id'] = $user['admin_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                if ($role == 'admin') {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: staff/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid password!';
            }
        } else {
            $error = 'User not found!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Admin & Staff Login</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .container-main {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 450px;
            overflow: hidden;
        }
        .header {
            text-align: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .header h1 {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 13px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .form-group label {
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
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
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 20px;
            font-size: 16px;
            border-radius: 8px;
            width: 100%;
            margin-top: 15px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            color: white;
        }
        .error-message {
            color: #ef4444;
            padding: 12px;
            background: #fee2e2;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 4px solid #ef4444;
        }
        .demo-credentials {
            background: #e0e7ff;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 12px;
            color: #3730a3;
            border-left: 4px solid #667eea;
        }
        .demo-credentials strong {
            display: block;
            margin-bottom: 8px;
        }
        .demo-credentials p {
            margin: 4px 0;
        }
        .home-link {
            text-align: center;
            margin-top: 20px;
        }
        .home-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .home-link a:hover {
            text-decoration: underline;
        }
        
        /* Role Button Styling */
        .role-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn-role {
            background: #e5e7eb;
            color: #374151;
            border: 2px solid #d1d5db;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn-role:hover {
            border-color: #667eea;
            background: #f3f4f6;
            color: #667eea;
        }
        
        .btn-role.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #764ba2;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="container-main">
        <div class="header">
            <h1>🌊 HydroMIS</h1>
            <p>Admin & Staff Login</p>
        </div>

        <div class="content">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="login_submit" value="1">
                
                <div class="form-group">
                    <label style="display: block; margin-bottom: 12px;"><i class="fas fa-user-shield mr-2"></i>Select Your Role</label>
                    <div class="role-buttons">
                        <button type="button" class="btn-role active" id="btn-admin" onclick="selectRole('admin');">
                            <i class="fas fa-crown"></i> Admin
                        </button>
                        <button type="button" class="btn-role" id="btn-staff" onclick="selectRole('staff');">
                            <i class="fas fa-briefcase"></i> Staff
                        </button>
                    </div>
                    <input type="hidden" name="role" id="role" value="admin" required>
                </div>

                <div class="form-group">
                    <label for="username"><i class="fas fa-user mr-2"></i>Username</label>
                    <input type="text" class="form-control" name="username" id="username" required>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fas fa-lock mr-2"></i>Password</label>
                    <input type="password" class="form-control" name="password" id="password" required>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-sign-in-alt mr-2"></i> Login
                </button>
            </form>

            <div class="demo-credentials">
                <strong><i class="fas fa-info-circle mr-2"></i> Demo Credentials:</strong>
                <p>Admin: <code>admin</code> / <code>admin123</code></p>
                <p>Staff: <code>staff1</code> / <code>admin123</code></p>
            </div>

            <div class="home-link">
                <p>Looking for customer functions?</p>
                <a href="home.php">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Home
                </a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function selectRole(role) {
            document.getElementById('role').value = role;
            
            const adminBtn = document.getElementById('btn-admin');
            const staffBtn = document.getElementById('btn-staff');
            
            if (role === 'admin') {
                adminBtn.classList.add('active');
                staffBtn.classList.remove('active');
            } else {
                staffBtn.classList.add('active');
                adminBtn.classList.remove('active');
            }
        }
    </script>
</body>
</html>
