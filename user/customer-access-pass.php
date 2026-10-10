<?php
if (empty($access_customer) || !hash_equals((string)($_SESSION['customer_user_id'] ?? ''), (string)$access_customer['user_id'])) {
    http_response_code(403);
    exit;
}
?>
<div class="tracking-access-pass" data-access-pass>
    <div class="access-pass-top"><span><img src="../imagess/hydromis-logo-v2.png" alt="HydroMIS logo" data-pass-logo> HydroMIS</span><small>Customer Access Pass</small></div>
    <h3><?= htmlspecialchars($access_customer['full_name']) ?></h3>
    <p class="access-pass-login">Use this mobile number to log in<strong><?= htmlspecialchars($access_customer['contact_number']) ?></strong></p>
    <div class="access-pass-qr"><img data-pass-qr src="../download_qr.php?inline=1&amp;user_id=<?= rawurlencode($access_customer['user_id']) ?>" alt="Your HydroMIS customer QR code" width="200" height="200"></div>
    <p class="access-pass-hint"><i class="fas fa-expand" aria-hidden="true"></i> Keep the full code visible when scanning</p>
    <p data-pass-error role="status" hidden>QR code could not load. <button type="button" data-pass-retry>Try again</button></p>
    <button type="button" class="access-pass-download" data-pass-download data-customer-name="<?= htmlspecialchars($access_customer['full_name'], ENT_QUOTES) ?>" data-contact-number="<?= htmlspecialchars($access_customer['contact_number'], ENT_QUOTES) ?>" data-user-id="<?= htmlspecialchars($access_customer['user_id'], ENT_QUOTES) ?>"><i class="fas fa-download" aria-hidden="true"></i> Download access pass</button>
    <a href="scan_qr.php" class="access-pass-signin">Customer login <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
</div>
