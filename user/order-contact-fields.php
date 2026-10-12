<?php
if (!guest_order_identity((string)$user_id)) return;
$guestStmt = $conn->prepare('SELECT * FROM users WHERE user_id=?');
$guestStmt->bind_param('s', $user_id);
$guestStmt->execute();
$guestContact = $guestStmt->get_result()->fetch_assoc();
if (!empty($edit_order)) {
    foreach (['customer_name'=>'full_name','customer_contact'=>'contact_number','customer_barangay'=>'barangay','customer_purok'=>'purok'] as $from=>$to) if (isset($edit_order[$from])) $guestContact[$to] = $edit_order[$from];
}
$requiredAddress = $fulfillment_method === 'delivery';
?>
<section class="panel" style="margin-bottom:20px;padding:22px">
    <h3 style="font-size:18px">Guest customer details</h3>
    <p>Guests do not earn loyalty points. <?= $requiredAddress ? 'Barangay and purok are required for delivery.' : 'Address is optional for pickup.' ?></p>
    <label for="guestName">Full name *</label>
    <input class="form-control" id="guestName" name="full_name" required minlength="2" maxlength="100" autocomplete="name" value="<?= htmlspecialchars($guestContact['full_name']) ?>">
    <label for="guestMobile">Contact number *</label>
    <input class="form-control" id="guestMobile" name="contact_number" required pattern="09[0-9]{9}" maxlength="11" inputmode="numeric" autocomplete="tel" title="11 digits beginning with 09" value="<?= htmlspecialchars($guestContact['contact_number']) ?>">
    <?php if (!$requiredAddress): ?><details><summary>Add an address (optional)</summary><?php endif; ?>
    <label for="guestBarangay">Barangay <?= $requiredAddress ? '*' : '(optional)' ?></label>
    <select class="form-control" id="guestBarangay" name="barangay" <?= $requiredAddress ? 'required' : '' ?>>
        <option value="">Select barangay</option>
        <?php foreach (ordering_barangays() as $barangay): ?><option value="<?= htmlspecialchars($barangay) ?>" <?= $guestContact['barangay'] === $barangay ? 'selected' : '' ?>><?= htmlspecialchars($barangay) ?></option><?php endforeach; ?>
    </select>
    <label for="guestPurok">Purok/street <?= $requiredAddress ? '*' : '(optional)' ?></label>
    <input class="form-control" id="guestPurok" name="purok" maxlength="100" autocomplete="address-line1" <?= $requiredAddress ? 'required' : '' ?> value="<?= htmlspecialchars($guestContact['purok']) ?>">
    <?php if (!$requiredAddress): ?></details><?php endif; ?>
</section>
<script src="../js/qr-ordering.js" defer></script>
