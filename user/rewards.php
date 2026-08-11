<?php
require_once '../config/database.php';
require_once '../config/system_settings.php';

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
        'code' => 'delivery_discount',
        'title' => 'Delivery Fee Discount',
        'description' => 'Get P20 off the delivery fee on your next order.',
        'points' => 125,
        'tag' => 'Delivery Perk'
    ],
    [
        'code' => 'bundle_fast_lane',
        'title' => 'Free 1 Gallons Bundle',
        'description' => 'Fast-lane service on your next visit.',
        'points' => 150,
        'tag' => 'Service Perk'
    ],
    [
        'code' => 'free_delivery',
        'title' => 'Free Delivery',
        'description' => 'Enjoy free delivery on your next eligible water order.',
        'points' => 200,
        'tag' => 'Delivery Reward'
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
$reward_enabled = [];
foreach ($reward_catalog as $reward_item) {
    $reward_by_code[$reward_item['code']] = $reward_item;
    $reward_enabled[$reward_item['code']] = system_int_setting($conn, 'reward_enabled_' . $reward_item['code'], 1, 0, 1) === 1;
}

$conn->query("CREATE TABLE IF NOT EXISTS reward_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL UNIQUE,
    user_id VARCHAR(50) NOT NULL,
    reward_code VARCHAR(80) NOT NULL,
    reward_title VARCHAR(255) NOT NULL,
    points_used INT NOT NULL,
    claim_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    claimed_by VARCHAR(80) NULL,
    claimed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reward_claim_status (claim_status),
    INDEX idx_reward_claim_user (user_id)
)");
$conn->query("ALTER TABLE reward_claims ADD COLUMN IF NOT EXISTS customer_seen_at DATETIME NULL");
$seenMigration = $conn->query("SELECT setting_key FROM system_settings WHERE setting_key='reward_seen_migration_v1' LIMIT 1");
if (!$seenMigration || $seenMigration->num_rows === 0) {
    $conn->query("UPDATE reward_claims SET customer_seen_at=NOW() WHERE claim_status='claimed' AND customer_seen_at IS NULL");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key,setting_value,updated_by) VALUES ('reward_seen_migration_v1','1','SYSTEM')");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge_reward_updates'])) {
    $seenUserId = sanitize(trim((string)($_POST['user_id'] ?? '')));
    if ($seenUserId !== '') {
        $conn->query("UPDATE reward_claims SET customer_seen_at=NOW() WHERE user_id='$seenUserId' AND claim_status='approved' AND customer_seen_at IS NULL");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

$error = '';
$success = '';
$redemption_details = null;
$selected_user = null;
$user_id = '';
$mobile_lookup = '';
$redemption_history = [];
$loyalty_reset_at = loyalty_points_reset_at($conn);

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
    $sql = "SELECT t.transaction_id, t.description, t.notes, t.created_at, rc.reward_code, COALESCE(rc.claim_status,'pending') AS claim_status, rc.customer_seen_at
        FROM transactions t
        LEFT JOIN reward_claims rc ON rc.transaction_id=t.transaction_id
        WHERE t.user_id = '$safe_user_id' AND t.description LIKE 'Reward Redemption - %'
        ORDER BY t.created_at DESC LIMIT $safe_limit";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
    }

    return $history;
}

function generate_reward_id($conn): string {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'RWD-' . strtoupper(bin2hex(random_bytes(4)));
        $safeCandidate = $conn->real_escape_string($candidate);
        $existing = $conn->query("SELECT transaction_id FROM transactions WHERE transaction_id='$safeCandidate' LIMIT 1");
        if (!$existing || $existing->num_rows === 0) return $candidate;
    }
    throw new RuntimeException('Unable to generate a unique reward ID.');
}

