<?php
require_once '../config/database.php';
require_once '../config/storage_service.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);

$scanned_data = null;
$error = '';
$transaction_success = false;
$transaction_data = null;
$profile_success = '';
$mobile_login_value = '';

if (isset($_GET['approval_required'])) {
    $requiredStatus = strtolower(trim((string)$_GET['approval_required']));
    $error = $requiredStatus === 'denied'
        ? 'This account was not approved. Please contact HydroMIS support.'
        : 'Your account is still pending administrator approval. Ordering will be available after approval.';
}

// Handle QR Scan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $qr_raw_data = $_POST['qr_data'];
    
    // Try to decode JSON
    $qr_decoded = json_decode($qr_raw_data, true);
    
    if ($qr_decoded && isset($qr_decoded['user_id'])) {
        // Get user from database
        $user_id = sanitize($qr_decoded['user_id']);
        $sql = "SELECT * FROM users WHERE user_id = '$user_id'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $scanned_data = $result->fetch_assoc();
        } else {
            $error = 'User not found in database!';
        }
    } else {
        $error = 'Invalid QR code format!';
    }
}

// Handle Mobile Number Login (go to purchase/account area)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mobile_login'])) {
    $mobile_login_value = sanitize(trim($_POST['mobile_number'] ?? ''));
    $mobileDigits = preg_replace('/\D+/', '', $mobile_login_value);
    if (str_starts_with($mobileDigits, '63') && strlen($mobileDigits) === 12) $mobileDigits = '0' . substr($mobileDigits, 2);
    if (strlen($mobileDigits) === 10 && str_starts_with($mobileDigits, '9')) $mobileDigits = '0' . $mobileDigits;

    if (empty($mobileDigits)) {
        $error = 'Please enter your mobile number.';
    } elseif (!preg_match('/^09\d{9}$/', $mobileDigits)) {
        $error = 'Enter a valid Philippine mobile number.';
    } else {
        $safeMobile = $conn->real_escape_string($mobileDigits);
        $contact_lookup = sensitive_lookup($safeMobile);
        $sql = "SELECT * FROM users WHERE contact_lookup = '$contact_lookup' LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $scanned_data = $result->fetch_assoc();
        } else {
            $error = 'Mobile number not found. Please check and try again.';
        }
    }
}

