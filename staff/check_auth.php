<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check if user has staff role
if ($_SESSION['role'] != 'staff') {
    header('Location: ../index.php');
    exit();
}
?>
