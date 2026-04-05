<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check if user has admin role
if ($_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit();
}
?>
