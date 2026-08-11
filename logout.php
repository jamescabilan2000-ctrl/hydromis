<?php
session_start();

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['logout_csrf'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid logout request. Please go back and try again.');
    }

    define('HYDROMIS_DISABLE_AUTO_ACTIVITY', true);
    require_once __DIR__ . '/config/database.php';
    log_system_activity($conn, 'logout', 'User signed out of HydroMIS.');

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}

$displayName = trim((string)($_SESSION['full_name'] ?? $_SESSION['rider_auth_full_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Logout — HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box}
        body{min-height:100vh;margin:0;display:grid;place-items:center;padding:20px;background:radial-gradient(circle at top,#e7f7f5 0,#f5f8f7 44%,#edf2f4 100%);color:#172532;font-family:Inter,sans-serif}
        .logout-card{width:min(100%,420px);padding:30px;border:1px solid #dce6e5;border-radius:22px;background:#fff;text-align:center;box-shadow:0 24px 65px rgba(24,54,65,.14)}
        .logout-icon{display:grid;place-items:center;width:62px;height:62px;margin:0 auto 18px;border-radius:18px;background:#fff1f2;color:#dc2626;font-size:24px}
        h1{margin:0 0 9px;font-size:24px;letter-spacing:-.03em}
        p{margin:0;color:#647481;font-size:14px;line-height:1.6}
        .user-name{margin-top:7px;color:#243846;font-weight:700}
        .actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:25px}
        button{min-height:48px;border-radius:12px;font:700 13px Inter,sans-serif;cursor:pointer}
        .cancel{border:1px solid #d7e0e3;background:#fff;color:#425764}
        .cancel:hover{background:#f5f8f9}
        .confirm{border:0;background:#dc2626;color:#fff;box-shadow:0 9px 20px rgba(220,38,38,.2)}
        .confirm:hover{background:#b91c1c}
        @media(max-width:380px){.logout-card{padding:25px 20px}.actions{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <main class="logout-card" aria-labelledby="logoutTitle">
        <div class="logout-icon"><i class="fas fa-arrow-right-from-bracket"></i></div>
        <h1 id="logoutTitle">Are you sure you want to log out?</h1>
        <p>You’ll need to sign in again to continue using HydroMIS.</p>
        <?php if ($displayName !== ''): ?><div class="user-name"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <div class="actions">
            <button class="cancel" type="button" onclick="goBack()"><i class="fas fa-arrow-left"></i> Cancel</button>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <button class="confirm" type="submit" style="width:100%"><i class="fas fa-arrow-right-from-bracket"></i> Yes, log me out</button>
            </form>
        </div>
    </main>
    <script>
        function goBack(){
            if (document.referrer && new URL(document.referrer).origin === window.location.origin) history.back();
            else window.location.href = 'index.php';
        }
    </script>
</body>
</html>
