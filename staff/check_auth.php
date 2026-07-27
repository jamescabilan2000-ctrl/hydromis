<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$staffSessionId = (string)($_SESSION['staff_auth_id'] ?? '');
if ($staffSessionId === '' && !empty($_SESSION['admin_id']) && (($_SESSION['role'] ?? '') === 'staff')) {
    // Upgrade an existing pre-fix session without forcing an immediate login.
    $staffSessionId = (string)$_SESSION['admin_id'];
    $_SESSION['staff_auth_id'] = $staffSessionId;
}
$has_valid_staff_session = $staffSessionId !== '';

// Recover a valid staff session when the account ID remains available but the
// role/name values were not carried into the next request.
if (!$has_valid_staff_session && !empty($_SESSION['admin_id'])) {
    require_once __DIR__ . '/../config/database.php';
    if (isset($conn)) {
        $sessionStaffId = $conn->real_escape_string((string)$_SESSION['admin_id']);
        $staffResult = $conn->query("SELECT username, full_name, role FROM admin_users WHERE admin_id = '$sessionStaffId' AND role = 'staff' LIMIT 1");
        if ($staffResult && $staffResult->num_rows > 0) {
            $staffAccount = $staffResult->fetch_assoc();
            $staffSessionId = (string)$_SESSION['admin_id'];
            $_SESSION['staff_auth_id'] = $staffSessionId;
            $_SESSION['role'] = 'staff';
            $_SESSION['username'] = $staffAccount['username'];
            $_SESSION['full_name'] = $staffAccount['full_name'];
            $has_valid_staff_session = true;
        }
    }
}

// Hydrate the legacy fields used by staff pages while preserving the separate
// admin identity for another browser tab.
if ($has_valid_staff_session) {
    require_once __DIR__ . '/../config/database.php';
    $verifiedStaffId = $conn->real_escape_string((string)($_SESSION['staff_auth_id'] ?? $staffSessionId));
    $verifiedStaff = $conn->query("SELECT admin_id, username, full_name FROM admin_users WHERE admin_id = '$verifiedStaffId' AND role = 'staff' LIMIT 1");
    if ($verifiedStaff && $verifiedStaff->num_rows > 0) {
        $staffAccount = $verifiedStaff->fetch_assoc();
        $_SESSION['staff_auth_id'] = $staffAccount['admin_id'];
        $_SESSION['staff_auth_username'] = $staffAccount['username'];
        $_SESSION['staff_auth_full_name'] = $staffAccount['full_name'];
        $_SESSION['admin_id'] = $staffAccount['admin_id'];
        $_SESSION['username'] = $staffAccount['username'];
        $_SESSION['full_name'] = $staffAccount['full_name'];
        $_SESSION['role'] = 'staff';
    } else {
        unset($_SESSION['staff_auth_id'], $_SESSION['staff_auth_username'], $_SESSION['staff_auth_full_name']);
        $has_valid_staff_session = false;
    }
}

if (!$has_valid_staff_session) {
    $returnTo = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/HydroMIS-1.3/staff/dashboard.php'));
    header('Location: ../login.php?role=staff&session=expired&return_to=' . $returnTo);
    exit();
}
?>
