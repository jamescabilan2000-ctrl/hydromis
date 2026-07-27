<?php
require_once '../config/database.php';

$reward_catalog = [
    [
        'code' => 'free_1_gallon',
        'title' => 'Free 1 Gallon Regular Water',
        'description' => 'Instantly redeem at cashier after purchase.',
        'points' => 50,
        'tag' => 'Water Reward'
    ],
    [
        'code' => 'voucher_20',
        'title' => 'Discount Voucher',
        'description' => 'Get P20 off on your next refill order.',
        'points' => 100,
        'tag' => 'Voucher'
    ],
    [
        'code' => 'bundle_fast_lane',
        'title' => 'Free 1 Gallons Bundle',
        'description' => 'Fast-lane service on your next visit.',
        'points' => 150,
        'tag' => 'Service Perk'
    ],
    [
        'code' => 'bundle_2_gallons',
        'title' => 'Free 2 Gallons Bundle',
        'description' => 'Best value bundle for loyal customers.',
        'points' => 250,
        'tag' => 'Premium Reward'
    ],
];

$reward_by_code = [];
foreach ($reward_catalog as $reward_item) {
    $reward_by_code[$reward_item['code']] = $reward_item;
}

$error = '';
$success = '';
$redemption_details = null;
$selected_user = null;
$user_id = '';
$mobile_lookup = '';
$redemption_history = [];

function load_user_by_id($conn, $user_id) {
    $safe_user_id = sanitize($user_id);
    $sql = "SELECT * FROM users WHERE user_id = '$safe_user_id' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

function load_redemption_history($conn, $user_id, $limit = 5) {
    $history = [];
    $safe_user_id = sanitize($user_id);
    $safe_limit = max(1, intval($limit));
    $sql = "SELECT transaction_id, description, notes, created_at FROM transactions WHERE user_id = '$safe_user_id' AND description LIKE 'Reward Redemption - %' ORDER BY created_at DESC LIMIT $safe_limit";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
    }

    return $history;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mobile_lookup_submit'])) {
    $mobile_lookup = sanitize(trim($_POST['mobile_number'] ?? ''));

    if (empty($mobile_lookup)) {
        $error = 'Please enter your mobile number.';
    } else {
        $sql = "SELECT * FROM users WHERE contact_number = '$mobile_lookup' LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $selected_user = $result->fetch_assoc();
            $user_id = $selected_user['user_id'];
        } else {
            $error = 'No account found for that mobile number.';
        }
    }
}

