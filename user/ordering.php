<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/database.php';
$assisted = isset($_GET['staff']);
if ($assisted) require_once __DIR__ . '/../staff/check_auth.php';
if (empty($_SESSION['public_order_csrf'])) $_SESSION['public_order_csrf'] = bin2hex(random_bytes(32));
$error = '';
$values = ['full_name'=>'','contact_number'=>'','barangay'=>'','purok'=>''];
$fulfillment = 'pickup';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// An unguessable private link restores only this guest's order session.
if (isset($_GET['token'])) {
    $token = is_string($_GET['token']) ? $_GET['token'] : '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) { http_response_code(403); exit('Invalid guest tracking link.'); }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE access_token_hash=? AND customer_type='guest' LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $guest = $stmt->get_result()->fetch_assoc();
    if (!$guest) { http_response_code(403); exit('Invalid guest tracking link.'); }
    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = $guest['user_id'];
    $_SESSION['guest_tracking_token'] = $token;
    $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['ordering_staff_id'], $_SESSION['ordering_source'], $_SESSION['qr_priority_user']);
    header('Location: track_order.php', true, 303); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['public_order_csrf'], (string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Refresh the ordering page and try again.'); }
    $customerId = null;
    if (isset($_POST['qr_data'])) {
        $raw = is_string($_POST['qr_data']) ? $_POST['qr_data'] : '';
        $qr = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
        $id = is_array($qr) && is_string($qr['user_id'] ?? null) ? $qr['user_id'] : '';
        $stmt = $conn->prepare("SELECT user_id,status FROM users WHERE user_id=? AND customer_type='registered' LIMIT 1");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        if (!$customer) $error = 'This is not a valid HydroMIS customer QR code.';
        elseif ($customer['status'] !== 'approved') $error = 'This account must be approved before ordering.';
        else $customerId = $customer['user_id'];
    } else {
        $fulfillment = is_string($_POST['fulfillment_method'] ?? null) ? $_POST['fulfillment_method'] : '';
        [$values, $errors] = validate_order_contact($_POST, $fulfillment);
        $error = implode(' ', $errors);
        $recent = array_filter($_SESSION['guest_order_starts'] ?? [], static fn($t) => $t > time()-3600);
        if (!$assisted && count($recent) >= 10) $error = 'Too many guest sessions. Please use your existing tracking link or try again later.';
        if ($error === '') {
            $customerId = 'GUEST-' . strtoupper(bin2hex(random_bytes(16)));
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $name = encrypt_sensitive($values['full_name']);
            $mobile = encrypt_sensitive($values['contact_number']);
            $address = encrypt_sensitive($values['address']);
            $barangay = encrypt_sensitive($values['barangay']);
            $purok = encrypt_sensitive($values['purok']);
            $stmt = $conn->prepare("INSERT INTO users (user_id,full_name,contact_number,address,barangay,purok,access_token_hash,customer_type,status,loyalty_points) VALUES (?,?,?,?,?,?,?,'guest','approved',0)");
            $stmt->bind_param('sssssss', $customerId, $name, $mobile, $address, $barangay, $purok, $hash);
            if (!$stmt->execute()) { $error = 'Unable to start guest ordering. Please try again.'; $customerId = null; }
            else {
                $_SESSION['guest_tracking_token'] = $token;
                $_SESSION['guest_fulfillment'] = $fulfillment;
                $recent[] = time();
                $_SESSION['guest_order_starts'] = array_values($recent);
            }
        }
    }
    if ($customerId !== null) {
        session_regenerate_id(true);
        $_SESSION['customer_user_id'] = $customerId;
        $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['ordering_source'] = $assisted ? (guest_order_identity($customerId) ? 'staff_guest' : 'staff_account_qr') : (guest_order_identity($customerId) ? 'public_qr' : 'account_qr');
        if ($assisted) $_SESSION['ordering_staff_id'] = (string)$_SESSION['staff_auth_id'];
        else unset($_SESSION['ordering_staff_id']);
        unset($_SESSION['qr_priority_user']);
        if (!guest_order_identity($customerId)) {
            unset($_SESSION['guest_tracking_token'], $_SESSION['guest_fulfillment']);
            $_SESSION['qr_priority_user'] = $customerId;
        }
        header('Location: purchase.php?user_id=' . rawurlencode($customerId), true, 303); exit;
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QR Ordering · HydroMIS</title><link rel="stylesheet" href="../css/qr-ordering.css"></head>
<body><main class="qr-ordering">
    <a href="<?= $assisted ? '../staff/qr_ordering.php' : '../home.php' ?>">← <?= $assisted ? 'Staff QR Ordering' : 'Home' ?></a>
    <h1>Order water</h1><p>Order as a guest or scan your customer account QR. Guests do not earn loyalty points.</p>
    <?php if ($error !== ''): ?><p class="qr-error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <section class="qr-card"><h2>Guest order</h2>
    <form method="post" id="guestOrderForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['public_order_csrf']) ?>">
        <label for="fullName">Full name *</label><input id="fullName" name="full_name" required minlength="2" maxlength="100" autocomplete="name" value="<?= htmlspecialchars($values['full_name']) ?>">
        <label for="contactNumber">Contact number *</label><input id="contactNumber" name="contact_number" required pattern="09[0-9]{9}" inputmode="numeric" maxlength="11" autocomplete="tel" placeholder="09XXXXXXXXX" title="Exactly 11 digits beginning with 09" value="<?= htmlspecialchars($values['contact_number']) ?>">
        <label for="fulfillment">Order type *</label><select id="fulfillment" name="fulfillment_method"><option value="pickup" <?= $fulfillment === 'pickup' ? 'selected' : '' ?>>Pickup</option><option value="delivery" <?= $fulfillment === 'delivery' ? 'selected' : '' ?>>Delivery</option></select>
        <button type="button" class="secondary" id="optionalAddress">Add an address (optional)</button>
        <div id="guestAddress" hidden>
            <label for="barangay">Barangay <span data-address-required></span></label><select id="barangay" name="barangay"><option value="">Select barangay</option><?php foreach (ordering_barangays() as $barangay): ?><option value="<?= htmlspecialchars($barangay) ?>" <?= $values['barangay'] === $barangay ? 'selected' : '' ?>><?= htmlspecialchars($barangay) ?></option><?php endforeach; ?></select>
            <label for="purok">Purok/street <span data-address-required></span></label><input id="purok" name="purok" maxlength="100" autocomplete="address-line1" value="<?= htmlspecialchars($values['purok']) ?>">
        </div>
        <button type="submit">Continue to ordering</button>
    </form></section>
    <section class="qr-card"><h2>Have a customer account?</h2><p>Scan your account QR to use your account and earn eligible points.</p>
        <form method="post" id="accountQrForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['public_order_csrf']) ?>"><input type="hidden" name="qr_data" id="accountQrData"></form>
        <button type="button" id="startQrCamera">Scan account QR</button><button type="button" class="secondary" id="stopQrCamera" hidden>Stop camera</button>
        <label for="qrImage">Or upload your QR image</label><input type="file" id="qrImage" accept="image/*">
        <video id="qrVideo" playsinline muted hidden></video><canvas id="qrCanvas" hidden></canvas><p id="qrScanStatus" role="status"></p>
        <?php if (!$assisted): ?><a href="scan_qr.php">Existing customer sign in</a><?php endif; ?>
    </section>
</main><script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script><script src="../js/qr-ordering.js" defer></script></body></html>
