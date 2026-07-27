<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['rider_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'rider') {
    header('Location: ../login.php?role=rider');
    exit();
}
?>
