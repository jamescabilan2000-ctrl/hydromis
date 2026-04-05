<?php
session_start();

if (!isset($_SESSION['rider_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'rider') {
    header('Location: ../index.php');
    exit();
}
?>
