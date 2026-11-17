<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/receipt_access.php';
require_once __DIR__.'/../config/order_items.php';
$id=trim((string)($_GET['transaction_id']??''));
$stmt=$conn->prepare('SELECT t.*,u.full_name,u.contact_number,u.address AS registered_address FROM transactions t JOIN users u ON u.user_id=t.user_id WHERE t.transaction_id=? LIMIT 1');
$stmt->bind_param('s',$id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();
if(!$order || !can_access_checkout_receipt($order,$_SESSION)) {http_response_code(403);exit('This receipt is only available in the browser that placed the order.');}
header('Cache-Control: no-store');
$stmt=$conn->prepare('SELECT container_size,container_status,quantity,unit_price,line_total FROM transaction_items WHERE transaction_id=? ORDER BY id');
$stmt->bind_param('s',$id);$stmt->execute();$rows=$stmt->get_result();
$items=[];$subtotal=0;$quantity=0;
while($line=$rows->fetch_assoc()){$items[]=$line;$subtotal+=(float)$line['line_total'];$quantity+=str_starts_with($line['container_size'],'cup')?0:(int)$line['quantity'];}
$sizeNames=['5gal-round'=>'19L Round','5gal-slim'=>'19L Slim','2.5gal-slim'=>'9.5L Slim'];
$photos=['cup-slim'=>'slimcup.jpg','cup-round'=>'Gemini_Generated_Image_bie8b4bie8b4bie8.jpg','5gal-round'=>'water5.webp','5gal-slim'=>'water4.webp','2.5gal-slim'=>'water3.jpg'];
$isPickup=$order['fulfillment_method']==='pickup';
$isPaid=$order['payment_status']==='paid';
$isCancelled=in_array($order['status'],['denied','cancelled'],true);
$paymentLabel=['cash'=>'Cash','gcash'=>'GCash','maya'=>'Maya'][$order['payment_method']]??ucfirst($order['payment_method']);
$statusLabel=$isCancelled?'Order cancelled':($order['delivery_status']==='delivered'?($isPickup?'Collected':'Delivered'):($order['status']==='approved'?'Order confirmed':'Order received'));
$paymentStatus=$isPaid?'Payment verified':($order['payment_status']==='processing'?'Awaiting verification':($order['payment_method']==='cash'?'Pay on '.($isPickup?'pickup':'delivery'):'Payment pending'));
$created=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',(string)$order['created_at']);
$displayDate=$created?$created->format('M j, Y'):(string)$order['created_at'];
$displayTime=$created?$created->format('g:i A'):'';
$address=trim((string)($order['delivery_address']??''));
if($address==='Delivery to selected map pin')$address='Deliver to the selected map pin';
if($address==='')$address=(string)($order['registered_address']??'Selected map pin');
$pinUrl=$order['delivery_latitude']!==null&&$order['delivery_longitude']!==null?'https://www.openstreetmap.org/?mlat='.rawurlencode($order['delivery_latitude']).'&mlon='.rawurlencode($order['delivery_longitude']).'#map=17/'.rawurlencode($order['delivery_latitude']).'/'.rawurlencode($order['delivery_longitude']):null;
function receipt_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Order Receipt <?=receipt_h($id)?> | HydroMIS</title>
    <link rel="stylesheet" href="../css/order-receipt.css?v=20261026">
</head>
<body>
<div class="receipt-page">
    <nav class="page-actions" aria-label="Receipt navigation">
        <a class="back-link" href="../home.php#quick-order"><span aria-hidden="true">&larr;</span> Back to home</a>
        <button class="button button-print" type="button" onclick="window.print()"><svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 8V3h12v5M6 17H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 13h12v8H6z"/></svg> Print / Save PDF</button>
    </nav>
    <main class="receipt" aria-labelledby="receipt-title">
        <header class="receipt-header">
            <a class="brand" href="../home.php"><img src="../imagess/hydromis-logo-v2.png" width="48" height="48" alt=""><span>Hydro<span class="brand-accent">MIS</span><small>WATER DELIVERY</small></span></a>
            <div class="document-type">CUSTOMER COPY<span>ORDER RECEIPT</span></div>
        </header>
        <section class="receipt-intro">
            <div><span class="status-badge <?=$isCancelled?'status-cancelled':''?>"><span aria-hidden="true"><?= $isCancelled?'&times;':'&#10003;' ?></span> <?=receipt_h($statusLabel)?></span><h1 id="receipt-title"><?=$isCancelled?'Your order receipt':'Thank you for your order.'?></h1><p class="muted"><?=$isCancelled?'Keep this receipt for your records.':'Your order details are saved below. Keep this receipt for reference.'?></p></div>
            <div class="order-reference"><span class="label">ORDER NUMBER</span><strong>#<?=receipt_h($id)?></strong><button type="button" class="copy-link no-print" id="copy-order" data-order-id="<?=receipt_h($id)?>">Copy order number</button><span class="copy-status" id="copy-status" role="status"></span></div>
        </section>
        <div class="receipt-meta"><span><span class="label">PLACED ON</span><strong><?=receipt_h($displayDate)?><span class="time"><?=receipt_h($displayTime)?></span></strong></span><span><span class="label">FULFILLMENT</span><strong><?=$isPickup?'Station pickup':'Home delivery'?></strong></span><span><span class="label">PAYMENT METHOD</span><strong><?=receipt_h($paymentLabel)?></strong></span></div>
        <section class="customer-grid" aria-label="Customer and delivery details">
            <div><h2>Customer details</h2><strong class="customer-name"><?=receipt_h($order['full_name'])?></strong><a class="phone" href="tel:<?=receipt_h(preg_replace('/[^+0-9]/','',(string)$order['contact_number']))?>"><?=receipt_h($order['contact_number'])?></a></div>
            <div><h2><?=$isPickup?'Collection details':'Delivery destination'?></h2><p class="destination"><?=$isPickup?'Pick up at the water station.':nl2br(receipt_h($address))?></p><?php if(!$isPickup&&$pinUrl):?><a class="map-link" href="<?=receipt_h($pinUrl)?>" target="_blank" rel="noopener noreferrer">View delivery pin <span aria-hidden="true">&nearr;</span></a><?php endif;?></div>
        </section>
        <section class="items-section" aria-labelledby="items-title">
            <div class="section-heading"><h2 id="items-title">Items ordered</h2><span><?=count($items)?> product<?=count($items)===1?'':'s'?> &middot; <?=$quantity?> gallon<?=$quantity===1?'':'s'?></span></div>
            <table class="items-table"><caption class="sr-only">Products, quantities and amounts for order <?=receipt_h($id)?></caption><thead><tr><th scope="col">Product</th><th scope="col" class="quantity-col">Qty</th><th scope="col" class="amount-col">Amount</th></tr></thead><tbody>
            <?php foreach($items as $item):?><tr><td><div class="product"><?php if(isset($photos[$item['container_size']])):?><img src="../imagess/<?=receipt_h($photos[$item['container_size']])?>" alt="" width="48" height="60"><?php endif;?><div><strong><?=str_starts_with($item['container_size'],'cup')?(['cup-slim'=>'Slim Cup','cup-round'=>'Round Cup'][$item['container_size']]??'Cups'):receipt_h($sizeNames[$item['container_size']]??$item['container_size']).' '.($item['container_status']==='new'?'New Gallon':'Refill')?></strong><?php if(!str_starts_with($item['container_size'],'cup')):?><small><?=$item['container_status']==='new'?'New container + first water fill':'Refill for your existing container'?></small><?php endif;?></div></div></td><td class="quantity-col"><span class="quantity-pill"><?=(int)$item['quantity']?></span></td><td class="amount-col">PHP <?=number_format($item['line_total'],2)?></td></tr><?php endforeach;?>
            </tbody></table>
        </section>
        <section class="payment-grid" aria-label="Payment and order total">
            <div class="payment-note"><span class="label">PAYMENT STATUS</span><strong class="payment-status <?=$isPaid?'verified':''?>"><span class="status-dot" aria-hidden="true"></span><?=receipt_h($paymentStatus)?></strong><p><?php if($isCancelled):?>Contact the station about any payment already submitted for this cancelled order.<?php elseif($isPaid):?>Your payment has been verified by station staff.<?php elseif($order['payment_method']==='cash'):?><?=$isPickup?'Please pay when you collect your order.':'Please prepare your payment for the rider upon delivery.'?><?php elseif(!empty($order['payment_proof'])):?>Your payment proof has been submitted. Station staff will verify the payment.<?php else:?>Submit your payment proof so station staff can verify it.<?php endif;?></p><?php if(!$isPaid):?><small>This is an order receipt, not confirmation of payment.</small><?php endif;?></div>
            <dl class="totals"><div><dt>Product subtotal</dt><dd>PHP <?=number_format($subtotal,2)?></dd></div><div><dt><?=$isPickup?'Station pickup':'Delivery fee'?></dt><dd><?=$isPickup?'Free':'PHP '.number_format(max(0,(float)$order['amount']-$subtotal),2)?></dd></div><div class="grand-total"><dt>Order total<span>PHP &middot; Philippine peso</span></dt><dd>PHP <?=number_format($order['amount'],2)?></dd></div></dl>
        </section>
        <?php if(trim((string)$order['notes'])!==''):?><section class="order-notes"><h2>Order notes</h2><p><?=nl2br(receipt_h($order['notes']))?></p></section><?php endif;?>
        <footer class="receipt-footer"><span>Thank you for choosing HydroMIS.</span><span>Order #<?=receipt_h($id)?></span></footer>
    </main>
    <div class="after-receipt no-print"><div class="footer-actions"><?php if(!$isCancelled&&$order['payment_method']!=='cash'&&!$isPaid&&empty($order['payment_proof'])):?><a class="button button-primary" href="payment.php?transaction_id=<?=rawurlencode($id)?>">Submit payment proof</a><?php endif;?><a class="button button-primary" href="../home.php#quick-order">Continue shopping <span aria-hidden="true">&rarr;</span></a><a class="button" href="scan_qr.php">Customer dashboard login</a></div><p>Print or save a PDF to keep a copy of your receipt.</p></div>
</div>
<script>
const copyButton=document.getElementById('copy-order');
copyButton.addEventListener('click',async()=>{const status=document.getElementById('copy-status');try{await navigator.clipboard.writeText(copyButton.dataset.orderId);status.textContent='Order number copied.';}catch{status.textContent='Select the order number above to copy it.';}});
</script>
</body></html>
