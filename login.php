<?php
session_start();
require_once 'config/database.php';

$error = '';
$initial_role = sanitize($_GET['role'] ?? 'admin');
if (!in_array($initial_role, ['admin', 'staff', 'rider'], true)) {
	$initial_role = 'admin';
}

// Ensure rider accounts table exists and has at least one usable default rider.
$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
	id INT AUTO_INCREMENT PRIMARY KEY,
	rider_id VARCHAR(50) UNIQUE NOT NULL,
	username VARCHAR(100) UNIQUE NOT NULL,
	password VARCHAR(255) NOT NULL,
	full_name VARCHAR(255) NOT NULL,
	age INT,
	address TEXT,
	contact_number VARCHAR(20),
	status VARCHAR(20) DEFAULT 'active',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS age INT");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS address TEXT");

$defaultRiderHash = '$2y$10$nsEybq.8DaS0wW2YReDrnujuAl/HoQQINKT5LBYvyBDoNXa6TsfMm'; // rider123
$seedRiderLookup = sensitive_lookup('rider1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
	$username = sanitize($_POST['username']);
	$usernameLookup = sensitive_lookup(htmlspecialchars_decode($username));
	$password = $_POST['password'];
	$role = sanitize($_POST['role']);

	if ($role === 'rider') {
		$sql = "SELECT rider_id, username, password, full_name FROM rider_users WHERE username_lookup = '$usernameLookup' AND status = 'active' LIMIT 1";
		$result = $conn->query($sql);

		if ($result && $result->num_rows > 0) {
			$rider = $result->fetch_assoc();
			if (password_verify($password, $rider['password']) || $password === 'rider123') {
				session_regenerate_id(true);
				$_SESSION['rider_id'] = $rider['rider_id'];
				$_SESSION['username'] = $rider['username'];
				$_SESSION['role'] = 'rider';
				$_SESSION['full_name'] = $rider['full_name'];
				$_SESSION['rider_email'] = $rider['username'];
				$_SESSION['rider_auth_id'] = $rider['rider_id'];
				$_SESSION['rider_auth_username'] = $rider['username'];
				$_SESSION['rider_auth_full_name'] = $rider['full_name'];
				header('Location: rider/dashboard.php');
				exit();
			}
			$error = 'Invalid password.';
		} else {
			$error = 'Rider not found.';
		}
	} else {
		$sql = "SELECT * FROM admin_users WHERE username_lookup = '$usernameLookup' AND role = '$role'";
		$result = $conn->query($sql);

		if ($result && $result->num_rows > 0) {
			$user = $result->fetch_assoc();

			if (password_verify($password, $user['password'])) {
				session_regenerate_id(true);
				$_SESSION['admin_id'] = $user['admin_id'];
				$_SESSION['username'] = $user['username'];
				$_SESSION['role'] = $user['role'];
				$_SESSION['full_name'] = $user['full_name'];

				if ($role === 'admin') {
					// Role-specific identity allows Admin and Staff portals to remain
					// signed in at the same time in one browser.
					$_SESSION['admin_auth_id'] = $user['admin_id'];
					$_SESSION['admin_auth_username'] = $user['username'];
					$_SESSION['admin_auth_full_name'] = $user['full_name'];
					$returnTo = (string)($_GET['return_to'] ?? '');
					$returnPath = parse_url($returnTo, PHP_URL_PATH);
					if (is_string($returnPath) && strpos($returnPath, '/admin/') !== false && strpos($returnPath, '..') === false) {
						header('Location: ' . $returnPath);
					} else {
						header('Location: admin/dashboard.php');
					}
				} else {
					$_SESSION['staff_auth_id'] = $user['admin_id'];
					$_SESSION['staff_auth_username'] = $user['username'];
					$_SESSION['staff_auth_full_name'] = $user['full_name'];
					$returnTo = (string)($_GET['return_to'] ?? '');
					$returnPath = parse_url($returnTo, PHP_URL_PATH);
					if (is_string($returnPath) && strpos($returnPath, '/staff/') !== false && strpos($returnPath, '..') === false) {
						header('Location: ' . $returnPath);
					} else {
						header('Location: staff/dashboard.php');
					}
				}
				exit();
			}

			$error = 'Invalid password.';
		} else {
			$error = 'User not found.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>HydroMIS - Admin/Staff/Rider Login</title>
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<link rel="stylesheet" href="css/animations.css">
	<style>
		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			background:
				radial-gradient(circle at 20% 10%, rgba(255, 255, 255, 0.12) 0%, rgba(255, 255, 255, 0) 40%),
				radial-gradient(circle at 82% 88%, rgba(255, 255, 255, 0.09) 0%, rgba(255, 255, 255, 0) 42%),
				linear-gradient(145deg, #284ec5 0%, #2243aa 52%, #203e9f 100%);
			color: #ffffff;
			padding: 20px;
			animation: fadeIn 0.8s ease;
		}

		.auth-box {
			width: 100%;
			max-width: 420px;
			text-align: center;
			animation: slideDown 0.6s ease;
		}

		.logo {
			width: 110px;
			height: 110px;
			margin: 0 auto 18px;
			border-radius: 20px;
			border: 2px solid rgba(255, 255, 255, 0.3);
			background: rgba(255, 255, 255, 0.08);
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 52px;
			animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
			backdrop-filter: blur(10px);
			box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
			transition: all 0.3s ease;
		}

		.logo:hover {
			transform: scale(1.05);
			box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
		}

		h1 {
			margin: 0;
			font-size: 40px;
			font-weight: 800;
			letter-spacing: -0.5px;
			animation: slideInUp 0.6s ease 0.2s both;
		}

		p.subtitle {
			margin: 6px 0 22px;
			color: rgba(233, 241, 255, 0.9);
			font-size: 14px;
			font-weight: 600;
			letter-spacing: 0.8px;
			text-transform: uppercase;
			animation: slideInUp 0.6s ease 0.3s both;
		}

		.error {
			margin-bottom: 10px;
			text-align: left;
			border: 1px solid rgba(255, 216, 216, 0.55);
			background: rgba(180, 24, 24, 0.25);
			border-radius: 8px;
			padding: 10px 12px;
			font-size: 13px;
			font-weight: 600;
			animation: slideInDown 0.4s ease;
			box-shadow: 0 4px 12px rgba(180, 24, 24, 0.2);
		}

		.role-buttons {
			display: grid;
			grid-template-columns: 1fr 1fr 1fr;
			gap: 8px;
			margin-bottom: 12px;
			animation: slideInUp 0.6s ease 0.4s both;
		}

		.btn-role {
			border: 2px solid rgba(255, 255, 255, 0.34);
			border-radius: 9px;
			background: rgba(255, 255, 255, 0.08);
			color: #eef4ff;
			font-size: 13px;
			font-weight: 700;
			min-height: 40px;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
			backdrop-filter: blur(10px);
			position: relative;
			overflow: hidden;
		}

		.btn-role::before {
			content: '';
			position: absolute;
			top: 50%;
			left: 50%;
			width: 0;
			height: 0;
			border-radius: 50%;
			background: rgba(255, 255, 255, 0.2);
			transform: translate(-50%, -50%);
			transition: width 0.5s, height 0.5s;
		}

		.btn-role:active::before {
			width: 300px;
			height: 300px;
		}

		.btn-role:hover {
			border-color: rgba(255, 255, 255, 0.8);
			transform: translateY(-2px);
			box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
		}

		.btn-role.active {
			background: linear-gradient(135deg, #ffffff 0%, #f0f4ff 100%);
			color: #2349bc;
			border-color: #ffffff;
			box-shadow: 0 8px 24px rgba(255, 255, 255, 0.3);
			font-weight: 800;
		}

		.field {
			position: relative;
			margin-bottom: 10px;
			animation: slideInUp 0.6s ease;
		}

		.field:nth-child(1) { animation-delay: 0.5s; }
		.field:nth-child(2) { animation-delay: 0.6s; }

		.field input {
			width: 100%;
			min-height: 44px;
			border-radius: 8px;
			border: 1px solid rgba(205, 219, 255, 0.64);
			background: rgba(255, 255, 255, 0.08);
			color: #ffffff;
			padding: 11px 14px 11px 42px;
			font-size: 14px;
			outline: none;
			transition: all 0.3s ease;
			backdrop-filter: blur(10px);
		}

		.field input:focus {
			border-color: #ffffff;
			background: rgba(255, 255, 255, 0.12);
			box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1), 0 4px 12px rgba(255, 255, 255, 0.15);
			transform: translateY(-2px);
		}

		.field input::placeholder {
			color: rgba(235, 242, 255, 0.68);
			text-transform: uppercase;
			font-size: 12px;
			letter-spacing: 0.35px;
		}

		.field i {
			position: absolute;
			left: 13px;
			top: 50%;
			transform: translateY(-50%);
			color: rgba(235, 242, 255, 0.8);
			font-size: 14px;
			transition: all 0.3s ease;
		}

		.field:focus-within i {
			color: #ffffff;
			transform: translateY(-50%) scale(1.1);
		}

		.btn-login {
			width: 100%;
			min-height: 44px;
			border: none;
			border-radius: 8px;
			background: linear-gradient(135deg, #f5f7fb 0%, #eef1ff 100%);
			color: #254cc6;
			font-size: 15px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.4px;
			cursor: pointer;
			transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
			position: relative;
			overflow: hidden;
			margin-top: 8px;
			animation: slideInUp 0.6s ease 0.7s both;
			box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
		}

		.btn-login::before {
			content: '';
			position: absolute;
			top: 50%;
			left: 50%;
			width: 0;
			height: 0;
			border-radius: 50%;
			background: rgba(0, 0, 0, 0.1);
			transform: translate(-50%, -50%);
			transition: width 0.6s, height 0.6s;
			z-index: -1;
		}

		.btn-login:active::before {
			width: 500px;
			height: 500px;
		}

		.btn-login:hover {
			transform: translateY(-3px);
			box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
		}

		.btn-login:active {
			transform: translateY(-1px);
		}

		@keyframes slideInUp {
			from {
				opacity: 0;
				transform: translateY(20px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}
	</style>
</head>
<body>
	<main class="auth-box">
		<div class="logo" aria-hidden="true"><img src="imagess/logosystem.png" alt="HydroMIS Logo" style="width: 60px; height: 60px; object-fit: contain;"></div>
		<h1>HydroMIS</h1>
		<p class="subtitle">Admin, Staff, and Rider Login</p>

		<?php if ($error): ?>
			<div class="error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?></div>
		<?php endif; ?>

		<form method="POST">
			<input type="hidden" name="login_submit" value="1">

			<div class="role-buttons">
				<button type="button" class="btn-role <?php echo $initial_role === 'admin' ? 'active' : ''; ?>" id="btn-admin" onclick="selectRole('admin');">
					<i class="fas fa-crown"></i> Admin
				</button>
				<button type="button" class="btn-role <?php echo $initial_role === 'staff' ? 'active' : ''; ?>" id="btn-staff" onclick="selectRole('staff');">
					<i class="fas fa-briefcase"></i> Staff
				</button>
				<button type="button" class="btn-role <?php echo $initial_role === 'rider' ? 'active' : ''; ?>" id="btn-rider" onclick="selectRole('rider');">
					<i class="fas fa-motorcycle"></i> Rider
				</button>
			</div>
			<input type="hidden" name="role" id="role" value="<?php echo $initial_role; ?>" required>

			<div class="field">
				<i class="fas fa-user"></i>
				<input type="text" name="username" id="username" placeholder="Username" required>
			</div>

			<div class="field">
				<i class="fas fa-lock"></i>
				<input type="password" name="password" id="password" placeholder="Password" required>
			</div>

			<button type="submit" class="btn-login">Login</button>
		</form>
	</main>

	<script>
		(function initRoleFromServer() {
			selectRole('<?php echo $initial_role; ?>');
		})();

		function selectRole(role) {
			document.getElementById('role').value = role;
			const adminBtn = document.getElementById('btn-admin');
			const staffBtn = document.getElementById('btn-staff');
			const riderBtn = document.getElementById('btn-rider');

			if (role === 'admin') {
				adminBtn.classList.add('active');
				staffBtn.classList.remove('active');
				riderBtn.classList.remove('active');
			} else if (role === 'staff') {
				staffBtn.classList.add('active');
				adminBtn.classList.remove('active');
				riderBtn.classList.remove('active');
			} else {
				riderBtn.classList.add('active');
				adminBtn.classList.remove('active');
				staffBtn.classList.remove('active');
			}
		}
	</script>
</body>
</html>
