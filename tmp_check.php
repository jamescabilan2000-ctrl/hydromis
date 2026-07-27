<?php
require 'config/database.php';
$res = $conn->query("SELECT transaction_id, status, delivery_status, rider_id, assigned_rider, approved_by FROM transactions ORDER BY created_at DESC LIMIT 20");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['transaction_id'] . ' | status=' . $row['status'] . ' | delivery_status=' . $row['delivery_status'] . ' | rider_id=' . $row['rider_id'] . ' | assigned_rider=' . $row['assigned_rider'] . ' | approved_by=' . $row['approved_by'] . PHP_EOL;
    }
}
$res2 = $conn->query("SELECT rider_id, username, full_name, status FROM rider_users ORDER BY rider_id");
if ($res2) {
    echo '--- riders ---' . PHP_EOL;
    while ($row = $res2->fetch_assoc()) {
        echo $row['rider_id'] . ' | ' . $row['username'] . ' | ' . $row['full_name'] . ' | ' . $row['status'] . PHP_EOL;
    }
}
?>
