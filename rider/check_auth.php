<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rider_auth_id = $_SESSION['rider_auth_id'] ?? (
    (($_SESSION['role'] ?? '') === 'rider') ? ($_SESSION['rider_id'] ?? '') : ''
);

if ($rider_auth_id === '') {
    header('Location: ../login.php?role=rider');
    exit();
}

// Keep rider authentication independent from Admin/Staff sessions in the same browser.
$_SESSION['rider_auth_id'] = $rider_auth_id;
$_SESSION['rider_id'] = $rider_auth_id;
if (isset($_SESSION['rider_auth_username'])) {
    $_SESSION['rider_email'] = $_SESSION['rider_auth_username'];
}
?>