// Transaction and profile handling moved to purchase.php
// Redirect to purchase page when user is scanned
if ($scanned_data && !isset($_POST['qr_data']) && !isset($_POST['mobile_login'])) {
    // Auto-redirect to purchase after successful scan
    // header('Location: purchase.php?user_id=' . urlencode($scanned_data['user_id']));
    // exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($scanned_data): ?><meta name="hydromis-user-id" content="<?php echo htmlspecialchars($scanned_data['user_id']); ?>"><?php endif; ?>
    <title>Scan QR Code - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/public-ui.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(to bottom right, #f0f9ff, #f0fdf4, #f0fdfa);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
        }
        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 12px 0;
        }
        .navbar-brand {
            font-size: 24px;
            font-weight: 800;
            color: #1f2937 !important;
            letter-spacing: -0.4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .navbar-brand i {
            color: #2563eb;
        }
        .nav-link {
            color: #4b5563 !important;
            margin-left: 20px;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 15px;
        }
        .nav-link:hover {
            color: #2563eb !important;
            transform: none;
        }
        .mobile-menu-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.45);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            z-index: 1050;
        }
        .mobile-menu-panel {
            position: absolute;
            top: 0;
            right: 0;
            width: min(85vw, 360px);
            height: 100%;
            background: #ffffff;
            transform: translateX(100%);
            transition: transform 0.25s ease;
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.18);
            overflow-y: auto;
        }
        .mobile-menu-link {
            display: block;
            margin: 0 !important;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #185f97 !important;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
        }
        .mobile-menu-link:hover {
            background: #f8fafc;
            color: #124c78 !important;
            text-decoration: none;
        }
        .mobile-menu-group {
            border-top: 1px solid #e5e7eb;
            padding: 14px 0;
        }
        .mobile-menu-group:first-of-type {
            border-top: none;
            padding-top: 0;
        }
        .mobile-menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 8px 2px;
            color: #374151;
            font-size: 18px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            background: transparent;
            text-align: left;
            cursor: pointer;
        }
        .mobile-menu-item:hover {
            color: #111827;
            text-decoration: none;
        }
        .mobile-menu-item i {
            width: 26px;
            color: #f97316;
            text-align: center;
        }
        .mobile-menu-item-secondary {
            padding-left: 2px;
            color: #374151;
            font-size: 16px;
            font-weight: 600;
        }
        .mobile-menu-item-secondary:hover {
            color: #111827;
        }
        body.mobile-nav-open {
            overflow: hidden;
        }
        body.mobile-nav-open .mobile-menu-overlay {
            opacity: 1;
            visibility: visible;
        }
        body.mobile-nav-open .mobile-menu-panel {
            transform: translateX(0);
        }
        .container-main {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 90vh;
            padding: 30px 20px;
            flex-wrap: wrap;
            gap: 30px;
        }
        .scan-success {
            position: relative;
            width: min(100%, 510px);
            overflow: hidden;
            padding: 46px 42px 34px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .82);
            border-radius: 26px;
            background: rgba(255, 255, 255, .93);
            box-shadow: 0 24px 60px rgba(30, 64, 100, .14), 0 4px 12px rgba(30, 64, 100, .06);
            animation: successEnter .58s cubic-bezier(.22, 1, .36, 1) both;
        }
        .scan-success::before {
            content: '';
            position: absolute;
            width: 260px;
            height: 260px;
            top: -176px;
            right: -90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(37, 99, 235, .14), rgba(37, 99, 235, 0) 68%);
            pointer-events: none;
        }
        .success-mark {
            display: grid;
            place-items: center;
            width: 64px;
            height: 64px;
            margin: 0 auto 19px;
            color: #fff;
            font-size: 27px;
            border-radius: 22px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            box-shadow: 0 13px 25px rgba(37, 99, 235, .27);
            animation: successMark .65s .16s cubic-bezier(.22, 1, .36, 1) both;
        }
        .success-eyebrow {
            margin: 0 0 7px;
            color: #2563eb;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .13em;
            text-transform: uppercase;
        }
        .scan-success h2 { margin: 0; color: #12263f; font-size: clamp(25px, 5vw, 31px); font-weight: 800; letter-spacing: -.045em; }
        .scan-success h2 span { color: #2563eb; }
        .success-copy { max-width: 360px; margin: 14px auto 28px; color: #60738a; font-size: 15px; line-height: 1.65; }
        .success-actions { display: grid; gap: 11px; max-width: 315px; margin: 0 auto; }
        .success-action { display: flex; align-items: center; justify-content: center; gap: 10px; min-height: 52px; padding: 13px 18px; border-radius: 14px; font-size: 15px; font-weight: 750; text-decoration: none !important; transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
        .success-action:hover { transform: translateY(-2px); }
        .success-action:active { transform: translateY(0); }
        .success-action.primary { color: #fff; background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 10px 20px rgba(37, 99, 235, .24); }
        .success-action.primary:hover { color: #fff; box-shadow: 0 14px 26px rgba(37, 99, 235, .32); }
        .success-action.secondary { color: #af6900; background: #fffaf0; border: 1px solid #f6c96e; }
        .success-action.secondary:hover { color: #915700; background: #fff4dc; box-shadow: 0 8px 18px rgba(180, 117, 14, .12); }
        .success-security { margin: 22px 0 0; color: #8291a4; font-size: 12px; }
        .success-security i { margin-right: 5px; color: #20a178; }
        .approval-lock{max-width:390px;margin:0 auto;padding:20px;border:1px solid #f4d38a;border-radius:16px;background:linear-gradient(145deg,#fffbeb,#fff7dc);text-align:left}.approval-lock.denied{border-color:#f1b5bf;background:linear-gradient(145deg,#fff5f6,#ffebee)}.approval-lock-icon{display:grid;place-items:center;width:42px;height:42px;margin-bottom:13px;border-radius:12px;background:#f59e0b;color:#fff;box-shadow:0 8px 18px rgba(245,158,11,.22)}.approval-lock.denied .approval-lock-icon{background:#e54861}.approval-lock h3{margin:0 0 7px;color:#573b08;font-size:16px;font-weight:800}.approval-lock.denied h3{color:#732333}.approval-lock p{margin:0;color:#806b43;font-size:12px;line-height:1.55}.approval-lock.denied p{color:#8b5963}.approval-next{display:flex;align-items:center;gap:7px;margin-top:13px;color:#8a650e;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
        @keyframes successEnter { from { opacity: 0; transform: translateY(20px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes successMark { from { opacity: 0; transform: scale(.55) rotate(-12deg); } to { opacity: 1; transform: scale(1) rotate(0); } }
        @media (max-width: 575px) { .scan-success { padding: 38px 24px 29px; border-radius: 22px; } .container-main { padding: 26px 16px; } }
        @media (prefers-reduced-motion: reduce) { .scan-success, .success-mark { animation: none; } .success-action { transition: none; } }
        .nav-links {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            background: #ffffff;
        }
        .card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        .card-body {
            padding: 30px;
        }
        .card-title {
            color: #1f2937;
            font-weight: 800;
            margin-bottom: 10px;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }
        .card-title i {
            color: #2563eb;
            font-size: 32px;
        }
        #video-container {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 4/3;
            max-width: 100%;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        #scanner {
            width: 100%;
            height: 100%;
            display: block;
        }
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 260px;
            height: 260px;
            border: 3px solid #10b981;
            border-radius: 12px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.4);
            animation: scannerPulse 2s infinite;
        }
        @keyframes scannerPulse {
            0%, 100% {
                box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.4);
            }
            50% {
                box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5), 0 0 40px rgba(16, 185, 129, 0.6);
            }
        }
        .scanner-corner {
            position: absolute;
            width: 24px;
            height: 24px;
            border: 3px solid #10b981;
        }
        .corner-tl {
            top: -3px;
            left: -3px;
            border-right: none;
            border-bottom: none;
        }
        .corner-tr {
            top: -3px;
            right: -3px;
            border-left: none;
            border-bottom: none;
        }
        .corner-bl {
            bottom: -3px;
            left: -3px;
            border-right: none;
            border-top: none;
        }
        .corner-br {
            bottom: -3px;
            right: -3px;
            border-left: none;
            border-top: none;
        }
        .status {
            text-align: center;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: none;
            letter-spacing: 0;
        }
        .status-waiting {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.1);
        }
        .status-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            box-shadow: 0 2px 8px rgba(6, 95, 70, 0.1);
        }
        .status-error {
            background: #fef2f2;
            color: #7f1d1d;
            border: 1px solid #fecaca;
            box-shadow: 0 2px 8px rgba(127, 29, 29, 0.1);
        }
        .btn-toggle {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            width: 100%;
            cursor: pointer;
            font-weight: 700;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            font-size: 15px;
            text-transform: none;
            letter-spacing: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-toggle:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        .btn-tracking {
            background: transparent;
            color: #14b8a6;
            border: 2px solid #14b8a6;
            padding: 11px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s ease;
            font-size: 15px;
            text-transform: none;
            letter-spacing: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-bottom: 0;
        }
        .btn-tracking:hover {
            background: #14b8a6;
            color: white;
            box-shadow: 0 6px 16px rgba(20, 184, 166, 0.3);
            transform: translateY(-2px);
        }
        .tab-button {
            transition: all 0.3s ease;
        }
        .tab-button:hover {
            color: #2563eb !important;
        }
        .user-info {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border-radius: 24px;
            box-shadow: 0 25px 70px rgba(102, 126, 234, 0.3);
            padding: 0;
            max-width: 520px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.6s ease;
        }
        .user-info::before {
            content: '';
            display: block;
            height: 8px;
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 50%, #fcd34d 100%);
            width: 100%;
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .user-info h5 {
            display: none;
        }
        .user-info-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 44px 40px;
            text-align: center;
        }
        .user-info-header h5 {
            display: block;
            margin: 0;
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 20px;
            letter-spacing: -0.4px;
        }
        .loyalty-points-display {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            margin: 0 -30px 30px -30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        .loyalty-points-display i {
            font-size: 24px;
        }
        .user-info-body {
            padding: 30px;
        }
        .loyalty-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            border-radius: 12px;
            padding: 16px 24px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .loyalty-badge i {
            font-size: 20px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f0f1f3;
            transition: background 0.3s ease;
        }
        .info-item:hover {
            background: rgba(102, 126, 234, 0.05);
            padding: 16px 12px;
            border-radius: 8px;
            margin: 0 -12px;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 16px;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-denied {
            background: #fee2e2;
            color: #7f1d1d;
        }
        .btn-back {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            margin-top: 20px;
            transition: all 0.3s ease;
            font-size: 15px;
            text-transform: none;
            letter-spacing: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-back:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        .hidden {
            display: none;
        }
        
        /* Additional Enhancement Styles */
        .card-title i {
            animation: iconFloat 3s ease-in-out infinite;
        }
        @keyframes iconFloat {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-5px);
            }
        }
        
        small {
            color: #6b7280;
            font-weight: 500;
        }
        
        /* Change Display Styling */
        .change-display {
            margin-top: 15px;
        }
        
        .change-item {
            padding: 12px 16px;
            background: linear-gradient(135deg, #d1fae5 0%, #d1f4f0 100%);
            border-radius: 10px;
            border-left: 4px solid #10b981;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            color: #047857;
        }
        
        /* Create Account Link */
        hr {
            border-color: #e5e7eb;
            margin: 25px 0;
        }
        
        .card-body p {
            color: #6b7280;
            text-align: center;
        }
        
        .card-body a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .card-body a:hover {
            color: #1d4ed8;
            transform: translateX(3px);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-group textarea {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 12px 16px;
            font-size: 14px;
            width: 100%;
            background: #f9fafb;
            transition: all 0.3s ease;
            resize: vertical;
            min-height: 120px;
            color: #1f2937;
        }
        .form-group textarea:focus {
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .error-message {
            color: #7f1d1d;
            padding: 14px 16px;
            background: #fee2e2;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1);
        }
        .scanner-width {
            max-width: 580px;
            width: 100%;
            background: #ffffff !important;
            border: 1px solid #e5e7eb;
        }
        .scanner-width .card-body {
            padding: 28px;
        }
        .buy-form {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 2px solid #10b981;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.1);
        }
        .buy-form h6 {
            color: #047857;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 15px;
            text-transform: none;
            letter-spacing: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .buy-form .form-control {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            margin-bottom: 10px;
            background: #ffffff;
            transition: all 0.3s ease;
            color: #1f2937;
        }
        .buy-form .form-control:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
            background: #f9fafb;
        }
        .buy-form .form-control::placeholder {
            color: #9ca3af;
        }
        .btn-buy {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s ease;
            font-size: 15px;
            text-transform: none;
            letter-spacing: 0;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-buy:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }
        .btn-buy:active {
            transform: translateY(0);
        }
        .transaction-success {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1f4f0 100%);
            border: 2px solid #10b981;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        .transaction-success h6 {
            color: #047857;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .transaction-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #d1fae5;
            font-size: 13px;
            color: #374151;
        }
        .transaction-detail:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #047857;
            font-weight: 600;
        }
        .detail-value {
            color: #065f46;
            font-weight: 500;
        }
        .price-breakdown {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border: 2px solid #e5e7eb;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .price-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            font-size: 15px;
        }
        .price-item:last-child {
            border-bottom: none;
        }
        .price-item.final {
            padding-top: 10px;
            border-top: 2px solid #10b981;
            color: #047857;
            font-weight: 700;
            margin-top: 8px;
            font-size: 16px;
        }
        .price-item.points {
            padding-top: 10px;
            color: #b45309;
            font-weight: 700;
            font-size: 15px;
        }
        .price-item.discount-row {
            color: #10b981;
            font-weight: 600;
        }
        .price-item.loyalty-row {
            color: #f59e0b;
            font-weight: 600;
        }
        .loyalty-points {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        
        /* Amount Input Styling */
        .amount-input {
            border: 2px solid #e5e7eb !important;
            padding: 14px 16px !important;
            border-radius: 10px !important;
            font-size: 16px !important;
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%) !important;
            transition: all 0.3s ease !important;
        }
        
        .amount-input:focus {
            border-color: #10b981 !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
        }
        
        .amount-input::placeholder {
            color: #d1d5db;
        }
        
        /* Status Badge Enhancements */
        .status-badge {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            animation: badgePulse 2s infinite;
        }
        @keyframes badgePulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.8;
            }
        }
        .badge-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            box-shadow: 0 4px 12px rgba(242, 158, 13, 0.15);
        }
        .badge-approved {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }
        .badge-denied {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
        }
        
        /* Receipt Styling - Premium */
        .receipt {
            background: white;
            border-radius: 16px;
            padding: 0;
            margin: 30px 0;
            max-width: 480px;
            font-family: 'Courier New', monospace;
            box-shadow: 0 15px 50px rgba(102, 126, 234, 0.2);
            overflow: hidden;
            border: 2px solid #f0f1f3;
            transition: transform 0.3s ease;
        }
        .receipt:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.25);
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 25px;
            text-align: center;
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 2px;
            box-shadow: 0 8px 16px rgba(16, 185, 129, 0.2);
        }
        
        .receipt-line {
            border-top: 2px dashed #d1d5db;
            margin: 16px 0;
        }
        
        .receipt-section {
            padding: 16px 25px;
        }
        
        .receipt-header-small {
            font-weight: 700;
            color: #047857;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 12px;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .receipt-label {
            color: #374151;
            font-weight: 500;
            flex: 1;
        }
        
        .receipt-value {
            color: #1f2937;
            font-weight: 600;
            text-align: right;
            flex: 1;
        }
        
        .receipt-item-row {
            display: flex;
            flex-wrap: wrap;
            padding: 8px 0;
            font-size: 12px;
            border-bottom: 1px solid #f3f4f6;
            line-height: 1.8;
        }
        
        .receipt-item-row:last-child {
            border-bottom: none;
        }
        
        .receipt-item-desc {
            flex: 0 0 50%;
            color: #374151;
            font-weight: 600;
        }
        
        .receipt-item-qty {
            flex: 0 0 15%;
            text-align: center;
            color: #6b7280;
        }
        
        .receipt-item-unit {
            flex: 0 0 25%;
            color: #6b7280;
        }
        
        .receipt-item-total {
            flex: 0 0 100%;
            text-align: right;
            color: #1f2937;
            font-weight: 700;
            margin-top: 3px;
        }
        
        .amount-due {
            background: #f0fdf4;
            padding: 8px 12px;
            border-radius: 6px;
            margin: 8px 0;
            font-weight: 700;
        }
        
        .amount-due .receipt-value {
            color: #10b981;
            font-size: 14px;
        }
        
        .change-row {
            background: linear-gradient(90deg, #dbeafe 0%, #d1fae5 100%);
            padding: 10px 12px;
            border-radius: 6px;
            margin: 8px 0;
            font-weight: 700;
        }
        
        .change-row .receipt-value {
            color: #047857;
            font-size: 15px;
        }
        
        .discount-row {
            color: #10b981;
            font-weight: 600;
        }
        
        .loyalty-row {
            background: #fffbeb;
            padding: 8px 12px;
            border-radius: 6px;
            color: #b45309;
            font-weight: 700;
        }
        
        .receipt-footer {
            background: #f9fafb;
            padding: 15px 20px;
            text-align: center;
            border-radius: 0 0 10px 10px;
            border-top: 1px solid #e5e7eb;
        }
        
        .receipt-footer p {
            margin: 4px 0;
            font-size: 12px;
            color: #6b7280;
        }
        
        .receipt-footer p:first-child {
            color: #10b981;
            font-weight: 700;
            font-size: 13px;
        }
        
        /* Water Type Button Styling */
        .btn-water-type {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
            border: 2px solid #d1d5db;
            padding: 14px 18px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .btn-water-type:hover {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #047857;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        
        .btn-water-type.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-color: #047857;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.35);
            transform: scale(1.02);
        }
        
        .btn-water-type.active:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
        }
        
        .btn-water-type i {
            font-size: 18px;
        }

        /* Realistic purchase area inspired by app rewards UI */
        .purchase-shell {
            width: 100%;
            max-width: 1080px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 24px;
            align-items: start;
            background: #f3f4f6;
            border-radius: 24px;
            padding: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }
        .rewards-panel {
            background: #f8fafc;
            border-radius: 18px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        .points-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .points-strip-title {
            color: #111827;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .points-chip {
            background: #111827;
            color: #f9fafb;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        a.points-chip {
            text-decoration: none;
        }
        a.points-chip:hover {
            color: #f9fafb;
            text-decoration: none;
            filter: brightness(1.08);
        }
        .hero-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-top: 4px;
            margin-bottom: 18px;
        }
        .profile-editor-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        .profile-editor-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .profile-editor-head h6 {
            margin: 0;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .profile-chip {
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            text-transform: uppercase;
        }
        .profile-edit-btn {
            border: none;
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: #ffffff;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 11px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            box-shadow: 0 6px 14px rgba(79, 70, 229, 0.25);
        }
        .profile-edit-btn:hover {
            filter: brightness(1.05);
        }
        .profile-edit-btn-bottom {
            width: 100%;
            justify-content: center;
            margin-top: 12px;
        }
        .profile-summary {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .profile-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 0;
        }
        .profile-summary-row:last-child {
            border-bottom: none;
        }
        .profile-summary-row span {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .profile-summary-row strong {
            color: #111827;
            font-size: 13px;
            font-weight: 700;
            text-align: right;
        }
        .profile-edit-form {
            margin-top: 4px;
        }
        .profile-photo-block {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding: 10px;
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            background: #f8fafc;
        }
        .profile-photo-avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.25);
        }
        .profile-photo-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .profile-photo-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 100%;
            flex: 1;
            min-width: 0;
        }
        .profile-user-name {
            margin: 0;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .profile-user-code {
            margin: 0;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1.35;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .photo-upload-label {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            padding: 8px 10px;
            cursor: pointer;
            text-align: center;
            margin: 0;
        }
        .photo-upload-hint {
            color: #6b7280;
            font-size: 11px;
            margin: 0;
            line-height: 1.3;
        }
        .profile-field {
            margin-bottom: 10px;
        }
        .profile-field:last-of-type {
            margin-bottom: 12px;
        }
        .profile-field label {
            display: block;
            margin-bottom: 5px;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .profile-input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #f9fafb;
            color: #111827;
            font-size: 13px;
            padding: 10px 11px;
            transition: all 0.25s ease;
        }
        .profile-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.14);
            background: #ffffff;
        }
        .profile-save {
            width: 100%;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
            padding: 10px 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.28);
            transition: all 0.25s ease;
        }
        .profile-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.35);
        }
        .profile-alert {
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .profile-alert-success {
            color: #065f46;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
        }
        .profile-alert-error {
            color: #7f1d1d;
            border: 1px solid #fecaca;
            background: #fef2f2;
        }
        .rewards-header {
            margin-top: 22px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .rewards-header h6 {
            margin: 0;
            font-size: 32px;
            color: #111827;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .rewards-header span {
            color: #2563eb;
            font-weight: 700;
            font-size: 14px;
        }
        .rewards-convert-link {
            color: #0f766e;
            font-weight: 800;
            font-size: 13px;
            border: 1px solid #99f6e4;
            background: #ecfeff;
            border-radius: 999px;
            padding: 6px 11px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .rewards-convert-link:hover {
            color: #115e59;
            text-decoration: none;
            background: #ccfbf1;
        }
        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .reward-item {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px;
            min-height: 132px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }
        .reward-item strong {
            color: #111827;
            font-size: 14px;
            line-height: 1.35;
        }
        .reward-item p {
            margin: 6px 0 0;
            color: #6b7280;
            font-size: 12px;
            text-align: left;
        }
        .reward-pill {
            align-self: flex-start;
            margin-top: 10px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #d1d5db;
        }
        .reward-pill-form {
            align-self: flex-start;
            margin-top: 10px;
        }
        .reward-pill-btn {
            background: #f3f4f6;
            color: #374151;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #d1d5db;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .reward-pill-btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .reward-item.unlocked {
            border-color: #10b981;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        }
        .reward-item.unlocked .reward-pill,
        .reward-item.unlocked .reward-pill-btn {
            background: #ecfdf5;
            border-color: #6ee7b7;
            color: #047857;
        }
        .checkout-panel {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e5e7eb;
            padding: 18px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }
        .member-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
        }
        .member-title {
            margin: 0 0 10px;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .member-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-top: 1px dashed #d1d5db;
            padding-top: 10px;
            margin-top: 8px;
        }
        .member-meta span {
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
        }
        .member-meta strong {
            display: block;
            color: #1f2937;
            font-size: 13px;
            margin-top: 3px;
        }
        .checkout-panel .buy-form,
        .checkout-panel .receipt {
            margin-top: 0;
            margin-bottom: 0;
        }

        .water-type-grid {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 992px) {
            .container-main {
                padding: 18px 12px;
                gap: 16px;
            }
            .purchase-shell {
                grid-template-columns: 1fr;
                padding: 14px;
                border-radius: 18px;
            }
            .checkout-panel {
                padding: 14px;
            }
            .rewards-panel {
                padding: 14px;
            }
            .rewards-header h6 {
                font-size: 26px;
            }
            .receipt {
                max-width: 100%;
                margin: 16px 0;
            }
            .btn-back {
                margin-top: 16px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 10px 0;
            }
            .navbar .container-fluid {
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding-left: 12px;
                padding-right: 12px;
            }
            .navbar-brand {
                font-size: 21px;
            }
            .ml-auto.nav-links {
                display: none;
            }
            .nav-link {
                margin-left: 0;
                font-size: 13px;
                padding: 4px 2px;
            }
            .points-strip {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .points-chip {
                font-size: 13px;
                padding: 7px 12px;
            }
            .rewards-header {
                margin-top: 16px;
                margin-bottom: 10px;
            }
            .rewards-header h6 {
                font-size: 22px;
            }
            .profile-editor-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .profile-edit-btn {
                width: 100%;
                justify-content: center;
            }
            .water-type-grid {
                flex-direction: column;
            }
            .buy-form {
                padding: 14px;
                margin-top: 14px;
                margin-bottom: 14px;
            }
            .buy-form h6 {
                font-size: 14px;
                margin-bottom: 12px;
            }
            .btn-buy,
            .btn-back,
            .btn-toggle,
            .btn-tracking {
                min-height: 48px;
                font-size: 14px;
                letter-spacing: 0.3px;
            }
            .price-item {
                font-size: 14px;
            }
            .price-item.final {
                font-size: 15px;
            }
            .scanner-width .card-body {
                padding: 18px;
            }
            #video-container {
                margin-bottom: 16px;
            }
            .scanner-frame {
                width: 210px;
                height: 210px;
            }
            .receipt-section {
                padding: 12px 14px;
            }
            .receipt-row {
                font-size: 12px;
            }
            .receipt-item-row {
                font-size: 11px;
            }
        }

        @media (max-width: 600px) {
            .container-main {
                padding: 14px 8px;
            }
            .purchase-shell {
                padding: 10px;
                border-radius: 14px;
            }
            .rewards-panel,
            .checkout-panel {
                border-radius: 12px;
                padding: 10px;
            }
            .rewards-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .hero-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .profile-editor-card {
                padding: 12px;
            }
            .profile-editor-head h6 {
                font-size: 14px;
            }
            .profile-edit-btn {
                font-size: 11px;
                padding: 7px 9px;
                width: 100%;
                justify-content: center;
            }
            .profile-photo-block {
                gap: 8px;
                padding: 8px;
            }
            .profile-photo-avatar {
                width: 56px;
                height: 56px;
            }
            .profile-user-name {
                font-size: 12px;
            }
            .profile-user-code {
                font-size: 10px;
                letter-spacing: 0.15px;
            }
            .points-strip-title {
                font-size: 17px;
            }
            .reward-item {
                min-height: 110px;
                padding: 12px;
            }
            .reward-item strong {
                font-size: 13px;
            }
            .reward-item p {
                font-size: 11px;
            }
            .price-breakdown {
                padding: 12px;
            }
            .form-group label {
                font-size: 13px;
            }
            .scanner-frame {
                width: 180px;
                height: 180px;
            }
            .scanner-corner {
                width: 18px;
                height: 18px;
            }
        }

        @media (max-width: 380px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .profile-photo-avatar {
                width: 60px;
                height: 60px;
            }
            .profile-user-name {
                font-size: 14px;
            }
            .profile-user-code {
                font-size: 11px;
            }
            .rewards-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Shared public UI alignment overrides */
        body.public-ui {
            min-height: 100vh;
            padding: 0 0 24px;
        }
        .navbar {
            background: rgba(244, 248, 251, 0.86);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(16, 38, 58, 0.08);
            padding: 12px 0;
        }
        .navbar-brand {
            color: #10263a !important;
            font-weight: 800;
            font-size: 28px;
            letter-spacing: -0.4px;
        }
        .nav-link {
            color: #185f97 !important;
            font-weight: 700;
            margin-left: 18px;
        }
        .nav-link:hover {
            color: #124c78 !important;
            transform: none;
        }
        .scanner-width {
            max-width: 660px;
            border: 1px solid rgba(16, 38, 58, 0.08);
            box-shadow: 0 20px 45px rgba(8, 33, 55, 0.14);
        }
        .scanner-width .card-body {
            padding: 26px;
        }
        .card-title {
            font-size: 42px;
            letter-spacing: -0.6px;
            margin-bottom: 18px;
        }
        .status {
            border-radius: 12px;
            text-transform: none;
            letter-spacing: 0;
            font-size: 16px;
            font-weight: 700;
            padding: 12px 14px;
        }
        .status-waiting {
            background: #edf7ff;
            color: #1f4f77;
            border: 1px solid #cde3f3;
            box-shadow: none;
        }
        .btn-toggle {
            background: linear-gradient(135deg, #145c9e 0%, #1c75bc 100%);
            border-radius: 12px;
            text-transform: none;
            letter-spacing: 0;
            box-shadow: 0 10px 20px rgba(20, 92, 158, 0.26);
        }
        .btn-toggle:hover {
            background: linear-gradient(135deg, #124c84 0%, #185f97 100%);
            box-shadow: 0 14px 24px rgba(20, 92, 158, 0.3);
        }

        /* Premium customer access experience */
        :root{--login-ink:#10263a;--login-blue:#1769d2;--login-aqua:#08b8c8;--login-ease:cubic-bezier(.22,1,.36,1)}
        body.public-ui{position:relative;isolation:isolate;background-color:#dff4fa;background-image:linear-gradient(115deg,rgba(235,249,252,.36),rgba(200,235,244,.2)),url('../imagess/customer-login-light-lab-v2.png');background-repeat:no-repeat;background-size:cover;background-position:50% 48%;background-attachment:fixed;overflow-x:hidden;animation:customerLabDrift 18s ease-in-out infinite alternate}
        body.public-ui::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 50% 42%,rgba(255,255,255,.2),transparent 54%),linear-gradient(180deg,rgba(220,245,250,.03),rgba(91,185,207,.12))}
        body.public-ui::after{content:'';position:fixed;inset:-32%;z-index:0;pointer-events:none;background:linear-gradient(112deg,transparent 39%,rgba(255,255,255,.44) 49%,transparent 59%);transform:translateX(-46%) rotate(3deg);animation:customerLightSweep 9s ease-in-out infinite}
        body.public-ui>.navbar,body.public-ui>.container-main,body.public-ui>.mobile-menu-overlay{position:relative;z-index:2}
        .navbar{position:relative;z-index:10;background:rgba(255,255,255,.76)!important;backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:0 8px 28px rgba(15,52,78,.06);animation:loginNavIn .65s var(--login-ease) both}
        .navbar-brand img{width:38px!important;height:38px!important;padding:4px;border-radius:11px;background:linear-gradient(135deg,#1376ca,#08aeba);box-shadow:0 8px 20px rgba(8,126,170,.2)}
        #mobile-menu-button{width:44px!important;height:44px!important;border-radius:12px!important;transition:transform .2s ease,box-shadow .2s ease}#mobile-menu-button:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(16,38,58,.1)}
        .container-main{min-height:calc(100vh - 74px);align-items:center;padding:40px 20px 60px}
        .scanner-width{--tilt-x:0deg;--tilt-y:0deg;position:relative;max-width:540px!important;border:1px solid rgba(255,255,255,.96)!important;border-radius:26px!important;background:linear-gradient(145deg,rgba(255,255,255,.88),rgba(224,246,251,.76))!important;box-shadow:0 46px 100px rgba(23,91,120,.28),0 18px 36px rgba(25,111,143,.16),inset 0 2px rgba(255,255,255,.98),inset 0 -1px rgba(91,183,205,.18)!important;backdrop-filter:blur(18px) saturate(1.18);transform-style:preserve-3d;transform:perspective(900px) rotateX(var(--tilt-x)) rotateY(var(--tilt-y)) translateZ(0);transition:transform .16s ease-out,box-shadow .45s ease,border-color .45s ease;animation:loginCardIn .8s .08s var(--login-ease) both;will-change:transform}
        .scanner-width::after{content:'';position:absolute;inset:12px -10px -18px 12px;z-index:-1;border-radius:28px;background:linear-gradient(145deg,rgba(40,161,195,.22),rgba(36,105,172,.08));filter:blur(13px);transform:translateZ(-34px);pointer-events:none}
        .scanner-width.tilt-ready:hover{border-color:rgba(74,190,218,.82)!important;box-shadow:0 58px 120px rgba(20,82,111,.32),0 24px 44px rgba(25,111,143,.2),inset 0 2px rgba(255,255,255,1)!important}
        .scanner-width::before{content:'';position:absolute;width:240px;height:240px;right:-150px;top:-155px;border-radius:50%;background:radial-gradient(circle,rgba(8,184,200,.16),transparent 68%);pointer-events:none}
        .scanner-width .card-body{position:relative;padding:42px!important;transform:translateZ(28px);transform-style:preserve-3d}
        .login-hero{text-align:center;margin-bottom:26px;transform:translateZ(18px)}.login-icon{display:grid;place-items:center;width:66px;height:66px;margin:0 auto 18px;border-radius:21px;color:#fff!important;font-size:26px!important;background:linear-gradient(145deg,#1769d2,#08b8c8);box-shadow:0 18px 34px rgba(23,105,210,.34),inset 0 1px rgba(255,255,255,.36);animation:loginIconIn .7s .3s var(--login-ease) both}.login-hero h2{color:var(--login-ink)!important;font-size:29px!important;font-weight:800!important;letter-spacing:-.04em}.login-hero p{color:#71869b!important;margin:0}.security-note{display:inline-flex;align-items:center;gap:6px;margin-top:11px;color:#16836f;font-size:11px;font-weight:700}.login-tabs{position:relative;display:flex;padding:4px;margin-bottom:25px;border:1px solid #cbdde6;border-radius:14px;background:#e5f0f5;box-shadow:inset 0 2px 5px rgba(42,91,113,.12)}.tab-button{position:relative;z-index:1;min-height:44px;border-radius:11px!important;color:#61758a!important;transition:color .25s ease,background .25s ease,box-shadow .25s ease,transform .2s ease!important}.tab-button.active{color:var(--login-ink)!important;background:#fff!important;box-shadow:0 8px 18px rgba(26,75,96,.16),inset 0 1px rgba(255,255,255,.9)!important;transform:translateY(-1px)}.tab-button i{margin-right:6px;color:#1785c7}.login-tab-panel.tab-enter{animation:tabEnter .36s var(--login-ease) both}.login-form label{color:var(--login-ink)!important;font-size:13px!important}.mobile-field{position:relative}.mobile-field .country-code{position:absolute;z-index:2;left:15px;top:50%;transform:translateY(-50%);padding-right:12px;border-right:1px solid #d7e3ec;color:#506b82;font-size:13px;font-weight:800}.mobile-field .form-control{height:56px!important;padding-left:70px!important;border:1px solid #cbdfe8!important;border-radius:14px!important;background:rgba(248,252,254,.88)!important;box-shadow:inset 0 2px 5px rgba(43,95,118,.07)!important;font-size:16px;letter-spacing:.04em;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease,transform .2s ease}.mobile-field .form-control:hover{border-color:#91bfd0!important;background:#fff!important;transform:translateY(-1px)}.mobile-field .form-control:focus{border-color:var(--login-aqua)!important;box-shadow:0 0 0 4px rgba(8,184,200,.14),0 12px 28px rgba(14,70,100,.12)!important;transform:translateY(-2px)}.login-helper{display:flex;align-items:flex-start;gap:7px;color:#71869b!important;font-size:12px!important;line-height:1.5}.login-helper i{margin-top:3px;color:#27a88f}.btn-toggle{position:relative;min-height:55px;overflow:hidden;border-radius:14px!important;background:linear-gradient(120deg,#1769d2,#08aeca,#1769d2)!important;background-size:180% 180%!important;box-shadow:0 16px 34px rgba(23,105,210,.32),inset 0 1px rgba(255,255,255,.24)!important;transition:transform .2s ease,box-shadow .2s ease!important;animation:loginGradient 6s ease infinite}.btn-toggle::after{content:'';position:absolute;inset:0;transform:translateX(-120%) skewX(-20deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.28),transparent);transition:transform .7s var(--login-ease)}.btn-toggle:hover::after{transform:translateX(120%) skewX(-20deg)}.btn-toggle:hover{transform:translateY(-3px)!important;box-shadow:0 22px 42px rgba(23,105,210,.4)!important}.btn-toggle:active,.btn-tracking:active{transform:scale(.985)!important}.btn-tracking{min-height:52px;border-radius:14px!important;background:rgba(255,255,255,.78);box-shadow:0 8px 20px rgba(36,104,130,.1);transition:transform .2s ease,background .2s ease,box-shadow .2s ease!important}.register-prompt{padding-top:4px;text-align:center}.register-prompt a{display:inline!important}.error-message{animation:errorIn .42s var(--login-ease) both}
        #video-container{border-radius:18px;background:#07111d;box-shadow:0 20px 42px rgba(4,15,28,.25)}.scanner-frame{border:0;box-shadow:0 0 0 9999px rgba(2,8,18,.48);animation:none}.scanner-corner{width:32px;height:32px;border-color:#34e4c0;border-width:4px}.scanner-line{position:absolute;left:8px;right:8px;top:12px;height:2px;border-radius:2px;background:linear-gradient(90deg,transparent,#55f5d3,transparent);box-shadow:0 0 12px #27dfba;animation:scanLine 2.4s ease-in-out infinite}.status-waiting{background:#eff9ff;border-color:#c7e7f2;color:#176484}.scanner-trust{display:flex;justify-content:center;gap:16px;margin:13px 0;color:#71869b;font-size:10px}.scanner-trust span{display:flex;align-items:center;gap:5px}.scanner-trust i{color:#1aa78f}
        @keyframes loginNavIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}@keyframes loginCardIn{from{opacity:0;transform:translateY(28px) scale(.985)}to{opacity:1;transform:none}}@keyframes loginIconIn{from{opacity:0;transform:scale(.65) rotate(-8deg)}to{opacity:1;transform:none}}@keyframes loginRing{from{opacity:.35;transform:scale(.9)}to{opacity:1;transform:scale(1.08)}}@keyframes loginGradient{0%,100%{background-position:0 50%}50%{background-position:100% 50%}}@keyframes tabEnter{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}@keyframes errorIn{0%{opacity:0;transform:translateX(-7px)}50%{transform:translateX(4px)}100%{opacity:1;transform:none}}@keyframes scanLine{0%,100%{top:12px;opacity:.4}50%{top:calc(100% - 14px);opacity:1}}@keyframes customerLabDrift{0%{background-position:44% 48%}50%{background-position:50% 52%}100%{background-position:57% 47%}}@keyframes customerLightSweep{0%,18%{opacity:0;transform:translateX(-46%) rotate(3deg)}48%{opacity:.8}78%,100%{opacity:0;transform:translateX(46%) rotate(3deg)}}
        a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid rgba(8,184,200,.3)!important;outline-offset:3px}
        @media(max-width:575px){body.public-ui{background-attachment:scroll;background-position:50% 48%}.container-main{align-items:flex-start;padding:20px 12px 36px}.scanner-width{border-radius:22px!important;transform:none!important}.scanner-width .card-body{padding:30px 22px!important}.login-icon{width:58px;height:58px;border-radius:18px}.login-hero h2{font-size:25px!important}.navbar-brand{font-size:25px}.navbar-brand img{width:34px!important;height:34px!important}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
        /* Preserve click/touch hit-testing above the decorative 3D layers. */
        body.public-ui > .navbar{display:none!important}
        .container-main{min-height:100vh!important;align-items:center!important;justify-content:center!important;padding:28px 16px!important}
        .scanner-width{width:min(92vw,540px)!important;margin:auto!important}
        .login-tabs{z-index:30!important;width:min(100%,350px);margin:0 auto 25px!important;padding:0!important;gap:18px;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;pointer-events:auto!important;transform:translateZ(42px)}
        .login-tabs .tab-button{position:relative;z-index:31!important;min-height:44px!important;padding:9px 12px 12px!important;border:0!important;border-radius:9px!important;background:transparent!important;color:#6d8294!important;box-shadow:none!important;font-size:13px!important;font-weight:600!important;pointer-events:auto!important;touch-action:manipulation;cursor:pointer;transition:color .25s ease,background .25s ease,transform .25s ease!important}
        .login-tabs .tab-button::after{content:'';position:absolute;left:18%;right:18%;bottom:2px;height:3px;border-radius:99px;background:linear-gradient(90deg,#16c8b7,#168fe3);box-shadow:0 2px 8px rgba(17,174,208,.3);transform:scaleX(0);transition:transform .32s cubic-bezier(.22,1,.36,1)}
        .login-tabs .tab-button.active{color:#12304a!important;background:rgba(255,255,255,.45)!important;box-shadow:none!important;transform:translateY(-1px)}
        .login-tabs .tab-button.active::after{transform:scaleX(1)}
        .login-tabs .tab-button:hover{color:#176f9e!important;background:rgba(255,255,255,.35)!important}
        .login-tabs .tab-button i{color:#159bc4!important;transition:transform .25s ease}.login-tabs .tab-button.active i{transform:translateY(-1px) scale(1.06)}
        /* Latest HydroMIS mark: transparent, animated, and free of the old square tile. */
        .login-icon{position:relative!important;isolation:isolate;display:grid!important;place-items:center!important;width:92px!important;height:92px!important;margin:0 auto 18px!important;border:0!important;border-radius:50%!important;background:transparent!important;box-shadow:none!important;overflow:visible!important;animation:customerLogoEnter .7s cubic-bezier(.22,1,.36,1) both,customerLogoFloat 4.6s .7s ease-in-out infinite!important;transition:transform .35s cubic-bezier(.22,1,.36,1),filter .35s ease!important}
        .login-icon::before{content:'';position:absolute;inset:5px;z-index:-2;border-radius:50%;background:radial-gradient(circle,rgba(72,225,255,.32) 0,rgba(24,155,229,.13) 48%,transparent 72%);filter:blur(7px);animation:customerLogoAura 3.2s ease-in-out infinite!important}
        .login-icon::after{content:'';position:absolute;inset:0;z-index:-1;border-radius:50%;padding:2px;background:conic-gradient(from 30deg,transparent 0 20%,#35d9eb 31%,#2389ec 45%,transparent 57% 76%,rgba(88,239,220,.9) 88%,transparent 100%);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;filter:drop-shadow(0 0 5px rgba(31,177,224,.65));animation:customerLogoOrbit 5.5s linear infinite!important}
        .login-icon img{display:block;width:84px!important;height:84px!important;object-fit:contain!important;border:0!important;border-radius:0!important;outline:0!important;background:transparent!important;box-shadow:none!important;filter:drop-shadow(0 9px 13px rgba(6,78,139,.24));animation:none!important;transition:transform .5s cubic-bezier(.22,1,.36,1),filter .35s ease!important}
        .login-icon:hover{transform:translateY(-4px) scale(1.04);filter:brightness(1.06)}
        .login-icon:hover img{transform:rotate(4deg) scale(1.06);filter:drop-shadow(0 13px 17px rgba(6,78,139,.34))}
        .login-icon:hover::after{animation-duration:2.7s!important}
        .station-login-note{display:flex;align-items:flex-start;gap:10px;margin:0 0 20px;padding:12px 14px;border:1px solid rgba(21,163,188,.2);border-radius:13px;background:linear-gradient(135deg,rgba(231,250,251,.88),rgba(238,247,255,.78));color:#42677c;font-size:12px;line-height:1.5;box-shadow:inset 0 1px rgba(255,255,255,.85)}
        .station-login-note i{flex:0 0 auto;margin-top:2px;color:#13a5ad;font-size:15px}.station-login-note strong{color:#164660;font-weight:700}
        .register-prompt{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:5px;margin-top:17px;padding-top:15px;border-top:1px solid rgba(103,149,171,.2)}
        .register-prompt span{color:#526b7e!important;font-size:13px!important}.register-prompt a{display:inline-flex!important;align-items:center;gap:5px;color:#087fba!important;font-size:13px!important;font-weight:700!important;text-decoration:none!important;transition:color .2s ease,transform .2s ease!important}.register-prompt a:hover{color:#05a9b8!important;transform:translateX(2px)}
        @keyframes customerLogoEnter{from{opacity:0;transform:scale(.62) rotate(-10deg)}to{opacity:1;transform:none}}
        @keyframes customerLogoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
        @keyframes customerLogoAura{0%,100%{opacity:.55;transform:scale(.92)}50%{opacity:1;transform:scale(1.1)}}
        @keyframes customerLogoOrbit{to{transform:rotate(360deg)}}
        @media(max-width:575px){.container-main{align-items:center!important;padding:20px 12px!important}.scanner-width{width:min(94vw,520px)!important;transform:none!important;transform-style:flat!important}.scanner-width .card-body,.login-hero,.login-tabs{transform:none!important}}
        @media(max-width:575px) and (max-height:700px){.container-main{align-items:flex-start!important}}
        /* Keep the signed-out customer login within one viewport. */
        body.login-viewport{width:100%;height:100dvh;min-height:0;padding:0!important;overflow:hidden}
        body.login-viewport .container-main{width:100%;height:100dvh;min-height:0!important;padding:clamp(10px,2.5vh,22px) 12px!important;overflow:hidden}
        body.login-viewport #mainLoginCard{max-height:calc(100dvh - clamp(20px,5vh,44px));overflow:hidden}
        @media(max-height:850px){
            body.login-viewport .scanner-width .card-body{padding:clamp(18px,3vh,27px)!important}
            body.login-viewport .login-hero{margin-bottom:14px}
            body.login-viewport .login-icon{width:68px!important;height:68px!important;margin-bottom:9px!important}
            body.login-viewport .login-icon img{width:62px!important;height:62px!important}
            body.login-viewport .login-hero h2{margin-bottom:4px!important;font-size:24px!important}
            body.login-viewport .login-hero p{font-size:12px!important}
            body.login-viewport .security-note{margin-top:6px;font-size:10px}
            body.login-viewport .login-tabs{margin-bottom:14px!important}
            body.login-viewport .login-tabs .tab-button{min-height:40px!important;padding:7px 10px 9px!important}
            body.login-viewport .login-form label{margin-bottom:7px!important}
            body.login-viewport .mobile-field .form-control{height:50px!important;margin-bottom:7px!important}
            body.login-viewport .login-helper{margin-bottom:10px!important;font-size:10px!important}
            body.login-viewport .btn-toggle{min-height:48px}
            body.login-viewport .register-prompt{margin-top:11px;padding-top:10px}
            body.login-viewport .register-prompt span,body.login-viewport .register-prompt a{font-size:11px!important}
        }
        @media(max-width:575px) and (max-height:700px){body.login-viewport .container-main{align-items:center!important}}
        /* Keep the account verification result within one viewport. */
        body.account-viewport{width:100%;height:100dvh;min-height:0;padding:0!important;overflow:hidden}
        body.account-viewport .container-main{width:100%;height:100dvh;min-height:0!important;padding:12px!important;overflow:hidden}
        body.account-viewport .container-main>div{display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;height:100%!important;padding:0!important}
        body.account-viewport .scan-success{max-height:calc(100dvh - 24px);margin:auto}
        @media(max-height:650px){
            body.account-viewport .scan-success{padding:24px 20px 20px}
            body.account-viewport .success-mark{width:52px;height:52px;margin-bottom:11px;border-radius:17px;font-size:22px}
            body.account-viewport .success-eyebrow{margin-bottom:4px;font-size:9px}
            body.account-viewport .scan-success h2{font-size:22px}
            body.account-viewport .success-copy{margin:9px auto 15px;font-size:12px;line-height:1.45}
            body.account-viewport .success-actions{gap:8px}
            body.account-viewport .success-action{min-height:44px;padding:9px 14px;font-size:13px}
            body.account-viewport .success-security{margin-top:13px;font-size:10px}
            body.account-viewport .approval-lock{padding:14px}
        }
        @media(prefers-reduced-motion:reduce){.login-icon,.login-icon::before,.login-icon::after{animation:none!important}.login-icon,.login-icon img{transition:none!important}}
    </style>
    <script src="../js/ui-protection.js" defer></script>
</head>
<body class="public-ui <?php echo empty($scanned_data) ? 'login-viewport' : 'account-viewport'; ?>">
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <span class="navbar-brand"><img src="<?php echo htmlspecialchars(hydromis_asset_url($systemLogo, '../')); ?>" alt="HydroMIS Logo" style="width: 24px; height: 24px; object-fit: contain; margin-right: 8px;">HydroMIS</span>
            <button id="mobile-menu-button" type="button" class="d-md-none d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#4b5563;" aria-controls="mobile-menu-panel" aria-expanded="false" aria-label="Toggle navigation">
                <i id="mobile-menu-icon" class="fas fa-bars"></i>
            </button>
        </div>

    </nav>

    <div id="mobile-menu-overlay" class="mobile-menu-overlay d-md-none" aria-hidden="true">
        <aside id="mobile-menu-panel" class="mobile-menu-panel" role="dialog" aria-modal="true" aria-label="Mobile navigation">
            <div class="d-flex align-items-center justify-content-between px-3 py-3" style="border-bottom:1px solid #e5e7eb;">
                <div class="d-flex align-items-center" style="gap:8px;">
                    <img src="<?php echo htmlspecialchars(hydromis_asset_url($systemLogo, '../')); ?>" alt="HydroMIS Logo" style="width: 28px; height: 28px; object-fit: contain; color:#2563eb;">
                    <span style="font-size:24px;font-weight:800;color:#1f2937;letter-spacing:-0.3px;">HydroMIS</span>
                </div>
                <button id="mobile-menu-close" type="button" class="d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;border:none;border-radius:8px;background:#fff;color:#4b5563;" aria-label="Close navigation">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="px-3 py-3">
                <div class="mobile-menu-group">
                    <a href="./rewards.php" class="mobile-menu-item mobile-menu-item-secondary">Rewards</a>
                    <button type="button" class="mobile-menu-item" onclick="shareWithFriends()">
                        <i class="fas fa-share-alt"></i>
                        <span>Share with friends</span>
                    </button>
                </div>

                <div class="mobile-menu-group">
                    <a href="mailto:hydromis.support@gmail.com" class="mobile-menu-item mobile-menu-item-secondary">Contact us</a>
                </div>

                <div class="mobile-menu-group">
                    <a href="../terms.php" class="mobile-menu-item mobile-menu-item-secondary">Terms & conditions</a>
                    <a href="../privacy.php" class="mobile-menu-item mobile-menu-item-secondary">Privacy terms</a>
                </div>
            </div>
        </aside>
    </div>

    <div class="container-main">
        <?php if ($scanned_data): ?>
        <!-- Redirect to Purchase Page -->
        <div style="width: 100%; display: flex; justify-content: center; padding: 30px 20px;">
            <section class="scan-success" aria-labelledby="scan-success-title">
                <div class="success-mark" aria-hidden="true">
                    <i class="fas fa-check-circle"></i>
                </div>
                <?php $accountApproved = strtolower((string)($scanned_data['status'] ?? 'pending')) === 'approved'; ?>
                <p class="success-eyebrow"><?php echo $accountApproved ? 'Account verified' : 'Account located'; ?></p>
                <h2 id="scan-success-title">Welcome, <span><?php echo htmlspecialchars($scanned_data['full_name']); ?></span></h2>
                <?php if ($accountApproved): ?>
                <p class="success-copy">Your approved account is ready. Choose what you would like to do next.</p>
                <div class="success-actions">
                <a class="success-action primary" href="purchase.php?user_id=<?php echo urlencode($scanned_data['user_id']); ?>">
                    <i class="fas fa-shopping-bag"></i> Order Water <i class="fas fa-arrow-right"></i>
                </a>
                <a class="success-action secondary" href="rewards.php?user_id=<?php echo urlencode($scanned_data['user_id']); ?>">
                    <i class="fas fa-gift"></i> View Rewards
                </a>
                </div>
                <p class="success-security"><i class="fas fa-shield-alt"></i> Secure account access</p>
                <?php else: $accountDenied = strtolower((string)($scanned_data['status'] ?? 'pending')) === 'denied'; ?>
                <p class="success-copy">We found your account, but ordering is not available yet.</p>
                <div class="approval-lock<?php echo $accountDenied ? ' denied' : ''; ?>">
                    <div class="approval-lock-icon"><i class="fas <?php echo $accountDenied ? 'fa-circle-xmark' : 'fa-clock'; ?>"></i></div>
                    <h3><?php echo $accountDenied ? 'Account not approved' : 'Waiting for administrator approval'; ?></h3>
                    <p><?php echo $accountDenied ? 'Please contact HydroMIS support if you believe this decision needs review.' : 'A HydroMIS administrator must review and approve your registration before you can purchase water.'; ?></p>
                    <div class="approval-next"><i class="fas <?php echo $accountDenied ? 'fa-headset' : 'fa-rotate'; ?>"></i> <?php echo $accountDenied ? 'Contact support for assistance' : 'Try logging in again after approval'; ?></div>
                </div>
                <p class="success-security"><i class="fas fa-lock"></i> Purchasing is locked for this account</p>
                <?php endif; ?>
            </section>
        </div>

        <!-- Purchase Shell (Removed - moved to separate purchase.php page)
        <div class="purchase-shell">
            <div class="rewards-panel">
                <div class="points-strip">
                    <div class="points-strip-title">Hydro Rewards</div>
                    <a class="points-chip" href="./rewards.php?user_id=<?php echo urlencode($scanned_data['user_id']); ?>">
                        <?php echo intval($scanned_data['loyalty_points'] ?? 0); ?> pts
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="hero-grid">
                        <div class="profile-editor-card">
                            <div class="profile-editor-head">
                                <h6><i class="fas fa-id-card"></i> User Profile</h6>
                            </div>

                            <?php
                                $profile_initials = strtoupper(substr(preg_replace('/[^A-Z]/', '', $scanned_data['full_name']), 0, 2));
                                if (strlen($profile_initials) < 2) {
                                    $profile_initials = strtoupper(substr($scanned_data['full_name'], 0, 2));
                                }
                                $profile_image_src = '';
                                foreach (['jpg', 'jpeg', 'png', 'webp'] as $img_ext) {
                                    $candidate = '../uploads/profile_photos/' . $scanned_data['user_id'] . '.' . $img_ext;
                                    if (hydromis_object_exists(ltrim($candidate, '../'))) {
                                        $profile_image_src = hydromis_storage_url(ltrim($candidate, '../'));
                                        break;
                                    }
                                }
                            ?>

                            <?php $show_edit_form = isset($_POST['profile_submit']) && !empty($error); ?>

                            <?php if (!empty($error) && isset($_POST['profile_submit'])): ?>
                                <div class="profile-alert profile-alert-error">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <div id="profileView" style="display: <?php echo $show_edit_form ? 'none' : 'block'; ?>;">
                                <div class="profile-photo-block">
                                    <div class="profile-photo-avatar">
                                        <?php if (!empty($profile_image_src)): ?>
                                            <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="User Photo">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($profile_initials); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="profile-photo-actions">
                                        <p class="profile-user-name"><?php echo htmlspecialchars($scanned_data['full_name']); ?></p>
                                        <p class="profile-user-code"><?php echo htmlspecialchars($scanned_data['user_id']); ?></p>
                                    </div>
                                </div>
                            </div>

                            <form id="profileEditForm" class="profile-edit-form" method="POST" enctype="multipart/form-data" style="display: <?php echo $show_edit_form ? 'block' : 'none'; ?>;">
                                <input type="hidden" name="profile_submit" value="1">
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($scanned_data['user_id']); ?>">

                                <div class="profile-photo-block">
                                    <div class="profile-photo-avatar">
                                        <?php if (!empty($profile_image_src)): ?>
                                            <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="User Photo">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($profile_initials); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="profile-photo-actions">
                                        <label for="profile_image" class="photo-upload-label"><i class="fas fa-image"></i> Choose Photo</label>
                                        <input id="profile_image" type="file" name="profile_image" accept="image/png,image/jpeg,image/webp" style="display:none;">
                                        <p class="photo-upload-hint">PNG, JPG, or WEBP. Save Profile to apply.</p>
                                    </div>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_full_name">Full Name</label>
                                    <input id="profile_full_name" class="profile-input" type="text" name="full_name" value="<?php echo htmlspecialchars($scanned_data['full_name']); ?>" required>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_contact_number">Contact Number</label>
                                    <input id="profile_contact_number" class="profile-input" type="text" name="contact_number" value="<?php echo htmlspecialchars($scanned_data['contact_number'] ?? ''); ?>" required>
                                </div>

                                <div class="profile-field">
                                    <label for="profile_address">Address</label>
                                    <input id="profile_address" class="profile-input" type="text" name="address" value="<?php echo htmlspecialchars($scanned_data['address'] ?? ''); ?>" required>
                                </div>

                                <button class="profile-save" type="submit">
                                    <i class="fas fa-save"></i> Save Profile
                                </button>
                                <button class="profile-save" type="button" onclick="toggleProfileEdit(false)" style="margin-top:8px;background:linear-gradient(135deg,#9ca3af 0%,#6b7280 100%);box-shadow:0 8px 16px rgba(107,114,128,.25);">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </form>

                            <button type="button" class="profile-edit-btn profile-edit-btn-bottom" id="editProfileBtn" onclick="toggleProfileEdit(true)">
                                <i class="fas fa-pen"></i> Edit Profile
                            </button>
                        </div>

        -->

        <?php else: ?>
        <!-- Main Card View -->
        <div class="card scanner-width" id="mainLoginCard">
            <div class="card-body" style="padding: 35px;">
                <div class="login-hero">
                    <div class="login-icon">
                        <img src="../imagess/hydromis-logo-v2.png" alt="HydroMIS logo">
                    </div>
                    <h2 style="color: #1f2937; font-weight: 700; margin-bottom: 8px; font-size: 26px; line-height: 1.2;">Customer Login</h2>
                    <p style="color: #64748b; font-size: 14px;">Access your HydroMIS account</p>
                    <span class="security-note"><i class="fas fa-shield-halved"></i> Secure customer access</span>
                </div>

                <div class="login-tabs" role="tablist" aria-label="Login method">
                    <button type="button" class="tab-button active" onclick="switchTab('mobile')" style="flex: 1; background: #ffffff; color: #111827; border: none; border-radius: 10px; padding: 10px 12px; font-weight: 700; font-size: 13px; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.12);">
                        <i class="fas fa-mobile-screen"></i> Mobile
                    </button>
                    <button type="button" class="tab-button" onclick="switchTab('qr')" style="flex: 1; background: transparent; color: #111827; border: none; border-radius: 10px; padding: 10px 12px; font-weight: 700; font-size: 13px; box-shadow: none;">
                        <i class="fas fa-qrcode"></i> Scan QR
                    </button>
                </div>

                <div id="mobileTab" class="login-tab-panel" style="display: block;">
                    <?php if ($error): ?>
                        <div class="error-message" style="margin-bottom: 12px;">
                            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="login-form" id="mobileLoginForm" style="margin-bottom: 12px;">
                        <label for="mobile_number" style="color: #1f2937; font-weight: 700; margin-bottom: 10px; display: block; font-size: 15px;">Mobile Number</label>
                        <div class="mobile-field"><span class="country-code">+63</span><input type="tel" id="mobile_number" name="mobile_number" class="form-control" placeholder="912 345 6789" autocomplete="tel" inputmode="numeric" value="<?php echo htmlspecialchars($mobile_login_value); ?>" style="border-radius: 12px; border: 1px solid #d1d5db; background: #ffffff; margin-bottom: 10px;" required></div>
                        <p class="login-helper" style="color: #475569; font-size: 13px; margin-bottom: 16px;"><i class="fas fa-circle-info"></i> Enter the same mobile number used during registration.</p>

                        <button type="submit" name="mobile_login" value="1" class="btn-toggle" style="margin-bottom: 0; width: 100%;">
                            <i class="fas fa-sign-in-alt mr-2"></i> Login & Go to Purchase
                        </button>
                    </form>

                    <div class="register-prompt">
                        <span style="color: #334155; font-size: 14px;">Don't have an account?</span>
                        <a href="../create_account.php" style="color: #2563eb; text-decoration: none; font-weight: 700; font-size: 14px;">Register here <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>

                <div id="qrTab" class="login-tab-panel" style="display: none;">
                    <p style="color: #475569; font-size: 14px; margin-bottom: 18px; text-align: center;">Use your account QR code to continue</p>
                    <div class="station-login-note" role="note">
                        <i class="fas fa-store"></i>
                        <span><strong>Main station QR only.</strong> Scan the customer QR code issued by the HydroMIS water refilling station.</span>
                    </div>
                    <button type="button" class="btn-toggle" onclick="showScanner();" style="margin-bottom: 12px; width: 100%;">
                        <i class="fas fa-camera mr-2"></i> Start Camera
                    </button>
                    <div class="register-prompt">
                        <span>Don't have an account?</span>
                        <a href="../create_account.php">Register here <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scanner View - Hidden Initially -->
        <div class="card scanner-width" id="scannerView" style="display: none;">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                </h5>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="status status-waiting" id="status">
                    <i class="fas fa-video mr-2"></i> Point your camera at a QR code
                </div>

                <div id="video-container">
                    <video id="scanner"></video>
                    <div class="scanner-frame">
                        <div class="scanner-line"></div>
                        <div class="scanner-corner corner-tl"></div>
                        <div class="scanner-corner corner-tr"></div>
                        <div class="scanner-corner corner-bl"></div>
                        <div class="scanner-corner corner-br"></div>
                    </div>
                </div>

                <button type="button" class="btn-toggle" onclick="toggleCamera();">
                    <i class="fas fa-camera mr-2"></i> Start Camera
                </button>

                <p style="color: #666; font-size: 12px; text-align: center;">
                    <i class="fas fa-info-circle"></i> By scanning your QR code, your information will be displayed
                </p>
                <div class="scanner-trust"><span><i class="fas fa-lock"></i> Encrypted access</span><span><i class="fas fa-camera"></i> Camera stays private</span></div>

                <form id="qr-form" method="POST" style="display: none;">
                    <div class="form-group">
                        <label>QR Data:</label>
                        <textarea name="qr_data" id="qr_data"></textarea>
                    </div>
                    <button type="submit" style="display: none;">Submit</button>
                </form>

                <hr>

                <div style="text-align: center; margin-bottom: 20px;">
                    <button type="button" class="btn-back" onclick="hideScanner();" style="margin:0;">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Customer Login
                    </button>
                </div>

                <button type="button" class="btn-tracking" onclick="goToTrackOrder();">
                    <i class="fas fa-map-marker-alt mr-2"></i> Track Your Order
                </button>

            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let video;
        let cameraActive = false;

        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const mobileMenuPanel = document.getElementById('mobile-menu-panel');
        const mobileMenuClose = document.getElementById('mobile-menu-close');
        const mobileMenuIcon = document.getElementById('mobile-menu-icon');

        function openMobileMenu() {
            document.body.classList.add('mobile-nav-open');
            if (mobileMenuButton) {
                mobileMenuButton.setAttribute('aria-expanded', 'true');
            }
            if (mobileMenuIcon) {
                mobileMenuIcon.classList.remove('fa-bars');
                mobileMenuIcon.classList.add('fa-xmark');
            }
        }

        function closeMobileMenu() {
            document.body.classList.remove('mobile-nav-open');
            if (mobileMenuButton) {
                mobileMenuButton.setAttribute('aria-expanded', 'false');
            }
            if (mobileMenuIcon) {
                mobileMenuIcon.classList.remove('fa-xmark');
                mobileMenuIcon.classList.add('fa-bars');
            }
        }

        function toggleMobileMenu() {
            if (document.body.classList.contains('mobile-nav-open')) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        }

        if (mobileMenuButton && mobileMenuOverlay && mobileMenuPanel && mobileMenuClose && mobileMenuIcon) {
            mobileMenuButton.addEventListener('click', toggleMobileMenu);
            mobileMenuClose.addEventListener('click', closeMobileMenu);

            mobileMenuOverlay.addEventListener('click', function (event) {
                if (!mobileMenuPanel.contains(event.target)) {
                    closeMobileMenu();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && document.body.classList.contains('mobile-nav-open')) {
                    closeMobileMenu();
                }
            });
        }

        async function shareWithFriends() {
            const shareData = {
                title: 'HydroMIS',
                text: 'Check out HydroMIS for easy water refill ordering and tracking.',
                url: window.location.origin + '/HydroMIS-1.3/'
            };

            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                } catch (error) {
                    // Ignore cancelled shares.
                }
                return;
            }

            try {
                await navigator.clipboard.writeText(shareData.url);
                alert('Link copied. Share it with your friends!');
            } catch (error) {
                alert('Sharing is not available on this device.');
            }
        }

        async function toggleCamera() {
            const scannerView = document.getElementById('scannerView');
            const button = scannerView ? scannerView.querySelector('.btn-toggle') : null;
            if (!button) return;
            
            if (!cameraActive) {
                try {
                    video = document.getElementById('scanner');
                    const stream = await navigator.mediaDevices.getUserMedia({ 
                        video: { facingMode: "environment" }
                    });
                    video.srcObject = stream;
                    video.play();
                    cameraActive = true;
                    button.innerHTML = '<i class="fas fa-stop-circle mr-2"></i> Stop Camera';
                    startScanning();
                } catch (err) {
                    alert('Unable to access camera: ' + err.message);
                    updateStatus('Camera access denied', false);
                }
            } else {
                video.srcObject.getTracks().forEach(track => track.stop());
                cameraActive = false;
                button.innerHTML = '<i class="fas fa-camera mr-2"></i> Start Camera';
                updateStatus('Camera stopped', false);
            }
        }

        function startScanning() {
            function scan() {
                if (!cameraActive) return;

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                video.width = video.clientWidth;
                video.height = video.clientHeight;
                
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const code = jsQR(imageData.data, canvas.width, canvas.height, {
                        inversionAttempts: "dontInvert",
                    });

                    if (code) {
                        try {
                            const data = JSON.parse(code.data);
                            if (data.user_id) {
                                updateStatus('QR Code detected! Processing...', 'success');
                                document.getElementById('qr_data').value = code.data;
                                setTimeout(() => {
                                    document.getElementById('qr-form').submit();
                                }, 500);
                                return;
                            }
                        } catch (e) {
                            // Not a valid JSON QR code
                        }
                    }
                }
                
                requestAnimationFrame(scan);
            }
            
            updateStatus('Scanning...', 'waiting');
            scan();
        }

        function updateStatus(message, type = 'waiting') {
            const status = document.getElementById('status');
            status.textContent = message;
            const classes = ['status-waiting', 'status-success', 'status-error'];
            status.className = 'status status-' + type;
        }

        // Price Calculation Function
        function calculatePrice() {
            const waterType = document.getElementById('water_type').value;
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const pricePerUnit = waterType === 'nowater' ? 30 : 20;
            
            // Calculate subtotal
            const subtotal = quantity * pricePerUnit;
            
            // Calculate discount (5 pesos per 5 units, only for regular water)
            let discount = 0;
            let loyaltyPoints = 0;
            if (waterType === 'regular') {
                const discountCount = Math.floor(quantity / 5);
                discount = discountCount * 5;
                loyaltyPoints = discountCount;
            }
            
            // Calculate final amount
            const finalAmount = subtotal - discount;
            
            // Update display
            document.getElementById('displayQty').textContent = quantity;
            document.getElementById('displayPrice').textContent = pricePerUnit;
            document.getElementById('subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('finalAmount').textContent = finalAmount.toFixed(2);
            
            // Show/hide discount row
            const discountRow = document.getElementById('discountRow');
            if (discount > 0) {
                discountRow.style.display = 'flex';
                document.getElementById('discount').textContent = discount.toFixed(2);
            } else {
                discountRow.style.display = 'none';
            }
            
            // Show/hide loyalty points row
            const pointsRow = document.getElementById('pointsRow');
            if (loyaltyPoints > 0) {
                pointsRow.style.display = 'flex';
                document.getElementById('loyaltyPointsCalc').textContent = loyaltyPoints;
            } else {
                pointsRow.style.display = 'none';
            }
        }
        
        // Water Type Selection
        function selectWaterType(type) {
            document.getElementById('water_type').value = type;
            
            // Update button styles
            const regularBtn = document.getElementById('btn-regular');
            const nowaterBtn = document.getElementById('btn-nowater');
            
            if (type === 'regular') {
                regularBtn.classList.add('active');
                nowaterBtn.classList.remove('active');
            } else {
                nowaterBtn.classList.add('active');
                regularBtn.classList.remove('active');
            }
        }
        
        // Calculate Change Function
        function calculateChange() {
            const amountTenderedInput = document.getElementById('amount_tendered');
            const changeDisplay = document.getElementById('changeDisplay');
            
            if (!amountTenderedInput || !changeDisplay) return;
            
            const amountTendered = parseFloat(amountTenderedInput.value) || 0;
            const finalAmountText = document.getElementById('finalAmount').textContent;
            const finalAmount = parseFloat(finalAmountText) || 0;
            
            if (amountTendered > 0 && finalAmount > 0) {
                const change = amountTendered - finalAmount;
                
                if (change >= 0) {
                    changeDisplay.innerHTML = `
                        <div style="padding: 12px; background: #d1fae5; border-radius: 8px; border-left: 4px solid #10b981; margin-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #047857; font-weight: 600;">Change:</span>
                                <span style="color: #10b981; font-weight: 700; font-size: 16px;">₱${change.toFixed(2)}</span>
                            </div>
                        </div>
                    `;
                } else {
                    const shortage = Math.abs(change);
                    changeDisplay.innerHTML = `
                        <div style="padding: 12px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #ef4444; margin-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #7f1d1d; font-weight: 600;">Still Need:</span>
                                <span style="color: #ef4444; font-weight: 700; font-size: 16px;">₱${shortage.toFixed(2)}</span>
                            </div>
                        </div>
                    `;
                }
            } else {
                changeDisplay.innerHTML = '';
            }
        }

        function toggleProfileEdit(showEdit) {
            const profileView = document.getElementById('profileView');
            const profileForm = document.getElementById('profileEditForm');
            const editBtn = document.getElementById('editProfileBtn');

            if (!profileView || !profileForm || !editBtn) return;

            if (showEdit) {
                profileView.style.display = 'none';
                profileForm.style.display = 'block';
                editBtn.style.display = 'none';
            } else {
                profileView.style.display = 'block';
                profileForm.style.display = 'none';
                editBtn.style.display = 'inline-flex';
            }
        }
        
        // Show scanner view
        function showScanner() {
            const mainCard = document.getElementById('mainLoginCard');
            const scannerView = document.getElementById('scannerView');
            
            if (mainCard) mainCard.style.display = 'none';
            if (scannerView) scannerView.style.display = 'block';
            
            // Auto-start camera
            setTimeout(() => toggleCamera(), 300);
        }

        // Hide scanner view and return to main view
        function hideScanner() {
            const mainCard = document.getElementById('mainLoginCard');
            const scannerView = document.getElementById('scannerView');
            
            if (cameraActive) {
                const video = document.getElementById('scanner');
                if (video && video.srcObject) {
                    video.srcObject.getTracks().forEach(track => track.stop());
                }
                cameraActive = false;
            }
            
            if (scannerView) scannerView.style.display = 'none';
            if (mainCard) mainCard.style.display = 'block';
        }

        // Switch between tabs
        function switchTab(tabName) {
            const mobileTab = document.getElementById('mobileTab');
            const qrTab = document.getElementById('qrTab');
            const tabButtons = document.querySelectorAll('.tab-button');
            
            // Hide all tabs
            if (mobileTab) mobileTab.style.display = 'none';
            if (qrTab) qrTab.style.display = 'none';
            
            tabButtons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab and style button
            if (tabName === 'mobile') {
                if (mobileTab) mobileTab.style.display = 'block';
                if (mobileTab) { mobileTab.classList.remove('tab-enter'); void mobileTab.offsetWidth; mobileTab.classList.add('tab-enter'); }
                if (tabButtons[0]) tabButtons[0].classList.add('active');
            } else if (tabName === 'qr') {
                if (qrTab) qrTab.style.display = 'block';
                if (qrTab) { qrTab.classList.remove('tab-enter'); void qrTab.offsetWidth; qrTab.classList.add('tab-enter'); }
                if (tabButtons[1]) tabButtons[1].classList.add('active');
            }
        }

        // Navigate to order tracking page
        function goToTrackOrder() {
            window.location.href = './track_order.php';
        }

        function confirmRewardConvert(rewardTitle, requiredPoints, currentPoints) {
            if (currentPoints < requiredPoints) {
                alert('You need ' + requiredPoints + ' points to convert this reward.');
                return false;
            }
            return window.confirm('Convert ' + requiredPoints + ' points for "' + rewardTitle + '"? Click OK to continue or Cancel to stop.');
        }

        // Initialize price calculation on page load
        window.addEventListener('DOMContentLoaded', function() {
            calculatePrice();

            const loginCard = document.querySelector('.scanner-width');
            const canTilt = window.matchMedia('(hover:hover) and (pointer:fine) and (prefers-reduced-motion:no-preference)').matches;
            if (loginCard && canTilt) {
                const enableTilt = () => {
                    loginCard.style.animation = 'none';
                    loginCard.classList.add('tilt-ready');
                };
                loginCard.addEventListener('animationend', enableTilt, { once:true });
                loginCard.addEventListener('pointermove', function(event) {
                    const box = this.getBoundingClientRect();
                    const x = (event.clientX - box.left) / box.width - .5;
                    const y = (event.clientY - box.top) / box.height - .5;
                    this.style.setProperty('--tilt-x', (-y * 5).toFixed(2) + 'deg');
                    this.style.setProperty('--tilt-y', (x * 5).toFixed(2) + 'deg');
                });
                loginCard.addEventListener('pointerleave', function() {
                    this.style.setProperty('--tilt-x', '0deg');
                    this.style.setProperty('--tilt-y', '0deg');
                });
            }

            const mobileForm = document.getElementById('mobileLoginForm');
            if (mobileForm) mobileForm.addEventListener('submit', function() {
                const submit = this.querySelector('.btn-toggle');
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Verifying account...';
            });

            const profileForm = document.getElementById('profileEditForm');
            const editBtn = document.getElementById('editProfileBtn');
            if (profileForm && editBtn && profileForm.style.display === 'block') {
                editBtn.style.display = 'none';
            }
        });
    </script>
    <?php if ($scanned_data): ?><script src="../js/user-notifications.js"></script><?php endif; ?>
</body>
</html>
