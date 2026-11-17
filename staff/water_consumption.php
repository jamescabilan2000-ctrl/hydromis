<?php
require_once 'check_auth.php';
require_once '../config/database.php';

$period = strtolower((string)($_GET['period'] ?? 'week'));
if (!in_array($period, ['week','month','year'], true)) $period = 'week';
$selectedWeek = preg_match('/^\d{4}-W\d{2}$/', (string)($_GET['week'] ?? '')) ? $_GET['week'] : date('o-\WW');
$selectedMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$selectedYear = preg_match('/^\d{4}$/', (string)($_GET['year'] ?? '')) ? (int)$_GET['year'] : (int)date('Y');
$buckets = [];

if ($period === 'week') {
    [$isoYear,$isoWeek] = array_map('intval', explode('-W',$selectedWeek));
    $start = (new DateTime())->setISODate($isoYear,$isoWeek)->setTime(0,0,0);
    $end = (clone $start)->modify('+7 days');
    $safeStart = $conn->real_escape_string($start->format('Y-m-d H:i:s'));
    $safeEnd = $conn->real_escape_string($end->format('Y-m-d H:i:s'));
    $condition = "updated_at >= '$safeStart' AND updated_at < '$safeEnd'";
    $bucketExpression = 'WEEKDAY(updated_at)';
    for ($i=0;$i<7;$i++) $buckets[(string)$i]=['label'=>(clone $start)->modify("+$i days")->format('D'),'gallons'=>0,'orders'=>0];
    $label = $start->format('M d') . ' – ' . (clone $end)->modify('-1 day')->format('M d, Y');
    $divisor = 7;
} elseif ($period === 'month') {
    $safeMonth = $conn->real_escape_string($selectedMonth);
    $condition = "DATE_FORMAT(updated_at,'%Y-%m')='$safeMonth'";
    $bucketExpression = "FLOOR((DAY(updated_at)-1)/7)";
    for ($i=0;$i<5;$i++) $buckets[(string)$i]=['label'=>'W'.($i+1),'gallons'=>0,'orders'=>0];
    $label = date('F Y',strtotime($selectedMonth.'-01'));
    $divisor = (int)date('t',strtotime($selectedMonth.'-01'));
} else {
    $condition = "YEAR(updated_at)={$selectedYear}";
    $bucketExpression = 'MONTH(updated_at)';
    for ($i=1;$i<=12;$i++) $buckets[(string)$i]=['label'=>date('M',mktime(0,0,0,$i,1)),'gallons'=>0,'orders'=>0];
    $label = (string)$selectedYear;
    $divisor = 365;
}

