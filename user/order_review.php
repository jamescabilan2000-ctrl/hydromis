<?php
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);

$user_id = null;
if (isset($_POST['user_id'])) {
    $user_id = sanitize($_POST['user_id']);
} elseif (isset($_GET['user_id'])) {
    $user_id = sanitize($_GET['user_id']);
}

if (!$user_id) {
    header('Location: scan_qr.php');
    exit;
}

$sql = "SELECT * FROM users WHERE user_id = '$user_id'";
$result = $conn->query($sql);
if (!$result || $result->num_rows === 0) {
    header('Location: scan_qr.php');
    exit;
}
$scanned_data = $result->fetch_assoc();
if (strtolower((string)($scanned_data['status'] ?? 'pending')) !== 'approved') {
    header('Location: scan_qr.php?approval_required=' . urlencode((string)($scanned_data['status'] ?? 'pending')));
    exit;
}

$allowed_sizes = ['5gal-round', '2.5gal-slim', '5gal-slim'];
$allowed_status = ['new', 'existing'];
$allowed_fulfillment = ['delivery', 'pickup'];

$container_size = isset($_POST['container_size']) ? sanitize($_POST['container_size']) : (isset($_GET['container_size']) ? sanitize($_GET['container_size']) : '2.5gal-slim');
$container_status = isset($_POST['container_status']) ? sanitize($_POST['container_status']) : (isset($_GET['container_status']) ? sanitize($_GET['container_status']) : 'new');
$fulfillment_method = isset($_POST['fulfillment_method']) ? sanitize($_POST['fulfillment_method']) : (isset($_GET['fulfillment_method']) ? sanitize($_GET['fulfillment_method']) : 'delivery');

if (!in_array($container_size, $allowed_sizes, true)) {
    $container_size = '2.5gal-slim';
}
if (!in_array($container_status, $allowed_status, true)) {
    $container_status = 'new';
}
if (!in_array($fulfillment_method, $allowed_fulfillment, true)) {
    $fulfillment_method = 'delivery';
}

$size_map = [
    '5gal-round' => '5 Gallon',
    '2.5gal-slim' => '2.5 Gallon',
    '5gal-slim' => '5 Gallon'
];

$type_map = [
    '5gal-round' => 'round',
    '2.5gal-slim' => 'slim',
    '5gal-slim' => 'slim'
];

$price_map = [
    '5gal-round' => ['new' => 20, 'pickup' => 20],
    '2.5gal-slim' => ['new' => 30, 'pickup' => 20],
    '5gal-slim' => ['new' => 50, 'pickup' => 40]
];

$pickup_base_map = [
    '5gal-round' => 20,
    '2.5gal-slim' => 20,
    '5gal-slim' => 40
];

