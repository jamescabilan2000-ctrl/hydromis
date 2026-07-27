<?php
require_once '../config/database.php';

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

$allowed_sizes = ['5gal-round', '2.5gal-slim', '5gal-slim'];
$allowed_status = ['new', 'pickup'];

$container_size = isset($_POST['container_size']) ? sanitize($_POST['container_size']) : (isset($_GET['container_size']) ? sanitize($_GET['container_size']) : '5gal-round');
$container_status = isset($_POST['container_status']) ? sanitize($_POST['container_status']) : (isset($_GET['container_status']) ? sanitize($_GET['container_status']) : 'new');

if (!in_array($container_size, $allowed_sizes, true)) {
    $container_size = '5gal-round';
}
if (!in_array($container_status, $allowed_status, true)) {
    $container_status = 'new';
}

$size_map = [
    '5gal-round' => '5.00 Gal',
    '2.5gal-slim' => '2.50 Gal',
    '5gal-slim' => '5.00 Gal'
];

$type_map = [
    '5gal-round' => 'round container',
    '2.5gal-slim' => 'slim container',
    '5gal-slim' => 'slim container'
];

$price_map = [
    '5gal-round' => ['new' => 50, 'pickup' => 45],
    '2.5gal-slim' => ['new' => 30, 'pickup' => 25],
    '5gal-slim' => ['new' => 50, 'pickup' => 45]
];

$pickup_base_map = [
    '5gal-round' => 45,
    '2.5gal-slim' => 25,
    '5gal-slim' => 45
];

$container_image_map = [
    '5gal-round' => '../imagess/water3.jpg',
    '2.5gal-slim' => '../imagess/water4.webp',
    '5gal-slim' => '../imagess/water5.webp'
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
    </style>
</head>
<body class="public-ui">
    <nav class="navbar">
        <div class="container-fluid">
            <a href="../home.php" class="navbar-brand">
                <i class="fas fa-droplet"></i> HydroMIS
            </a>
        </div>
    </nav>

    <div class="review-wrap">
        <div class="sheet">
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
                <div class="mode-row">
                    <button type="button" class="mode-btn" id="deliveryBtn" data-status="new" aria-pressed="false">
                        <i class="fas fa-truck"></i> Delivery
                    </button>
                    <button type="button" class="mode-btn" id="pickupBtn" data-status="pickup" aria-pressed="false">
                        <i class="fas fa-cube"></i> Self pickup
                    </button>
                </div>
            </div>

            <div class="shop-card">
                <h2 class="shop-title">HydroMIS Water Refilling Station</h2>
                <div class="meta-list">
                    <div class="meta-line"><i class="far fa-star"></i> Reviews: <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i> no ratings</div>
                </div>
                <div class="more-info">More info <i class="far fa-question-circle"></i></div>

                <div class="divider"></div>

                <div class="totals">
                    <div class="total-line">Water: P <strong id="waterTotal">0.00</strong></div>
                    <div class="total-line" id="containerLine">New container : P <strong id="containerTotal">0.00</strong></div>
                    <div class="total-line">Delivery: <strong>10.00</strong></div>
                </div>
            </div>

            <div class="actions">
                <form method="POST" action="checkout.php" id="finalForm">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                    <input type="hidden" name="container_size" value="<?php echo htmlspecialchars($container_size); ?>">
                    <input type="hidden" name="container_status" id="finalStatus" value="<?php echo htmlspecialchars($container_status); ?>">
                    <input type="hidden" name="quantity" id="finalQuantity" value="2">
                    <button class="continue-btn" type="submit" id="continueBtn">Continue</button>
                </form>
                <div class="sub-actions">
                    <a href="purchase.php?user_id=<?php echo urlencode($user_id); ?>" class="back-link">Back to container selection</a>
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
            let quantity = 2;

            const qtyDisplay = document.getElementById('qtyDisplay');
            const minusBtn = document.getElementById('minusBtn');
            const plusBtn = document.getElementById('plusBtn');
            const deliveryBtn = document.getElementById('deliveryBtn');
            const pickupBtn = document.getElementById('pickupBtn');
            const waterTotal = document.getElementById('waterTotal');
            const containerTotal = document.getElementById('containerTotal');
            const containerLine = document.getElementById('containerLine');
            const continueBtn = document.getElementById('continueBtn');
            const itemTitle = document.getElementById('itemTitle');

            const finalStatus = document.getElementById('finalStatus');
            const finalQuantity = document.getElementById('finalQuantity');
            const finalAmountTendered = document.getElementById('finalAmountTendered');

            function updateModeButtons() {
                deliveryBtn.classList.toggle('active', containerStatus === 'new');
                pickupBtn.classList.toggle('active', containerStatus === 'pickup');
                deliveryBtn.setAttribute('aria-pressed', containerStatus === 'new' ? 'true' : 'false');
                pickupBtn.setAttribute('aria-pressed', containerStatus === 'pickup' ? 'true' : 'false');
            }

            function updateSummary() {
                const selectedPrice = priceMap[containerSize][containerStatus];
                const pickupBase = pickupBaseMap[containerSize];
                const water = pickupBase * quantity;
                const newContainer = containerStatus === 'new' ? (selectedPrice - pickupBase) * quantity : 0;

                const discountCount = Math.floor(quantity / 5);
                const discount = discountCount > 0 ? (discountCount * 5) : 0;
                const finalAmount = (selectedPrice * quantity) - discount;

                qtyDisplay.textContent = String(quantity);
                waterTotal.textContent = water.toFixed(2);
                containerTotal.textContent = newContainer.toFixed(2);
                containerLine.style.display = containerStatus === 'new' ? 'block' : 'none';
                continueBtn.disabled = false;

                itemTitle.textContent = sizeMap[containerSize] + ' - ' + typeMap[containerSize].charAt(0).toUpperCase() + typeMap[containerSize].slice(1);

                finalStatus.value = containerStatus;
                finalQuantity.value = String(quantity);
                finalAmountTendered.value = finalAmount.toFixed(2);

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
                containerStatus = 'new';
                updateSummary();
            });

            pickupBtn.addEventListener('click', function() {
                containerStatus = 'pickup';
                updateSummary();
            });

            updateSummary();
        })();
    </script>
</body>
</html>
