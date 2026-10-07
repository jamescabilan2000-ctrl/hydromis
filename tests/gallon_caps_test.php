<?php
require_once __DIR__ . '/../config/gallon_caps.php';
function check($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function rejects($fn, $label) { try { $fn(); } catch (InvalidArgumentException $e) { check(true, $label); return; } throw new RuntimeException($label); }
check(cap_order_values(false, 'invalid', 5.25) === ['cap_quantity'=>0,'cap_unit_price'=>0,'cap_subtotal'=>0], 'unchecked caps ignore submitted quantity');
foreach (['0','-1','1.2','1e2','abc','100000',[],null] as $bad) rejects(fn()=>cap_order_values(true,$bad,5.25), 'invalid cap quantity rejected');
check(cap_order_values(true,'3',5.25)['cap_subtotal'] === 15.75, 'cap quantity times configured price');
check(cap_order_values(true,'7',0.1)['cap_subtotal'] === 0.7, 'centavo calculation');
check(cap_order_values(true,'2',0.0)['cap_subtotal'] == 0.0, 'configured free caps');
rejects(fn()=>cap_order_values(true,'1',null),'unconfigured caps cannot be ordered');
foreach (['-1','1.234','1e2','100000',[]] as $bad) rejects(fn()=>cap_price_cents($bad),'invalid configured price rejected');
$old = ['quantity'=>2,'price_per_unit'=>40,'amount'=>100];
check(order_price_breakdown($old) === ['water'=>80.0,'caps'=>0.0,'discount'=>0.0,'delivery'=>20.0,'total'=>100.0], 'legacy order defaults to zero caps');
$order = ['quantity'=>3,'price_per_unit'=>26.67,'order_water_subtotal'=>80,'amount'=>125.75] + cap_order_values(true,'3',5.25);
check(order_price_breakdown($order)['delivery'] === 30.0, 'exact water subtotal preserves volume pricing');
$priceChanged = cap_order_values(true,'3',9.0);
check(order_price_breakdown($order)['caps']===15.75 && $priceChanged['cap_subtotal']==27.0,'price changes affect new snapshots only');
$order['amount']=95.75;
check(order_price_breakdown($order)['delivery']===0.0,'pickup or free delivery');
$order['quantity']=5;$order['price_per_unit']=15;$order['order_water_subtotal']=75;$order['amount']=140.75;
check(order_price_breakdown($order)['water']===75.0 && order_price_breakdown($order)['total']===140.75,'changed water quantity total');
ob_start(); render_order_caps($old); $html=ob_get_clean();
check(str_contains($html,'No caps requested') && str_contains($html,'Order Details'),'legacy display');
ob_start(); render_order_caps($order); $html=ob_get_clean();
check(str_contains($html,'Caps requested: 3') && str_contains($html,'15.75'),'cap breakdown display');
// Database reporting tests use a connection-local temporary table only.
require_once __DIR__ . '/../config/database_config.php';
$c=hydromis_database_config();
mysqli_report(MYSQLI_REPORT_OFF);
$db=@new mysqli($c['host'],$c['user'],$c['password'],$c['database'],(int)($c['port']??3306));
if ($db->connect_errno) { echo "SKIP database report tests: configured database unavailable\n"; exit(0); }
$db->query("CREATE TEMPORARY TABLE transactions (transaction_id VARCHAR(80), status VARCHAR(30), delivery_status VARCHAR(30), payment_status VARCHAR(30), created_at DATETIME, cap_quantity INT DEFAULT 0, cap_subtotal DECIMAL(12,2) DEFAULT 0)");
$insert=$db->prepare('INSERT INTO transactions VALUES (?,?,?,?,?,?,?)');
$rows=[
 ['A','pending','pending','pending','2026-10-01 00:00:00',2,10],
 ['B','approved','delivered','paid','2026-10-02 12:00:00',3,15.75],
 ['C','approved','delivered','pending','2026-10-02 13:00:00',4,20],
 ['D','cancelled','delivered','paid','2026-10-02 14:00:00',10,50],
 ['E','approved','cancelled','paid','2026-10-02 15:00:00',10,50],
 ['F','denied','pending','paid','2026-10-02 16:00:00',10,50],
 ['G','completed','completed','paid','2026-10-03 23:59:59',5,25],
 ['H','approved','delivered','paid','2026-10-04 00:00:00',6,30],
 ['RWD-1','approved','delivered','paid','2026-10-02 12:00:00',100,500],
 ['DEMO-1','approved','delivered','paid','2026-10-02 12:00:00',100,500],
 ['OLD','approved','delivered','paid','2026-10-02 12:00:00',0,0],
];
foreach ($rows as $r) { $insert->bind_param('sssssid',...$r);check($insert->execute(),'report fixture inserted'); }
$range=" AND created_at >= '2026-10-01 00:00:00' AND created_at < '2026-10-04 00:00:00'";
$r=$db->query(cap_summary_sql($range))->fetch_assoc();
check((int)$r['requested']===14 && (int)$r['fulfilled']===12 && (float)$r['sales']===40.75,'date boundaries, paid completion, exclusions, no double counting');
$r=$db->query(cap_summary_sql(" AND created_at >= '2026-10-02 00:00:00' AND created_at < '2026-10-03 00:00:00'"))->fetch_assoc();
check((int)$r['requested']===7 && (int)$r['fulfilled']===7 && (float)$r['sales']===15.75,'single date totals');
$r=$db->query("SELECT COUNT(*) n FROM transactions WHERE status='approved' AND cap_quantity > 0 AND created_at >= '2026-10-02' AND created_at < '2026-10-03'")->fetch_assoc();
check((int)$r['n']===5,'combined status date cap filter');
$r=$db->query("SELECT COUNT(*) n FROM transactions WHERE cap_quantity=0")->fetch_assoc();
check((int)$r['n']===1,'no caps filter includes older orders');
$db->close();
echo "All gallon cap checks passed.\n";
