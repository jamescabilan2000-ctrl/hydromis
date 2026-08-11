<?php
require_once '../config/database.php';
require_once '../config/system_settings.php';

$transactionId = sanitize(trim((string)($_GET['transaction_id'] ?? '')));
$userId = sanitize(trim((string)($_GET['user_id'] ?? '')));
if ($transactionId === '' || $userId === '') {
    header('Location: track_order.php');
    exit;
}

$stmt = $conn->prepare("SELECT t.transaction_id,t.delivery_status,t.status,t.user_id,u.address,ru.full_name AS rider_name,ru.contact_number AS rider_contact
    FROM transactions t
    JOIN users u ON u.user_id=t.user_id
    LEFT JOIN rider_users ru ON ru.rider_id=t.rider_id
    WHERE t.transaction_id=? AND t.user_id=? AND t.status='approved' AND COALESCE(t.fulfillment_method,'delivery')='delivery' LIMIT 1");
$stmt->bind_param('ss', $transactionId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    header('Location: track_order.php?user_id=' . urlencode($userId));
    exit;
}
$status = strtolower((string)($order['delivery_status'] ?? 'pending'));
$statusLabel = in_array($status, ['on_way','on_the_way'], true) ? 'On the way' : ucfirst(str_replace('_', ' ', $status));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Live Delivery Map - HydroMIS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#edf7fa;color:#10263a;font-family:Manrope,"Segoe UI",sans-serif}.page{min-height:100vh;display:flex;flex-direction:column}.top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#fff;border-bottom:1px solid #dce9ef}.back{display:inline-flex;align-items:center;gap:8px;color:#1769d2;text-decoration:none;font-weight:800;font-size:13px}.status{padding:7px 11px;border-radius:999px;background:#e5f0ff;color:#1769d2;font-size:11px;font-weight:800}.heading{padding:18px;background:#fff}.heading h1{margin:0 0 5px;font-size:20px}.heading p{margin:0;color:#70879a;font-size:11px}.map-wrap{position:relative;flex:1;min-height:480px}#map{position:absolute;inset:0}.live-card{position:absolute;z-index:500;left:14px;right:14px;bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border:1px solid rgba(255,255,255,.9);border-radius:16px;background:rgba(255,255,255,.94);box-shadow:0 12px 35px rgba(16,38,58,.2);backdrop-filter:blur(10px)}.rider{display:flex;align-items:center;gap:10px}.rider-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#dcf8ef;color:#07947d}.rider strong{display:block;font-size:13px}.rider small{display:block;margin-top:3px;color:#7890a2;font-size:10px}.call{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:#1769d2;color:#fff;text-decoration:none}.marker{width:38px;height:38px;border:4px solid #fff;border-radius:50%;display:grid;place-items:center;background:#0aa98d;color:#fff;box-shadow:0 5px 16px rgba(0,0,0,.25)}.leaflet-div-icon{border:0;background:transparent}@media(max-width:600px){.map-wrap{min-height:calc(100vh - 139px)}.heading{padding:14px 16px}.heading h1{font-size:17px}}
</style>
</head>
<body><main class="page">
<header class="top"><a class="back" href="track_order.php?user_id=<?php echo urlencode($userId); ?>"><i class="fas fa-arrow-left"></i> Back to orders</a><span class="status" id="statusLabel"><?php echo htmlspecialchars($statusLabel); ?></span></header>
<section class="heading"><h1>Live delivery map</h1><p>Order <?php echo htmlspecialchars($transactionId); ?> · Rider location refreshes automatically</p></section>
<section class="map-wrap"><div id="map"></div><div class="live-card"><div class="rider"><span class="rider-icon"><i class="fas fa-motorcycle"></i></span><div><strong id="riderName">Your delivery rider</strong><small id="lastUpdate">Waiting for live location…</small></div></div><a class="call" id="callRider" href="#" hidden aria-label="Call rider"><i class="fas fa-phone"></i></a></div></section>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const transactionId=<?php echo json_encode($transactionId); ?>;
const station=[9.9403,123.9517];
const map=L.map('map').setView(station,14);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
L.marker(station).addTo(map).bindTooltip('HydroMIS Station · Guiwanon, Tubigon');
let riderMarker=null,route=null;
const icon=L.divIcon({className:'',html:'<div class="marker"><i class="fas fa-motorcycle"></i></div>',iconSize:[38,38],iconAnchor:[19,19]});
function labelStatus(value){value=(value||'pending').toLowerCase();return value==='on_way'||value==='on_the_way'?'On the way':value.replaceAll('_',' ').replace(/^./,c=>c.toUpperCase())}
function refresh(){fetch('../api/delivery_tracker.php?request=get_rider_location&transaction_id='+encodeURIComponent(transactionId)).then(r=>r.json()).then(result=>{if(!result.success)return;const d=result.data||{};document.getElementById('statusLabel').textContent=labelStatus(d.delivery_status);if(d.rider_name)document.getElementById('riderName').textContent=d.rider_name;if(d.rider_contact_number){const call=document.getElementById('callRider');call.href='tel:'+d.rider_contact_number;call.hidden=false}if(!d.has_live_location||!d.rider_location){document.getElementById('lastUpdate').textContent='Waiting for rider GPS…';return}const point=[Number(d.rider_location.latitude),Number(d.rider_location.longitude)];if(!riderMarker)riderMarker=L.marker(point,{icon}).addTo(map);else riderMarker.setLatLng(point);if(route)map.removeLayer(route);route=L.polyline([station,point],{color:'#1769d2',weight:4,dashArray:'8 8'}).addTo(map);map.fitBounds(L.latLngBounds([station,point]),{padding:[45,45],maxZoom:16});document.getElementById('lastUpdate').textContent='Location updated just now'}).catch(()=>{document.getElementById('lastUpdate').textContent='Unable to refresh location'});}
refresh();setInterval(refresh,5000);
</script></body></html>