if (!$selected_user) {
    if (isset($_GET['user_id'])) {
        $user_id = trim($_GET['user_id']);
    } elseif (isset($_POST['user_id'])) {
        $user_id = trim($_POST['user_id']);
    }

    if (!empty($user_id)) {
        $selected_user = load_user_by_id($conn, $user_id);
        if (!$selected_user) {
            $error = 'User not found. Please log in again from Scan QR.';
        } else {
            $redemption_history = load_redemption_history($conn, $selected_user['user_id']);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_submit'])) {
    $posted_user_id = trim($_POST['user_id'] ?? '');
    $reward_code = trim($_POST['reward_code'] ?? '');

    if (empty($posted_user_id) || empty($reward_code)) {
        $error = 'Invalid redemption request.';
    } elseif (!isset($reward_by_code[$reward_code])) {
        $error = 'Selected reward is not available.';
    } else {
        $selected_user = load_user_by_id($conn, $posted_user_id);

        if (!$selected_user) {
            $error = 'User not found. Please try again.';
        } else {
            $reward = $reward_by_code[$reward_code];
            $available_points = intval($selected_user['loyalty_points'] ?? 0);
            $required_points = intval($reward['points']);

            if ($available_points < $required_points) {
                $error = 'Not enough points for this reward.';
            } else {
                $safe_user_id = sanitize($posted_user_id);
                $sql_deduct = "UPDATE users SET loyalty_points = loyalty_points - $required_points WHERE user_id = '$safe_user_id'";

                if ($conn->query($sql_deduct) === TRUE) {
                    $redemption_id = generateID('RWD');
                    $reward_title = sanitize($reward['title']);
                    $reward_tag = sanitize($reward['tag']);
                    $reward_description = sanitize($reward['description']);
                    $log_description = "Reward Redemption - $reward_title";
                    $log_notes = "Converted $required_points points for [$reward_tag] $reward_description";

                    $sql_log = "INSERT INTO transactions (transaction_id, user_id, amount, description, water_type, quantity, price_per_unit, discount, loyalty_points_earned, notes, status, created_at) VALUES ('$redemption_id', '$safe_user_id', 0, '$log_description', 'regular', 0, 0, 0, 0, '$log_notes', 'approved', NOW())";

                    if ($conn->query($sql_log) !== TRUE) {
                        $fallback_log = "INSERT INTO activity_logs (admin_id, action, description, timestamp) VALUES ('SYSTEM', 'reward_redeem', '$log_description', NOW())";
                        $conn->query($fallback_log);
                    }

                    $selected_user = load_user_by_id($conn, $posted_user_id);
                    $remaining_points = intval($selected_user['loyalty_points'] ?? 0);

                    $redemption_details = [
                        'id' => $redemption_id,
                        'reward_title' => $reward['title'],
                        'used_points' => $required_points,
                        'remaining_points' => $remaining_points,
                        'time' => date('M d, Y h:i A')
                    ];

                    $success = 'Conversion successful. Your points have been deducted.';
                    $redemption_history = load_redemption_history($conn, $posted_user_id);
                } else {
                    $error = 'Unable to process conversion right now. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Conversion - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --accent: #10b981;
            --accent-light: #6ee7b7;
            --accent-soft: #ecfdf5;
            --warning: #f59e0b;
            --danger: #ef4444;
            --danger-soft: #fee2e2;
            --ink: #0f172a;
            --ink-light: #475569;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            font-size: 14px;
            color: var(--ink);
            background: linear-gradient(135deg, #f0f9ff 0%, #f0fdf4 50%, #faf5ff 100%);
            min-height: 100vh;
            padding: 20px 16px 40px;
            -webkit-font-smoothing: antialiased;
            -webkit-touch-callout: none;
        }

        .page-shell {
            max-width: 1000px;
            margin: 0 auto;
        }

        @supports (padding: max(0px)) {
            body {
                padding-bottom: max(40px, env(safe-area-inset-bottom));
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
            }
        }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--ink);
        }

        .brand i {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            font-size: 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--ink);
            border-radius: 10px;
            padding: 11px 16px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
        }

        .back-link:hover {
            text-decoration: none;
            color: var(--primary);
            border-color: var(--primary-light);
            background: #f0f4ff;
            transform: translateX(-2px);
            box-shadow: var(--shadow-md);
        }

        @media (hover: none) and (pointer: coarse) {
            .back-link:active {
                background: #f0f4ff;
                border-color: var(--primary-light);
            }
        }

        .dashboard-card {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(12px);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.5s ease;
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

        .login-panel {
            max-width: 520px;
            margin: 40px auto 0;
        }

        .title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .title-row h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            letter-spacing: -0.6px;
            font-weight: 800;
            color: var(--ink);
        }

        .subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .points-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 2px solid var(--accent-light);
            color: var(--accent);
            background: var(--accent-soft);
            border-radius: 999px;
            padding: 10px 18px;
            font-weight: 800;
            font-size: 14px;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .alert-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border-radius: 12px;
            padding: 14px 16px;
            font-weight: 600;
            margin-bottom: 20px;
            font-size: 13px;
            border: 1px solid;
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: var(--danger-soft);
            border-color: #fca5a5;
            color: #991b1b;
        }

        .alert-success {
            background: var(--accent-soft);
            border-color: #86efac;
            color: #15803d;
        }

        .alert-box i {
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .user-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #f0f9ff 0%, #f5f3ff 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 18px;
        }

        .user-item {
            flex: 1 1 200px;
            min-width: 0;
        }

        .user-item small {
            display: block;
            color: #64748b;
            font-size: 11px;
            margin-bottom: 4px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .user-item strong {
            font-size: 14px;
            color: var(--ink);
            word-break: break-word;
        }

        .rewards-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            margin-bottom: 28px;
        }

        .reward-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: var(--shadow-md);
            display: flex;
            flex-direction: column;
            min-height: 220px;
            position: relative;
            transition: all 0.3s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .reward-card:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
        }

        .reward-card.unlocked {
            border-color: var(--accent-light);
            box-shadow: 0 12px 24px rgba(16, 185, 129, 0.12);
            background: linear-gradient(135deg, #f7fffc 0%, #f0fdf4 100%);
        }

        .reward-card.unlocked:hover {
            box-shadow: 0 16px 32px rgba(16, 185, 129, 0.18);
        }

        @media (hover: none) and (pointer: coarse) {
            .reward-card:active {
                transform: translateY(-2px);
                box-shadow: var(--shadow-lg);
            }
        }

        .reward-tag {
            position: absolute;
            top: 16px;
            right: 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .reward-card.unlocked .reward-tag {
            color: var(--accent);
            background: var(--accent-soft);
            border-color: var(--accent-light);
        }

        .reward-title {
            margin: 0 0 12px 0;
            padding-right: 90px;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: -0.3px;
            color: var(--ink);
        }

        .reward-desc {
            margin: 0 0 20px 0;
            color: var(--muted);
            line-height: 1.5;
            font-size: 13px;
        }

        .reward-footer {
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 7px 14px;
            font-weight: 700;
            font-size: 13px;
            color: var(--muted);
            background: var(--bg);
            transition: all 0.3s ease;
        }

        .reward-card.unlocked .pill {
            color: var(--accent);
            border-color: var(--accent-light);
            background: var(--accent-soft);
        }

        .convert-btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 13px;
            background: linear-gradient(135deg, var(--accent) 0%, #059669 100%);
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .convert-btn:hover:not([disabled]) {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }

        .convert-btn[disabled] {
            cursor: not-allowed;
            background: #cbd5e1;
            color: var(--muted);
            box-shadow: none;
            opacity: 0.6;
        }

        .convert-pill-form {
            margin: 0;
        }

        .convert-pill-btn {
            border: 1.5px solid var(--accent-light);
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 700;
            font-size: 14px;
            color: var(--accent);
            background: var(--accent-soft);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
        }

        .convert-pill-btn:hover:not([disabled]) {
            background: #d1fae5;
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
            transform: scale(1.05);
        }

        .convert-pill-btn[disabled] {
            cursor: not-allowed;
            color: #94a3b8;
            border-color: #cbd5e1;
            background: #f1f5f9;
            opacity: 0.5;
        }

        @media (hover: none) and (pointer: coarse) {
            .convert-pill-btn:active:not([disabled]) {
                background: #a7f3d0;
                transform: scale(0.98);
            }
        }

        .receipt {
            margin-top: 24px;
            border: 1px solid #a7f3d0;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border-radius: 14px;
            padding: 16px;
            animation: slideUp 0.5s ease;
        }

        .receipt h5 {
            margin: 0 0 14px 0;
            color: var(--accent);
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: #047857;
            font-size: 13px;
            margin: 6px 0;
            padding: 4px 0;
        }

        .receipt-row strong {
            color: #15803d;
            font-weight: 700;
        }

        .history-block {
            margin-top: 28px;
            border: 1px solid var(--border);
            background: var(--bg);
            border-radius: 14px;
            padding: 16px;
        }

        .history-head {
            margin: 0 0 14px 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .history-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 10px;
        }

        .history-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--card);
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            transition: all 0.3s ease;
        }

        .history-item:hover {
            border-color: var(--accent-light);
            background: linear-gradient(135deg, #f7fffc 0%, #f0fdf4 100%);
            box-shadow: var(--shadow-sm);
        }

        .history-main {
            min-width: 0;
            flex: 1;
        }

        .history-main strong {
            display: block;
            font-size: 13px;
            color: var(--ink);
            line-height: 1.3;
            margin-bottom: 4px;
        }

        .history-meta {
            font-size: 12px;
            color: var(--muted);
        }

        .history-points {
            white-space: nowrap;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid var(--accent-light);
            border-radius: 999px;
            padding: 6px 12px;
            flex-shrink: 0;
        }

        .history-empty {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
            padding: 16px;
            text-align: center;
        }

        .field-label {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--ink);
            display: block;
        }

        .field-input {
            width: 100%;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            padding: 14px 16px;
            font-size: 16px;
            margin-bottom: 16px;
            font-family: inherit;
            transition: all 0.3s ease;
            background: var(--bg);
            -webkit-appearance: none;
            appearance: none;
            min-height: 44px;
        }

        .field-input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--card);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            font-size: 16px;
        }

        .field-input::placeholder {
            color: #94a3b8;
        }

        .primary-btn {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 16px 16px;
            font-weight: 700;
            font-size: 15px;
            color: white;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            -webkit-tap-highlight-color: transparent;
        }

        .primary-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        .primary-btn:active {
            transform: translateY(0);
        }

        @media (hover: none) and (pointer: coarse) {
            .primary-btn:active {
                background: linear-gradient(135deg, #1e40af 0%, #1a3a8a 100%);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .page-shell {
                padding: 16px 14px;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 20px;
            }

            .dashboard-card {
                border-radius: 14px;
                padding: 14px;
            }

            .title-row {
                margin-bottom: 14px;
            }

            .title-row h1 {
                font-size: 22px;
            }

            .user-strip {
                gap: 12px;
                padding: 14px;
                margin-bottom: 20px;
            }

            .user-item {
                flex: 1 1 140px;
            }

            .rewards-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .reward-title {
                font-size: 16px;
                padding-right: 60px;
            }

            .reward-footer {
                gap: 8px;
            }

            .history-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .history-points {
                align-self: flex-start;
            }

            .field-label {
                font-size: 12px;
            }

            .field-input {
                padding: 11px 12px;
                font-size: 13px;
            }

            .primary-btn {
                padding: 12px 14px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .page-shell {
                padding: 12px 14px;
            }

            .dashboard-card {
                border-radius: 14px;
                padding: 18px;
            }

            .top-bar {
                gap: 12px;
                margin-bottom: 20px;
            }

            .brand {
                font-size: 20px;
            }

            .title-row {
                flex-direction: column;
                gap: 14px;
                margin-bottom: 18px;
            }

            .title-row h1 {
                font-size: 24px;
                margin: 0;
            }

            .points-chip {
                font-size: 13px;
                padding: 10px 16px;
                min-height: 40px;
            }

            .user-strip {
                gap: 12px;
                padding: 14px;
                margin-bottom: 20px;
            }

            .user-item {
                flex: 1 1 120px;
            }

            .user-item small {
                font-size: 10px;
            }

            .user-item strong {
                font-size: 13px;
            }

            .rewards-grid {
                gap: 14px;
                grid-template-columns: 1fr;
            }

            .reward-card {
                padding: 16px;
                min-height: 200px;
            }

            .reward-title {
                font-size: 15px;
                padding-right: 45px;
                margin: 0 0 10px 0;
            }

            .reward-desc {
                font-size: 13px;
                margin: 0 0 16px 0;
                line-height: 1.4;
            }

            .reward-footer {
                gap: 10px;
            }

            .pill {
                font-size: 12px;
                padding: 6px 12px;
                min-height: 32px;
            }

            .convert-btn {
                font-size: 12px;
                padding: 10px 12px;
                min-height: 40px;
            }

            .convert-pill-btn {
                font-size: 13px;
                padding: 10px 16px;
                min-height: 44px;
            }

            .field-label {
                font-size: 13px;
                margin-bottom: 10px;
            }

            .field-input {
                font-size: 16px;
                padding: 14px 14px;
                margin-bottom: 14px;
                min-height: 48px;
            }

            .primary-btn {
                font-size: 15px;
                padding: 14px 16px;
                min-height: 48px;
            }

            .receipt {
                padding: 14px;
                margin-top: 20px;
            }

            .receipt h5 {
                font-size: 14px;
                margin: 0 0 12px 0;
            }

            .receipt-row {
                font-size: 13px;
                margin: 8px 0;
            }

            .history-block {
                margin-top: 20px;
                padding: 14px;
            }

            .history-head {
                font-size: 13px;
                margin: 0 0 12px 0;
            }

            .history-item {
                padding: 12px;
                border-radius: 12px;
                gap: 10px;
            }

            .history-main strong {
                font-size: 13px;
            }

            .history-meta {
                font-size: 12px;
            }

            .history-points {
                font-size: 12px;
                padding: 6px 12px;
                min-height: 32px;
            }

            .history-empty {
                font-size: 13px;
                padding: 16px 0;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="top-bar">
            <div class="brand"><img src="../imagess/logosystem.png" alt="HydroMIS Logo" style="width: 24px; height: 24px; object-fit: contain; margin-right: 6px;"> HydroMIS</div>
            </a>
        </div>

        <?php if (!$selected_user): ?>
            <div class="dashboard-card login-panel">
                <div class="title-row">
                    <div>
                        <h1>Rewards Conversion</h1>
                        <p class="subtitle">Enter your mobile number to open your rewards wallet.</p>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <label class="field-label" for="mobile_number">Mobile Number</label>
                    <input class="field-input" type="text" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($mobile_lookup); ?>" required>
                    <button type="submit" name="mobile_lookup_submit" value="1" class="primary-btn">
                        <i class="fas fa-right-to-bracket"></i> Open My Rewards
                    </button>
                </form>
            </div>
        <?php else: ?>
            <?php $available_points = intval($selected_user['loyalty_points'] ?? 0); ?>
            <?php $user_id = $selected_user['user_id']; ?>

            <div class="dashboard-card">
                <div class="title-row">
                    <div>
                        <h1>Rewards</h1>
                        <p class="subtitle">Convert your points into real perks for your next refill.</p>
                    </div>
                    <div class="points-chip">
                        <i class="fas fa-lock"></i>
                        <?php echo $available_points; ?> pts available
                    </div>
                </div>


                <?php if (!empty($error)): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert-box alert-success">
                        <i class="fas fa-circle-check"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <div class="rewards-grid">
                    <?php foreach ($reward_catalog as $reward): ?>
                        <?php $is_unlocked = $available_points >= $reward['points']; ?>
                        <div class="reward-card <?php echo $is_unlocked ? 'unlocked' : ''; ?>">
                            <span class="reward-tag"><?php echo htmlspecialchars($reward['tag']); ?></span>
                            <h3 class="reward-title"><?php echo htmlspecialchars($reward['title']); ?></h3>
                            <p class="reward-desc"><?php echo htmlspecialchars($reward['description']); ?></p>

                            <div class="reward-footer">
                                <form method="POST" class="convert-pill-form">
                                    <input type="hidden" name="redeem_submit" value="1">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                                    <input type="hidden" name="reward_code" value="<?php echo htmlspecialchars($reward['code']); ?>">
                                    <button type="submit" class="convert-pill-btn">
                                        <i class="fas fa-lock"></i> <?php echo intval($reward['points']); ?> pts
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($redemption_details): ?>
                    <div class="receipt">
                        <h5><i class="fas fa-check-circle"></i> Successful Conversion</h5>
                        <div class="receipt-row"><span>Redemption ID</span><strong><?php echo htmlspecialchars($redemption_details['id']); ?></strong></div>
                        <div class="receipt-row"><span>Reward</span><strong><?php echo htmlspecialchars($redemption_details['reward_title']); ?></strong></div>
                        <div class="receipt-row"><span>Points Used</span><strong><?php echo intval($redemption_details['used_points']); ?> pts</strong></div>
                        <div class="receipt-row"><span>Remaining Points</span><strong><?php echo intval($redemption_details['remaining_points']); ?> pts</strong></div>
                        <div class="receipt-row"><span>Converted At</span><strong><?php echo htmlspecialchars($redemption_details['time']); ?></strong></div>
                    </div>
                <?php endif; ?>

                <div class="history-block">
                    <h5 class="history-head"><i class="fas fa-history"></i> Last 5 Conversions</h5>
                    <?php if (!empty($redemption_history)): ?>
                        <ul class="history-list">
                            <?php foreach ($redemption_history as $history_item): ?>
                                <?php
                                    $history_reward = str_replace('Reward Redemption - ', '', $history_item['description'] ?? 'Reward Conversion');
                                    $history_time = !empty($history_item['created_at']) ? date('M d, Y h:i A', strtotime($history_item['created_at'])) : '-';
                                    $history_points_label = 'Converted';
                                    if (!empty($history_item['notes']) && preg_match('/Converted\s+(\d+)\s+points/i', $history_item['notes'], $matches)) {
                                        $history_points_label = '-' . intval($matches[1]) . ' pts';
                                    }
                                ?>
                                <li class="history-item">
                                    <div class="history-main">
                                        <strong><?php echo htmlspecialchars($history_reward); ?></strong>
                                        <div class="history-meta">
                                            <?php echo htmlspecialchars($history_item['transaction_id'] ?? '-'); ?> | <?php echo htmlspecialchars($history_time); ?>
                                        </div>
                                    </div>
                                    <div class="history-points"><?php echo htmlspecialchars($history_points_label); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="history-empty">No conversions yet. Convert your first reward to see history here.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: var(--card); border-radius: 16px; padding: 28px; max-width: 420px; width: 90%; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2); animation: slideUp 0.3s ease;">
            <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: var(--ink);">Confirm Conversion</h3>
            <p id="confirmMessage" style="margin: 0 0 24px 0; color: var(--muted); font-size: 14px; line-height: 1.5;"></p>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button id="cancelBtn" type="button" style="border: 1.5px solid var(--border); background: transparent; color: var(--ink); padding: 12px 20px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.3s ease; min-height: 44px;">
                    Cancel
                </button>
                <button id="confirmBtn" type="button" style="border: none; background: linear-gradient(135deg, var(--accent) 0%, #059669 100%); color: white; padding: 12px 24px; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.3s ease; min-height: 44px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);">
                    Convert
                </button>
            </div>
        </div>
    </div>

    <script>
        let pendingForm = null;
        const confirmModal = document.getElementById('confirmModal');
        const cancelBtn = document.getElementById('cancelBtn');
        const confirmBtn = document.getElementById('confirmBtn');
        const confirmMessage = document.getElementById('confirmMessage');

        function showConfirmModal(rewardTitle, requiredPoints) {
            confirmMessage.textContent = 'Convert ' + requiredPoints + ' points for "' + rewardTitle + '"?';
            confirmModal.style.display = 'flex';
        }

        function hideConfirmModal() {
            confirmModal.style.display = 'none';
            pendingForm = null;
        }

        cancelBtn.addEventListener('click', function() {
            hideConfirmModal();
        });

        confirmBtn.addEventListener('click', function() {
            if (pendingForm) {
                hideConfirmModal();
                pendingForm.submit();
            }
        });

        // Close modal when clicking outside
        confirmModal.addEventListener('click', function(e) {
            if (e.target === confirmModal) {
                hideConfirmModal();
            }
        });

        // Capture form submissions and show confirmation
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form.classList.contains('convert-pill-form')) {
                const button = form.querySelector('button');
                const pointsText = button.textContent.trim();
                const pointsMatch = pointsText.match(/(\d+)\s*pts/);
                const rewardTitle = form.closest('.reward-card').querySelector('.reward-title').textContent.trim();
                const requiredPoints = pointsMatch ? pointsMatch[1] : 0;
                
                e.preventDefault();
                pendingForm = form;
                showConfirmModal(rewardTitle, requiredPoints);
            }
        });

        // Add hover/active styles for modal buttons
        [cancelBtn, confirmBtn].forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                if (this.id === 'cancelBtn') {
                    this.style.background = 'var(--bg)';
                    this.style.borderColor = 'var(--primary-light)';
                } else {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 6px 16px rgba(16, 185, 129, 0.3)';
                }
            });
            btn.addEventListener('mouseleave', function() {
                if (this.id === 'cancelBtn') {
                    this.style.background = 'transparent';
                    this.style.borderColor = 'var(--border)';
                } else {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 4px 12px rgba(16, 185, 129, 0.2)';
                }
            });
        });
    </script>
</body>
</html>
