<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$adminSessionId = (string)($_SESSION['admin_auth_id'] ?? '');
if ($adminSessionId === '' && !empty($_SESSION['admin_id']) && (($_SESSION['role'] ?? '') === 'admin')) {
    // Upgrade an existing pre-fix session without forcing an immediate login.
    $adminSessionId = (string)$_SESSION['admin_id'];
    $_SESSION['admin_auth_id'] = $adminSessionId;
}
$has_valid_admin_session = $adminSessionId !== '';

// Recover a valid admin session if the ID remains available but older session
// data is missing the role value. Always verify the role against the database.
if (!$has_valid_admin_session && !empty($_SESSION['admin_id'])) {
    require_once __DIR__ . '/../config/database.php';
    if (isset($conn)) {
        $sessionAdminId = $conn->real_escape_string((string)$_SESSION['admin_id']);
        $adminResult = $conn->query("SELECT username, full_name, role FROM admin_users WHERE admin_id = '$sessionAdminId' AND role = 'admin' LIMIT 1");
        if ($adminResult && $adminResult->num_rows > 0) {
            $adminAccount = $adminResult->fetch_assoc();
            $adminSessionId = (string)$_SESSION['admin_id'];
            $_SESSION['admin_auth_id'] = $adminSessionId;
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = $adminAccount['username'];
            $_SESSION['full_name'] = $adminAccount['full_name'];
            $has_valid_admin_session = true;
        }
    }
}

// Hydrate the legacy fields used throughout admin pages without affecting the
// separately stored staff identity.
if ($has_valid_admin_session) {
    require_once __DIR__ . '/../config/database.php';
    $verifiedAdminId = $conn->real_escape_string((string)($_SESSION['admin_auth_id'] ?? $adminSessionId));
    $verifiedAdmin = $conn->query("SELECT admin_id, username, full_name FROM admin_users WHERE admin_id = '$verifiedAdminId' AND role = 'admin' LIMIT 1");
    if ($verifiedAdmin && $verifiedAdmin->num_rows > 0) {
        $adminAccount = $verifiedAdmin->fetch_assoc();
        $_SESSION['admin_auth_id'] = $adminAccount['admin_id'];
        $_SESSION['admin_auth_username'] = $adminAccount['username'];
        $_SESSION['admin_auth_full_name'] = $adminAccount['full_name'];
        $_SESSION['admin_id'] = $adminAccount['admin_id'];
        $_SESSION['username'] = $adminAccount['username'];
        $_SESSION['full_name'] = $adminAccount['full_name'];
        $_SESSION['role'] = 'admin';
    } else {
        unset($_SESSION['admin_auth_id'], $_SESSION['admin_auth_username'], $_SESSION['admin_auth_full_name']);
        $has_valid_admin_session = false;
    }
}

if (!$has_valid_admin_session) {
    $returnTo = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/HydroMIS-1.3/admin/dashboard.php'));
    header('Location: ../login.php?role=admin&session=expired&return_to=' . $returnTo);
    exit();
}

// Fetch avatar path if not cached in session
if (!isset($_SESSION['avatar_path'])) {
    require_once __DIR__ . '/../config/database.php';
    if (isset($conn)) {
        $admin_id = $conn->real_escape_string($_SESSION['admin_id']);
        $av_res = $conn->query("SELECT avatar_path FROM admin_profiles WHERE admin_id = '$admin_id' LIMIT 1");
        if ($av_res && $av_res->num_rows > 0) {
            $_SESSION['avatar_path'] = $av_res->fetch_assoc()['avatar_path'] ?? '';
        } else {
            $_SESSION['avatar_path'] = '';
        }
    }
}
?>