$result = $conn->query("SELECT {$bucketExpression} AS bucket,COALESCE(SUM(quantity),0) AS gallons,COUNT(*) AS orders
    FROM transactions
    WHERE status='approved' AND delivery_status='delivered' AND {$condition}
      AND transaction_id NOT LIKE 'RWD-%' AND COALESCE(description,'') NOT LIKE 'Reward Redemption - %'
    GROUP BY bucket ORDER BY bucket");
if ($result) while ($row=$result->fetch_assoc()) {
    $key=(string)$row['bucket'];
    if (isset($buckets[$key])) {
        $buckets[$key]['gallons']=(int)$row['gallons'];
        $buckets[$key]['orders']=(int)$row['orders'];
    }
}
$totalGallons=array_sum(array_column($buckets,'gallons'));
$totalOrders=array_sum(array_column($buckets,'orders'));
$maxGallons=max(1,...array_column($buckets,'gallons'));
$staff_active='consumption';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Water Consumption — HydroMIS</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="../css/staff-sidebar.css" rel="stylesheet"><link href="../css/staff-sidebar-size.css" rel="stylesheet"><link href="../css/staff-pages-unified.css" rel="stylesheet">
<style>.filter-card{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:22px;padding:16px 20px}.filter-field{display:flex;flex-direction:column;gap:6px}.filter-field label{color:var(--staff-muted);font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.filter-field select,.filter-field input{min-height:42px;padding:8px 12px;border:1px solid var(--staff-border);border-radius:10px;background:var(--staff-surface-2);color:var(--staff-text);font:700 12px 'Plus Jakarta Sans',sans-serif}.period-label{margin-left:auto;align-self:center;color:var(--staff-muted);font-size:12px}.dot{display:inline-block;width:10px;height:10px;margin-right:10px;border-radius:50%;background:#3b82f6}.dot.green{background:#10b981}.card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 0;border-bottom:1px solid var(--staff-border)}.card-title{color:var(--staff-text);font-weight:800}.card-meta{color:var(--staff-muted);font-size:12px}@media(max-width:650px){.period-label{width:100%;margin-left:0}.filter-field{width:100%}.filter-field select,.filter-field input{width:100%}}</style>
</head><body><div class="layout">
<?php include 'sidebar.php'; ?>
<main class="main"><header class="topbar"><div><div class="topbar-title">Water Consumption</div><div class="topbar-subtitle">Completed gallon volume by week, month, or year</div></div></header>
<section class="page">
<form method="get" id="consumptionFilter" class="card filter-card">
  <div class="filter-field"><label for="period">Water consumption period</label><select id="period" name="period"><option value="week" <?=$period==='week'?'selected':''?>>Weekly</option><option value="month" <?=$period==='month'?'selected':''?>>Monthly</option><option value="year" <?=$period==='year'?'selected':''?>>Yearly</option></select></div>
  <div class="filter-field period-value" data-period="week"><label>Select week</label><input type="week" name="week" value="<?=htmlspecialchars($selectedWeek)?>"></div>
  <div class="filter-field period-value" data-period="month"><label>Select month</label><input type="month" name="month" value="<?=htmlspecialchars($selectedMonth)?>"></div>
  <div class="filter-field period-value" data-period="year"><label>Select year</label><input type="number" min="2020" max="<?=date('Y')+1?>" name="year" value="<?=$selectedYear?>"></div>
  <span class="period-label"><i class="fas fa-calendar"></i> <?=htmlspecialchars($label)?></span>
</form>
<section class="analytics-grid">
  <div class="card analytics-chart-card"><div class="card-head"><div class="card-title"><span class="dot"></span>Water Consumption</div><span class="card-meta"><?=number_format($totalGallons)?> gallons consumed</span></div><div class="analytics-bars">
  <?php foreach($buckets as $bucket): ?><div class="analytics-bar-item" title="<?=number_format($bucket['gallons'])?> gallons · <?=$bucket['orders']?> completed orders"><span><?=number_format($bucket['gallons'])?></span><div class="analytics-track"><i style="height:<?=$bucket['gallons']>0?max(5,round(($bucket['gallons']/$maxGallons)*100)):0?>%;background:linear-gradient(180deg,#22d3ee,#0ea5e9)"></i></div><small><?=htmlspecialchars($bucket['label'])?></small></div><?php endforeach; ?>
  </div></div>
  <div class="card analytics-summary"><div class="card-head"><div class="card-title"><span class="dot green"></span><?=htmlspecialchars($label)?></div></div><div class="analytics-metric"><i class="fas fa-bottle-water"></i><div><strong><?=number_format($totalGallons)?></strong><span>Total gallons consumed</span></div></div><div class="analytics-metric"><i class="fas fa-circle-check"></i><div><strong><?=number_format($totalOrders)?></strong><span>Completed orders</span></div></div><div class="analytics-metric"><i class="fas fa-chart-line"></i><div><strong><?=number_format($totalGallons/max(1,$divisor),1)?></strong><span>Average gallons per day</span></div></div></div>
</section></section></main></div>
<script>const form=document.getElementById('consumptionFilter'),period=document.getElementById('period');function sync(){document.querySelectorAll('.period-value').forEach(field=>field.style.display=field.dataset.period===period.value?'flex':'none')}period.addEventListener('change',()=>{sync();form.requestSubmit()});form.querySelectorAll('input').forEach(input=>input.addEventListener('change',()=>form.requestSubmit()));sync();</script>
<?php require_once __DIR__.'/../config/logout_dialog.php'; ?>
</body></html>
