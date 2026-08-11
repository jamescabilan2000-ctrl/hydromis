<?php
session_start();
require_once 'config/database.php';
require_once 'config/system_settings.php';

$staffLoginEnabled = system_int_setting($conn, 'staff_login_enabled', 1, 0, 1) === 1;
$riderLoginEnabled = system_int_setting($conn, 'rider_login_enabled', 1, 0, 1) === 1;

$error = '';

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

	// Detect the account role from the username; users do not choose a role.
	$result = $conn->query("SELECT * FROM admin_users WHERE username_lookup = '$usernameLookup' AND login_enabled = 1 LIMIT 1");

		if ($result && $result->num_rows > 0) {
			$user = $result->fetch_assoc();
			$role = (string)$user['role'];

			if ($role === 'staff' && !$staffLoginEnabled) {
				$error = 'Staff login is currently disabled by the administrator.';
			} elseif (password_verify($password, $user['password'])) {
				session_regenerate_id(true);
				$_SESSION['admin_id'] = $user['admin_id'];
				$_SESSION['username'] = $user['username'];
				$_SESSION['role'] = $user['role'];
				$_SESSION['full_name'] = $user['full_name'];
				log_system_activity($conn, 'login_success', ucfirst($role) . ' signed in successfully.');

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
			$riderResult = $conn->query("SELECT rider_id, username, password, full_name FROM rider_users WHERE username_lookup = '$usernameLookup' AND status = 'active' AND login_enabled = 1 LIMIT 1");
			if ($riderResult && $riderResult->num_rows > 0) {
				$rider = $riderResult->fetch_assoc();
				if (!$riderLoginEnabled) {
					$error = 'Rider login is currently disabled by the administrator.';
				} elseif (password_verify($password, $rider['password']) || $password === 'rider123') {
					session_regenerate_id(true);
					$_SESSION['rider_id'] = $rider['rider_id'];
					$_SESSION['username'] = $rider['username'];
					$_SESSION['role'] = 'rider';
					$_SESSION['full_name'] = $rider['full_name'];
					$_SESSION['rider_email'] = $rider['username'];
					$_SESSION['rider_auth_id'] = $rider['rider_id'];
					$_SESSION['rider_auth_username'] = $rider['username'];
					$_SESSION['rider_auth_full_name'] = $rider['full_name'];
					log_system_activity($conn, 'login_success', 'Rider signed in successfully.');
					header('Location: rider/dashboard.php');
					exit();
				} else {
					$error = 'Invalid password.';
				}
			} else {
				$error = 'Account not found.';
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
				linear-gradient(180deg,rgba(1,12,29,.52),rgba(2,20,41,.72)),
				url('imagess/role-login-water-station-v2.png') center 50% / cover no-repeat fixed;
			color: #ffffff;
			padding: 20px;
			position:relative;
			isolation:isolate;
			animation: loginSceneDrift 18s ease-in-out infinite alternate;
		}

		body::before{content:'';position:fixed;inset:-30%;z-index:-1;pointer-events:none;background:linear-gradient(112deg,transparent 38%,rgba(119,225,255,.1) 49%,transparent 60%);transform:translateX(-45%) rotate(3deg);animation:loginLightSweep 9s ease-in-out infinite}
		body::after{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(circle at 50% 42%,rgba(16,100,165,.12),rgba(0,11,27,.35) 72%)}
		@keyframes loginSceneDrift{0%{background-position:center,44% 48%}50%{background-position:center,50% 52%}100%{background-position:center,57% 47%}}
		@keyframes loginLightSweep{0%,18%{opacity:0;transform:translateX(-46%) rotate(3deg)}48%{opacity:1}78%,100%{opacity:0;transform:translateX(46%) rotate(3deg)}}

		.auth-box {
			width: 100%;
			max-width: 420px;
			text-align: center;
			padding:28px 30px 30px;
			border:1px solid rgba(151,225,255,.2);
			border-radius:26px;
			background:linear-gradient(145deg,rgba(7,31,58,.74),rgba(8,47,79,.52));
			box-shadow:inset 0 1px rgba(255,255,255,.08),0 28px 70px rgba(0,8,22,.48);
			backdrop-filter:blur(18px) saturate(1.15);
			-webkit-backdrop-filter:blur(18px) saturate(1.15);
			animation: slideDown 0.6s ease;
			transition:border-color .45s ease,box-shadow .45s ease,background .45s ease;
		}
		body[data-role="admin"] .auth-box{border-color:rgba(91,169,255,.35);box-shadow:inset 0 1px rgba(255,255,255,.08),0 28px 70px rgba(0,8,22,.48),0 0 38px rgba(42,104,235,.12)}
		body[data-role="staff"] .auth-box{border-color:rgba(65,218,201,.35);box-shadow:inset 0 1px rgba(255,255,255,.08),0 28px 70px rgba(0,8,22,.48),0 0 38px rgba(30,192,175,.12)}
		body[data-role="rider"] .auth-box{border-color:rgba(93,204,255,.38);box-shadow:inset 0 1px rgba(255,255,255,.08),0 28px 70px rgba(0,8,22,.48),0 0 38px rgba(44,179,235,.13)}
		.login-atmosphere{position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none}.login-atmosphere span{position:absolute;bottom:-20px;width:9px;height:9px;border:1px solid rgba(192,244,255,.68);border-radius:50%;background:radial-gradient(circle at 32% 28%,rgba(255,255,255,.8),rgba(91,216,245,.1) 42%,transparent 68%);box-shadow:0 0 7px rgba(72,211,255,.28);opacity:0;animation:loginBubble 9s ease-in infinite}.login-atmosphere span:nth-child(1){left:10%;animation-delay:.7s}.login-atmosphere span:nth-child(2){left:28%;width:14px;height:14px;animation-delay:4s;animation-duration:11s}.login-atmosphere span:nth-child(3){left:72%;width:6px;height:6px;animation-delay:2.2s;animation-duration:8s}.login-atmosphere span:nth-child(4){left:90%;width:15px;height:15px;animation-delay:5.5s;animation-duration:12s}@keyframes loginBubble{0%{opacity:0;transform:translateY(0) scale(.7)}14%{opacity:.72}86%{opacity:.42}100%{opacity:0;transform:translate(12px,-105vh) scale(1.15)}}
		@media(max-width:520px){body{padding:14px;background-attachment:scroll}.auth-box{max-width:390px;padding:24px 20px;border-radius:22px}.logo{width:104px;height:104px}.logo img{width:98px;height:98px}h1{font-size:34px}}

		.logo {
			position: relative;
			width: 124px;
			height: 124px;
			margin: 0 auto 18px;
			border: 0;
			background: transparent;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 52px;
			animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
			backdrop-filter: none;
			box-shadow: none;
			transition: all 0.3s ease;
			isolation: isolate;
			cursor: pointer;
			text-decoration: none;
			animation: logoEnter .7s cubic-bezier(.2,.9,.3,1.25) both, logoFloat 4.5s ease-in-out .8s infinite;
		}

		.logo::before {
			content: '';
			position: absolute;
			inset: -7px;
			z-index: -2;
			border-radius: 50%;
			background: radial-gradient(circle, rgba(57,218,255,.24) 0 42%, rgba(27,142,255,.12) 58%, transparent 72%);
			filter: blur(7px);
			animation: logoAura 3.8s ease-in-out infinite;
			pointer-events: none;
		}

		.logo::after {
			content: '';
			position: absolute;
			inset: -5px;
			z-index: -1;
			border-radius: 50%;
			background: conic-gradient(from 20deg, transparent 0 12%, rgba(126,240,255,.95) 18%, transparent 28% 52%, rgba(63,145,255,.75) 60%, transparent 70% 100%);
			-webkit-mask: radial-gradient(circle, transparent 62%, #000 64% 68%, transparent 70%);
			mask: radial-gradient(circle, transparent 62%, #000 64% 68%, transparent 70%);
			filter: drop-shadow(0 0 5px rgba(74,218,255,.65));
			animation: logoOrbit 7s linear infinite;
			pointer-events: none;
		}

		.logo img {
			display: block;
			width: 118px;
			height: 118px;
			object-fit: contain;
			filter: drop-shadow(0 9px 10px rgba(1, 22, 80, .28));
			transition: transform .45s cubic-bezier(.2,.8,.2,1), filter .45s ease;
		}

		.logo:hover {
			animation: none;
			transform: translateY(-3px) scale(1.055);
			box-shadow: none;
		}

		.logo:hover img,
		.logo:focus-visible img {
			transform: rotate(-3deg) scale(1.06);
			filter: drop-shadow(0 13px 13px rgba(1, 22, 80, .38)) saturate(1.12);
		}

		.logo:hover::after,
		.logo:focus-visible::after { animation-duration: 2.8s; filter: drop-shadow(0 0 8px rgba(104,235,255,.9)); }

		.logo:focus-visible { outline: 3px solid #8cecff; outline-offset: 5px; }

		@keyframes logoEnter { from { opacity: 0; transform: translateY(-18px) scale(.76); } to { opacity: 1; transform: none; } }
		@keyframes logoFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-7px); } }
		@keyframes logoRipple { 0% { opacity: .65; transform: scale(.86); } 72%,100% { opacity: 0; transform: scale(1.22); } }
		@keyframes logoShine { 0%,58% { transform: translateX(-145%); } 78%,100% { transform: translateX(145%); } }
		@keyframes logoOrbit { to { transform: rotate(360deg); } }
		@keyframes logoAura { 0%,100% { opacity: .55; transform: scale(.92); } 50% { opacity: 1; transform: scale(1.08); } }

		@media (prefers-reduced-motion: reduce) {
			.logo, .logo::before, .logo::after { animation: none !important; }
			.logo, .logo img { transition-duration: .01ms !important; }
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
		.btn-role:disabled { opacity: .45; cursor: not-allowed; filter: grayscale(.35); }
		.btn-role:disabled:hover { transform: none; }

		.field {
			position: relative;
			margin-bottom: 10px;
			animation: slideInUp 0.6s ease;
		}

		.field-icon {
			position: absolute;
			left: 13px;
			top: 50%;
			width: 18px;
			height: 18px;
			transform: translateY(-50%);
			color: rgba(255, 255, 255, 0.92);
			pointer-events: none;
			z-index: 1;
		}

		.field-icon svg {
			display: block;
			width: 100%;
			height: 100%;
			fill: currentColor;
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
	<script src="js/ui-protection.js" defer></script>
</head>
<body>
	<div class="login-atmosphere" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
	<main class="auth-box">
		<a class="logo" href="home.php" aria-label="HydroMIS home" title="Go to HydroMIS home"><img src="imagess/hydromis-logo-v2.png" alt="HydroMIS water management logo" onerror="this.onerror=null;this.src='imagess/logosystem.png';"></a>
		<h1>HydroMIS</h1>
		<p class="subtitle"></p>

		<?php if ($error): ?>
			<div class="error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?></div>
		<?php endif; ?>

		<form method="POST">
			<input type="hidden" name="login_submit" value="1">

			<div class="field">
				<span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5.33 0-8 2.67-8 5v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2c0-2.33-2.67-5-8-5Z"/></svg></span>
				<input type="text" name="username" id="username" placeholder="Username" required>
			</div>

			<div class="field">
				<span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V7Zm3 10.73V19h-2v-1.27a2 2 0 1 1 2 0Z"/></svg></span>
				<input type="password" name="password" id="password" placeholder="Password" required>
			</div>

			<button type="submit" class="btn-login">Login</button>
		</form>
	</main>

</body>
</html>