$container_image_map = [
    '5gal-round' => '../imagess/water5.webp',
    '2.5gal-slim' => '../imagess/water3.jpg',
    '5gal-slim' => '../imagess/water4.webp'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Order - HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/public-ui.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
        :root {
            --bg: #d5dbe2;
            --panel: #dce2e8;
            --card: #f0f4f8;
            --ink: #2b3b4f;
            --muted: #62748a;
            --line: #c2cad3;
            --orange: #f97316;
            --orange-strong: #ea580c;
            --danger: #ef4663;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background: linear-gradient(180deg, var(--bg) 0%, #cfd6de 100%);
            color: var(--ink);
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
            margin: 0;
        }

        .navbar-brand i {
            color: #2563eb;
        }

        .review-wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 16px;
        }

        .sheet {
            background: var(--panel);
            border: 1px solid #c6ced8;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 12px 34px rgba(30, 41, 59, 0.14);
        }

        .row-box {
            border-bottom: 1px solid var(--line);
            padding: 16px;
        }

        .item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .item-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .item-thumb {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            border: 1px solid #bfc8d1;
            background: #e9edf2;
            object-fit: cover;
            padding: 3px;
            flex-shrink: 0;
        }

        .item-copy {
            min-width: 0;
        }

        .item-title {
            font-size: 19px;
            font-weight: 800;
            color: #27384c;
            margin: 0;
            line-height: 1.2;
            word-break: break-word;
        }

        .item-subtitle {
            margin-top: 3px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .qty-box {
            display: inline-flex;
            align-items: center;
            border: 1px solid #f9a16f;
            border-radius: 10px;
            overflow: hidden;
            background: #fdf4ec;
            min-width: 128px;
            height: 52px;
        }

        .qty-btn {
            border: 0;
            background: transparent;
            color: var(--orange);
            font-size: 28px;
            font-weight: 600;
            width: 38px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .qty-btn:hover {
            background: rgba(249, 115, 22, 0.08);
        }

        .qty-btn:focus {
            outline: none;
            box-shadow: inset 0 0 0 2px rgba(249, 115, 22, 0.3);
        }

        .qty-value {
            width: 50px;
            text-align: center;
            font-size: 29px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
        }

        .mode-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .mode-btn {
            border: 1px solid #bac3cc;
            border-radius: 10px;
            padding: 12px 10px;
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #8390a0;
            background: #d4dae0;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .mode-btn i {
            font-size: 16px;
            transition: color 0.25s ease;
        }

        .mode-btn:hover {
            border-color: #f2a46f;
            background: #dde4eb;
            color: #6c7b8d;
        }

        .mode-btn.active {
            color: var(--white);
            background: linear-gradient(180deg, #ff9b3d 0%, var(--orange) 100%);
            border-color: #f97316;
            box-shadow: 0 8px 18px rgba(249, 115, 22, 0.32), inset 0 1px 0 rgba(255,255,255,0.22);
            transform: translateY(-1px);
        }

        .mode-btn.active i {
            color: var(--white);
        }

        .mode-btn:active {
            transform: translateY(0);
        }

        .mode-btn:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
        }

        .shop-card {
            margin: 16px;
            border-radius: 16px;
            border: 1px solid #bfc8d2;
            background: linear-gradient(180deg, #d7dde4 0%, #d4dbe2 100%);
            padding: 18px;
        }

        .shop-title {
            margin: 0;
            color: #43546a;
            font-weight: 800;
            font-size: 18px;
        }

        .meta-list {
            margin-top: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            color: #5f6f83;
            font-size: 15px;
            font-weight: 500;
        }

        .meta-line i {
            width: 20px;
            text-align: center;
            color: #64748b;
            margin-right: 6px;
        }

        .meta-line strong {
            color: #43546b;
            font-weight: 700;
        }

        .more-info {
            margin-top: 10px;
            color: var(--orange);
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .divider {
            border-top: 1px solid #bcc5cf;
            margin: 16px 0;
        }

        .totals {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .total-line {
            font-size: 20px;
            color: #5f7084;
            font-weight: 500;
            line-height: 1.1;
        }

        .total-line strong {
            font-weight: 600;
            color: #52657a;
        }

        .min-chip {
            margin-left: auto;
            background: var(--danger);
            color: var(--white);
            border-radius: 12px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            display: none;
            width: fit-content;
        }

        .actions {
            padding: 0 16px 16px;
        }

        .continue-btn {
            width: 100%;
            border: 0;
            border-radius: 10px;
            min-height: 44px;
            color: var(--white);
            font-size: 23px;
            font-weight: 600;
            background: linear-gradient(180deg, #fb8500 0%, var(--orange) 100%);
            box-shadow: 0 8px 18px rgba(249, 115, 22, 0.3);
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .continue-btn:hover {
            background: linear-gradient(180deg, #f97316 0%, var(--orange-strong) 100%);
            transform: translateY(-1px);
        }

        .continue-btn:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.25);
        }

        .continue-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            box-shadow: none;
        }

        .sub-actions {
            margin-top: 10px;
            display: flex;
            justify-content: center;
        }

        .back-link {
            color: #42556b;
            text-decoration: none;
            font-weight: 700;
            font-size: 15px;
        }

        .back-link:hover {
            color: #334155;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .review-wrap {
                padding: 10px;
            }

            .navbar-brand {
                font-size: 21px;
            }

            .row-box {
                padding: 12px;
            }

            .item-meta {
                gap: 10px;
            }

            .item-thumb {
                width: 44px;
                height: 44px;
                border-radius: 10px;
            }

            .item-title {
                font-size: 16px;
            }

            .item-subtitle {
                font-size: 11px;
            }

            .qty-box {
                min-width: 116px;
                height: 46px;
            }

            .qty-btn {
                width: 34px;
                font-size: 24px;
            }

            .qty-value {
                width: 48px;
                font-size: 24px;
            }

            .mode-btn {
                font-size: 14px;
                padding: 11px 8px;
            }

            .shop-title {
                font-size: 17px;
            }

            .meta-list {
                font-size: 14px;
            }

            .total-line {
                font-size: 16px;
            }

            .continue-btn {
                font-size: 20px;
                min-height: 42px;
            }

            .min-chip {
                font-size: 12px;
                padding: 7px 10px;
            }
        }

        /* Premium review and confirmation experience */
        :root{--review-blue:#1769d2;--review-aqua:#09b4c8;--review-ink:#10263a;--review-green:#0b9b80;--review-ease:cubic-bezier(.22,1,.36,1)}
        body.public-ui{background:radial-gradient(circle at 10% 15%,rgba(9,180,200,.15),transparent 28%),radial-gradient(circle at 90% 85%,rgba(23,105,210,.13),transparent 30%),linear-gradient(145deg,#f0f9fd,#fbfdff 55%,#ecf6fb);color:var(--review-ink)}.navbar{position:relative;z-index:5;background:rgba(255,255,255,.8);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:0 8px 28px rgba(15,52,78,.06);animation:reviewNavIn .6s var(--review-ease) both}.navbar-brand img{width:36px;height:36px;padding:4px;border-radius:11px;background:linear-gradient(135deg,var(--review-blue),var(--review-aqua));box-shadow:0 8px 20px rgba(9,130,170,.2)}.review-wrap{max-width:760px;padding:40px 18px 70px}.sheet{border:1px solid rgba(255,255,255,.9);border-radius:27px;background:rgba(255,255,255,.94);box-shadow:0 30px 80px rgba(14,55,85,.15),0 4px 12px rgba(14,55,85,.05);animation:reviewCardIn .8s .08s var(--review-ease) both}.review-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid #e8eff4}.review-head-main{display:flex;align-items:center;gap:12px}.review-head-icon{display:grid;place-items:center;width:43px;height:43px;border-radius:13px;background:linear-gradient(145deg,var(--review-blue),var(--review-aqua));color:#fff;box-shadow:0 10px 22px rgba(23,105,210,.2)}.review-head h2{margin:0 0 3px;font-size:19px;font-weight:800}.review-head p{margin:0;color:#71869a;font-size:11px}.review-step{padding:7px 10px;border:1px solid #d8e7ef;border-radius:999px;background:#f5f9fc;color:#5c768b;font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.row-box{padding:20px 24px;border-color:#e8eff4}.item-thumb{width:70px;height:70px;padding:7px;object-fit:contain;border:1px solid #e0e9ef;border-radius:16px;background:radial-gradient(circle,#fff,#f0f5f8);mix-blend-mode:multiply}.item-title{color:var(--review-ink);font-size:20px}.item-subtitle{color:#7890a2;font-size:10px;letter-spacing:.08em}.qty-box{min-width:142px;height:54px;border:1px solid #d6e5ed;border-radius:14px;background:#f6fafc;box-shadow:inset 0 1px 2px rgba(16,38,58,.03)}.qty-btn{height:100%;color:var(--review-blue);font-size:22px;transition:background .2s ease,transform .15s ease}.qty-btn:hover{background:#eaf4ff}.qty-btn:active{transform:scale(.88)}.qty-value{font-size:22px;color:var(--review-ink)}.mode-row{gap:13px}.mode-btn{min-height:55px;border:1px solid #dce6ed;border-radius:14px;background:#f5f8fa;color:#71869a;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease,background .2s ease}.mode-btn:hover{transform:translateY(-2px);background:#fff;border-color:#bad8e5}.mode-btn.active{border-color:var(--review-blue);background:linear-gradient(135deg,#1769d2,#168ec8);box-shadow:0 13px 26px rgba(23,105,210,.23);transform:none}.shop-card{margin:20px 24px;padding:22px;border:1px solid #e0e9ef;border-radius:18px;background:linear-gradient(145deg,#f9fcfe,#f1f7fa)}.shop-title{color:var(--review-ink);font-size:17px}.verified-station{display:inline-flex;align-items:center;gap:6px;margin-top:6px;color:#16866f;font-size:10px;font-weight:800}.verified-station i{color:#16a085}.meta-list{font-size:12px}.rating-stars{display:inline-flex;gap:3px;margin:0 5px;color:#f5a623}.rating-stars i{width:auto;margin:0}.more-info{color:var(--review-blue);font-size:11px}.divider{border-color:#dde8ee}.totals{gap:0}.total-line{display:flex;justify-content:space-between;align-items:center;padding:8px 0;color:#61788c;font-size:13px}.total-line strong{color:#29465d}.total-line.grand-total{margin-top:7px;padding-top:14px;border-top:1px dashed #cbdbe4;color:var(--review-ink);font-size:15px;font-weight:800}.total-line.grand-total strong{color:var(--review-green);font-size:21px}.actions{padding:4px 24px 24px}.continue-btn{position:relative;min-height:56px;overflow:hidden;border-radius:14px;background:linear-gradient(120deg,#0b967f,#09b4a2,#087e73);background-size:180% 180%;font-size:16px;font-weight:800;box-shadow:0 15px 32px rgba(8,145,125,.25);animation:reviewGradient 6s ease infinite;transition:transform .2s ease,box-shadow .2s ease}.continue-btn::after{content:'';position:absolute;inset:0;transform:translateX(-120%) skewX(-20deg);background:linear-gradient(90deg,transparent,rgba(255,255,255,.28),transparent);transition:transform .7s var(--review-ease)}.continue-btn:hover::after{transform:translateX(120%) skewX(-20deg)}.continue-btn:hover{transform:translateY(-2px);box-shadow:0 19px 38px rgba(8,145,125,.32);background:linear-gradient(120deg,#0b967f,#09b4a2,#087e73)}.continue-btn.is-loading{pointer-events:none;opacity:.84}.back-link{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:9px;color:#577187;font-size:12px;transition:background .2s ease,transform .2s ease}.back-link:hover{background:#eff6fa;transform:translateX(-2px);text-decoration:none}
        @keyframes reviewNavIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}@keyframes reviewCardIn{from{opacity:0;transform:translateY(26px) scale(.988)}to{opacity:1;transform:none}}@keyframes reviewGradient{0%,100%{background-position:0 50%}50%{background-position:100% 50%}}
        @media(max-width:560px){.review-wrap{padding:20px 12px 54px}.sheet{border-radius:22px}.review-head{padding:18px}.review-step{display:none}.row-box{padding:17px}.item-row{align-items:flex-start}.item-thumb{width:58px;height:58px}.item-title{font-size:16px}.qty-box{min-width:116px;height:48px}.qty-btn{width:32px}.qty-value{width:48px;font-size:20px}.mode-row{grid-template-columns:1fr}.shop-card{margin:16px;padding:18px}.actions{padding:2px 16px 20px}.total-line{font-size:12px}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
    </style>
</head>
<body class="public-ui">
    <nav class="navbar">
        <div class="container-fluid">
            <a href="../home.php" class="navbar-brand">
                <img src="../<?php echo htmlspecialchars($systemLogo); ?>" alt="HydroMIS logo"> HydroMIS
            </a>
        </div>
    </nav>

    <div class="review-wrap">
        <div class="sheet">
            <div class="review-head"><div class="review-head-main"><div class="review-head-icon"><i class="fas fa-clipboard-check"></i></div><div><h2>Review your order</h2><p>Confirm quantity and fulfillment method.</p></div></div><span class="review-step">Step 2 of 2</span></div>
            <div class="row-box">
                <div class="item-row">
                    <div class="item-meta">
                        <img class="item-thumb" src="<?php echo htmlspecialchars($container_image_map[$container_size]); ?>" alt="Selected container image">
                        <div class="item-copy">
                            <h1 class="item-title" id="itemTitle"><?php echo htmlspecialchars($size_map[$container_size] . ' - ' . ucfirst($type_map[$container_size])); ?></h1>
                            <div class="item-subtitle">HydroMIS order review</div>
                        </div>
                    </div>
                    <div class="qty-box">
                        <button type="button" class="qty-btn" id="minusBtn">-</button>
                        <div class="qty-value" id="qtyDisplay">2</div>
                        <button type="button" class="qty-btn" id="plusBtn">+</button>
                    </div>
                </div>
            </div>

            <div class="row-box">
                <div class="mode-row" style="margin-bottom:13px">
                    <button type="button" class="mode-btn" id="newContainerBtn" aria-pressed="false">
                        <i class="fas fa-box-open"></i> Buy new container
                    </button>
                    <button type="button" class="mode-btn" id="existingContainerBtn" aria-pressed="false">
                        <i class="fas fa-recycle"></i> I have a container
                    </button>
                </div>
                <div class="mode-row">
                    <button type="button" class="mode-btn" id="deliveryBtn" aria-pressed="false">
                        <i class="fas fa-truck"></i> Delivery
                    </button>
                    <button type="button" class="mode-btn" id="pickupBtn" aria-pressed="false">
                        <i class="fas fa-cube"></i> Self pickup
                    </button>
                </div>
            </div>

            <div class="shop-card">
                <div class="totals">
                    <div class="total-line"><span>Water</span><strong>₱<span id="waterTotal">0.00</span></strong></div>
                    <div class="total-line" id="containerLine"><span>New container surcharge</span><strong>₱<span id="containerTotal">0.00</span></strong></div>
                    <div class="total-line"><span>Delivery fee</span><strong id="deliveryFeeDisplay">₱0.00</strong></div>
                    <div class="total-line" id="discountLine" style="display:none;color:#059669;"><span>Quantity discount</span><strong style="color:#059669;">-₱<span id="discountTotal">0.00</span></strong></div>
                    <div class="total-line grand-total"><span>Order total</span><strong>₱<span id="reviewTotal">0.00</span></strong></div>
                </div>
            </div>

            <div class="actions">
                <form method="POST" action="checkout.php" id="finalForm">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                    <input type="hidden" name="container_size" value="<?php echo htmlspecialchars($container_size); ?>">
                    <input type="hidden" name="container_status" id="finalStatus" value="<?php echo htmlspecialchars($container_status); ?>">
                    <input type="hidden" name="fulfillment_method" id="finalFulfillment" value="<?php echo htmlspecialchars($fulfillment_method); ?>">
                    <input type="hidden" name="quantity" id="finalQuantity" value="2">
                    <button class="continue-btn" type="submit" id="continueBtn"><i class="fas fa-lock"></i> Continue securely</button>
                </form>
                <div class="sub-actions">
                    <a href="purchase.php?user_id=<?php echo urlencode($user_id); ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back to container selection</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const priceMap = <?php echo json_encode($price_map); ?>;
            const pickupBaseMap = <?php echo json_encode($pickup_base_map); ?>;
            const sizeMap = <?php echo json_encode($size_map); ?>;
            const typeMap = <?php echo json_encode($type_map); ?>;

            const containerSize = <?php echo json_encode($container_size); ?>;
            let containerStatus = <?php echo json_encode($container_status); ?>;
            let fulfillmentMethod = <?php echo json_encode($fulfillment_method); ?>;
            let quantity = 2;

            const qtyDisplay = document.getElementById('qtyDisplay');
            const minusBtn = document.getElementById('minusBtn');
            const plusBtn = document.getElementById('plusBtn');
            const deliveryBtn = document.getElementById('deliveryBtn');
            const pickupBtn = document.getElementById('pickupBtn');
            const newContainerBtn = document.getElementById('newContainerBtn');
            const existingContainerBtn = document.getElementById('existingContainerBtn');
            const waterTotal = document.getElementById('waterTotal');
            const containerTotal = document.getElementById('containerTotal');
            const containerLine = document.getElementById('containerLine');
            const reviewTotal = document.getElementById('reviewTotal');
            const deliveryFeeDisplay = document.getElementById('deliveryFeeDisplay');
            const discountLine = document.getElementById('discountLine');
            const discountTotal = document.getElementById('discountTotal');
            const continueBtn = document.getElementById('continueBtn');
            const itemTitle = document.getElementById('itemTitle');

            const finalStatus = document.getElementById('finalStatus');
            const finalFulfillment = document.getElementById('finalFulfillment');
            const finalQuantity = document.getElementById('finalQuantity');
            const finalAmountTendered = document.getElementById('finalAmountTendered');

            function updateModeButtons() {
                newContainerBtn.classList.toggle('active', containerStatus === 'new');
                existingContainerBtn.classList.toggle('active', containerStatus === 'existing');
                deliveryBtn.classList.toggle('active', fulfillmentMethod === 'delivery');
                pickupBtn.classList.toggle('active', fulfillmentMethod === 'pickup');
                newContainerBtn.setAttribute('aria-pressed', containerStatus === 'new' ? 'true' : 'false');
                existingContainerBtn.setAttribute('aria-pressed', containerStatus === 'existing' ? 'true' : 'false');
                deliveryBtn.setAttribute('aria-pressed', fulfillmentMethod === 'delivery' ? 'true' : 'false');
                pickupBtn.setAttribute('aria-pressed', fulfillmentMethod === 'pickup' ? 'true' : 'false');
            }

            function updateSummary() {
                const pickupBase = pickupBaseMap[containerSize];
                const newContainer = containerStatus === 'new' ? 20 * quantity : 0;
                const water = pickupBase * quantity;

                const discountCount = Math.floor(quantity / 5);
                const discount = discountCount > 0 ? (discountCount * 5) : 0;
                const deliveryFee = fulfillmentMethod === 'delivery' ? 10 * quantity : 0;
                const finalAmount = water + newContainer + deliveryFee - discount;

                qtyDisplay.textContent = String(quantity);
                waterTotal.textContent = water.toFixed(2);
                containerTotal.textContent = newContainer.toFixed(2);
                containerLine.style.display = containerStatus === 'new' ? 'block' : 'none';
                reviewTotal.textContent = finalAmount.toFixed(2);
                deliveryFeeDisplay.textContent = deliveryFee > 0 ? '₱' + deliveryFee.toFixed(2) : 'Free';
                discountTotal.textContent = discount.toFixed(2);
                discountLine.style.display = discount > 0 ? 'flex' : 'none';
                continueBtn.disabled = false;

                itemTitle.textContent = sizeMap[containerSize] + ' - ' + typeMap[containerSize].charAt(0).toUpperCase() + typeMap[containerSize].slice(1);

                finalStatus.value = containerStatus;
                finalFulfillment.value = fulfillmentMethod;
                finalQuantity.value = String(quantity);
                if (finalAmountTendered) {
                    finalAmountTendered.value = finalAmount.toFixed(2);
                }

                updateModeButtons();
            }

            minusBtn.addEventListener('click', function() {
                if (quantity > 1) {
                    quantity -= 1;
                    updateSummary();
                }
            });

            plusBtn.addEventListener('click', function() {
                quantity += 1;
                updateSummary();
            });

            deliveryBtn.addEventListener('click', function() {
                fulfillmentMethod = 'delivery';
                updateSummary();
            });

            pickupBtn.addEventListener('click', function() {
                fulfillmentMethod = 'pickup';
                updateSummary();
            });

            newContainerBtn.addEventListener('click', function() {
                containerStatus = 'new';
                updateSummary();
            });

            existingContainerBtn.addEventListener('click', function() {
                containerStatus = 'existing';
                updateSummary();
            });

            document.getElementById('finalForm').addEventListener('submit', function() {
                continueBtn.classList.add('is-loading');
                continueBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Opening checkout...';
            });

            updateSummary();
        })();
    </script>
</body>
</html>
