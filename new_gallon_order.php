<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$setupCustomerId = (string)($_SESSION['customer_user_id'] ?? '');
// Public order setup. Final ordering remains behind the customer sign-in flow.
define('HYDROMIS_ALLOW_DB_FAILURE', true);
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/config/gallon_availability.php';
$gallonStock=gallon_inventory_snapshot($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start a New Gallon Order | HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root{--ink:#0a304b;--muted:#678093;--line:#dce9f0;--blue:#147fc7;--aqua:#2dc8df;--pale:#f5fbfe}*{box-sizing:border-box}body{margin:0;background:var(--pale);font-family:'DM Sans',sans-serif;color:var(--ink)}h1,h2,h3{font-family:Sora,sans-serif;margin:0}.topbar{height:68px;background:#082b49;display:flex;align-items:center}.topbar-inner{width:min(1160px,100%);margin:auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:10px;color:#f2fbff;text-decoration:none;font-family:Sora,sans-serif;font-weight:800;font-size:1.18rem}.brand img{width:38px;height:38px;object-fit:contain}.brand-word span{color:#48d8ed}.top-link{color:#d1e2ed;text-decoration:none;font-size:.88rem;font-weight:700}.top-link:hover{color:#fff}.page{width:min(1160px,100%);margin:0 auto;padding:48px 24px 72px}.eyebrow{display:inline-flex;gap:7px;align-items:center;color:#1682bd;font-size:.72rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.intro{display:flex;justify-content:space-between;gap:30px;margin:12px 0 34px}.intro h1{max-width:640px;font-size:clamp(2rem,4vw,3rem);line-height:1.15;letter-spacing:-.05em}.intro p{max-width:390px;margin:7px 0 0;color:var(--muted);line-height:1.65}.progress{display:flex;gap:9px;align-items:center;margin-bottom:25px;color:#7490a1;font-size:.77rem;font-weight:700}.progress span{display:flex;align-items:center;gap:7px}.progress i{width:24px;height:24px;display:grid;place-items:center;border-radius:50%;font-style:normal;color:#fff;background:#1682bd;font-size:.7rem}.progress b{width:38px;height:1px;background:#cddfe8}.layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:25px;align-items:start}.setup{padding:27px;border:1px solid var(--line);border-radius:22px;background:#fff;box-shadow:0 14px 34px rgba(17,62,88,.06)}.section+.section{margin-top:27px;padding-top:27px;border-top:1px solid #e8f0f4}.section-head{display:flex;gap:12px;align-items:flex-start;margin-bottom:17px}.section-number{width:28px;height:28px;display:grid;place-items:center;flex:0 0 auto;border-radius:9px;background:#e4f5fb;color:#1480bd;font-weight:800;font-size:.78rem}.section-head h2{font-size:1.02rem}.section-head p{margin:4px 0 0;color:var(--muted);font-size:.84rem}.size-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}.size-option{position:relative;padding:16px 13px;border:1px solid var(--line);border-radius:14px;cursor:pointer;transition:.2s}.size-option:hover,.size-option.selected{border-color:#43bfe0;background:#f1fbfe;box-shadow:0 7px 16px rgba(20,127,199,.08)}.size-option input{position:absolute;opacity:0}.size-option i{display:block;margin-bottom:10px;color:#1682bd;font-size:1.2rem}.size-option strong{display:block;font-size:.84rem}.size-option span{display:block;margin-top:3px;color:var(--muted);font-size:.72rem}.order-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 0}.order-row+.order-row{border-top:1px solid #edf3f6}.order-row strong{display:block;font-size:.87rem}.order-row small{display:block;margin-top:3px;color:var(--muted);font-size:.75rem}.quantity{display:flex;align-items:center;border:1px solid var(--line);border-radius:10px;overflow:hidden}.quantity button{width:34px;height:34px;border:0;background:#fff;color:#177fbd;cursor:pointer;font-size:1rem}.quantity button:hover{background:#eff9fd}.quantity output{width:34px;text-align:center;font-size:.85rem;font-weight:800}.toggle{appearance:none;width:39px;height:23px;border-radius:99px;background:#cfdee6;position:relative;cursor:pointer;transition:.2s}.toggle::after{content:'';position:absolute;width:17px;height:17px;top:3px;left:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18);transition:.2s}.toggle:checked{background:#23a8ce}.toggle:checked::after{transform:translateX(16px)}.fulfillment{display:grid;grid-template-columns:1fr 1fr;gap:11px}.fulfillment label{display:flex;align-items:center;gap:11px;padding:14px;border:1px solid var(--line);border-radius:13px;cursor:pointer}.fulfillment label.selected{border-color:#42bee0;background:#f2fbfe}.fulfillment input{accent-color:#1682bd}.fulfillment strong{display:block;font-size:.84rem}.fulfillment span{display:block;margin-top:3px;color:var(--muted);font-size:.72rem}.review{position:sticky;top:18px;padding:22px;border-radius:20px;color:#eaf8ff;background:linear-gradient(150deg,#0b3a5a,#07597b);box-shadow:0 20px 42px rgba(3,43,66,.22)}.review h2{font-size:1.1rem;color:#fff}.review-intro{margin:7px 0 18px;color:#b9d9e8;font-size:.8rem;line-height:1.55}.review-list{padding:0;margin:0;list-style:none}.review-list li{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-top:1px solid rgba(217,246,255,.14);font-size:.82rem}.review-list span{color:#bdd9e7}.review-list strong{color:#fff;text-align:right}.review-note{display:flex;gap:8px;margin:16px 0;color:#c1dce8;font-size:.72rem;line-height:1.5}.review-note i{color:#65e3f2;margin-top:2px}.continue{display:flex;justify-content:center;align-items:center;gap:9px;width:100%;padding:13px;border-radius:11px;color:#06334c;background:#e9fbff;text-decoration:none;font-size:.86rem;font-weight:800}.continue:hover{background:#fff}.login{display:block;margin-top:12px;color:#c7e1ec;text-align:center;text-decoration:none;font-size:.76rem;font-weight:700}.login:hover{color:#fff}@media(max-width:800px){.intro{display:block}.intro p{margin-top:13px}.layout{grid-template-columns:1fr}.review{position:static}.size-grid{grid-template-columns:1fr}.page{padding-top:32px}}@media(max-width:480px){.topbar-inner,.page{padding-left:17px;padding-right:17px}.top-link{font-size:.78rem}.setup{padding:19px}.fulfillment{grid-template-columns:1fr}.progress b{width:14px}}
            .gallon-product-photo{display:block;width:100%;height:140px;margin:0 auto 14px;object-fit:contain;border-radius:10px;background:#fff}
        .size:focus-within,.size-option:focus-within{outline:3px solid #43bfe0;outline-offset:3px}
        @media(max-width:800px){.gallon-product-photo{height:120px}}
    </style>
<link rel="stylesheet" href="css/gallon-stock.css?v=1"><link rel="stylesheet" href="css/order-cart-actions.css?v=1"></head>
<body>
    <header class="topbar"><div class="topbar-inner"><a href="home.php" class="brand"><img src="imagess/hydromis-logo-v2.png" alt="HydroMIS logo"><span class="brand-word">Hydro<span>MIS</span></span></a><a href="home.php" class="top-link"><i class="fa-solid fa-arrow-left"></i> Back to home</a></div></header>
    <main class="page">
        <span class="eyebrow"><i class="fa-solid fa-bottle-water"></i> New gallon order</span>
        <div class="intro"><h1>Set up your first gallon delivery.</h1><p>Choose your container and quantity. Select delivery or pickup and confirm payment in your cart.</p></div>
        <div class="progress" aria-label="Order setup progress"><span><i>1</i> Set up order</span><b></b><span><i>2</i> Review cart</span><b></b><span><i>3</i> Confirm &amp; pay</span></div>
        <div class="layout">
            <form class="setup" id="new-gallon-form">
                <section class="section"><div class="section-head"><span class="section-number">1</span><div><h2>Choose your gallon</h2><p>Select the container that works best for your home or office.</p></div></div><div class="size-grid">
                    <label class="size-option selected"><input type="radio" name="container_size" value="5gal-round" checked <?= !gallon_availability($gallonStock,'5gal-round',true)['available'] ? 'disabled' : '' ?>><img class="gallon-product-photo" src="imagess/water5.webp" alt="19L Round water container" width="160" height="140"><strong>19L Round</strong><span>Classic household gallon</span><?=gallon_stock_badge(gallon_availability($gallonStock,'5gal-round',true))?></label>
                    <label class="size-option"><input type="radio" name="container_size" value="5gal-slim" <?= !gallon_availability($gallonStock,'5gal-slim',true)['available'] ? 'disabled' : '' ?>><img class="gallon-product-photo" src="imagess/water4.webp" alt="19L Slim water container" width="160" height="140"><strong>19L Slim</strong><span>Space-saving shape</span><?=gallon_stock_badge(gallon_availability($gallonStock,'5gal-slim',true))?></label>
                    <label class="size-option"><input type="radio" name="container_size" value="2.5gal-slim" <?= !gallon_availability($gallonStock,'2.5gal-slim',true)['available'] ? 'disabled' : '' ?>><img class="gallon-product-photo" src="imagess/water3.jpg" alt="9.5L Slim water container" width="160" height="140"><strong>9.5L Slim</strong><span>Compact everyday option</span><?=gallon_stock_badge(gallon_availability($gallonStock,'2.5gal-slim',true))?></label>
                </div></section>
                <section class="section"><div class="section-head"><span class="section-number">2</span><div><h2>Choose quantity</h2><p>Add this gallon to your cart, then choose another size or refill if needed.</p></div></div>
                    <div class="order-row"><div><strong>New gallon with first refill</strong><small>Your clean container and purified-water fill.</small></div><div class="quantity"><button type="button" data-adjust="-1" aria-label="Decrease quantity">−</button><output id="quantity" for="quantity-input">1</output><button type="button" data-adjust="1" aria-label="Increase quantity">+</button><input type="hidden" id="quantity-input" name="quantity" value="1"></div></div>
                    </section>

            </form>
            <aside class="review" aria-live="polite"><h2>Your order setup</h2><p class="review-intro">No payment is taken yet.</p><ul class="review-list"><li><span>Container</span><strong id="review-size">19L Round</strong></li><li><span>Quantity</span><strong id="review-quantity">1 gallon</strong></li></ul><p class="review-note"><i class="fa-solid fa-circle-info"></i><span>Choose delivery or pickup and review your total and payment details in the cart.</span></p><a class="continue" data-add-setup id="continue-order" href="user/cart.php">Add to cart <i class="fa-solid fa-plus"></i></a><a class="login" data-view-cart id="login-to-continue" href="user/cart.php">View cart &rarr;</a></aside>
        </div>
    </main>
    <script>
const setupForm=document.getElementById('new-gallon-form');
const setupQuantity=document.getElementById('quantity-input');
function syncSetup(){
    let selected=setupForm.querySelector('input[name="container_size"]:checked:not(:disabled)')||setupForm.querySelector('input[name="container_size"]:not(:disabled)');
    if(selected)selected.checked=true;
    const addButton=document.querySelector('[data-add-setup]');
    addButton.setAttribute('aria-disabled',String(!selected));
    if(!selected){document.getElementById('review-size').textContent='No gallons available';return;}
    document.getElementById('review-size').textContent=selected.closest('.size-option').querySelector('strong').textContent;
    const quantity=Number(setupQuantity.value);
    document.getElementById('quantity').textContent=quantity;
    document.getElementById('review-quantity').textContent=`${quantity} ${quantity===1?'gallon':'gallons'}`;
    setupForm.querySelectorAll('.size-option').forEach(option=>option.classList.toggle('selected',option.contains(selected)));
}
setupForm.querySelectorAll('input[name="container_size"]').forEach(input=>input.addEventListener('change',syncSetup));
setupForm.querySelectorAll('[data-adjust]').forEach(button=>button.addEventListener('click',()=>{
    setupQuantity.value=Math.max(1,Math.min(99,Number(setupQuantity.value)+Number(button.dataset.adjust)));
    syncSetup();
}));
syncSetup();
</script>
<script src="js/shopping-cart.js?v=20261027-2" defer></script></body>
</html>
