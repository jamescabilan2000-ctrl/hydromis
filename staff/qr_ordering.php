<?php
require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/../config/database.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff QR Ordering · HydroMIS</title><link rel="stylesheet" href="../css/qr-ordering.css"></head><body>
<main class="qr-ordering"><a class="no-print" href="dashboard.php">← Staff dashboard</a><h1>QR Ordering</h1>
<section class="qr-card"><h2>Scan to order water</h2><p>Customers can order without an account or use their customer QR for eligible loyalty points.</p><div class="qr-display" id="shopOrderingQr"></div><p class="qr-url" id="shopOrderingUrl"></p><p id="qrDisplayStatus" role="status"></p>
<div class="no-print"><button type="button" id="printOrderingQr">Print QR</button> <button type="button" id="downloadOrderingQr">Download QR</button></div></section>
<section class="qr-card no-print"><h2>Assist a customer</h2><p>Enter a guest order or scan a registered customer's account QR. Staff identity will be recorded on the order.</p><a class="qr-button" href="../user/ordering.php?staff=1">Create order / scan customer QR</a></section>
</main><script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script><script>
(() => {
    const url = new URL('../user/ordering.php', window.location.href).href;
    document.getElementById('shopOrderingUrl').textContent = url;
    if (!window.QRCode) { document.getElementById('qrDisplayStatus').textContent = 'QR generator could not load. Refresh or use the ordering link above.'; return; }
    new QRCode(document.getElementById('shopOrderingQr'), {text:url,width:280,height:280,correctLevel:QRCode.CorrectLevel.M});
    document.getElementById('printOrderingQr').addEventListener('click', () => window.print());
    document.getElementById('downloadOrderingQr').addEventListener('click', () => {
        const canvas = document.querySelector('#shopOrderingQr canvas');
        if (!canvas) return;
        // Include a quiet margin so printed/downloaded codes remain readable.
        const output = document.createElement('canvas'); output.width=344; output.height=344;
        const ctx=output.getContext('2d'); ctx.fillStyle='#fff'; ctx.fillRect(0,0,344,344); ctx.drawImage(canvas,32,32,280,280);
        const link=document.createElement('a'); link.href=output.toDataURL('image/png'); link.download='hydromis-ordering-qr.png'; link.click();
    });
})();
</script></body></html>
