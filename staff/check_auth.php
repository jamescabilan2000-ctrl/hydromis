<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$has_valid_staff_session = isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'staff';

if (!$has_valid_staff_session) {
    header('Location: ../index.php');
    exit();
}
?>
