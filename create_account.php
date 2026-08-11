<?php
session_start();
require_once 'config/database.php';
require_once 'config/storage_service.php';

$error = '';
$success = false;
$new_user_id = '';

// Handle Account Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_submit'])) {
    $full_name = trim(preg_replace('/\s+/u', ' ', sanitize($_POST['full_name'] ?? '')));
    $barangay = sanitize($_POST['barangay']);
    $street_number = trim((string)($_POST['street_number'] ?? ''));
    $address = $street_number . ', ' . $barangay; // Combine for storage
    $contact_input = trim((string)($_POST['contact_number'] ?? ''));
    $contact_number = $contact_input;
    
    if (empty($full_name) || empty($barangay) || empty($street_number) || empty($contact_number)) {
        $error = 'All fields are required!';
    } elseif (!preg_match('/^[\p{L} ]+$/u', $full_name)) {
        $error = 'Full name can contain letters and spaces only.';
    } elseif (!preg_match('/^[\p{L}\d ]+$/u', $street_number)) {
        $error = 'Street or purok can contain letters, numbers, and spaces only.';
    } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
        $error = 'Contact number must contain exactly 11 numbers and begin with 09.';
    } else {
        $contact_lookup = sensitive_lookup($contact_number);
        $duplicateMobile = $conn->prepare("SELECT user_id FROM users WHERE contact_lookup = ? LIMIT 1");
        $duplicateMobile->bind_param('s', $contact_lookup);
        $duplicateMobile->execute();

        if ($duplicateMobile->get_result()->num_rows > 0) {
            $error = 'This mobile number is already registered. Please log in instead.';
        } else {
            $user_id = generateUserID();
            $encrypted_name = encrypt_sensitive($full_name);
            $encrypted_address = encrypt_sensitive($address);
            $encrypted_contact = encrypt_sensitive($contact_number);
            $insertUser = $conn->prepare("INSERT INTO users (user_id, full_name, address, contact_number, contact_lookup, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $insertUser->bind_param('sssss', $user_id, $encrypted_name, $encrypted_address, $encrypted_contact, $contact_lookup);
        }

        if ($error === '' && $insertUser->execute()) {
            $qr_data = json_encode([
                'user_id' => $user_id,
                'full_name' => $full_name,
                'address' => $address,
                'contact_number' => $contact_number
            ]);
            
            $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_data);
            
            $qr_image_path = 'qrcodes/' . $user_id . '.png';
            $qr_image_content = file_get_contents($qr_code_url);
            if ($qr_image_content === false || !hydromis_store_bytes($qr_image_path, $qr_image_content, 'image/png')) {
                throw new RuntimeException('Unable to store the customer QR code.');
            }
            
            $sql = "UPDATE users SET qr_code_path = '$qr_image_path' WHERE user_id = '$user_id'";
            $conn->query($sql);
            
            $success = true;
            $new_user_id = $user_id;
        } elseif ($error === '') {
            $error = 'Unable to create the account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($success): ?><meta name="hydromis-user-id" content="<?php echo htmlspecialchars($new_user_id); ?>"><?php endif; ?>
    <title>HydroMIS - Create Account</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/public-ui.css" rel="stylesheet">
    <link href="css/animations.css" rel="stylesheet">
    <style>
        body.public-ui {
            min-height: 100vh;
        }
        .create-wrap {
            max-width: 1160px;
            margin: 20px auto 38px;
            padding: 0 20px;
        }
        .container-main {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 45px rgba(8, 33, 55, 0.14);
            width: 100%;
            max-width: 680px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid rgba(16, 38, 58, 0.08);
        }
        .header {
            text-align: center;
            padding: 64px 48px;
            background: linear-gradient(135deg, #0d3657 0%, #106780 78%);
            color: white;
        }
        .header h1 {
            font-size: 56px;
            font-weight: 700;
            margin-bottom: 18px;
            letter-spacing: -0.9px;
        }
        .header p {
            font-size: 20px;
            opacity: 0.9;
            font-weight: 500;
            letter-spacing: 0.4px;
        }
        .content {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            color: #10263a;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 15px;
        }
        .form-group label i {
            color: #1c75bc;
        }
        .form-control {
            border-radius: 12px;
            border: 1px solid #d8e5f0;
            padding: 12px 14px;
            font-size: 15px;
            background: #f4f8fb;
            color: #10263a;
            height: 48px;
        }
        select.form-control {
            height: auto;
            padding: 12px 14px;
        }
        .form-control::placeholder {
            color: #6a8497;
        }
        .form-control:focus {
            border-color: #6da8d8;
            box-shadow: 0 0 0 0.18rem rgba(28, 117, 188, 0.15);
            background: #ffffff;
        }
        .btn-submit {
            background: linear-gradient(140deg, #0d9b8a 0%, #0f8f5f 100%);
            border: none;
            color: white;
            padding: 13px 20px;
            font-size: 16px;
            font-weight: 800;
            border-radius: 12px;
            width: 100%;
            margin-top: 10px;
            box-shadow: 0 12px 22px rgba(15, 143, 95, 0.23);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(15, 143, 95, 0.28);
            color: white;
        }
        .error-message {
            color: #7f1f1f;
            padding: 11px 13px;
            background: #fff0f0;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
            border: 1px solid #efb4b4;
            border-left: 4px solid #b73333;
            font-weight: 600;
        }
        .success-box {
            text-align: center;
            padding: 2px;
        }
        .success-icon {
            font-size: 56px;
            color: #10b981;
            margin-bottom: 20px;
            animation: bounce 0.6s ease-out;
        }
        @keyframes bounce {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .success-message {
            color: #0f8f5f;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .qr-code-display {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
            padding: 30px;
            border-radius: 12px;
            margin: 28px 0;
            text-align: center;
        }
        .qr-code-display p {
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 16px;
            font-size: 15px;
        }
        .qr-code-display img {
            max-width: 280px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border: 3px solid white;
        }
        .success-kicker{display:inline-flex;align-items:center;gap:7px;margin-bottom:11px;padding:6px 10px;border-radius:999px;background:#e8fbf4;color:#087f62;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.success-subtitle{max-width:390px;margin:-12px auto 24px;color:#71869a;font-size:13px;line-height:1.55}.digital-pass{position:relative;max-width:390px;margin:0 auto 24px;padding:18px;border:1px solid #dce8ef;border-radius:22px;background:linear-gradient(145deg,#f9fcfe,#eef6fa);box-shadow:0 18px 38px rgba(13,55,84,.12);overflow:hidden}.digital-pass::before{content:'';position:absolute;width:170px;height:170px;right:-95px;top:-100px;border-radius:50%;background:rgba(8,184,213,.13)}.pass-top{position:relative;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;text-align:left}.pass-brand{display:flex;align-items:center;gap:9px;color:#14334d;font-size:13px;font-weight:800}.pass-brand i{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#0879ca,#08b8d5);color:#fff}.pass-type{color:#7690a3;font-size:9px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.qr-frame{position:relative;width:min(100%,310px);margin:0 auto;padding:14px;border:1px solid #e1e9ef;border-radius:18px;background:#fff;box-shadow:0 12px 28px rgba(12,45,68,.11)}.qr-frame::before,.qr-frame::after{content:'';position:absolute;width:34px;height:34px;border-color:#08aeba;border-style:solid;pointer-events:none}.qr-frame::before{left:7px;top:7px;border-width:3px 0 0 3px;border-radius:9px 0 0}.qr-frame::after{right:7px;bottom:7px;border-width:0 3px 3px 0;border-radius:0 0 9px}.qr-frame img{display:block;width:100%;max-width:none;border:0;border-radius:10px;box-shadow:none}.pass-hint{display:flex;align-items:center;justify-content:center;gap:6px;margin:13px 0 0;color:#6f879a;font-size:10px}.pass-hint i{color:#159d86}.qr-download{display:inline-flex;align-items:center;justify-content:center;gap:7px;margin-top:13px;padding:8px 11px;border:1px solid #cfe0eb;border-radius:9px;color:#176ca5!important;background:#fff;font-size:11px!important;font-weight:800;text-decoration:none!important;transition:transform .2s ease,box-shadow .2s ease}.qr-download:hover{transform:translateY(-1px)!important;box-shadow:0 8px 16px rgba(18,92,137,.1)}.account-details{margin:0 0 22px;padding:4px 0;border-top:1px solid #e4edf3;border-bottom:1px solid #e4edf3}.account-detail{display:grid;grid-template-columns:125px minmax(0,1fr);gap:14px;padding:11px 4px;text-align:left;border-bottom:1px solid #edf2f5}.account-detail:last-child{border:0}.account-detail-label{color:#7890a2;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.account-detail-value{min-width:0;color:#17334b;font-size:13px;font-weight:700;overflow-wrap:anywhere}.approval-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;background:#fff4d8;color:#9b6207;font-size:10px;font-weight:800}.approval-pill i{font-size:6px;animation:statusPulse 1.8s ease-in-out infinite}.success-actions-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.success-actions-grid .btn-action{margin:0;min-height:48px;display:flex;align-items:center;justify-content:center}.success-actions-grid .home-action{grid-column:1/-1;background:#eef4f8!important;color:#476277;border:1px solid #dae5ec;box-shadow:none}.success-actions-grid .home-action:hover{color:#1f435f;background:#e6f0f6!important}.success-box>*{opacity:0;animation:successReveal .65s var(--register-ease) forwards}.success-box>*:nth-child(2){animation-delay:.08s}.success-box>*:nth-child(3){animation-delay:.16s}.success-box>*:nth-child(4){animation-delay:.24s}.success-box>*:nth-child(5){animation-delay:.32s}@keyframes successReveal{from{opacity:0;transform:translateY(13px)}to{opacity:1;transform:none}}@keyframes statusPulse{50%{opacity:.4;transform:scale(.8)}}
        .qr-message {
            background: #edf7ff;
            padding: 14px 16px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #1c75bc;
        }
        .qr-instruction {
            color: #3730a3;
            font-size: 14px;
            margin: 0;
            display: flex;
            align-items: flex-start;
        }
        .qr-instruction i {
            margin-right: 10px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .demo-credentials {
            background: #edf7ff;
            padding: 16px 18px;
            border-radius: 12px;
            margin-top: 24px;
            font-size: 13px;
            color: #1f4f77;
            border: 1px solid #cde3f3;
        }
        .demo-credentials strong {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .demo-credentials p {
            margin: 6px 0;
            color: #4338ca;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #185f97;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            transition: color 0.3s ease;
        }
        .back-link a:hover {
            color: #124c78;
        }
        .btn-action {
            background: linear-gradient(135deg, #145c9e 0%, #1c75bc 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            margin-top: 12px;
            font-size: 15px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 20px rgba(20, 92, 158, 0.26);
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 24px rgba(20, 92, 158, 0.3);
            color: white;
            text-decoration: none;
        }
        .btn-secondary-action {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-secondary-action:hover {
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        @media (max-width: 768px) {
            .create-wrap {
                padding: 0 14px;
            }
            .content {
                padding: 22px 16px;
            }
            .header h1 {
                font-size: 32px;
            }
        }

        /* Premium registration experience */
        :root {
            --register-ease: cubic-bezier(.22, 1, .36, 1);
            --register-blue: #0b5bd3;
            --register-aqua: #08b8d5;
            --register-ink: #0b253d;
        }
        body.public-ui {
            background:
                radial-gradient(circle at 12% 18%, rgba(8,184,213,.14), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(11,91,211,.12), transparent 32%),
                linear-gradient(145deg,#f5fbff 0%,#eaf4fb 52%,#f7fbff 100%);
            overflow-x: hidden;
        }
        body.public-ui::before, body.public-ui::after {
            content: '';
            position: fixed;
            z-index: 0;
            border-radius: 50%;
            border: 1px solid rgba(8,184,213,.12);
            pointer-events: none;
            animation: ambientRing 12s ease-in-out infinite alternate;
        }
        body.public-ui::before { width:420px;height:420px;left:-210px;top:18%; }
        body.public-ui::after { width:560px;height:560px;right:-300px;bottom:-240px;animation-delay:-4s; }
        .topbar {
            position: relative;
            z-index: 5;
            background:rgba(255,255,255,.72)!important;
            border-bottom:1px solid rgba(11,37,61,.07)!important;
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            animation:topbarIn .65s var(--register-ease) both;
        }
        .brand-icon { background:transparent;box-shadow:none; }
        .create-wrap { position:relative;z-index:1;max-width:1040px;margin:42px auto 60px; }
        .container-main {
            max-width:960px;
            display:grid;
            grid-template-columns:minmax(300px,.8fr) minmax(430px,1.2fr);
            border-radius:28px;
            box-shadow:0 30px 80px rgba(8,43,72,.16),0 2px 8px rgba(8,43,72,.07);
            animation:cardIn .85s .08s var(--register-ease) both;
        }
        .header {
            position:relative;
            display:flex;
            flex-direction:column;
            justify-content:center;
            min-height:100%;
            padding:52px 42px;
            text-align:left;
            overflow:hidden;
            background-color:#052b4a;
            background-image:url('imagess/registration-gallons-bg-v3.png');
            background-repeat:no-repeat;
            background-position:48% 50%;
            background-size:cover;
            isolation:isolate;
            animation:waterPanelDrift 16s ease-in-out infinite alternate;
            transition:filter .8s ease;
        }
        .header::before {
            content:'';position:absolute;inset:0;z-index:-1;
            background:linear-gradient(125deg,rgba(2,23,46,.8) 0%,rgba(2,43,72,.64) 48%,rgba(3,87,107,.38) 100%);
            transition:background .8s ease;
        }
        .header::after { content:'';position:absolute;inset:-35%;z-index:-1;pointer-events:none;background:linear-gradient(112deg,transparent 38%,rgba(156,238,255,.14) 49%,transparent 60%);transform:translateX(-45%) rotate(4deg);animation:waterLightSweep 8s ease-in-out infinite; }
        .header:hover { background-size:cover;filter:saturate(1.08) brightness(1.03); }
        .header:hover::before { background:linear-gradient(125deg,rgba(2,23,46,.74) 0%,rgba(2,43,72,.58) 48%,rgba(3,87,107,.3) 100%); }
        .water-bubbles{position:absolute;inset:0;z-index:0;overflow:hidden;pointer-events:none}
        .water-bubbles span{position:absolute;bottom:-24px;width:10px;height:10px;border:1px solid rgba(194,246,255,.72);border-radius:50%;background:radial-gradient(circle at 32% 28%,rgba(255,255,255,.8),rgba(105,220,245,.12) 38%,transparent 66%);box-shadow:inset -2px -2px 4px rgba(31,152,205,.28),0 0 7px rgba(91,222,255,.28);opacity:0;animation:bubbleRise 8s ease-in infinite}
        .water-bubbles span:nth-child(1){left:8%;width:7px;height:7px;animation-delay:.4s;animation-duration:7.2s}
        .water-bubbles span:nth-child(2){left:22%;width:13px;height:13px;animation-delay:3.1s;animation-duration:9.5s}
        .water-bubbles span:nth-child(3){left:48%;width:6px;height:6px;animation-delay:1.7s;animation-duration:6.8s}
        .water-bubbles span:nth-child(4){left:68%;width:16px;height:16px;animation-delay:4.6s;animation-duration:10.2s}
        .water-bubbles span:nth-child(5){left:83%;width:9px;height:9px;animation-delay:2.4s;animation-duration:8.4s}
        .water-bubbles span:nth-child(6){left:92%;width:5px;height:5px;animation-delay:5.5s;animation-duration:7.6s}
        .header-badge {
            position:relative;display:inline-flex;align-items:center;gap:8px;align-self:flex-start;
            margin-bottom:26px;padding:7px 12px;border:1px solid rgba(255,255,255,.2);border-radius:999px;
            background:rgba(255,255,255,.09);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
            backdrop-filter:blur(10px);
        }
        .header h1 { position:relative;font-size:42px;line-height:1.08;margin-bottom:14px;letter-spacing:-1.5px; }
        .header p { position:relative;font-size:18px;line-height:1.55;margin:0;opacity:.82; }
        .header-note { position:relative;display:flex;align-items:center;gap:9px;margin-top:28px;color:rgba(255,255,255,.72);font-size:13px; }
        .header-note i { color:#66eddf; }
        @keyframes waterPanelDrift { 0%{background-position:42% 48%} 50%{background-position:50% 52%} 100%{background-position:58% 47%} }
        @keyframes waterLightSweep { 0%,18%{opacity:0;transform:translateX(-48%) rotate(4deg)} 48%{opacity:1} 76%,100%{opacity:0;transform:translateX(48%) rotate(4deg)} }
        @keyframes bubbleRise{0%{opacity:0;transform:translate3d(0,0,0) scale(.7)}12%{opacity:.8}55%{transform:translate3d(12px,-145px,0) scale(1)}88%{opacity:.48}100%{opacity:0;transform:translate3d(-7px,-300px,0) scale(1.16)}}
        .content { padding:42px 44px; }
        .form-intro { margin-bottom:26px; }
        .form-intro h2 { color:var(--register-ink);font-size:24px;font-weight:800;letter-spacing:-.4px;margin-bottom:7px; }
        .form-intro p { color:#678095;font-size:14px;margin:0; }
        .registration-form .form-group { position:relative;margin-bottom:19px;animation:fieldIn .65s var(--register-ease) both; }
        .registration-form .form-group:nth-child(2){animation-delay:.18s}.registration-form .form-group:nth-child(3){animation-delay:.25s}.registration-form .form-group:nth-child(4){animation-delay:.32s}.registration-form .form-group:nth-child(5){animation-delay:.39s}
        .form-group label { display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;letter-spacing:.01em; }
        .form-group label i { display:grid;place-items:center;width:28px;height:28px;margin:0!important;border-radius:8px;background:#eaf5ff;color:#0877c9; }
        .form-control, select.form-control {
            height:54px;padding:0 16px;border:1px solid #d7e5ef;border-radius:14px;background:#f7fafc;
            transition:border-color .2s ease,box-shadow .2s ease,background .2s ease,transform .2s ease;
        }
        .form-control:hover { border-color:#aac9de;background:#fff; }
        .form-control:focus { border-color:var(--register-aqua);box-shadow:0 0 0 4px rgba(8,184,213,.12),0 8px 24px rgba(8,67,102,.06);transform:translateY(-1px); }
        .field-help { display:block;margin-top:7px;color:#8095a7;font-size:11px; }
        .btn-submit {
            position:relative;min-height:54px;margin-top:9px;overflow:hidden;border-radius:14px;
            background:linear-gradient(120deg,#0879ca,#08b8d5,#0b5bd3);background-size:180% 180%;
            box-shadow:0 14px 30px rgba(8,122,190,.25);animation:gradientMove 6s ease infinite,fieldIn .65s .46s var(--register-ease) both;
        }
        .btn-submit::after { content:'';position:absolute;inset:0;transform:translateX(-120%) skewX(-20deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);transition:transform .7s var(--register-ease); }
        .btn-submit:hover::after { transform:translateX(120%) skewX(-20deg); }
        .btn-submit:hover { transform:translateY(-2px);box-shadow:0 18px 36px rgba(8,122,190,.32); }
        .btn-submit:active { transform:scale(.985); }
        .btn-submit.is-loading { pointer-events:none;opacity:.82; }
        .back-link a { display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:9px;transition:background .2s ease,transform .2s ease; }
        .back-link a:hover { background:#edf6fc;transform:translateX(-2px); }
        .error-message { animation:shakeIn .45s var(--register-ease) both; }
        .success-box { animation:fieldIn .65s var(--register-ease) both; }
        @keyframes topbarIn { from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none} }
        @keyframes cardIn { from{opacity:0;transform:translateY(28px) scale(.985)}to{opacity:1;transform:none} }
        @keyframes fieldIn { from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none} }
        @keyframes ambientRing { from{transform:scale(.9);opacity:.4}to{transform:scale(1.08);opacity:1} }
        @keyframes gradientMove { 0%,100%{background-position:0 50%}50%{background-position:100% 50%} }
        @keyframes shakeIn { 0%{opacity:0;transform:translateX(-8px)}45%{transform:translateX(5px)}100%{opacity:1;transform:none} }
        a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible { outline:3px solid rgba(8,184,213,.28);outline-offset:2px; }
        @media(max-width:800px){
            .create-wrap{width:100%;max-width:none;min-height:100dvh;margin:0;padding:0}.container-main{display:block;width:100%;max-width:none;min-height:100dvh;border-radius:0;box-shadow:none}.header{min-height:280px;padding:38px 32px;text-align:center;align-items:center;background-size:cover!important;background-position:64% 52%}.header-badge{align-self:center;margin-bottom:18px}.header h1{font-size:34px}.header-note{margin-top:18px}.content{padding:32px}
        }
        @media(max-width:480px){
            .create-wrap{margin:0;padding:0}.container-main{border-radius:0}.header{min-height:260px;padding:28px 20px;background-size:cover!important;background-position:66% 50%}.header h1{font-size:29px;margin-bottom:9px}.header p{font-size:15px}.header-note{font-size:11px;margin-top:14px}.content{padding:26px 18px}.form-intro h2{font-size:21px}.form-control,select.form-control{height:51px}.btn-submit{min-height:52px}
            .digital-pass{padding:13px;border-radius:18px}.qr-frame{padding:10px}.account-detail{grid-template-columns:100px minmax(0,1fr)}.success-actions-grid{grid-template-columns:1fr}.success-actions-grid .home-action{grid-column:1}.success-message{font-size:20px}
        }
        /* Compact QR pass, sized like a printable customer ID. */
        .digital-pass{max-width:350px}
        .pass-customer-name{position:relative;margin:4px auto 14px;color:#142b40;font-size:20px;font-weight:800;letter-spacing:-.025em;line-height:1.25;overflow-wrap:anywhere}
        .pass-login-guide{position:relative;margin:-5px auto 12px;color:#678095;font-size:10px;line-height:1.35}.pass-login-guide strong{display:block;margin-top:2px;color:#0879a8;font-family:monospace;font-size:14px;letter-spacing:.08em}
        .pass-logo{position:relative;isolation:isolate;display:grid;place-items:center;width:38px;height:38px;border:0;border-radius:50%;background:transparent;box-shadow:none;animation:passLogoFloat 4.4s ease-in-out infinite}
        .pass-logo::after{content:'';position:absolute;inset:-2px;z-index:-1;border-radius:50%;padding:1.5px;background:conic-gradient(from 25deg,transparent 0 22%,#35d9eb 34%,#2389ec 48%,transparent 59% 80%,#4ae5cc 91%,transparent);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;filter:drop-shadow(0 0 4px rgba(31,177,224,.55));animation:passLogoOrbit 5.4s linear infinite}
        .pass-logo img{display:block;width:34px!important;height:34px!important;object-fit:contain;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;outline:0!important;animation:none!important;filter:drop-shadow(0 5px 7px rgba(6,78,139,.22));transition:transform .4s ease,filter .4s ease}
        .pass-brand:hover .pass-logo img{transform:rotate(4deg) scale(1.07);filter:drop-shadow(0 7px 9px rgba(6,78,139,.32))}
        @keyframes passLogoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
        @keyframes passLogoOrbit{to{transform:rotate(360deg)}}
        button.qr-download{font-family:inherit;cursor:pointer}
        .qr-frame{width:min(100%,230px);padding:12px;border-radius:16px}
        @media(max-width:480px){.pass-customer-name{font-size:18px;margin-bottom:12px}.qr-frame{width:min(100%,205px);padding:9px}}
        /* Full-viewport registration success screen. */
        body.success-view{width:100%;height:100dvh;min-height:0;overflow:hidden;background:linear-gradient(155deg,#f8fcfd 0%,#fff 48%,#edf8f7 100%)}
        body.success-view .create-wrap{width:100%;max-width:none;min-height:100dvh;margin:0;padding:0}
        body.success-view .container-main{display:block;width:100%;max-width:none;height:100dvh;min-height:0;margin:0;border:0;border-radius:0;background:transparent;box-shadow:none;overflow:hidden;animation:none}
        body.success-view .content{display:flex;justify-content:center;width:100%;height:100dvh;min-height:0;padding:clamp(16px,3vh,32px) clamp(18px,5vw,64px);overflow:hidden}
        body.success-view .success-box{width:min(100%,680px);margin:auto;padding:0}
        body.success-view .success-icon{font-size:clamp(50px,7vw,70px);margin-bottom:16px}
        body.success-view .success-kicker{margin-bottom:12px;padding:7px 13px;font-size:10px}
        body.success-view .success-message{margin-bottom:18px;font-size:clamp(23px,4vw,32px)}
        body.success-view .success-subtitle{max-width:540px;margin:0 auto 22px;font-size:clamp(13px,2vw,15px)}
        body.success-view .digital-pass{width:min(100%,440px);max-width:none;margin:0 auto 18px;padding:clamp(15px,3vw,22px);border-radius:24px}
        body.success-view .pass-top{margin-bottom:12px}
        body.success-view .pass-customer-name{margin:2px auto 12px;font-size:clamp(18px,3vw,22px)}
        body.success-view .qr-frame{width:min(100%,280px);padding:11px}
        body.success-view .pass-hint{margin-top:10px}
        body.success-view .qr-download{min-height:40px;margin-top:10px;padding:9px 14px}
        body.success-view .success-actions-grid{width:min(100%,440px);margin:0 auto}
        @media(max-height:850px){
            body.success-view .success-icon{font-size:44px;margin-bottom:9px}
            body.success-view .success-kicker{margin-bottom:8px;padding:5px 11px}
            body.success-view .success-message{margin-bottom:9px;font-size:22px}
            body.success-view .success-subtitle{margin-bottom:12px;font-size:12px;line-height:1.4}
            body.success-view .digital-pass{margin-bottom:10px;padding:12px}
            body.success-view .pass-top{margin-bottom:7px}
            body.success-view .pass-logo{width:32px;height:32px}
            body.success-view .pass-logo img{width:29px!important;height:29px!important}
            body.success-view .pass-customer-name{margin:0 auto 7px;font-size:17px}
            body.success-view .pass-login-guide{margin:-2px auto 6px;font-size:9px}.pass-login-guide strong{font-size:12px}
            body.success-view .qr-frame{width:min(100%,24vh,205px);padding:7px}
            body.success-view .pass-hint{margin-top:6px;font-size:9px}
            body.success-view .qr-download{min-height:34px;margin-top:6px;padding:6px 11px}
            body.success-view .success-actions-grid .btn-action{min-height:40px}
        }
        @media(max-width:480px){
            body.success-view .content{padding:14px 14px}
            body.success-view .digital-pass{border-radius:19px}
            body.success-view .qr-frame{width:min(100%,24vh,205px)}
        }
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
    </style>
    <script src="js/ui-protection.js" defer></script>
</head>
<body class="public-ui<?php echo $success ? ' success-view' : ''; ?>">
    <main class="create-wrap">
    <div class="container-main">
        <?php if (!$success): ?>
        <div class="header">
            <div class="water-bubbles" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <div class="header-badge"><i class="fas fa-droplet"></i> New customer</div>
            <h1>HydroMIS</h1>
            <p>Simple registration for faster water delivery and order tracking.</p>
            <div class="header-note"><i class="fas fa-shield-halved"></i> Your information stays private and secure.</div>
        </div>
        <?php endif; ?>

        <div class="content">
            <?php if ($success): ?>
                <!-- Success Message -->
                <div class="success-box">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="success-kicker"><i class="fas fa-shield-halved"></i> Registration complete</div>
                    <div class="success-message">Account Created Successfully!</div>
                    <p class="success-subtitle">Your personal HydroMIS access pass is ready. Save the QR code and present it whenever you need fast account access.</p>

                    <div class="digital-pass">
                        <div class="pass-top"><div class="pass-brand"><span class="pass-logo"><img src="imagess/hydromis-logo-v2.png?v=20260802" alt="HydroMIS logo"></span> HydroMIS</div><span class="pass-type">Customer Access Pass</span></div>
                        <div class="pass-customer-name"><?php echo htmlspecialchars($_POST['full_name']); ?></div>
                        <p class="pass-login-guide">Use this mobile number to log in<strong><?php echo htmlspecialchars($contact_number); ?></strong></p>
                        <div class="qr-frame">
                            <img id="customerQrImage" src="<?php echo htmlspecialchars(hydromis_storage_url('qrcodes/' . $new_user_id . '.png')); ?>" alt="HydroMIS customer QR code for <?php echo htmlspecialchars($_POST['full_name']); ?>">
                        </div>
                        <p class="pass-hint"><i class="fas fa-expand"></i> Keep the full code visible when scanning</p>
                        <button type="button" class="qr-download" id="downloadAccessPass"
                            data-customer-name="<?php echo htmlspecialchars($_POST['full_name'], ENT_QUOTES); ?>"
                            data-contact-number="<?php echo htmlspecialchars($contact_number, ENT_QUOTES); ?>"
                            data-user-id="<?php echo htmlspecialchars($new_user_id, ENT_QUOTES); ?>">
                            <i class="fas fa-download"></i> Download access pass
                        </button>
                    </div>

                    <div class="success-actions-grid">
                        <a href="user/scan_qr.php" class="btn-action home-action"><i class="fas fa-right-to-bracket"></i> User Login</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Registration Form -->
                <div class="form-intro">
                    <h2>Create your account</h2>
                    <p>Enter your delivery information below. All fields are required.</p>
                </div>
                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="registration-form" id="registrationForm">
                    <input type="hidden" name="create_submit" value="1">
                    
                    <div class="form-group">
                        <label for="full_name"><i class="fas fa-user mr-2"></i>Full Name *</label>
                        <input type="text" class="form-control" name="full_name" id="full_name" placeholder="e.g. Juan Dela Cruz" autocomplete="name" pattern="[A-Za-zÀ-ÖØ-öø-ÿ ]+" title="Use letters and spaces only." maxlength="100" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="barangay"><i class="fas fa-map-marker-alt mr-2"></i>Barangay *</label>
                        <select class="form-control" name="barangay" id="barangay" required>
                            <option value="">Select your barangay</option>
                            <option value="BagongBanwa Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'BagongBanwa Island') ? 'selected' : ''; ?>>BagongBanwa Island</option>
                            <option value="Banlasan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Banlasan') ? 'selected' : ''; ?>>Banlasan</option>
                            <option value="Batasan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Batasan Island') ? 'selected' : ''; ?>>Batasan Island</option>
                            <option value="BilangBilangan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'BilangBilangan Island') ? 'selected' : ''; ?>>BilangBilangan Island</option>
                            <option value="Bosongon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Bosongon') ? 'selected' : ''; ?>>Bosongon</option>
                            <option value="Buenos Aires" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Buenos Aires') ? 'selected' : ''; ?>>Buenos Aires</option>
                            <option value="Bunacan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Bunacan') ? 'selected' : ''; ?>>Bunacan</option>
                            <option value="Cabulijan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cabulijan') ? 'selected' : ''; ?>>Cabulijan</option>
                            <option value="Cahayag" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cahayag') ? 'selected' : ''; ?>>Cahayag</option>
                            <option value="Cawayanan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cawayanan') ? 'selected' : ''; ?>>Cawayanan</option>
                            <option value="Centro" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Centro') ? 'selected' : ''; ?>>Centro</option>
                            <option value="Genonocan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Genonocan') ? 'selected' : ''; ?>>Genonocan</option>
                            <option value="Guiwanon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Guiwanon') ? 'selected' : ''; ?>>Guiwanon</option>
                            <option value="Ilijan Norte" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ilijan Norte') ? 'selected' : ''; ?>>Ilijan Norte</option>
                            <option value="Ilijan Sur" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ilijan Sur') ? 'selected' : ''; ?>>Ilijan Sur</option>
                            <option value="Libertad" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Libertad') ? 'selected' : ''; ?>>Libertad</option>
                            <option value="Macaas" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Macaas') ? 'selected' : ''; ?>>Macaas</option>
                            <option value="Matabao" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Matabao') ? 'selected' : ''; ?>>Matabao</option>
                            <option value="Mocaboc Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Mocaboc Island') ? 'selected' : ''; ?>>Mocaboc Island</option>
                            <option value="Panadtaran" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Panadtaran') ? 'selected' : ''; ?>>Panadtaran</option>
                            <option value="Panaytayon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Panaytayon') ? 'selected' : ''; ?>>Panaytayon</option>
                            <option value="Pandan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pandan') ? 'selected' : ''; ?>>Pandan</option>
                            <option value="Pangapasan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pangapasan Island') ? 'selected' : ''; ?>>Pangapasan Island</option>
                            <option value="Pinayagan Norte" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pinayagan Norte') ? 'selected' : ''; ?>>Pinayagan Norte</option>
                            <option value="Pinayagan Sur" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pinayagan Sur') ? 'selected' : ''; ?>>Pinayagan Sur</option>
                            <option value="Pooc Occidental" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pooc Occidental') ? 'selected' : ''; ?>>Pooc Occidental</option>
                            <option value="Pooc Oriental" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pooc Oriental') ? 'selected' : ''; ?>>Pooc Oriental</option>
                            <option value="Potohan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Potohan') ? 'selected' : ''; ?>>Potohan</option>
                            <option value="Talenceras" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Talenceras') ? 'selected' : ''; ?>>Talenceras</option>
                            <option value="Tan-awan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Tan-awan') ? 'selected' : ''; ?>>Tan-awan</option>
                            <option value="Tinangnan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Tinangnan') ? 'selected' : ''; ?>>Tinangnan</option>
                            <option value="Ubojan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ubojan') ? 'selected' : ''; ?>>Ubojan</option>
                            <option value="Ubay Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ubay Island') ? 'selected' : ''; ?>>Ubay Island</option>
                            <option value="Villanueva" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Villanueva') ? 'selected' : ''; ?>>Villanueva</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="street_number"><i class="fas fa-home mr-2"></i>Street Number or Purok *</label>
                        <input type="text" class="form-control" name="street_number" id="street_number" placeholder="e.g. Purok 2 or 24 Rizal Street" autocomplete="address-line1" pattern="[A-Za-zÀ-ÖØ-öø-ÿ0-9 ]+" title="Use letters, numbers, and spaces only." maxlength="100" required value="<?php echo isset($_POST['street_number']) ? htmlspecialchars($_POST['street_number']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="contact_number"><i class="fas fa-phone mr-2"></i>Contact Number *</label>
                        <input type="tel" class="form-control" name="contact_number" id="contact_number" placeholder="e.g. 09123456789" autocomplete="tel" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" title="Enter exactly 11 numbers beginning with 09." required value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>">
                        <small class="field-help">We’ll use this number for order and delivery updates.</small>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check mr-2"></i> Register Account
                    </button>
                </form>


                <div class="back-link">
                    <a href="home.php">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Home
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Location Picker
        function selectLocation(location) {
            const addressField = document.getElementById('address');
            addressField.value = location + ', Philippines';
            document.getElementById('locationSearch').value = location;
            document.getElementById('locationDropdown').style.display = 'none';
            addressField.focus();
            addressField.setSelectionRange(addressField.value.length, addressField.value.length);
        }

        // Location Search
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('locationSearch');
            const dropdown = document.getElementById('locationDropdown');
            if (!searchInput || !dropdown) return;
            const options = dropdown.querySelectorAll('.location-option');

            searchInput.addEventListener('focus', function() {
                dropdown.style.display = 'block';
            });

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                options.forEach(option => {
                    if (option.textContent.toLowerCase().includes(searchTerm) || searchTerm === '') {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (!document.querySelector('.location-picker-wrapper').contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        });

        // Geolocation
        function getLocationAddress() {
            const btn = document.getElementById('getLocationBtn');
            const statusDiv = document.getElementById('locationStatus');
            const addressField = document.getElementById('address');
            
            btn.disabled = true;
            statusDiv.textContent = 'Getting location...';
            statusDiv.className = 'location-status';
            
            if (!navigator.geolocation) {
                statusDiv.textContent = 'Geolocation not supported by your browser';
                statusDiv.className = 'location-status error';
                btn.disabled = false;
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Use OpenStreetMap Nominatim API for reverse geocoding (free, no key needed)
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                        .then(response => response.json())
                        .then(data => {
                            const address = data.address;
                            let fullAddress = '';
                            
                            // Build address from components
                            if (address.house_number) fullAddress += address.house_number + ' ';
                            if (address.road) fullAddress += address.road + ', ';
                            if (address.neighbourhood) fullAddress += address.neighbourhood + ', ';
                            if (address.suburb) fullAddress += address.suburb + ', ';
                            if (address.city) fullAddress += address.city + ', ';
                            if (address.state) fullAddress += address.state + ' ';
                            if (address.postcode) fullAddress += address.postcode + ', ';
                            if (address.country) fullAddress += address.country;
                            
                            if (fullAddress.trim()) {
                                addressField.value = fullAddress.trim();
                                statusDiv.textContent = '✓ Location detected successfully!';
                                statusDiv.className = 'location-status success';
                            } else {
                                statusDiv.textContent = 'Could not find address for this location';
                                statusDiv.className = 'location-status error';
                            }
                            btn.disabled = false;
                        })
                        .catch(error => {
                            statusDiv.textContent = 'Error getting address: ' + error.message;
                            statusDiv.className = 'location-status error';
                            btn.disabled = false;
                        });
                },
                function(error) {
                    let errorMsg = 'Location not available';
                    if (error.code === error.PERMISSION_DENIED) {
                        errorMsg = 'Please enable location access in your browser settings';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        errorMsg = 'Location information is unavailable';
                    } else if (error.code === error.TIMEOUT) {
                        errorMsg = 'Location request timed out';
                    }
                    statusDiv.textContent = errorMsg;
                    statusDiv.className = 'location-status error';
                    btn.disabled = false;
                }
            );
        }

        const registrationForm = document.getElementById('registrationForm');
        const fullNameInput = document.getElementById('full_name');
        const streetNumberInput = document.getElementById('street_number');
        const contactNumberInput = document.getElementById('contact_number');

        if (fullNameInput) {
            fullNameInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ ]/g, '').replace(/\s{2,}/g, ' ');
            });
        }
        if (streetNumberInput) {
            streetNumberInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ0-9 ]/g, '').replace(/\s{2,}/g, ' ');
            });
        }
        if (contactNumberInput) {
            contactNumberInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 11);
            });
        }

        if (registrationForm) {
            registrationForm.addEventListener('submit', function() {
                const button = this.querySelector('.btn-submit');
                button.classList.add('is-loading');
                button.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Creating account...';
            });
        }

        const passDownloadButton = document.getElementById('downloadAccessPass');
        if (passDownloadButton) {
            passDownloadButton.addEventListener('click', async function() {
                const qrImage = document.getElementById('customerQrImage');
                if (!qrImage || !qrImage.complete || !qrImage.naturalWidth) {
                    alert('The QR code is still loading. Please try again.');
                    return;
                }
                const logoImage = document.querySelector('.pass-logo img');
                if (!logoImage) {
                    alert('The HydroMIS logo is unavailable. Please refresh and try again.');
                    return;
                }
                if (!logoImage.complete || !logoImage.naturalWidth) {
                    try { await logoImage.decode(); } catch (error) {
                        alert('The HydroMIS logo is still loading. Please try again.');
                        return;
                    }
                }

                const canvas = document.createElement('canvas');
                canvas.width = 860;
                canvas.height = 980;
                const ctx = canvas.getContext('2d');
                const roundRect = (x, y, width, height, radius) => {
                    const r = Math.min(radius, width / 2, height / 2);
                    ctx.beginPath();
                    ctx.moveTo(x + r, y);
                    ctx.arcTo(x + width, y, x + width, y + height, r);
                    ctx.arcTo(x + width, y + height, x, y + height, r);
                    ctx.arcTo(x, y + height, x, y, r);
                    ctx.arcTo(x, y, x + width, y, r);
                    ctx.closePath();
                };

                // Access-pass background and soft top-right accent.
                ctx.fillStyle = '#f5f9fc';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = '#d4f1f6';
                ctx.beginPath();
                ctx.arc(858, -8, 188, 0, Math.PI * 2);
                ctx.fill();

                // Latest transparent HydroMIS logo—no square brand tile.
                ctx.save();
                ctx.shadowColor = 'rgba(13, 113, 189, .24)';
                ctx.shadowBlur = 12;
                ctx.shadowOffsetY = 5;
                ctx.drawImage(logoImage, 26, 36, 92, 92);
                ctx.restore();
                ctx.fillStyle = '#15344f';
                ctx.font = '700 31px Arial, sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('HydroMIS', 128, 94);
                ctx.fillStyle = '#7890a3';
                ctx.font = '700 19px Arial, sans-serif';
                ctx.letterSpacing = '2px';
                ctx.textAlign = 'right';
                ctx.fillText('CUSTOMER ACCESS PASS', 812, 90);

                ctx.fillStyle = '#142b40';
                ctx.font = '700 43px Arial, sans-serif';
                ctx.textAlign = 'center';
                const customerName = this.dataset.customerName || 'HydroMIS Customer';
                ctx.fillText(customerName, 430, 178, 740);

                ctx.fillStyle = '#6b8294';
                ctx.font = '500 20px Arial, sans-serif';
                ctx.fillText('Use this mobile number to log in', 430, 218);
                ctx.fillStyle = '#0879a8';
                ctx.font = '700 28px monospace';
                ctx.fillText(this.dataset.contactNumber || '', 430, 254);

                // Raised white QR panel.
                ctx.save();
                ctx.shadowColor = 'rgba(20, 50, 72, .14)';
                ctx.shadowBlur = 30;
                ctx.shadowOffsetY = 12;
                roundRect(132, 305, 596, 596, 38);
                ctx.fillStyle = '#ffffff';
                ctx.fill();
                ctx.restore();

                // Keep image smoothing off so every QR module remains sharp.
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(qrImage, 164, 337, 532, 532);

                // Cyan corner details from the reference card.
                ctx.strokeStyle = '#08b6bd';
                ctx.lineWidth = 5;
                ctx.beginPath();
                ctx.moveTo(153, 410); ctx.lineTo(153, 337);
                ctx.quadraticCurveTo(153, 320, 170, 320);
                ctx.lineTo(238, 320);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(707, 796); ctx.lineTo(707, 864);
                ctx.quadraticCurveTo(707, 884, 687, 884);
                ctx.lineTo(618, 884);
                ctx.stroke();

                const link = document.createElement('a');
                link.download = 'HydroMIS-' + (this.dataset.userId || 'Customer') + '-Access-Pass.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });
        }
    </script>
    <?php if ($success): ?><script src="js/user-notifications.js"></script><?php endif; ?>
