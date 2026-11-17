<?php
require_once '../config/database.php';
require_once '../config/delivery_destination.php';
require_once '../config/system_settings.php';
require_once '../config/maps.php';
$mapsConfig=hydromis_maps_config();

$transactionId = trim((string)($_GET['transaction_id'] ?? ''));
$userId = trim((string)($_GET['user_id'] ?? ''));
require_once '../config/customer_order_access.php';
require_customer_order_access($userId);
if ($transactionId === '' || $userId === '') {
    header('Location: track_order.php');
    exit;
}

$stmt = $conn->prepare("SELECT t.transaction_id,t.delivery_status,t.status,t.user_id,t.delivery_latitude,t.delivery_longitude,COALESCE(NULLIF(t.delivery_address,''),u.address) AS address,ru.full_name AS rider_name,ru.contact_number AS rider_contact
    FROM transactions t
    JOIN users u ON u.user_id=t.user_id
    LEFT JOIN rider_users ru ON ru.rider_id=COALESCE(NULLIF(t.rider_id,''),t.assigned_rider)
    WHERE t.transaction_id=? AND t.user_id=? AND t.status='approved' AND COALESCE(t.fulfillment_method,'delivery')='delivery' LIMIT 1");
$stmt->bind_param('ss', $transactionId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    header('Location: track_order.php?user_id=' . urlencode($userId));
    exit;
}
$destination = delivery_destination($order['delivery_latitude'] ?? null, $order['delivery_longitude'] ?? null);
$deliveryAddress = trim((string)($order['address'] ?? ''));
$status = strtolower((string)($order['delivery_status'] ?? 'pending'));
$statusLabel = in_array($status, ['on_way','on_the_way'], true) ? 'On the way' : ucfirst(str_replace('_', ' ', $status));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Real-time Delivery Map - HydroMIS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/v3.33.0/mapbox-gl.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#edf7fa;color:#10263a;font-family:Manrope,"Segoe UI",sans-serif}.page{min-height:100vh;display:flex;flex-direction:column}.top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#fff;border-bottom:1px solid #dce9ef}.back{display:inline-flex;align-items:center;gap:8px;color:#1769d2;text-decoration:none;font-weight:800;font-size:13px}.status{padding:7px 11px;border-radius:999px;background:#e5f0ff;color:#1769d2;font-size:11px;font-weight:800}.heading{padding:18px;background:#fff}.heading h1{margin:0 0 5px;font-size:20px}.heading p{margin:0;color:#70879a;font-size:11px}.map-wrap{position:relative;flex:1;min-height:480px}#map{position:absolute;inset:0}.live-card{position:absolute;z-index:500;left:14px;right:14px;bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border:1px solid rgba(255,255,255,.9);border-radius:16px;background:rgba(255,255,255,.94);box-shadow:0 12px 35px rgba(16,38,58,.2);backdrop-filter:blur(10px)}.rider{display:flex;align-items:center;gap:10px}.rider-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#dcf8ef;color:#07947d}.rider strong{display:block;font-size:13px}.rider small{display:block;margin-top:3px;color:#7890a2;font-size:10px}.call{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:#1769d2;color:#fff;text-decoration:none}.marker{width:38px;height:38px;border:4px solid #fff;border-radius:50%;display:grid;place-items:center;background:#0aa98d;color:#fff;box-shadow:0 5px 16px rgba(0,0,0,.25)}.leaflet-div-icon{border:0;background:transparent}@media(max-width:600px){.map-wrap{min-height:calc(100vh - 139px)}.heading{padding:14px 16px}.heading h1{font-size:17px}}
</style>
<link rel="stylesheet" href="../css/rider-map-marker.css?v=3"><link rel="stylesheet" href="../css/customer-delivery-eta.css?v=1"></head>
<body><main class="page">
<header class="top"><a class="back" href="track_order.php?user_id=<?php echo urlencode($userId); ?>"><i class="fas fa-arrow-left"></i> Back to orders</a><span class="status" id="statusLabel"><?php echo htmlspecialchars($statusLabel); ?></span></header>
<section class="heading"><h1>Real-time delivery map</h1><p>Order <?php echo htmlspecialchars($transactionId); ?> · Rider location refreshes automatically</p><?php if(!$destination):?><p id="destinationNote"><?php echo $deliveryAddress !== '' ? 'Locating the saved delivery address for the arrival estimate.' : 'No delivery pin or address was saved for this order.'; ?></p><?php endif;?></section>
<section class="eta-panel" aria-label="Delivery arrival estimate"><div class="eta-grid" aria-live="polite"><div class="eta-stat"><i class="fas fa-route" aria-hidden="true"></i><div><small>Distance remaining</small><strong id="eta-distance">?</strong></div></div><div class="eta-stat"><i class="far fa-clock" aria-hidden="true"></i><div><small>Estimated travel time</small><strong id="eta-duration">?</strong></div></div><div class="eta-stat"><i class="fas fa-truck" aria-hidden="true"></i><div><small>Expected arrival</small><strong id="eta-arrival">?</strong></div></div></div><p id="eta-note">Waiting for the rider?s live location to estimate arrival.</p></section><section class="map-wrap"><p id="mapMessage" role="status" hidden style="position:absolute;z-index:2;top:10px;left:14px;right:14px;padding:12px;background:#fff;border-radius:10px;font-size:12px">The map could not load. Rider status will continue to update.</p><div id="map" data-mapbox-token="<?=htmlspecialchars($mapsConfig['mapbox_token'],ENT_QUOTES,'UTF-8')?>"></div><div class="live-card"><div class="rider"><span class="rider-icon"><i class="fas fa-motorcycle"></i></span><div><strong id="riderName">Your delivery rider</strong><small id="lastUpdate">Waiting for real-time location…</small></div></div><a class="call" id="callRider" href="#" hidden aria-label="Call rider"><i class="fas fa-phone"></i></a></div></section>
</main>
<script src="https://api.mapbox.com/mapbox-gl-js/v3.33.0/mapbox-gl.js"></script>
<script src="../js/customer-delivery-eta.js?v=1"></script>
<script>
const transactionId=<?php echo json_encode($transactionId); ?>;
let destination=<?= json_encode($destination) ?>;
const deliveryAddress=<?= json_encode($deliveryAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let map=null,riderMarker=null,routePoint=null,roadGeometry=null,roadFitted=false;
function markerElement(icon,color){
    const element=document.createElement('div');
    element.innerHTML=`<div class="marker" style="background:${color}"><i class="fas ${icon}" aria-hidden="true"></i></div>`;
    return element;
}
function syncRoute(){
    if(!map?.isStyleLoaded())return;
    const data={type:'FeatureCollection',features:roadGeometry?[{type:'Feature',properties:{},geometry:roadGeometry}]:[]};
    if(map.getSource('delivery-connection'))map.getSource('delivery-connection').setData(data);
    else {
        map.addSource('delivery-connection',{type:'geojson',data});
        map.addLayer({id:'delivery-connection-outline',type:'line',source:'delivery-connection',layout:{'line-join':'round','line-cap':'round'},paint:{'line-color':'#fff','line-width':10,'line-opacity':0.9}});
        map.addLayer({id:'delivery-connection',type:'line',source:'delivery-connection',layout:{'line-join':'round','line-cap':'round'},paint:{'line-color':'#6326ff','line-width':6}});
    }
    if(roadGeometry&&!roadFitted){
        const bounds=new mapboxgl.LngLatBounds();
        roadGeometry.coordinates.forEach(point=>bounds.extend(point));
        if(destination)bounds.extend([destination[1],destination[0]]);
        if(routePoint)bounds.extend([routePoint[1],routePoint[0]]);
        map.fitBounds(bounds,{padding:{top:65,bottom:135,left:55,right:55},maxZoom:18,duration:0});
        roadFitted=true;
    }
}
async function initializeDeliveryMap(){
if(!destination&&deliveryAddress){
    const note=document.getElementById('destinationNote');
    try{
        const token=document.getElementById('map').dataset.mapboxToken;
        const query=deliveryAddress+', Tubigon, Bohol, Philippines';
        const url=new URL('https://api.mapbox.com/geocoding/v5/mapbox.places/'+encodeURIComponent(query)+'.json');
        url.search=new URLSearchParams({access_token:token,country:'ph',proximity:'123.9622,9.9509',limit:'1',types:'address,poi,neighborhood,locality,place'});
        const response=await fetch(url,{signal:AbortSignal.timeout(10000)});
        const data=response.ok?await response.json():null;
        const center=data?.features?.[0]?.center;
        if(Array.isArray(center)&&center.length===2&&Number.isFinite(Number(center[0]))&&Number.isFinite(Number(center[1]))){
            destination=[Number(center[1]),Number(center[0])];
            if(note)note.textContent='Arrival estimate uses your saved delivery address: '+deliveryAddress+'.';
        }else if(note)note.textContent='The saved delivery address could not be located precisely. Add a map pin on your next order for an ETA.';
    }catch(_){if(note)note.textContent='The saved delivery address could not be located right now. Rider tracking remains available.';}
}
try{
    const token=document.getElementById('map').dataset.mapboxToken;
    if(typeof mapboxgl==='undefined'||!token.startsWith('pk.'))throw new Error('Map unavailable');
    map=new mapboxgl.Map({container:'map',accessToken:token,style:'mapbox://styles/mapbox/streets-v12',center:destination?[destination[1],destination[0]]:[123.9622,9.9509],zoom:destination?16:12});
    map.addControl(new mapboxgl.NavigationControl(),'top-right');
    map.addControl(new mapboxgl.FullscreenControl(),'top-right');
    if(destination){
        const customerPin=document.createElement('div');
        customerPin.className='customer-delivery-marker';
        customerPin.innerHTML='<span class="customer-delivery-badge"><img src="../imagess/customer-waiting-gallon-marker.png" alt="You are waiting for your water delivery" width="60" height="60"></span>';
        new mapboxgl.Marker({element:customerPin,anchor:'bottom'}).setLngLat([destination[1],destination[0]]).setPopup(new mapboxgl.Popup({offset:65}).setText('Your delivery location')).addTo(map);
    }
    map.on('load',syncRoute);
    map.on('error',()=>{document.getElementById('mapMessage').hidden=false;});
}catch(_){document.getElementById('mapMessage').hidden=false;}
refresh();
}
function labelStatus(value){value=(value||'pending').toLowerCase();return value==='on_way'||value==='on_the_way'?'On the way':value.replaceAll('_',' ').replace(/^./,c=>c.toUpperCase())}
let refreshTimer=null, stopped=false, fitted=false;
async function refresh(){
    if(stopped) return;
    const update=document.getElementById('lastUpdate');
    try {
        const response=await fetch('../api/delivery_tracker.php?request=get_rider_location&transaction_id='+encodeURIComponent(transactionId), {cache:'no-store', signal:AbortSignal.timeout(10000)});
        if(response.status===403 || response.status===401){stopped=true;throw new Error('Your session has expired. Return to orders and sign in again.');}
        if(!response.ok) throw new Error('Unable to refresh location. Retrying?');
        const result=await response.json();
        if(!result.success) throw new Error(result.error || 'Unable to refresh location.');
        const d=result.data||{};
        document.getElementById('statusLabel').textContent=labelStatus(d.delivery_status);
        if(d.rider_name) document.getElementById('riderName').textContent=d.rider_name;
        const call=document.getElementById('callRider');
        call.hidden=!d.rider_contact_number;
        if(d.rider_contact_number) call.href='tel:'+String(d.rider_contact_number).replace(/[^+0-9]/g,'');
        if(d.delivery_status==='delivered'){stopped=true;window.HydroMISCustomerETA.reset('Delivery completed.');roadGeometry=null;syncRoute();update.textContent='Delivery completed';return;}
        if(!d.has_live_location || !d.rider_location){
            if(riderMarker){riderMarker.remove();riderMarker=null;}
            routePoint=null;roadGeometry=null;syncRoute();window.HydroMISCustomerETA.reset('Waiting for the rider?s live location to estimate arrival.');
            update.textContent='Waiting for rider GPS?';return;
        }
        const point=[Number(d.rider_location.latitude),Number(d.rider_location.longitude)];
        if(!Number.isFinite(point[0]) || !Number.isFinite(point[1]) || Math.abs(point[0])>90 || Math.abs(point[1])>180) throw new Error('Waiting for a valid GPS position');
        if(!['on_way','on_the_way'].includes(String(d.delivery_status||'').toLowerCase())){roadGeometry=null;syncRoute();}
        window.HydroMISCustomerETA.update(point,destination,String(d.delivery_status||'').toLowerCase(),geometry=>{roadGeometry=geometry;syncRoute();});
        if(map){
            if(!riderMarker){
                const riderPin=document.createElement('div');
                riderPin.className='customer-delivery-marker station-location-marker';
                riderPin.innerHTML='<span class="customer-delivery-badge"><img src="../imagess/water-delivery-rider-marker.png" alt="Live water delivery rider" width="60" height="60"></span>';
                riderMarker=new mapboxgl.Marker({element:riderPin,anchor:'bottom'}).setLngLat([point[1],point[0]]).setPopup(new mapboxgl.Popup({offset:65}).setText('Your water delivery rider')).addTo(map);
            }
            else riderMarker.setLngLat([point[1],point[0]]);
            routePoint=point;syncRoute();
            if(!fitted){
                const bounds=new mapboxgl.LngLatBounds();
                (destination?[destination,point]:[point]).forEach(p=>bounds.extend([p[1],p[0]]));
                map.fitBounds(bounds,{padding:{top:55,bottom:125,left:45,right:45},maxZoom:16,duration:0});fitted=true;
            }
        }
        update.textContent=d.rider_location.last_update ? 'Last GPS update: '+d.rider_location.last_update : 'Rider location received';
    } catch(error){window.HydroMISCustomerETA.reset('Waiting for a fresh rider location to estimate arrival.');roadGeometry=null;syncRoute();update.textContent=error.message || 'Unable to refresh location';}
    finally {if(!stopped) refreshTimer=setTimeout(refresh,5000);}
}
window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(refreshTimer);window.HydroMISCustomerETA.reset('Tracking paused.');});
initializeDeliveryMap();
</script></body></html>
