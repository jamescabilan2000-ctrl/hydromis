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

$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'today';
$periods = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'date' => 'Specific Date', 'all' => 'All History'];
if (!isset($periods[$period])) $period = 'today';
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$date = is_string($_GET['date'] ?? null) ? $_GET['date'] : $today->format('Y-m-d');
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
    $selectedDate = $today;
    $date = $today->format('Y-m-d');
}
$params = $has_assigned_rider ? [$rider_id, $rider_id] : [$rider_id];
$filters = '';
if ($period !== 'all') {
    $start = $today;
    $end = $today->modify('+1 day');
    if ($period === 'yesterday') {
        $start = $today->modify('-1 day');
        $end = $today;
    } elseif ($period === 'week') {
        $start = $today->modify('monday this week');
    } elseif ($period === 'date') {
        $start = $selectedDate;
        $end = $start->modify('+1 day');
    }
    $filters .= ' AND t.updated_at >= ? AND t.updated_at < ?';
    $params[] = $start->format('Y-m-d H:i:s');
    $params[] = $end->format('Y-m-d H:i:s');
}
if ($query !== '') {
    $filters .= " AND (LOCATE(LOWER(?), LOWER(COALESCE(u.full_name, 'Unknown Customer'))) > 0
        OR LOCATE(LOWER(?), LOWER(COALESCE(u.address, 'No address provided'))) > 0
        OR LOCATE(LOWER(?), LOWER(t.transaction_id)) > 0)";
    array_push($params, $query, $query);
}
$sql = "SELECT t.transaction_id, t.amount, t.updated_at, COALESCE(u.full_name, 'Unknown Customer') AS customer, COALESCE(u.address, 'No address provided') AS address
        FROM transactions t
        LEFT JOIN users u ON u.user_id = t.user_id
        WHERE t.status = 'approved' AND t.delivery_status = 'delivered' AND {$rider_where}{$filters}
        ORDER BY t.updated_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count($params)), ...$params);
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
<link rel="icon" type="image/png" href="../imagess/favicon-cropped.png?v=2">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery History — HydroMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--ink:#16202b;--paper:#f7f5f0;--card:#fff;--green:#16a34a;--steel:#64748b;--line:#e7e2d6}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,sans-serif}.topbar{height:62px;padding:0 16px;background:var(--ink);display:flex;align-items:center;gap:12px;color:#fff}.back{display:grid;place-items:center;width:38px;height:38px;border-radius:9px;color:#e2e8f0;text-decoration:none}.back:hover{background:rgba(255,255,255,.1);color:#fff}.topbar b{font:700 21px 'Barlow Condensed',sans-serif}.topbar span{display:block;color:#7dd3fc;font-size:10px}.shell{max-width:720px;margin:0 auto;padding:24px 14px 40px}.heading{display:flex;gap:10px;align-items:center;margin-bottom:4px}.heading i{color:var(--green);font-size:22px}.heading h1{margin:0;font:700 27px 'Barlow Condensed',sans-serif}.intro{margin:0 0 22px;color:var(--steel);font-size:13px}.day{margin:20px 0 10px;color:var(--steel);font-size:14px;font-weight:700}.day:first-of-type{margin-top:0}.delivery{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;margin-bottom:10px;background:var(--card);border:1px solid var(--line);border-radius:14px}.name{display:block;font-size:15px;font-weight:700}.address,.details,.status{display:block;color:var(--steel);font-size:12px;line-height:1.4}.amount{display:block;color:var(--green);font:700 16px 'JetBrains Mono',monospace;text-align:right}.status{text-align:right}.empty{text-align:center;padding:42px 18px;background:#fff;border:1px dashed var(--line);border-radius:14px;color:var(--steel)}.empty i{display:block;margin-bottom:10px;font-size:28px;color:#cbd5e1}@media(max-width:480px){.shell{padding:20px 12px}.delivery{padding:13px 14px}.amount{font-size:14px}}
</style>
<link href="../css/rider-theme.css?v=20260909-logo" rel="stylesheet">
<style>
.topbar span{color:#c0f1fc}
.back:focus-visible{outline:3px solid #38c9df;outline-offset:3px}
.heading h1{color:#153d5d}
.heading i,.address i{color:var(--teal)}
.delivery{box-shadow:0 8px 24px #174b7010}
.delivery>div:first-child{min-width:0;overflow-wrap:anywhere}
.delivery>div:last-child{flex-shrink:0;text-align:right}
.name{line-height:1.4;margin-bottom:4px}
.details{margin-top:4px}
.status{display:inline-block;margin-top:6px;padding:4px 9px;border-radius:999px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700}
.empty i{color:var(--teal)}
@media(max-width:360px){.delivery{flex-wrap:wrap}.delivery>div:last-child{margin-left:auto}}
</style>
<style>
.history-filters{display:grid;gap:12px;margin-bottom:16px}.history-filters label{font-size:12px;font-weight:600;color:#36556c}.search-row,.date-row{display:flex;align-items:center;gap:8px}.history-filters input{min-width:0;border:1px solid #cbdce8;border-radius:10px;padding:12px;background:white;color:#153d5d;font:inherit;font-size:14px}.search-row input{flex:1;width:0}.history-filters button{border:1px solid #cbdce8;border-radius:10px;padding:11px 12px;min-height:44px;background:#fff;color:#153d5d;font:600 12px Inter,sans-serif;cursor:pointer}.search-row button,.date-filters button[aria-pressed="true"]{background:#1264a3;border-color:#1264a3;color:white}.date-filters{display:flex;flex-wrap:wrap;gap:6px}.date-filters button{flex:1;white-space:nowrap}.date-row input{flex:1;width:0}.results-summary{font-size:12px;color:#536e82;overflow-wrap:anywhere}.day{font-size:13px;margin-top:22px}.day span{font-weight:500}.delivery-row{background:white;border:1px solid #dce8ef;border-radius:12px;margin-bottom:8px;overflow:hidden}.delivery-row summary{display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;list-style:none}.delivery-row summary::-webkit-details-marker{display:none}.delivery-customer{flex:1;min-width:0}.delivery-row .name{font-size:13px;overflow-wrap:anywhere;margin:0 0 3px}.delivery-row time{font-size:11px;color:#536e82}.delivery-row .amount{font-size:13px;flex-shrink:0}.delivery-row summary>i{font-size:10px;color:#536e82}.delivery-row[open] summary>i{transform:rotate(180deg)}.delivery-details{padding:12px;border-top:1px solid #e5eef4;overflow-wrap:anywhere}.empty a{display:inline-block;margin-top:14px;color:#1264a3}.history-filters :focus-visible,.delivery-row summary:focus-visible{outline:3px solid #0891b2;outline-offset:2px}
</style>
</head>
<body>
<header class="topbar"><a class="back" href="dashboard.php" aria-label="Back to dashboard"><i class="fas fa-arrow-left"></i></a><div><b>Delivery History</b><span>Rider Portal</span></div></header>
<main class="shell">
  <form class="history-filters" method="get" role="search" aria-label="Find completed deliveries">
    <label for="history-search">Find a delivery</label>
    <div class="search-row">
      <input id="history-search" type="search" name="q" value="<?= $escape($query) ?>" placeholder="Name, address or transaction #">
      <button type="submit">Search</button>
    </div>
    <input type="hidden" name="period" value="<?= $escape($period) ?>">
    <div class="date-filters" aria-label="Delivery dates">
      <?php foreach ($periods as $value => $label): if ($value === 'date') continue; ?>
        <button type="submit" name="period" value="<?= $value ?>" aria-pressed="<?= $period === $value ? 'true' : 'false' ?>"><?= $label ?></button>
      <?php endforeach; ?>
    </div>
    <div class="date-row">
      <label for="history-date">Specific date</label>
      <input id="history-date" type="date" name="date" value="<?= $escape($date) ?>" required>
      <button type="submit" name="period" value="date">Go</button>
    </div>
  </form>
  <p class="results-summary" role="status"><?= $result->num_rows ?> <?= $result->num_rows === 1 ? 'delivery' : 'deliveries' ?> &middot; <?= $period === 'date' ? $escape($selectedDate->format('M j, Y')) : $periods[$period] ?><?= $query !== '' ? ' &middot; Matching ?' . $escape($query) . '?' : '' ?></p>
  <?php if (empty($history)): ?>
    <div class="empty"><i class="fas fa-inbox" aria-hidden="true"></i>No deliveries found for these filters.<br><a href="history.php?period=all">View all history</a></div>
  <?php else: foreach ($history as $day => $deliveries): ?>
    <h2 class="day"><?= $day === $today->format('Y-m-d') ? 'Today' : $escape(date('D, M j, Y', strtotime($day))) ?> <span>&middot; <?= count($deliveries) ?> <?= count($deliveries) === 1 ? 'delivery' : 'deliveries' ?></span></h2>
    <?php foreach ($deliveries as $delivery): ?>
      <details class="delivery-row">
        <summary>
          <span class="delivery-customer"><strong class="name"><?= $escape($delivery['customer']) ?></strong><time><?= date('h:i A', strtotime($delivery['updated_at'])) ?></time></span>
          <strong class="amount">&#8369;<?= number_format((float)$delivery['amount'], 2) ?></strong>
          <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="delivery-details"><span class="address"><i class="fas fa-location-dot" aria-hidden="true"></i> <?= $escape($delivery['address']) ?></span><span class="details">Transaction #<?= $escape($delivery['transaction_id']) ?></span></div>
      </details>
    <?php endforeach; ?>
  <?php endforeach; endif; ?>
</main>
</body>
</html>