function compact_reward_id(string $transactionId): string {
    return str_starts_with($transactionId, 'RWD-') && strlen($transactionId) > 12
        ? 'RWD-' . substr($transactionId, 4, 8)
        : $transactionId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mobile_lookup_submit'])) {
    $mobile_lookup = sanitize(trim($_POST['mobile_number'] ?? ''));

    if (empty($mobile_lookup)) {
        $error = 'Please enter your mobile number.';
    } else {
        $contact_lookup = sensitive_lookup(htmlspecialchars_decode($mobile_lookup));
        $sql = "SELECT * FROM users WHERE contact_lookup = '$contact_lookup' LIMIT 1";
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
    } elseif (empty($reward_enabled[$reward_code])) {
        $error = 'This reward is temporarily disabled by the administrator.';
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
                    $redemption_id = generate_reward_id($conn);
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
                    $safe_reward_code = sanitize($reward['code']);
                    $safe_reward_claim_title = sanitize($reward['title']);
                    $conn->query("INSERT INTO reward_claims (transaction_id, user_id, reward_code, reward_title, points_used, claim_status, created_at) VALUES ('$redemption_id', '$safe_user_id', '$safe_reward_code', '$safe_reward_claim_title', $required_points, 'pending', NOW())");

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
if ($selected_user && empty($redemption_history)) {
    $redemption_history = load_redemption_history($conn, (string)$selected_user['user_id']);
}
$has_approved_reward = count(array_filter($redemption_history, fn($item) => ($item['claim_status'] ?? '') === 'approved' && empty($item['customer_seen_at']))) > 0;
$has_approved_free_delivery = count(array_filter($redemption_history, fn($item) => ($item['claim_status'] ?? '') === 'approved' && ($item['reward_code'] ?? '') === 'free_delivery' && empty($item['customer_seen_at']))) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($user_id)): ?><meta name="hydromis-user-id" content="<?php echo htmlspecialchars($user_id); ?>"><?php endif; ?>
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

        .rewards-hero {
            position: relative;
            isolation: isolate;
            min-height: 205px;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 30px 34px;
            margin: -6px -6px 28px;
            border-radius: 20px;
            color: #fff;
            background-color: #052b4a;
            background-image: url('../imagess/registration-gallons-bg-v3.png');
            background-repeat: no-repeat;
            background-position: 58% 50%;
            background-size: cover;
            box-shadow: 0 18px 34px rgba(23, 85, 170, .22);
            animation: rewardsWaterDrift 16s ease-in-out infinite alternate;
            transition: background-size 1.1s cubic-bezier(.2,.8,.2,1), filter .8s ease, box-shadow .5s ease;
        }
        .rewards-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 0;
            background: linear-gradient(105deg, rgba(1,20,42,.88) 0%, rgba(2,37,67,.72) 48%, rgba(3,63,86,.28) 100%);
            transition: background .8s ease;
        }
        .rewards-hero::after { content:''; position:absolute; inset:-35%; z-index:1; pointer-events:none; background:linear-gradient(112deg,transparent 38%,rgba(151,239,255,.13) 49%,transparent 60%); transform:translateX(-46%) rotate(4deg); animation:rewardsLightSweep 8s ease-in-out infinite; }
        .rewards-hero:hover { background-size:cover; filter:saturate(1.04) brightness(1.02); box-shadow:0 23px 44px rgba(10,89,157,.3); }
        .rewards-hero-copy { position: relative; z-index: 2; max-width: 500px; }
        .rewards-kicker { margin: 0 0 8px; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #b8e5ff; }
        .rewards-hero h1 { margin: 0; color: #fff; font-size: clamp(27px, 4vw, 36px); font-weight: 800; letter-spacing: -.045em; }
        .rewards-hero .subtitle { max-width: 380px; margin: 9px 0 0; color: rgba(235, 247, 255, .86); font-size: 14px; }
        .hero-points {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-top: 19px;
            padding: 9px 13px;
            color: #14345d;
            background: rgba(255,255,255,.95);
            border-radius: 11px;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(4, 29, 67, .18);
        }
        .rewards-water-bubbles { position:absolute; inset:0; z-index:1; overflow:hidden; pointer-events:none; }
        .rewards-water-bubbles span { position:absolute; bottom:-18px; width:9px; height:9px; border:1px solid rgba(193,245,255,.7); border-radius:50%; background:radial-gradient(circle at 32% 28%,rgba(255,255,255,.82),rgba(94,216,245,.1) 40%,transparent 68%); box-shadow:0 0 7px rgba(86,218,255,.3); opacity:0; animation:rewardsBubbleRise 8s ease-in infinite; }
        .rewards-water-bubbles span:nth-child(1){left:12%;animation-delay:.5s}.rewards-water-bubbles span:nth-child(2){left:38%;width:13px;height:13px;animation-delay:3s;animation-duration:10s}.rewards-water-bubbles span:nth-child(3){left:67%;width:6px;height:6px;animation-delay:1.7s;animation-duration:7s}.rewards-water-bubbles span:nth-child(4){left:88%;width:15px;height:15px;animation-delay:4.8s;animation-duration:11s}
        @keyframes rewardsWaterDrift { 0%{background-position:52% 48%} 50%{background-position:59% 52%} 100%{background-position:66% 47%} }
        @keyframes rewardsLightSweep { 0%,18%{opacity:0;transform:translateX(-46%) rotate(4deg)} 48%{opacity:1} 78%,100%{opacity:0;transform:translateX(46%) rotate(4deg)} }
        @keyframes rewardsBubbleRise { 0%{opacity:0;transform:translateY(0) scale(.7)} 14%{opacity:.75} 85%{opacity:.45} 100%{opacity:0;transform:translate(10px,-245px) scale(1.14)} }

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
            min-height: 192px;
            position: relative;
            transition: all 0.3s ease;
            -webkit-tap-highlight-color: transparent;
            animation: rewardRise .45s ease both;
        }
        .reward-card:nth-child(2) { animation-delay: .07s; }
        .reward-card:nth-child(3) { animation-delay: .14s; }
        .reward-card:nth-child(4) { animation-delay: .21s; }
        @keyframes rewardRise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }

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
        @media (max-width: 640px) {
            .rewards-hero { min-height: 218px; padding: 27px 24px; margin: -4px -4px 24px; }
            .rewards-hero-copy { max-width: 270px; }
            .hero-water-image { right: -44px; bottom: -20px; width: 190px; height: 190px; opacity: .48; }
        }
        @media (prefers-reduced-motion: reduce) { .points-chip, .hero-water-image, .reward-card { animation: none; } }

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

        .reward-icon {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            margin-bottom: 15px;
            border-radius: 14px;
            color: #2563eb;
            background: #eaf3ff;
            font-size: 18px;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .08);
        }
        .reward-card.unlocked .reward-icon { color: #059669; background: #dcfce7; }

        .reward-card.unlocked .reward-tag {
            color: var(--accent);
            background: var(--accent-soft);
            border-color: var(--accent-light);
        }

        .reward-title {
            margin: 0 0 8px 0;
            padding-right: 104px;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: -0.3px;
            color: var(--ink);
        }

        .reward-desc {
            margin: 0 0 16px 0;
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
            border-radius: 12px;
            padding: 11px 15px;
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
            min-height: 42px;
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
            opacity: .82;
        }

        .conversion-modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(7, 24, 47, .56);
            backdrop-filter: blur(7px);
        }
        .conversion-dialog {
            width: min(100%, 430px);
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, .9);
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(4, 20, 44, .3);
            animation: dialogEnter .24s ease both;
        }
        .conversion-dialog-icon { display: grid; place-items: center; width: 46px; height: 46px; margin-bottom: 17px; border-radius: 14px; color: #059669; background: #d1fae5; font-size: 19px; }
        .conversion-dialog h3 { margin: 0 0 8px; color: var(--ink); font-size: 21px; font-weight: 800; letter-spacing: -.025em; }
        .conversion-dialog p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.58; }
        .conversion-note { margin-top: 13px !important; color: #8a9aac !important; font-size: 12px !important; }
        .reward-collection-note { display: flex; align-items: flex-start; gap: 9px; margin-top: 15px; padding: 11px 12px; border-radius: 10px; color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; font-size: 12px; line-height: 1.45; }
        .reward-collection-note i { margin-top: 2px; color: #16a34a; }
        .conversion-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; }
        .modal-btn { min-height: 46px; padding: 11px 19px; border-radius: 12px; font-weight: 750; font-size: 14px; cursor: pointer; transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
        .modal-btn:hover { transform: translateY(-1px); }
        .modal-btn-cancel { border: 1px solid var(--border); color: var(--ink); background: #fff; }
        .modal-btn-confirm { border: 0; color: #fff; background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 16px rgba(5, 150, 105, .22); }
        .modal-btn-confirm[disabled] { cursor: wait; opacity: .8; transform: none; }
        @keyframes dialogEnter { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @media (max-width: 460px) { .conversion-dialog { padding: 25px 22px; } .conversion-actions { flex-direction: column-reverse; } .modal-btn { width: 100%; } }

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

        .history-trigger{position:absolute;z-index:4;top:18px;right:18px;display:grid;place-items:center;width:46px;height:46px;border:1px solid rgba(255,255,255,.55);border-radius:15px;background:rgba(255,255,255,.92);color:#0879b9;font-size:18px;box-shadow:0 10px 26px rgba(3,55,91,.2);cursor:pointer;transition:transform .2s ease,background .2s ease}.history-trigger:hover{transform:translateY(-2px);background:#fff}.history-trigger.has-history::after{content:'';position:absolute;top:7px;right:7px;width:8px;height:8px;border:2px solid #fff;border-radius:50%;background:#10b981}.history-modal{position:fixed;z-index:1100;inset:0;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(8,29,45,.58);backdrop-filter:blur(5px);opacity:0;visibility:hidden;transition:opacity .2s ease,visibility .2s ease}.history-modal.open{opacity:1;visibility:visible}.history-dialog{width:min(100%,520px);max-height:min(82vh,680px);overflow:auto;border:1px solid rgba(255,255,255,.75);border-radius:22px;background:#fff;box-shadow:0 28px 70px rgba(4,34,53,.3);transform:translateY(12px) scale(.98);transition:transform .22s ease}.history-modal.open .history-dialog{transform:none}.history-dialog-head{position:sticky;z-index:2;top:0;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:19px 20px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.96);backdrop-filter:blur(10px)}.history-dialog-title{display:flex;align-items:center;gap:10px}.history-dialog-title i{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:var(--accent-soft);color:var(--accent)}.history-dialog-title h3{margin:0;color:var(--ink);font-size:17px}.history-dialog-title p{margin:2px 0 0;color:var(--muted);font-size:11px}.history-close{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:11px;background:#eef4f6;color:var(--muted);cursor:pointer}.history-dialog-body{padding:18px}.history-dialog .history-list{gap:10px}.history-dialog .history-empty{padding:30px 12px}@media(max-width:480px){.history-trigger{top:12px;right:12px;width:42px;height:42px}.history-dialog{border-radius:19px}.history-dialog-body{padding:13px}}

        .history-trigger.has-approved::after{content:'';position:absolute;top:7px;right:7px;width:10px;height:10px;border:2px solid #fff;border-radius:50%;background:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.2)}.reward-ready-notice{margin-bottom:14px;padding:12px 13px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#9f1239;font-size:12px;line-height:1.5}.reward-ready-notice strong{display:block;margin-bottom:3px;color:#be123c}.history-status-ready,.history-status-approved{display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 8px;border-radius:99px;font-size:9px;font-weight:800;text-transform:uppercase}.history-status-ready{background:#e8faf4;color:#087961}.history-status-approved{background:#e8f2ff;color:#1769aa}

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

        /* Full-screen rewards layout without the decorative outer background/card. */
        body {
            width: 100%;
            min-height: 100dvh;
            padding: 0 !important;
            background: #ffffff;
        }
        .page-shell {
            width: 100%;
            max-width: none;
            min-height: 100dvh;
            margin: 0;
            padding: 0 !important;
        }
        .dashboard-card {
            width: 100%;
            min-height: 100dvh;
            margin: 0;
            padding: clamp(18px, 3vw, 36px);
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
        }
        @media (max-width: 480px) {
            .dashboard-card { padding: 12px; }
            .rewards-hero { margin: 0 0 20px; }
        }
    </style>
    <script src="../js/ui-protection.js" defer></script>
</head>
<body>
    <div class="page-shell">
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
                <section class="rewards-hero" aria-labelledby="rewards-title">
                    <button type="button" class="history-trigger <?php echo $has_approved_reward ? 'has-approved' : (!empty($redemption_history) ? 'has-history' : ''); ?>" id="historyTrigger" aria-label="View recent redemptions" title="Recent redemptions"><i class="fas fa-clock-rotate-left"></i></button>
                    <div class="rewards-water-bubbles" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                    <div class="rewards-hero-copy">
                        <p class="rewards-kicker"><i class="fas fa-droplet"></i> HydroMIS loyalty</p>
                        <h1 id="rewards-title">Your rewards, refreshed.</h1>
                        <p class="subtitle">Turn every refill into a little more value. Choose a perk when you are ready.</p>
                        <div class="hero-points"><i class="fas fa-sparkles"></i> <?php echo $available_points; ?> points available</div>
                        <?php if ($loyalty_reset_at): ?><p style="position:relative;z-index:2;margin:8px 0 0;color:rgba(235,247,255,.78);font-size:11px;"><i class="fas fa-calendar-rotate"></i> Points reset on <?php echo htmlspecialchars(date('M d, Y', strtotime($loyalty_reset_at))); ?></p><?php endif; ?>
                    </div>
                </section>


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
                        <?php $is_enabled = !empty($reward_enabled[$reward['code']]); ?>
                        <?php
                            $reward_icons = [
                                'free_1_gallon' => 'fa-droplet',
                                'voucher_20' => 'fa-ticket',
                                'delivery_discount' => 'fa-truck-fast',
                                'bundle_fast_lane' => 'fa-bolt',
                                'free_delivery' => 'fa-truck',
                                'bundle_2_gallons' => 'fa-gem'
                            ];
                            $reward_icon = $reward_icons[$reward['code']] ?? 'fa-gift';
                        ?>
                        <div class="reward-card <?php echo $is_unlocked ? 'unlocked' : ''; ?>">
                            <span class="reward-tag"><?php echo htmlspecialchars($reward['tag']); ?></span>
                            <div class="reward-icon" aria-hidden="true"><i class="fas <?php echo $reward_icon; ?>"></i></div>
                            <h3 class="reward-title"><?php echo htmlspecialchars($reward['title']); ?></h3>
                            <p class="reward-desc"><?php echo htmlspecialchars($reward['description']); ?></p>

                            <div class="reward-footer">
                                <form method="POST" class="convert-pill-form">
                                    <input type="hidden" name="redeem_submit" value="1">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                                    <input type="hidden" name="reward_code" value="<?php echo htmlspecialchars($reward['code']); ?>">
                                    <button type="submit" class="convert-pill-btn" <?php echo ($is_unlocked && $is_enabled) ? '' : 'disabled'; ?>>
                                        <i class="fas <?php echo !$is_enabled ? 'fa-pause' : ($is_unlocked ? 'fa-gift' : 'fa-lock'); ?>"></i>
                                        <?php if (!$is_enabled): ?>Temporarily unavailable<?php else: ?><?php echo $is_unlocked ? 'Redeem for ' : 'Need '; ?><?php echo intval($reward['points']); ?> pts<?php endif; ?>
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
                        <div class="reward-collection-note"><i class="fas fa-store"></i><span>Please visit the HydroMIS shop and show this conversion record to the cashier to claim your reward.</span></div>
                    </div>
                <?php endif; ?>

                <div class="history-modal" id="historyModal" aria-hidden="true">
                  <div class="history-dialog" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
                    <div class="history-dialog-head">
                      <div class="history-dialog-title"><i class="fas fa-clock-rotate-left"></i><div><h3 id="historyModalTitle">Recent redemptions</h3><p>Your last five points conversions</p></div></div>
                      <button type="button" class="history-close" id="historyClose" aria-label="Close recent redemptions"><i class="fas fa-xmark"></i></button>
                    </div>
                    <div class="history-dialog-body">
                    <?php if ($has_approved_reward): ?>
                        <div class="reward-ready-notice"><strong><i class="fas fa-circle-exclamation"></i> New reward approval</strong><?php echo $has_approved_free_delivery ? 'Your Free Delivery reward is active and will be applied automatically to your next delivery order.' : 'Your reward is approved. Please come to the HydroMIS water refilling station and show your redemption ID to claim it.'; ?></div>
                    <?php endif; ?>
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
                                            <span title="<?php echo htmlspecialchars($history_item['transaction_id'] ?? '-'); ?>"><?php echo htmlspecialchars(compact_reward_id((string)($history_item['transaction_id'] ?? '-'))); ?></span> | <?php echo htmlspecialchars($history_time); ?>
                                        </div>
                                        <?php if (($history_item['claim_status'] ?? '') === 'approved'): ?><span class="history-status-approved"><i class="fas <?php echo ($history_item['reward_code'] ?? '') === 'free_delivery' ? 'fa-truck-fast' : 'fa-store'; ?>"></i> <?php echo ($history_item['reward_code'] ?? '') === 'free_delivery' ? 'Active — next delivery is free' : 'Approved — ready to claim'; ?></span><?php elseif (($history_item['claim_status'] ?? '') === 'claimed'): ?><span class="history-status-ready"><i class="fas fa-circle-check"></i> Already claimed</span><?php endif; ?>
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
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="conversion-modal" role="dialog" aria-modal="true" aria-labelledby="conversion-title" aria-describedby="confirmMessage">
        <div class="conversion-dialog">
            <div class="conversion-dialog-icon"><i class="fas fa-gift"></i></div>
            <h3 id="conversion-title">Confirm conversion</h3>
            <p id="confirmMessage"></p>
            <p class="conversion-note"><i class="fas fa-circle-info"></i> Points will be deducted once you confirm.</p>
            <div class="reward-collection-note"><i class="fas fa-store"></i><span>After converting, please visit the HydroMIS shop and show the cashier your conversion record to claim your reward.</span></div>
            <div class="conversion-actions">
                <button id="cancelBtn" class="modal-btn modal-btn-cancel" type="button">Cancel</button>
                <button id="confirmBtn" class="modal-btn modal-btn-confirm" type="button"><i class="fas fa-check"></i> Convert points</button>
            </div>
        </div>
    </div>

    <script>
        let pendingForm = null;
        const confirmModal = document.getElementById('confirmModal');
        const cancelBtn = document.getElementById('cancelBtn');
        const confirmBtn = document.getElementById('confirmBtn');
        const confirmMessage = document.getElementById('confirmMessage');
        const historyTrigger = document.getElementById('historyTrigger');
        const historyModal = document.getElementById('historyModal');
        const historyClose = document.getElementById('historyClose');

        // Keep the fixed overlay outside the animated/backdrop-filtered card.
        // Mobile browsers otherwise position it against the full card height.
        if (historyModal && historyModal.parentElement !== document.body) {
            document.body.appendChild(historyModal);
        }

        function setHistoryModal(open) {
            if (!historyModal) return;
            historyModal.classList.toggle('open', open);
            historyModal.setAttribute('aria-hidden', open ? 'false' : 'true');
            document.body.style.overflow = open ? 'hidden' : '';
            if (open) historyClose?.focus();
            else historyTrigger?.focus();
        }

        historyTrigger?.addEventListener('click', () => {
            setHistoryModal(true);
            if (historyTrigger.classList.contains('has-approved')) {
                const seenData = new FormData();
                seenData.append('acknowledge_reward_updates', '1');
                seenData.append('user_id', <?php echo json_encode((string)$user_id); ?>);
                fetch('rewards.php', {method: 'POST', body: seenData, credentials: 'same-origin'})
                    .then(response => response.ok ? response.json() : Promise.reject())
                    .then(() => historyTrigger.classList.remove('has-approved'))
                    .catch(() => {});
            }
        });
        historyClose?.addEventListener('click', () => setHistoryModal(false));
        historyModal?.addEventListener('click', event => {
            if (event.target === historyModal) setHistoryModal(false);
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && historyModal?.classList.contains('open')) setHistoryModal(false);
        });

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
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting…';
                cancelBtn.disabled = true;
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
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> Convert points';
                cancelBtn.disabled = false;
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
<?php if (!empty($user_id)): ?><script src="../js/user-notifications.js"></script><?php endif; ?>
</body>
</html>
