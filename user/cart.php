<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$cart = $_SESSION['hydromis_cart'] ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $key = $_POST['key'] ?? '';
    if ($action === 'add') {
        $size = $_POST['container_size'] ?? '5gal-round'; $status = $_POST['container_status'] ?? 'existing';
        if (in_array($size, ['5gal-round','5gal-slim','2.5gal-slim'], true) && in_array($status, ['new','existing'], true)) {
            $key = $size . ':' . $status;
            $cart[$key] = ['container_size'=>$size,'container_status'=>$status,'quantity'=>max(1, (int)($cart[$key]['quantity'] ?? 0) + 1)];
        }
    }
    if ($action === 'remove' && isset($cart[$key])) unset($cart[$key]);
    if ($action === 'quantity' && isset($cart[$key])) $cart[$key]['quantity'] = max(1, min(99, (int)($_POST['quantity'] ?? 1)));
    $_SESSION['hydromis_cart'] = $cart;
    header('Location: cart.php'); exit;
}
$names=['5gal-round'=>'19L Round','5gal-slim'=>'19L Slim','2.5gal-slim'=>'9.5L Slim'];
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Cart | HydroMIS</title><style>body{font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;background:#f5fbfe;color:#0b314c}h1{margin-bottom:8px}.card{background:#fff;border:1px solid #d9e7ef;border-radius:16px;padding:18px;margin:12px 0}.row{display:flex;justify-content:space-between;align-items:center;gap:12px}.muted{color:#668195;font-size:14px}button,a{padding:10px 14px;border:0;border-radius:9px;font-weight:bold;text-decoration:none;cursor:pointer}.primary{background:#147fc7;color:#fff}.danger{background:#fff0f0;color:#b42318}.qty{width:62px;padding:9px;border:1px solid #ccdce6;border-radius:8px}.actions{display:flex;gap:10px;margin-top:22px}</style></head><body><h1>Your cart</h1><p class="muted">Review your selected water products before secure checkout.</p><?php if (!$cart): ?><div class="card"><strong>Your cart is empty.</strong><p class="muted">Return to the homepage and add a gallon product.</p></div><?php else: foreach($cart as $key=>$item): ?><div class="card"><div class="row"><div><strong><?php echo htmlspecialchars($names[$item['container_size']]); ?> <?php echo $item['container_status']==='new'?'New Gallon':'Refill'; ?></strong><div class="muted">Quantity: <?php echo (int)$item['quantity']; ?></div></div><form method="post"><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?php echo htmlspecialchars($key); ?>"><button class="danger">Remove</button></form></div></div><?php endforeach; ?><div class="actions"><a class="primary" href="scan_qr.php">Log in to checkout</a><a href="../home.php">Continue ordering</a></div><?php endif; ?></body></html>
