<?php
include 'check_auth.php';
require_once '../config/database.php';

$rider_id = sanitize($_SESSION['rider_id'] ?? '');
if ($rider_id === '') {
    header('Location: login.php');
    exit();
}

$columnCheck = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'assigned_rider' LIMIT 1");
$has_assigned_rider = $columnCheck && $columnCheck->num_rows > 0;
$rider_where = $has_assigned_rider ? '(t.rider_id = ? OR t.assigned_rider = ?)' : 't.rider_id = ?';

$sql = "SELECT t.transaction_id, t.amount, t.updated_at, COALESCE(u.full_name, 'Unknown Customer') AS customer, COALESCE(u.address, 'No address provided') AS address
        FROM transactions t
        LEFT JOIN users u ON u.user_id = t.user_id
        WHERE t.status = 'approved' AND t.delivery_status = 'delivered' AND {$rider_where}
        ORDER BY t.updated_at DESC";
$stmt = $conn->prepare($sql);
if ($has_assigned_rider) {
    $stmt->bind_param('ss', $rider_id, $rider_id);
} else {
    $stmt->bind_param('s', $rider_id);
}
$stmt->execute();
$result = $stmt->get_result();
$history = [];
while ($delivery = $result->fetch_assoc()) {
    $day = date('Y-m-d', strtotime($delivery['updated_at']));
    $history[$day][] = $delivery;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery History — HydroMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--ink:#16202b;--paper:#f7f5f0;--card:#fff;--green:#16a34a;--steel:#64748b;--line:#e7e2d6}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,sans-serif}.topbar{height:62px;padding:0 16px;background:var(--ink);display:flex;align-items:center;gap:12px;color:#fff}.back{display:grid;place-items:center;width:38px;height:38px;border-radius:9px;color:#e2e8f0;text-decoration:none}.back:hover{background:rgba(255,255,255,.1);color:#fff}.topbar b{font:700 21px 'Barlow Condensed',sans-serif}.topbar span{display:block;color:#7dd3fc;font-size:10px}.shell{max-width:720px;margin:0 auto;padding:24px 14px 40px}.heading{display:flex;gap:10px;align-items:center;margin-bottom:4px}.heading i{color:var(--green);font-size:22px}.heading h1{margin:0;font:700 27px 'Barlow Condensed',sans-serif}.intro{margin:0 0 22px;color:var(--steel);font-size:13px}.day{margin:20px 0 10px;color:var(--steel);font-size:14px;font-weight:700}.day:first-of-type{margin-top:0}.delivery{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;margin-bottom:10px;background:var(--card);border:1px solid var(--line);border-radius:14px}.name{display:block;font-size:15px;font-weight:700}.address,.details,.status{display:block;color:var(--steel);font-size:12px;line-height:1.4}.amount{display:block;color:var(--green);font:700 16px 'JetBrains Mono',monospace;text-align:right}.status{text-align:right}.empty{text-align:center;padding:42px 18px;background:#fff;border:1px dashed var(--line);border-radius:14px;color:var(--steel)}.empty i{display:block;margin-bottom:10px;font-size:28px;color:#cbd5e1}@media(max-width:480px){.shell{padding:20px 12px}.delivery{padding:13px 14px}.amount{font-size:14px}}
</style>
</head>
<body>
<header class="topbar"><a class="back" href="dashboard.php" aria-label="Back to dashboard"><i class="fas fa-arrow-left"></i></a><div><b>Delivery History</b><span>Rider Portal</span></div></header>
<main class="shell">
  <div class="heading"><i class="fas fa-clock-rotate-left"></i><h1>Delivery History</h1></div>
  <p class="intro">Your completed deliveries, grouped by delivery day.</p>
  <?php if (empty($history)): ?>
    <div class="empty"><i class="fas fa-inbox"></i>No completed deliveries yet.</div>
  <?php else: foreach ($history as $day => $deliveries): ?>
    <div class="day"><?php echo date('l, F j, Y', strtotime($day)); ?></div>
    <?php foreach ($deliveries as $delivery): ?>
      <article class="delivery">
        <div><strong class="name"><?php echo htmlspecialchars($delivery['customer']); ?></strong><span class="address"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($delivery['address']); ?></span><span class="details">#<?php echo htmlspecialchars($delivery['transaction_id']); ?> · <?php echo date('h:i A', strtotime($delivery['updated_at'])); ?></span></div>
        <div><strong class="amount">₱<?php echo number_format((float)$delivery['amount'], 2); ?></strong><span class="status">Delivered</span></div>
      </article>
    <?php endforeach; ?>
  <?php endforeach; endif; ?>
</main>
</body>
</html>
