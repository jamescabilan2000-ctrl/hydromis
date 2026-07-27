<?php
require_once '../config/database.php';
require_once '../config/inventory_service.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['customer_order_csrf'])) {
    $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
}
ensure_inventory_schema($conn);

$column_exists = function ($table, $column) use ($conn) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column' LIMIT 1");
    return $result && $result->num_rows > 0;
};

$conn->query("CREATE TABLE IF NOT EXISTS feedback_ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    rating INT NOT NULL,
    feedback_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feedback_transaction_user (transaction_id, user_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS rider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    rider_id VARCHAR(50) NOT NULL,
    rider_latitude DECIMAL(10, 8) NOT NULL,
    rider_longitude DECIMAL(11, 8) NOT NULL,
    accuracy FLOAT,
    speed FLOAT,
    heading FLOAT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_id (rider_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
)");

$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id BIGSERIAL PRIMARY KEY,
    rider_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    age INT,
    address TEXT,
    contact_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$transactions_has_assigned_rider = $column_exists('transactions', 'assigned_rider');
$transaction_rider_expr = $transactions_has_assigned_rider ? "COALESCE(t.rider_id, t.assigned_rider)" : "t.rider_id";

$tracking_info = null;
$error = '';
$success = '';
$success_title = 'Success';
$search_value = sanitize($_GET['search_value'] ?? ($_GET['user_id'] ?? ($_GET['contact_number'] ?? '')));
$search_contact_lookup = '';

function tracking_contact_lookup(string $input): string {
    $plain = htmlspecialchars_decode(trim($input));
    $digits = preg_replace('/\D+/', '', $plain);
    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    return sensitive_lookup($digits);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');
    $cancel_user_id = sanitize($_POST['user_id'] ?? '');
    $search_value = sanitize($_POST['search_value'] ?? '');
    $csrf_token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['customer_order_csrf'], $csrf_token)) {
        $error = 'The cancellation request expired. Refresh the page and try again.';
    } else {
        $contact_lookup = tracking_contact_lookup($search_value);
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT t.* FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            WHERE t.transaction_id = ? AND t.user_id = ?
              AND (u.contact_lookup = ? OR u.user_id = ?)
              AND t.transaction_id NOT LIKE 'RWD-%'
            LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ssss', $transaction_id, $cancel_user_id, $contact_lookup, $search_value);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $order_status = strtolower((string)($order['status'] ?? ''));
        $delivery_status = strtolower((string)($order['delivery_status'] ?? 'pending'));
        $can_cancel = $order && in_array($order_status, ['pending', 'approved'], true)
            && in_array($delivery_status, ['', 'pending', 'assigned'], true);

        if (!$can_cancel) {
            $conn->rollback();
            $error = 'This order can no longer be cancelled because delivery has already started or finished.';
        } elseif (!release_order_inventory($conn, $order, $cancel_user_id, "Customer cancelled order $transaction_id")) {
            $conn->rollback();
            $error = 'The order could not be cancelled. Please try again.';
        } else {
            $reason = 'Cancelled by customer before delivery started.';
            $update = $conn->prepare("UPDATE transactions SET status = 'denied', rider_id = NULL, cancellation_reason = ?,
                payment_status = CASE
                    WHEN LOWER(COALESCE(payment_method, 'cash')) IN ('gcash', 'maya') AND payment_status = 'paid' THEN 'refund_pending'
                    ELSE payment_status
                END
                WHERE transaction_id = ?");
            $update->bind_param('ss', $reason, $transaction_id);
            $ok = $update->execute();
            if ($ok && $transactions_has_assigned_rider) {
                $clear = $conn->prepare('UPDATE transactions SET assigned_rider = NULL WHERE transaction_id = ?');
                $clear->bind_param('s', $transaction_id);
                $ok = $clear->execute();
            }
            if ($ok) {
                add_user_notification($conn, $cancel_user_id, $transaction_id, 'Order cancelled', $reason, 'warning');
                $conn->commit();
                $success = 'Your order has been cancelled.';
                $success_title = 'Order Cancelled';
            } else {
                $conn->rollback();
                $error = 'The order could not be cancelled. Please try again.';
            }
        }
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
    header('Content-Type: application/json');
    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : $_GET;
    $transaction_id = trim((string)($input['transaction_id'] ?? ''));
    $user_id = trim((string)($input['user_id'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    $rider_expr = $transactions_has_assigned_rider ? 'COALESCE(rider_id, assigned_rider)' : 'rider_id';
    $stmt = $conn->prepare("SELECT {$rider_expr} AS rider_id FROM transactions WHERE transaction_id = ? AND user_id = ? AND status = 'approved' LIMIT 1");
    $stmt->bind_param('ss', $transaction_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Order conversation is unavailable.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($message === '' || strlen($message) > 500) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Enter a message up to 500 characters.']);
            exit;
        }
        if (empty($order['rider_id'])) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'A rider must be assigned before messaging is available.']);
            exit;
        }
        $insert = $conn->prepare('INSERT INTO rider_messages (transaction_id, sender, recipient, message) VALUES (?, ?, ?, ?)');
        $insert->bind_param('ssss', $transaction_id, $user_id, $order['rider_id'], $message);
        $insert->execute();
    }

    $messages = [];
    $list = $conn->prepare('SELECT id, transaction_id, sender, recipient, message, created_at FROM (
        SELECT id, transaction_id, sender, recipient, message, created_at
        FROM rider_messages
        WHERE (sender = ? AND recipient = ?) OR (sender = ? AND recipient = ?)
        ORDER BY id DESC LIMIT 100
    ) recent_messages ORDER BY id ASC');
    $list->bind_param('ssss', $user_id, $order['rider_id'], $order['rider_id'], $user_id);
    $list->execute();
    $result = $list->get_result();
    while ($row = $result->fetch_assoc()) $messages[] = $row;
    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');
    $user_id = sanitize($_POST['user_id'] ?? '');
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback_message = sanitize(trim($_POST['feedback_message'] ?? ''));
    $search_value = sanitize($_POST['search_value'] ?? '');

    if ($transaction_id === '' || $user_id === '') {
        $error = 'Missing order details for feedback submission.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating from 1 to 5 stars.';
    } else {
        $valid_feedback = $conn->query("
            SELECT transaction_id
            FROM transactions
            WHERE transaction_id = '$transaction_id'
              AND user_id = '$user_id'
              AND status = 'approved'
              AND delivery_status = 'delivered'
            LIMIT 1
        ");

        if (!$valid_feedback || $valid_feedback->num_rows === 0) {
            $error = 'Feedback can only be submitted for delivered orders.';
        } else {
            $existing_feedback = $conn->query("
                SELECT id
                FROM feedback_ratings
                WHERE transaction_id = '$transaction_id' AND user_id = '$user_id'
                LIMIT 1
            ");

            if ($existing_feedback && $existing_feedback->num_rows > 0) {
                $error = 'Feedback has already been submitted for this order.';
            } else {
                $conn->query("
                    INSERT INTO feedback_ratings (transaction_id, user_id, rating, feedback_message)
                    VALUES ('$transaction_id', '$user_id', $rating, '$feedback_message')
                ");
                $success = 'Thank you for your feedback and rating!';
                $success_title = 'Feedback Saved';
            }
        }
    }
}

if (
    ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['search_submit']) || isset($_POST['cancel_order'])))
    || ($_SERVER['REQUEST_METHOD'] == 'GET' && $search_value !== '')
) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_submit'])) {
        $search_value = sanitize($_POST['search_value']);
    }

    $search_contact_lookup = tracking_contact_lookup($search_value);

    if (!empty($search_value)) {
        $sql = "SELECT t.*, {$transaction_rider_expr} AS effective_rider_id, u.full_name, u.address, u.contact_number, u.loyalty_points, u.user_id,
                   ru.full_name AS rider_name, ru.contact_number AS rider_contact_number
                FROM transactions t
                JOIN users u ON t.user_id = u.user_id
                LEFT JOIN rider_users ru ON {$transaction_rider_expr} = ru.rider_id
                WHERE (u.contact_lookup = '$search_contact_lookup' OR u.user_id LIKE '%$search_value%')
                  AND t.transaction_id NOT LIKE 'RWD-%'
                  AND COALESCE(t.description, '') NOT LIKE 'Reward Redemption - %'
                ORDER BY CASE
                    WHEN t.status = 'approved' AND LOWER(COALESCE(t.delivery_status, 'pending')) IN ('on_way', 'on_the_way') THEN 0
                    WHEN t.status IN ('pending', 'approved') AND LOWER(COALESCE(t.delivery_status, 'pending')) <> 'delivered' THEN 1
                    ELSE 2
                END, t.created_at DESC";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $tracking_info = [];
            while ($row = $result->fetch_assoc()) {
                $tracking_info[] = $row;
            }
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $error = 'No transactions found for this mobile number or User ID.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $error = 'Please enter a mobile number or User ID.';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback']) && $search_value !== '') {
    $search_contact_lookup = tracking_contact_lookup($search_value);
    $sql = "SELECT t.*, {$transaction_rider_expr} AS effective_rider_id, u.full_name, u.address, u.contact_number, u.loyalty_points, u.user_id,
               ru.full_name AS rider_name, ru.contact_number AS rider_contact_number
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_users ru ON {$transaction_rider_expr} = ru.rider_id
            WHERE (u.contact_lookup = '$search_contact_lookup' OR u.user_id LIKE '%$search_value%')
              AND t.transaction_id NOT LIKE 'RWD-%'
              AND COALESCE(t.description, '') NOT LIKE 'Reward Redemption - %'
             ORDER BY CASE
                 WHEN t.status = 'approved' AND LOWER(COALESCE(t.delivery_status, 'pending')) IN ('on_way', 'on_the_way') THEN 0
                 WHEN t.status IN ('pending', 'approved') AND LOWER(COALESCE(t.delivery_status, 'pending')) <> 'delivered' THEN 1
                 ELSE 2
             END, t.created_at DESC";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $tracking_info = [];
        while ($row = $result->fetch_assoc()) {
            $tracking_info[] = $row;
        }
    }
}

$feedback_by_transaction = [];
if ($tracking_info) {
    $transaction_ids = array_map(function ($item) use ($conn) {
        return "'" . $conn->real_escape_string($item['transaction_id']) . "'";
    }, $tracking_info);

    if (!empty($transaction_ids)) {
        $feedback_result = $conn->query("
            SELECT *
            FROM feedback_ratings
            WHERE transaction_id IN (" . implode(',', $transaction_ids) . ")
        ");

        if ($feedback_result) {
            while ($feedback_row = $feedback_result->fetch_assoc()) {
                $feedback_by_transaction[$feedback_row['transaction_id']] = $feedback_row;
            }
        }
    }
}

$view = $tracking_info ? 'results' : 'search';

function stepState($ds, $step) {
    $ds = strtolower($ds ?? 'pending');
    if ($step === 'confirmed') return 'done';
    if ($step === 'preparing') return in_array($ds, ['pending', 'assigned']) ? 'active' : 'done';
    if ($step === 'on_the_way') {
        if (in_array($ds, ['pending', 'assigned'])) return 'pending';
        if (in_array($ds, ['on_the_way','on_way'])) return 'active';
        return 'done';
    }
    if ($step === 'delivered') return $ds === 'delivered' ? 'done' : 'pending';
}

function trackingDeliveryStatus($txn) {
    $status = strtolower($txn['delivery_status'] ?? 'pending');
    $has_rider = !empty($txn['effective_rider_id']) || !empty($txn['rider_id']) || !empty($txn['assigned_rider']) || !empty($txn['rider_name']) || !empty($txn['rider_contact_number']);
    if ($status === 'pending' && $has_rider) {
        return 'assigned';
    }
    return $status;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="hydromis-user-id" content="<?php echo htmlspecialchars($user_id ?? ''); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Order — HydroMIS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
    :root {
        --navy:       #0b1829;
        --navy-mid:   #112240;
        --navy-lt:    #1a3a5c;
        --blue:       #1d6fd8;
        --blue-br:    #2d85f0;
        --blue-glow:  rgba(45,133,240,0.2);
        --orange:     #f97316;
        --green:      #10b981;
        --amber:      #f59e0b;
        --red:        #ef4444;
        --surf:       #ffffff;
        --surf2:      #f4f8fd;
        --border:     #e2ecf6;
        --t1:         #0b1829;
        --t2:         #3d5a7a;
        --t3:         #7b97b8;
        --r-sm: 10px; --r-md: 16px; --r-lg: 22px;
        --sh-sm: 0 2px 8px rgba(11,24,41,.08);
        --sh-md: 0 8px 24px rgba(11,24,41,.11);
        --sh-lg: 0 20px 50px rgba(11,24,41,.14);
        --sh-bl: 0 12px 32px rgba(29,111,216,.24);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html{scroll-behavior:smooth}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--surf2);color:var(--t1);min-height:100vh;-webkit-font-smoothing:antialiased}
    a{text-decoration:none}
    button{font-family:inherit;cursor:pointer}

    /* BG */
    .bg-anim{position:fixed;inset:0;z-index:0;pointer-events:none;
        background:linear-gradient(135deg,#e8f2ff 0%,#f0f7fe 45%,#e4f0fd 100%);overflow:hidden}
    .bg-anim::before{content:'';position:absolute;width:700px;height:700px;border-radius:50%;
        background:radial-gradient(circle,rgba(45,133,240,.13) 0%,transparent 70%);
        top:-200px;right:-150px;animation:blob 8s ease-in-out infinite alternate}
    .bg-anim::after{content:'';position:absolute;width:500px;height:500px;border-radius:50%;
        background:radial-gradient(circle,rgba(249,115,22,.09) 0%,transparent 70%);
        bottom:-100px;left:-100px;animation:blob 10s ease-in-out infinite alternate-reverse}
    @keyframes blob{from{transform:translate(0,0) scale(1)}to{transform:translate(30px,20px) scale(1.05)}}

    /* NAVBAR */
    .navbar{position:sticky;top:0;z-index:200;background:rgba(255,255,255,.82);
        backdrop-filter:blur(22px) saturate(150%);-webkit-backdrop-filter:blur(22px) saturate(150%);
        border-bottom:1px solid rgba(210,225,241,.78);padding:0 24px;height:72px;
        display:flex;align-items:center;justify-content:space-between;
        box-shadow:0 8px 30px rgba(25,63,103,.07)}
    .nav-brand{display:flex;align-items:center;gap:11px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--navy);line-height:1}
    .nav-brand-ico{position:relative;width:38px;height:38px;border-radius:12px;
        background:linear-gradient(145deg,#3b92ff 0%,#1769d7 72%,#0d54b8 100%);
        display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;
        box-shadow:0 9px 22px rgba(29,111,216,.28),inset 0 1px 0 rgba(255,255,255,.32)}
    .nav-brand-ico::after{content:'';position:absolute;inset:1px;border-radius:11px;border:1px solid rgba(255,255,255,.16);pointer-events:none}
    .nav-brand-copy{display:flex;flex-direction:column;gap:5px}
    .nav-brand-copy strong{font-size:18px;font-weight:850;letter-spacing:-.5px;line-height:1}
    .nav-brand-copy small{font-size:9px;font-weight:750;letter-spacing:1.35px;text-transform:uppercase;color:#7891ad;line-height:1}
    .nav-links{display:flex;align-items:center;gap:4px}
    .nav-link{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--r-sm);
        color:var(--t2);font-weight:600;font-size:14px;transition:all .2s}
    .nav-link:hover{color:var(--blue);background:var(--blue-glow)}
    .nav-ham{position:relative;width:44px;height:44px;border-radius:14px;border:1px solid rgba(205,221,238,.9);
        background:linear-gradient(145deg,rgba(255,255,255,.98),rgba(244,248,253,.92));display:none;align-items:center;justify-content:center;
        color:#355573;font-size:17px;transition:transform .2s,border-color .2s,color .2s,box-shadow .2s;box-shadow:0 7px 18px rgba(31,74,117,.08)}
    .nav-ham:hover{border-color:#9dc7f6;color:var(--blue);transform:translateY(-1px);box-shadow:0 10px 24px rgba(29,111,216,.15)}
    .nav-ham:active{transform:scale(.96)}
    .nav-ham.has-update::after{content:'';position:absolute;right:6px;top:6px;width:8px;height:8px;border-radius:50%;background:var(--orange);border:2px solid #fff;box-shadow:0 0 0 2px rgba(249,115,22,.12)}
    body.mob-open .nav-ham{color:var(--blue);border-color:#a9cef7;background:#eef6ff}

    /* MOBILE MENU */
    .mob-ov{position:fixed;inset:0;z-index:500;background:rgba(11,24,41,.5);
        backdrop-filter:blur(4px);opacity:0;visibility:hidden;
        transition:opacity .25s,visibility .25s}
    .mob-pn{position:absolute;top:0;right:0;width:min(82vw,320px);height:100%;
        background:#fff;transform:translateX(100%);
        transition:transform .28s cubic-bezier(.4,0,.2,1);
        box-shadow:-16px 0 48px rgba(11,24,41,.18);overflow-y:auto}
    body.mob-open .mob-ov{opacity:1;visibility:visible}
    body.mob-open .mob-pn{transform:translateX(0)}
    body.mob-open{overflow:hidden}
    .mob-hd{display:flex;align-items:center;justify-content:space-between;
        padding:16px 20px;border-bottom:1px solid var(--border)}
    .mob-cls{width:34px;height:34px;border-radius:8px;border:none;
        background:var(--surf2);color:var(--t2);
        display:flex;align-items:center;justify-content:center;
        font-size:16px;transition:all .2s}
    .mob-cls:hover{background:rgba(239,68,68,.1);color:var(--red)}
    .mob-nav{padding:12px 14px}
    .mob-itm{display:flex;align-items:center;gap:12px;padding:13px 12px;
        border-radius:var(--r-sm);color:var(--t1);font-weight:700;font-size:16px;transition:background .15s}
    .mob-itm:hover{background:var(--surf2)}
    .mob-itm i{width:20px;color:var(--orange);text-align:center}
    .mob-btn{width:100%;border:none;background:transparent;text-align:left;font-family:inherit}
    .mob-itm.is-disabled{opacity:.55;cursor:not-allowed}
    .mob-itm.is-disabled:hover{background:transparent}
    .mob-itm.is-active{background:var(--blue-glow);color:var(--blue)}
    .mob-orders-panel{display:none;padding:10px 4px 4px}
    .mob-orders-panel.on{display:block}
    .mob-orders-head{display:flex;align-items:center;justify-content:space-between;
        padding:0 8px 10px;color:var(--t2);font-size:12px;font-weight:700}
    .mob-orders-list .txn{margin-bottom:12px}
    .mob-orders-list .txn:last-child{margin-bottom:0}
    .mob-orders-list .txn-hd{padding:12px 14px 0}
    .mob-orders-list .txn-body{padding:10px 14px 0}
    .mob-orders-list .txn-id{font-size:10px !important}
    .mob-orders-list .txn-dt{font-size:9px !important}
    .mob-orders-list .btn-map{padding:9px 12px;font-size:11px}
    .mob-div{height:1px;background:var(--border);margin:8px 0}
    .mob-sub{display:block;padding:10px 12px;color:var(--t2);font-weight:600;
        font-size:14px;border-radius:var(--r-sm);transition:background .15s}
    .mob-sub:hover{background:var(--surf2);color:var(--blue)}

    /* SECTIONS */
    .pg{display:none}
    .pg.on{display:block}

    /* ── SEARCH PAGE ── */
    .s-wrap{position:relative;z-index:1;min-height:calc(100vh - 72px);
        display:flex;flex-direction:column;align-items:center;justify-content:center;
        padding:40px 16px 60px}
    .s-hero{text-align:center;margin-bottom:34px;max-width:560px;
        animation:heroIn .6s cubic-bezier(.22,1,.36,1) both}
    @keyframes heroIn{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
    .s-ico{width:84px;height:84px;margin:0 auto 22px;border-radius:24px;
        background:linear-gradient(135deg,var(--blue),var(--blue-br));
        display:flex;align-items:center;justify-content:center;font-size:34px;color:#fff;
        box-shadow:var(--sh-bl),0 0 0 8px rgba(45,133,240,.12);
        animation:icoPulse 3s ease-in-out infinite}
    @keyframes icoPulse{
        0%,100%{box-shadow:var(--sh-bl),0 0 0 8px rgba(45,133,240,.12)}
        50%{box-shadow:var(--sh-bl),0 0 0 16px rgba(45,133,240,.05)}}
    .s-kicker{display:inline-flex;align-items:center;gap:7px;margin:0 auto 12px;padding:6px 10px;border:1px solid rgba(29,111,216,.14);border-radius:999px;background:rgba(255,255,255,.58);color:#356d9f;font-size:9px;font-weight:800;letter-spacing:1.35px;text-transform:uppercase;box-shadow:0 5px 16px rgba(29,111,216,.06)}
    .s-kicker i{font-size:7px;color:var(--orange)}
    .s-hero h1{font-family:'Plus Jakarta Sans',sans-serif;font-size:clamp(25px,4vw,34px) !important;font-weight:850;
        color:var(--navy);letter-spacing:-1.15px;margin-bottom:10px;line-height:1.12;text-wrap:balance}
    .s-hero h1 span{background:linear-gradient(120deg,#1d6fd8,#3295f5);-webkit-background-clip:text;background-clip:text;color:transparent}
    .s-hero p{max-width:390px;margin:0 auto;color:#617d9c;font-size:13px;font-weight:550;line-height:1.65;text-wrap:balance}
    .s-card{width:100%;max-width:460px;background:var(--surf);border-radius:var(--r-lg);
        padding:36px 32px;box-shadow:var(--sh-lg);border:1px solid var(--border);
        animation:cardIn .65s .1s cubic-bezier(.22,1,.36,1) both}
    @keyframes cardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
    .f-label{font-size:13px;font-weight:700;color:var(--t1);margin-bottom:8px;
        display:flex;align-items:center;gap:6px}
    .f-label i{color:var(--blue);font-size:12px}
    .f-wrap{position:relative}
    .f-inp{width:100%;padding:14px 16px 14px 46px;border:1.5px solid var(--border);
        border-radius:var(--r-md);font-family:inherit;font-size:15px;font-weight:500;
        color:var(--t1);background:var(--surf2);
        transition:border-color .2s,box-shadow .2s,background .2s;outline:none}
    .f-inp:focus{border-color:var(--blue);box-shadow:0 0 0 4px var(--blue-glow);background:#fff}
    .f-ico{position:absolute;left:16px;top:50%;transform:translateY(-50%);
        color:var(--t3);font-size:14px;pointer-events:none;transition:color .2s}
    .f-wrap:focus-within .f-ico{color:var(--blue)}
    .btn-primary{width:100%;padding:15px;
        background:linear-gradient(135deg,#1557c0,var(--blue-br));
        color:#fff;border:none;border-radius:var(--r-md);
        font-family:'Syne',sans-serif;font-size:15px;font-weight:800;letter-spacing:.2px;
        display:flex;align-items:center;justify-content:center;gap:8px;
        margin-top:16px;box-shadow:var(--sh-bl);
        transition:transform .18s,box-shadow .18s,filter .18s;position:relative;overflow:hidden}
    .btn-primary::before{content:'';position:absolute;inset:0;
        background:linear-gradient(135deg,rgba(255,255,255,.15),transparent);
        opacity:0;transition:opacity .2s}
    .btn-primary:hover{transform:translateY(-2px);
        box-shadow:0 16px 36px rgba(29,111,216,.32);filter:brightness(1.04)}
    .btn-primary:hover::before{opacity:1}
    .btn-primary:active{transform:translateY(0)}
    .alert-err{display:flex;align-items:flex-start;gap:10px;
        background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);
        border-left:3px solid var(--red);padding:12px 14px;border-radius:var(--r-sm);
        margin-bottom:20px;color:#7f1d1d;font-size:13px;font-weight:600;
        animation:shake .4s ease}
    @keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}
        40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}

    /* ── RESULTS PAGE ── */
    .r-wrap{max-width:820px;margin:0 auto;padding:20px 16px 56px}
    .r-top{display:flex;align-items:center;justify-content:space-between;
        margin-bottom:22px;flex-wrap:wrap;gap:12px;
        animation:fadeDown .4s ease both}
    .r-top>div:first-child{min-width:0}
    @keyframes fadeDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
    .r-title{font-family:'Syne',sans-serif;font-size:18px !important;font-weight:800;
        color:var(--navy);letter-spacing:-.35px;line-height:1.25;
        display:inline-flex;align-items:center;gap:9px;padding-left:2px}
    .r-title i{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;
        width:22px;height:22px;color:var(--blue);font-size:14px;line-height:1}
    .r-sub{font-size:11px;color:var(--t3);font-weight:500;margin-top:4px;line-height:1.4;overflow-wrap:anywhere}
    .r-sub span{color:var(--blue);font-weight:700}
    .btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;
        border-radius:var(--r-sm);border:1.5px solid var(--border);background:var(--surf);
        color:var(--t2);font-weight:700;font-size:13px;box-shadow:var(--sh-sm);
        transition:all .2s}
    .btn-back:hover{border-color:var(--blue);color:var(--blue);
        background:var(--blue-glow);box-shadow:none}

    /* MAP SHELL */
    .map-shell{border-radius:var(--r-lg);overflow:hidden;background:var(--surf);
        box-shadow:var(--sh-lg);border:1px solid var(--border);margin-bottom:24px;
        animation:riseUp .5s .05s cubic-bezier(.22,1,.36,1) both}
    @keyframes riseUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
    .map-hd{display:flex;align-items:center;justify-content:space-between;
        padding:14px 18px;background:var(--surf);border-bottom:1px solid var(--border)}
    .map-hd-title{font-weight:800;font-size:15px;color:var(--navy);
        display:flex;align-items:center;gap:7px}
    .map-hd-title i{color:var(--orange)}
    .map-close{border:0;background:#edf3fa;color:var(--t2);width:32px;height:32px;border-radius:9px;cursor:pointer;font-size:15px;margin-left:10px}.map-close:hover{background:#dfeaf6;color:var(--navy)}
    .status-pill{padding:5px 14px;border-radius:999px;font-size:12px;font-weight:800;
        letter-spacing:.3px;display:flex;align-items:center;gap:5px}
    .pill-preparing   {background:rgba(245,158,11,.12);color:#92400e}
    .pill-on_the_way,.pill-on_way{background:rgba(45,133,240,.13);color:#1e40af}
    .pill-delivered   {background:rgba(16,185,129,.12);color:#065f46}
    .pill-dot{width:7px;height:7px;border-radius:50%;animation:dotPulse 1.4s ease infinite}
    @keyframes dotPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.65)}}
    .pill-preparing .pill-dot  {background:var(--amber)}
    .pill-on_the_way .pill-dot,.pill-on_way .pill-dot{background:var(--blue-br)}
    .pill-delivered .pill-dot  {background:var(--green);animation:none}
    #tracking-map{width:100%;height:300px;background:linear-gradient(135deg,#d4e6f7,#e8f2ff)}
    .tracking-map-wrap{position:relative}.tracking-legend{position:absolute;left:10px;bottom:10px;z-index:500;display:flex;gap:7px;padding:7px 9px;border:1px solid rgba(255,255,255,.9);border-radius:10px;background:rgba(255,255,255,.92);box-shadow:0 5px 16px rgba(11,24,41,.14);backdrop-filter:blur(8px);font-size:10px;font-weight:800;color:var(--t2)}.tracking-legend span{display:flex;align-items:center;gap:4px}.tracking-legend i{font-size:8px}.tracking-legend .station-key i{color:#f97316}.tracking-legend .rider-key i{color:#10b981}
    .rider-live-icon{background:transparent!important;border:0!important;overflow:visible!important}
    .rider-live-wrap{position:relative;width:42px;height:42px;display:grid;place-items:center;filter:drop-shadow(0 7px 8px rgba(5,96,82,.24))}
    .rider-live-pulse{position:absolute;inset:2px;border-radius:50%;background:rgba(16,185,129,.3);animation:riderPulse 1.8s ease-out infinite}
    .rider-live-core{position:relative;z-index:2;width:38px;height:38px;border:4px solid #fff;border-radius:50%;display:grid;place-items:center;background:linear-gradient(145deg,#20c997,#078a78);color:#fff;font-size:16px;box-shadow:0 5px 15px rgba(16,185,129,.42);animation:riderBob 1.15s ease-in-out infinite}
    .rider-live-core::after{content:'';position:absolute;right:-2px;top:-2px;width:9px;height:9px;border:2px solid #fff;border-radius:50%;background:#34d399}
    @keyframes riderPulse{0%{transform:scale(.72);opacity:.9}75%,100%{transform:scale(1.45);opacity:0}}
    @keyframes riderBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
    @media(prefers-reduced-motion:reduce){.rider-live-pulse,.rider-live-core{animation:none}}
    .customer-chat{margin-top:16px;padding:18px;border:1px solid var(--border);border-radius:18px;background:#fff;box-shadow:var(--sh-sm)}.customer-chat-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.customer-chat-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800}.customer-chat-title i{color:var(--blue)}.customer-chat-status{font-size:9px;font-weight:800;color:var(--green);text-transform:uppercase;letter-spacing:.06em}.customer-message-list{display:flex;flex-direction:column;gap:9px;min-height:68px;max-height:240px;padding:2px 2px 12px;overflow-y:auto}.customer-message{display:flex;flex-direction:column;align-items:flex-start}.customer-message.mine{align-items:flex-end}.customer-message-bubble{max-width:82%;padding:10px 12px;border-radius:14px 14px 14px 4px;background:#f0f5f8;color:var(--t1);font-size:12px;line-height:1.45}.customer-message.mine .customer-message-bubble{border-radius:14px 14px 4px 14px;background:linear-gradient(135deg,#1769d2,#168ec8);color:#fff}.customer-message-meta{margin-top:3px;color:var(--t3);font-size:9px}.customer-chat-empty{margin:auto;color:var(--t3);font-size:11px}.customer-chat-form{display:flex;gap:9px}.customer-chat-input{min-width:0;flex:1;height:44px;padding:0 13px;border:1px solid var(--border);border-radius:12px;background:#f9fbfd;color:var(--t1);font:inherit;font-size:12px;outline:none;transition:border-color .2s,box-shadow .2s}.customer-chat-input:focus{border-color:var(--blue-br);box-shadow:0 0 0 3px var(--blue-glow)}.customer-chat-send{width:46px;height:44px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--blue-br));color:#fff;box-shadow:var(--sh-bl);transition:transform .2s}.customer-chat-send:hover{transform:translateY(-2px)}.customer-chat-send:disabled{opacity:.55;cursor:not-allowed;transform:none}

    .rider-bar{display:flex;align-items:center;justify-content:space-between;
        padding:14px 18px;
        background:linear-gradient(135deg,var(--navy),var(--navy-lt));color:#fff}
    .rider-bar-l{display:flex;align-items:center;gap:12px}
    .rider-av{width:42px;height:42px;border-radius:12px;
        background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.18);
        display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
    .rider-nm{font-weight:800;font-size:15px;line-height:1.1}
    .rider-rl{font-size:12px;opacity:.7;margin-top:2px}
    .rider-call{width:40px;height:40px;border-radius:50%;
        background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);
        display:flex;align-items:center;justify-content:center;
        color:#fff;font-size:15px;transition:background .2s}
    .rider-call:hover{background:rgba(255,255,255,.25);color:#fff}

    .steps-bar{display:flex;align-items:center;justify-content:space-between;
        padding:16px 20px;background:var(--surf2);border-top:1px solid var(--border);gap:4px}
    .step-it{display:flex;flex-direction:column;align-items:center;gap:5px;
        flex:1;position:relative}
    .step-it::after{content:'';position:absolute;top:18px;left:calc(50% + 20px);
        right:calc(-50% + 20px);height:2px;background:var(--border)}
    .step-it:last-child::after{display:none}
    .step-ico{width:36px;height:36px;border-radius:10px;background:var(--surf);
        border:2px solid var(--border);display:flex;align-items:center;justify-content:center;
        font-size:13px;color:var(--t3);transition:all .3s;position:relative;z-index:1}
    .step-ico.done{background:var(--green);border-color:var(--green);color:#fff;
        box-shadow:0 4px 12px rgba(16,185,129,.3)}
    .step-ico.active{background:var(--blue);border-color:var(--blue);color:#fff;
        box-shadow:0 4px 12px rgba(45,133,240,.35);animation:stepPulse 1.6s ease infinite}
    @keyframes stepPulse{
        0%,100%{box-shadow:0 4px 12px rgba(45,133,240,.35)}
        50%{box-shadow:0 4px 18px rgba(45,133,240,.6),0 0 0 7px rgba(45,133,240,.09)}}
    .step-lb{font-size:10px;font-weight:700;color:var(--t3);text-align:center;line-height:1.2}
    .step-lb.done  {color:var(--green)}
    .step-lb.active{color:var(--blue)}
    .map-note{padding:10px 18px;font-size:11.5px;color:var(--t3);font-weight:500;
        border-top:1px solid var(--border);display:flex;align-items:center;gap:6px}

    /* TXN CARDS */
    .txn{background:var(--surf);border-radius:var(--r-lg);border:1.5px solid var(--border);
        margin-bottom:16px;overflow:hidden;box-shadow:var(--sh-sm);
        transition:box-shadow .25s,border-color .25s,transform .2s;
        animation:riseUp .5s cubic-bezier(.22,1,.36,1) both}
    .txn:nth-child(1){animation-delay:.08s}.txn:nth-child(2){animation-delay:.14s}
    .txn:nth-child(3){animation-delay:.20s}.txn:nth-child(4){animation-delay:.26s}
    .txn:nth-child(5){animation-delay:.32s}
    .txn:hover{box-shadow:var(--sh-md);transform:translateY(-2px)}
    .txn.sel{border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-glow),var(--sh-md)}

    .txn-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:14px 16px 0;gap:10px}
    .txn-hd>div:first-child{min-width:0;flex:1}
    .txn-id{display:flex;align-items:center;gap:6px;min-width:0;font-family:'Syne',sans-serif;font-size:12px !important;font-weight:800;color:var(--navy);letter-spacing:-.1px;line-height:1.25;white-space:nowrap}
    .txn-id i{flex:0 0 auto;color:var(--orange);font-size:11px}
    .txn-id span{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .txn-dt{font-size:11px !important;color:var(--t3);font-weight:500;margin-top:3px;line-height:1.2}
    .txn-bdg{padding:5px 12px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
    .bdg-pending {background:rgba(245,158,11,.12);color:#92400e}
    .bdg-approved{background:rgba(16,185,129,.12); color:#065f46}
    .bdg-denied  {background:rgba(239,68,68,.1);   color:#7f1d1d}

    .txn-body{padding:12px 16px 0}
    .txn-row{display:flex;justify-content:space-between;align-items:center;
        padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px}
    .txn-row:last-child{border-bottom:none}
    .txn-lbl{color:var(--t2);font-weight:600}
    .txn-val{color:var(--navy);font-weight:700}
    .txn-val.total   {font-size:15px;color:var(--blue)}
    .txn-val.discount{color:var(--green)}
    .txn-val.pts     {color:var(--amber)}

    .btn-map{width:100%;padding:10px 16px;border:1.5px solid var(--blue);
        background:rgba(45,133,240,.06);color:var(--blue);border-radius:var(--r-sm);
        font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;gap:7px;
        transition:all .2s;margin-top:8px}
    .btn-map:hover{background:var(--blue);color:#fff;box-shadow:var(--sh-bl)}
    .map-locked{display:flex;align-items:center;gap:6px;padding:7px 10px;border-radius:9px;background:#f4f7fb;color:var(--t3);font-size:10px;font-weight:750;white-space:nowrap}
    .cancel-order-form{padding:0 20px 14px}.cancel-order-btn{width:100%;padding:10px 14px;border:1px solid #fecaca;border-radius:11px;background:#fff7f7;color:#b91c1c;font:800 12px inherit;cursor:pointer;transition:background .2s,border-color .2s}.cancel-order-btn:hover{background:#fee2e2;border-color:#fca5a5}

    /* DELIVERY BLOCK */
    .dlv{margin:14px 20px 0;border-radius:var(--r-md);overflow:hidden}
    .dlv-hd{display:flex;align-items:center;gap:10px;padding:14px 16px;font-weight:800;font-size:14px}
    .dlv-ico{width:34px;height:34px;border-radius:9px;
        display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .dlv-bd{padding:0 16px 14px;font-size:13px;font-weight:500}
    .dlv-pending   {background:rgba(245,158,11,.1)}
    .dlv-pending .dlv-hd   {color:#92400e}
    .dlv-pending .dlv-ico  {background:rgba(245,158,11,.18);color:var(--amber)}
    .dlv-pending .dlv-bd   {color:#92400e}
    .dlv-on_the_way,.dlv-on_way{background:rgba(45,133,240,.08)}
    .dlv-on_the_way .dlv-hd,.dlv-on_way .dlv-hd{color:#1e40af}
    .dlv-on_the_way .dlv-ico,.dlv-on_way .dlv-ico{background:rgba(45,133,240,.15);color:var(--blue)}
    .dlv-on_the_way .dlv-bd,.dlv-on_way .dlv-bd{color:#1e40af}
    .dlv-delivered  {background:rgba(16,185,129,.1)}
    .dlv-delivered .dlv-hd  {color:#065f46}
    .dlv-delivered .dlv-ico {background:rgba(16,185,129,.18);color:var(--green)}
    .dlv-delivered .dlv-bd  {color:#065f46}
    .rider-chip{display:inline-flex;align-items:center;gap:6px;margin-top:8px;
        padding:5px 10px;border-radius:999px;background:rgba(255,255,255,.6);
        font-size:12px;font-weight:700}

    /* TIMELINE */
    .tl{display:none}
    .tl-it{display:flex;gap:14px;padding:0 20px 18px;position:relative}
    .tl-it::before{content:'';position:absolute;left:36px;top:36px;width:2px;bottom:0;background:var(--border)}
    .tl-it:last-child::before{display:none}
    .tl-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;
        justify-content:center;font-size:13px;flex-shrink:0;position:relative;z-index:1;transition:all .3s}
    .tl-dot.done   {background:var(--green);color:#fff;box-shadow:0 3px 10px rgba(16,185,129,.3)}
    .tl-dot.active {background:var(--blue); color:#fff;box-shadow:0 3px 10px rgba(45,133,240,.3);animation:stepPulse 1.6s ease infinite}
    .tl-dot.pending{background:var(--border);color:var(--t3)}
    .tl-cnt{padding-top:4px}
    .tl-title{font-size:14px;font-weight:800;color:var(--navy);margin:0}
    .tl-sub  {font-size:12px;color:var(--t3);font-weight:500;margin-top:2px}

    /* NOTICES */
    .notice{margin:14px 20px 18px;padding:14px 16px;border-radius:var(--r-md);border-left:4px solid}
    .notice-title{font-weight:800;font-size:14px;margin-bottom:4px}
    .notice-body {font-size:13px;font-weight:500}
    .n-await{background:rgba(245,158,11,.1);border-color:var(--amber);color:#92400e}
    .n-deny {background:rgba(239,68,68,.08); border-color:var(--red);  color:#7f1d1d}
    .n-success{background:rgba(16,185,129,.08);border-color:var(--green);color:#065f46}
    .feedback-card{margin:14px 20px 18px;padding:16px;border:1px solid var(--border);border-radius:var(--r-md);background:linear-gradient(180deg,#ffffff 0%,#f9fbff 100%)}
    .feedback-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap}
    .feedback-title{font-weight:800;font-size:14px;color:var(--navy);display:flex;align-items:center;gap:8px}
    .feedback-title i{color:var(--amber)}
    .feedback-summary{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:var(--t2)}
    .feedback-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;background:rgba(245,158,11,.10);color:#92400e;font-weight:800}
    .feedback-stars{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 12px}
    .feedback-stars label{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid var(--border);border-radius:999px;background:#fff;font-size:13px;font-weight:700;color:var(--t2);cursor:pointer;transition:all .2s}
    .feedback-stars label:hover{border-color:var(--amber);color:#92400e;background:rgba(245,158,11,.08)}
    .feedback-stars input{margin:0}
    .feedback-text{width:100%;min-height:96px;padding:12px 14px;border:1.5px solid var(--border);border-radius:14px;font-family:inherit;font-size:14px;color:var(--t1);background:#fff;resize:vertical;outline:none}
    .feedback-text:focus{border-color:var(--blue);box-shadow:0 0 0 4px var(--blue-glow)}
    .feedback-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px}
    .feedback-note{font-size:12px;color:var(--t3)}
    .feedback-btn{padding:10px 16px;border:none;border-radius:12px;background:linear-gradient(135deg,#1557c0,var(--blue-br));color:#fff;font-weight:800;font-family:inherit}
    .feedback-btn:hover{filter:brightness(1.04)}

    /* TEXT SIZE (SMALLER) */
    .nav-link{font-size:12px}
    .mob-itm{font-size:14px}
    .mob-sub{font-size:12px}
    .f-label{font-size:12px}
    .f-inp{font-size:13px}
    .btn-primary{font-size:13px}
    .alert-err{font-size:12px}
    .btn-back{font-size:12px}
    .map-hd-title{font-size:13px}
    .status-pill{font-size:11px}
    .rider-nm{font-size:13px}
    .rider-rl{font-size:11px}
    .step-lb{font-size:9px}
    .map-note{font-size:10.5px}
    .txn-bdg{font-size:10.5px}
    .txn-row{font-size:12px}
    .txn-val.total{font-size:13px}
    .btn-map{font-size:12px}
    .dlv-hd{font-size:13px}
    .dlv-bd{font-size:12px}
    .rider-chip{font-size:11px}
    .tl-title{font-size:13px}
    .tl-sub{font-size:11px}
    .notice-title{font-size:13px}
    .notice-body{font-size:12px}
    .page-orders{display:block}

    /* RESPONSIVE */
    @media(max-width:768px){
        .nav-links{display:none}.nav-ham{display:flex}
        .s-wrap{padding:28px 14px 48px}
        .s-hero{margin-bottom:26px}
        .s-ico{width:70px;height:70px;margin-bottom:18px;border-radius:20px;font-size:28px}
        .s-hero h1{font-size:25px !important}.s-card{padding:24px 20px}
        #tracking-map{height:240px}
        .steps-bar{gap:2px}.step-lb{font-size:9px}
        .r-wrap{padding:14px 12px 44px}
        .r-top{margin-bottom:16px}.r-top>div:first-child{width:100%}
        .r-title{font-size:16px !important}
        .r-sub{font-size:10px}
        .btn-back{display:none}
        .page-orders{display:none}
        .page-orders.is-active-delivery{display:block}
        .txn-hd{padding:12px 14px 0}
        .txn-id{font-size:11px !important}
        .txn-dt{font-size:10px !important}
        .txn-body{padding:10px 14px 0}
    }
    @media(max-width:420px){
        .navbar{height:68px;padding:0 16px}.nav-brand{gap:9px}
        .nav-brand-ico{width:36px;height:36px;font-size:14px}
        .nav-brand-copy strong{font-size:17px}.nav-brand-copy small{font-size:8px;letter-spacing:1.05px}
        .nav-ham{width:42px;height:42px;border-radius:13px}
        .s-ico{width:60px;height:60px;font-size:24px}
        .s-hero h1{font-size:23px !important;letter-spacing:-.75px}
        .s-hero p{font-size:12px}
        .r-title{font-size:15px !important}
        .txn-id{font-size:10px !important}
        .txn-dt{font-size:9px !important}
    }
    /* Live GPS Animation */
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    </style>
</head>
<body>

<div class="bg-anim" id="bg-anim" style="<?php echo $view==='results'?'display:none':''; ?>"></div>

<!-- NAVBAR -->
<nav class="navbar">
    <div class="nav-brand">
        <div class="nav-brand-ico"><i class="fas fa-droplet"></i></div>
        <div class="nav-brand-copy"><strong>HydroMIS</strong><small>Water Delivery</small></div>
    </div>
    <div class="nav-links">
    </div>
    <button class="nav-ham <?php echo $tracking_info ? 'has-update' : ''; ?>" id="mob-toggle" aria-label="Open menu" aria-expanded="false" title="Menu"><i class="fas fa-bars" id="mob-icon"></i></button>
</nav>

<!-- MOBILE MENU -->
<div class="mob-ov" id="mob-ov">
    <aside class="mob-pn" id="mob-pn">
        <div class="mob-hd">
            <div class="nav-brand" style="font-size:16px !important;">
                <div class="nav-brand-ico" style="width:28px;height:28px;font-size:12px;border-radius:8px;"><i class="fas fa-droplet"></i></div>
                <div class="nav-brand-copy"><strong style="font-size:16px;">HydroMIS</strong><small>Water Delivery</small></div>
            </div>
            <button class="mob-cls" id="mob-close"><i class="fas fa-xmark"></i></button>
        </div>
        <nav class="mob-nav">
            <button
                type="button"
                class="mob-itm mob-btn <?php echo $tracking_info ? '' : 'is-disabled'; ?>"
                <?php if($tracking_info): ?>
                data-toggle-orders="1"
                <?php else: ?>
                data-open-page="pg-search"
                title="Search for orders first"
                <?php endif; ?>>
                <i class="fas fa-box-open"></i>
                <?php echo $tracking_info ? 'Order History' : 'Find Orders'; ?>
            </button>
            <button type="button" class="mob-itm mob-btn" data-open-page="pg-search">
                <i class="fas fa-magnifying-glass"></i>
                Search Again
            </button>
            <?php if($tracking_info): ?>
            <div class="mob-orders-panel" id="mob-orders-panel">
                <div class="mob-orders-head">
                    <span>Order history</span>
                    <span><?php echo htmlspecialchars($search_value); ?></span>
                </div>
                <div class="mob-orders-list">
                    <?php foreach($tracking_info as $mi => $txn): ?>
                    <?php
                        $mcs  = trackingDeliveryStatus($txn);
                        $mcst = $mcs==='delivered'?'Delivered':(in_array($mcs,['on_the_way','on_way'])?'On the way':($mcs==='assigned'?'Assigned':'Preparing'));
                        $mcrn = $txn['rider_name'] ?: 'Assigned Rider';
                        $mcrc = $txn['rider_contact_number'] ?? '';
                        $mcad = $txn['address'] ?: 'Tubigon, Bohol';
                        $msub = $txn['quantity'] * $txn['price_per_unit'];
                        $mcpc = $mcs==='delivered'?'pill-delivered':(in_array($mcs,['on_the_way','on_way'])?'pill-on_the_way':'pill-preparing');
                        $mobile_feedback = $feedback_by_transaction[$txn['transaction_id']] ?? null;
                    ?>
                    <div class="txn" data-card-id="<?php echo htmlspecialchars($txn['transaction_id']); ?>">
                        <div class="txn-hd">
                            <div>
                                <div class="txn-id" title="<?php echo htmlspecialchars($txn['transaction_id']); ?>"><i class="fas fa-box"></i><span><?php echo htmlspecialchars($txn['transaction_id']); ?></span></div>
                                <div class="txn-dt"><?php echo date('M d, Y · h:i A', strtotime($txn['created_at'])); ?></div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;">
                                <span class="txn-bdg bdg-<?php echo $mcs === 'delivered' ? 'approved' : $txn['status']; ?>"><?php echo $mcs === 'delivered' ? 'Done' : ucfirst($txn['status']); ?></span>
                                <?php if($txn['status'] === 'approved' && in_array($mcs, ['on_the_way', 'on_way'], true)): ?>
                                <button class="btn-map"
                                    data-transaction-id="<?php echo htmlspecialchars($txn['transaction_id']); ?>"
                                    data-address="<?php echo htmlspecialchars($mcad); ?>"
                                    data-status="<?php echo htmlspecialchars($mcs); ?>"
                                    data-rider-name="<?php echo htmlspecialchars($mcrn); ?>"
                                    data-rider-contact="<?php echo htmlspecialchars($mcrc); ?>"
                                    data-status-text="<?php echo htmlspecialchars($mcst); ?>"
                                    data-pill-class="<?php echo $mcpc; ?>">
                                    <i class="fas fa-map-location-dot"></i> View on Map
                                </button>
                                <?php else: ?>
                                <span class="map-locked"><i class="fas fa-<?php echo $mcs === 'delivered' ? 'circle-check' : 'lock'; ?>"></i> <?php echo $mcs === 'delivered' ? 'Tracking completed' : 'Map available when rider starts delivery'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="txn-body">
                            <div class="txn-row">
                                <span class="txn-lbl">Product</span>
                                <span class="txn-val"><?php 
                                    $size_display = $txn['water_type']==='nowater'?'units':'gallons';
                                    if (preg_match('/×\s*(.*?)\s*\((?:New|Pickup & Deliver)\)/', $txn['description'], $m)) {
                                        $size_display = trim($m[1]);
                                    }
                                    echo ($txn['water_type']==='nowater'?'No-Water':'Regular Water') . ' · ' . $txn['quantity'] . ' × ' . htmlspecialchars($size_display); 
                                ?></span>
                            </div>
                            <div class="txn-row">
                                <span class="txn-lbl">Price / Unit</span>
                                <span class="txn-val">₱<?php echo number_format($txn['price_per_unit'],2); ?></span>
                            </div>
                            <div class="txn-row">
                                <span class="txn-lbl">Subtotal</span>
                                <span class="txn-val">₱<?php echo number_format($msub,2); ?></span>
                            </div>
                            <?php if($txn['discount']>0): ?>
                            <div class="txn-row">
                                <span class="txn-lbl"><i class="fas fa-tag" style="color:var(--green);margin-right:4px;font-size:11px;"></i>Discount</span>
                                <span class="txn-val discount">−₱<?php echo number_format($txn['discount'],2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="txn-row">
                                <span class="txn-lbl" style="font-weight:800;color:var(--navy);">Total</span>
                                <span class="txn-val total">₱<?php echo number_format($txn['amount'],2); ?></span>
                            </div>
                            <?php if($txn['status']==='pending'): ?>
                            <div class="notice n-await" style="margin:12px 0 0;">
                                <div class="notice-title"><i class="fas fa-hourglass-half" style="margin-right:6px;"></i>Awaiting Approval</div>
                                <div class="notice-body">Your order is waiting for approval. You'll be notified once it's approved and on the way.</div>
                            </div>
                            <?php elseif($txn['status']==='denied'): ?>
                            <div class="notice n-deny" style="margin:12px 0 0;">
                                <div class="notice-title"><i class="fas fa-circle-xmark" style="margin-right:6px;"></i><?php echo str_contains((string)($txn['cancellation_reason'] ?? ''), 'Cancelled by customer') ? 'Order Cancelled' : 'Order Denied'; ?></div>
                                <div class="notice-body"><?php echo htmlspecialchars($txn['cancellation_reason'] ?: 'Unfortunately this order was denied. Please contact support for more information.'); ?></div>
                            </div>
                            <?php elseif($mcs==='delivered'): ?>
                            <div class="feedback-card" style="margin:12px 0 0;">
                                <div class="feedback-head">
                                    <div class="feedback-title"><i class="fas fa-star"></i> Feedback & Rating</div>
                                    <?php if($mobile_feedback): ?>
                                    <div class="feedback-summary">
                                        <span class="feedback-pill"><?php echo str_repeat('★', (int)$mobile_feedback['rating']); ?> <?php echo (int)$mobile_feedback['rating']; ?>/5</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if(!$mobile_feedback): ?>
                                <form method="POST">
                                    <input type="hidden" name="submit_feedback" value="1">
                                    <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($txn['transaction_id']); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($txn['user_id']); ?>">
                                    <input type="hidden" name="search_value" value="<?php echo htmlspecialchars($search_value); ?>">
                                    <div class="feedback-stars">
                                        <?php for($star = 5; $star >= 1; $star--): ?>
                                        <label>
                                            <input type="radio" name="rating" value="<?php echo $star; ?>" <?php echo $star === 5 ? 'checked' : ''; ?>>
                                            <span><?php echo $star; ?> Star<?php echo $star > 1 ? 's' : ''; ?></span>
                                        </label>
                                        <?php endfor; ?>
                                    </div>
                                    <textarea class="feedback-text" name="feedback_message" placeholder="Share your experience with the delivery, product quality, or service."></textarea>
                                    <div class="feedback-row">
                                        <div class="feedback-note">Customer ratings and messaging help improve service quality.</div>
                                        <button type="submit" class="feedback-btn">Submit Feedback</button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <div class="feedback-note"><i class="fas fa-circle-check"></i> Feedback submitted for this order. Thank you!</div>
                                <?php if(trim((string)($mobile_feedback['feedback_message'] ?? '')) !== ''): ?><div class="feedback-text"><?php echo nl2br(htmlspecialchars($mobile_feedback['feedback_message'])); ?></div><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if(in_array($txn['status'], ['pending', 'approved'], true) && in_array($mcs, ['pending', 'assigned'], true)): ?>
                            <form method="POST" class="cancel-order-form" style="padding:12px 0 0;" onsubmit="return confirm('Cancel this order? This action cannot be undone.');">
                                <input type="hidden" name="cancel_order" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['customer_order_csrf']); ?>">
                                <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($txn['transaction_id']); ?>">
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($txn['user_id']); ?>">
                                <input type="hidden" name="search_value" value="<?php echo htmlspecialchars($search_value); ?>">
                                <button type="submit" class="cancel-order-btn"><i class="fas fa-ban"></i> Cancel Order</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="mob-div"></div>
            <a href="mailto:hydromis.support@gmail.com" class="mob-sub">Contact us</a>
            <a href="../terms.php" class="mob-sub">Terms &amp; conditions</a>
            <a href="../privacy.php" class="mob-sub">Privacy terms</a>
        </nav>
    </aside>
</div>

<!-- ══ PAGE 1: SEARCH ══ -->
<section id="pg-search" class="pg <?php echo $view==='search'?'on':''; ?>">
    <div class="s-wrap">
        <div class="s-hero">
            <div class="s-ico"><i class="fas fa-magnifying-glass-location"></i></div>
            <div class="s-kicker"><i class="fas fa-circle"></i> Live delivery access</div>
            <h1>Track Your <span>Order</span></h1>
            <p>Enter your mobile number or User ID to find and track your water deliveries.</p>
        </div>
        <div class="s-card">
            <?php if($error && $view==='search'): ?>
            <div class="alert-err">
                <i class="fas fa-circle-exclamation" style="color:var(--red);flex-shrink:0;margin-top:1px;"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <div style="margin-bottom:20px;">
                    <label class="f-label" for="search_value"><i class="fas fa-phone"></i> Mobile Number or User ID</label>
                    <div class="f-wrap">
                        <input type="text" class="f-inp" id="search_value" name="search_value"
                            placeholder=""
                            value="<?php echo htmlspecialchars($search_value); ?>"
                            autocomplete="off">
                        <i class="fas fa-phone f-ico"></i>
                    </div>
                </div>
                <button type="submit" name="search_submit" value="1" class="btn-primary">
                    <i class="fas fa-magnifying-glass"></i> Search Orders
                </button>
            </form>
        </div>
    </div>
</section>

<!-- ══ PAGE 2: RESULTS ══ -->
<section id="pg-results" class="pg <?php echo $view==='results'?'on':''; ?>">
<div class="r-wrap">

    <div class="r-top">
        <div>
            <div class="r-title"><i class="fas fa-box" aria-hidden="true"></i><span>Your Orders</span></div>
            <?php if($tracking_info): ?>
            <div class="r-sub"><span>Latest order</span> · Track its current delivery progress below.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if($success): ?>
    <div class="notice n-success" style="margin:0 0 18px;">
        <div class="notice-title"><i class="fas fa-circle-check" style="margin-right:6px;"></i><?php echo htmlspecialchars($success_title); ?></div>
        <div class="notice-body"><?php echo htmlspecialchars($success); ?></div>
    </div>
    <?php elseif($error && $tracking_info): ?>
    <div class="notice n-deny" style="margin:0 0 18px;">
        <div class="notice-title"><i class="fas fa-circle-xmark" style="margin-right:6px;"></i>Feedback Error</div>
        <div class="notice-body"><?php echo htmlspecialchars($error); ?></div>
    </div>
    <?php endif; ?>

    <?php if($tracking_info): ?>
    <?php
        $init   = $tracking_info[0];
        $ids    = trackingDeliveryStatus($init);
        $idt    = $ids==='delivered'?'Delivered':(in_array($ids,['on_the_way','on_way'])?'On the way':($ids==='assigned'?'Assigned':'Preparing'));
        $idr    = $init['rider_name'] ?: 'Assigned Rider';
        $irc    = $init['rider_contact_number'] ?? '';
        $iad    = $init['address'] ?: 'Tubigon, Bohol';
        $pilcls = $ids==='delivered'?'pill-delivered':(in_array($ids,['on_the_way','on_way'])?'pill-on_the_way':'pill-preparing');
    ?>

    <!-- MAP SHELL -->
    <div class="map-shell" id="live-map-panel" hidden>
        <div class="map-hd">
            <div class="map-hd-title"><i class="fas fa-location-dot"></i> Live Tracking</div>
            <div style="display:flex;align-items:center;">
                <div id="map-pill" class="status-pill <?php echo $pilcls; ?>">
                    <span class="pill-dot"></span>
                    <span id="map-pill-txt"><?php echo htmlspecialchars($idt); ?></span>
                </div>
                <button type="button" class="map-close" id="close-live-map" aria-label="Close live map" title="Close map"><i class="fas fa-xmark"></i></button>
            </div>
        </div>

        <div class="tracking-map-wrap"><div id="tracking-map"
            data-address="<?php echo htmlspecialchars($iad); ?>"
            data-status="<?php echo htmlspecialchars($ids); ?>"
            data-rider-name="<?php echo htmlspecialchars($idr); ?>"
            data-rider-contact="<?php echo htmlspecialchars($irc); ?>"
            data-transaction-id="<?php echo htmlspecialchars($init['transaction_id']); ?>"></div>
            <div class="tracking-legend"><span class="station-key"><i class="fas fa-circle"></i> HydroMIS station</span><span class="rider-key"><i class="fas fa-circle"></i> Live rider</span></div>
        </div>

        <div class="rider-bar">
            <div class="rider-bar-l">
                <div class="rider-av"><i class="fas fa-person-biking"></i></div>
                <div>
                    <div id="map-rider-name" class="rider-nm"><?php echo htmlspecialchars($idr); ?></div>
                    <div class="rider-rl">Delivery Rider</div>
                </div>
            </div>
            <?php if($irc): ?>
                <a id="map-rider-call" class="rider-call" href="tel:<?php echo htmlspecialchars($irc); ?>"><i class="fas fa-phone"></i></a>
            <?php else: ?>
                <span id="map-rider-call" class="rider-call" style="opacity:.35;cursor:default;"><i class="fas fa-phone"></i></span>
            <?php endif; ?>
        </div>

        <!-- Steps -->
        <div class="steps-bar" id="map-steps">
        <?php
        $stepDefs = [
            ['key'=>'confirmed',  'icon'=>'fa-check',             'lbl'=>'Confirmed'],
            ['key'=>'preparing',  'icon'=>'fa-box-open',          'lbl'=>'Preparing'],
            ['key'=>'on_the_way', 'icon'=>'fa-truck',             'lbl'=>'On the Way'],
            ['key'=>'delivered',  'icon'=>'fa-house-circle-check','lbl'=>'Delivered'],
        ];
        foreach($stepDefs as $sd):
            $sst = stepState($ids, $sd['key']);
        ?>
        <div class="step-it">
            <div class="step-ico <?php echo $sst; ?>"><i class="fas <?php echo $sd['icon']; ?>"></i></div>
            <div class="step-lb <?php echo $sst; ?>"><?php echo $sd['lbl']; ?></div>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="map-note">
            <i class="fas fa-circle-info" style="color:var(--blue-br);flex-shrink:0;"></i>
            Tracking is view-only. Status updated by staff or rider.
        </div>

        <!-- Live Location Info -->
        <div id="live-location-info" style="padding: 16px 18px; background: #f9fbff; border-top: 1px solid var(--border); font-size: 13px; color: #0b1829;">
            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 8px; font-weight: 700; color: #10b981;">
                <i class="fas fa-satellite-dish" style="animation: spin 3s linear infinite;"></i>
                <span id="live-gps-title">Waiting for rider GPS</span>
            </div>
            <div id="live-location-details" style="font-size: 12px; color: #7b97b8; line-height: 1.5;">
                <div><strong>📍 Rider Location:</strong> <span id="rider-lat-lng">Loading...</span></div>
                <div><strong>🎯 Your Location:</strong> <span id="your-location">Loading...</span></div>
                <div><strong>⏰ Last Update:</strong> <span id="last-update">Just now</span></div>
            </div>
        </div>
    </div>

    <section class="customer-chat" aria-labelledby="customer-chat-title">
        <div class="customer-chat-head">
            <div class="customer-chat-title" id="customer-chat-title"><i class="fas fa-message"></i> Message your rider</div>
            <span class="customer-chat-status">Auto refresh</span>
        </div>
        <div class="customer-message-list" id="customer-message-list"><div class="customer-chat-empty">Loading conversation…</div></div>
        <form class="customer-chat-form" id="customer-chat-form">
            <input class="customer-chat-input" id="customer-chat-input" maxlength="500" autocomplete="off" placeholder="Type a message to your rider" aria-label="Message to rider">
            <button class="customer-chat-send" id="customer-chat-send" type="submit" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>
        </form>
    </section>

    <!-- TXN CARDS -->
    <div class="page-orders <?php echo in_array($init['status'], ['pending', 'approved'], true) && $ids !== 'delivered' ? 'is-active-delivery' : ''; ?>" id="active-order-panel">
    <?php foreach(array_slice($tracking_info, 0, 1) as $ci => $txn): ?>
    <?php
        $cs  = trackingDeliveryStatus($txn);
        $cst = $cs==='delivered'?'Delivered':(in_array($cs,['on_the_way','on_way'])?'On the way':($cs==='assigned'?'Assigned':'Preparing'));
        $crn = $txn['rider_name'] ?: 'Assigned Rider';
        $crc = $txn['rider_contact_number'] ?? '';
        $cad = $txn['address'] ?: 'Tubigon, Bohol';
        $sub = $txn['quantity'] * $txn['price_per_unit'];
        $cpc = $cs==='delivered'?'pill-delivered':(in_array($cs,['on_the_way','on_way'])?'pill-on_the_way':'pill-preparing');
        $txn_feedback = $feedback_by_transaction[$txn['transaction_id']] ?? null;
    ?>
    <div class="txn <?php echo $ci===0?'sel':''; ?>" data-card-id="<?php echo htmlspecialchars($txn['transaction_id']); ?>">

        <div class="txn-hd">
            <div>
                <div class="txn-id" title="<?php echo htmlspecialchars($txn['transaction_id']); ?>"><i class="fas fa-box"></i><span><?php echo htmlspecialchars($txn['transaction_id']); ?></span></div>
                <div class="txn-dt"><?php echo date('M d, Y · h:i A', strtotime($txn['created_at'])); ?></div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;">
                <span class="txn-bdg bdg-<?php echo $cs === 'delivered' ? 'approved' : $txn['status']; ?>"><?php echo $cs === 'delivered' ? 'Done' : ucfirst($txn['status']); ?></span>
                <?php if($txn['status'] === 'approved' && in_array($cs, ['on_the_way', 'on_way'], true)): ?>
                <button class="btn-map"
                    data-transaction-id="<?php echo htmlspecialchars($txn['transaction_id']); ?>"
                    data-address="<?php echo htmlspecialchars($cad); ?>"
                    data-status="<?php echo htmlspecialchars($cs); ?>"
                    data-rider-name="<?php echo htmlspecialchars($crn); ?>"
                    data-rider-contact="<?php echo htmlspecialchars($crc); ?>"
                    data-status-text="<?php echo htmlspecialchars($cst); ?>"
                    data-pill-class="<?php echo $cpc; ?>">
                    <i class="fas fa-map-location-dot"></i> View on Map
                </button>
                <?php else: ?>
                <span class="map-locked"><i class="fas fa-<?php echo $cs === 'delivered' ? 'circle-check' : 'lock'; ?>"></i> <?php echo $cs === 'delivered' ? 'Tracking completed' : 'Map available when rider starts delivery'; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="txn-body">
            <div class="txn-row">
                <span class="txn-lbl">Product</span>
                <span class="txn-val"><?php 
                    $size_display = $txn['water_type']==='nowater'?'units':'gallons';
                    if (preg_match('/×\s*(.*?)\s*\((?:New|Pickup & Deliver)\)/', $txn['description'], $m)) {
                        $size_display = trim($m[1]);
                    }
                    echo ($txn['water_type']==='nowater'?'No-Water':'Regular Water') . ' · ' . $txn['quantity'] . ' × ' . htmlspecialchars($size_display); 
                ?></span>
            </div>
            <div class="txn-row">
                <span class="txn-lbl">Price / Unit</span>
                <span class="txn-val">₱<?php echo number_format($txn['price_per_unit'],2); ?></span>
            </div>
            <div class="txn-row">
                <span class="txn-lbl">Subtotal</span>
                <span class="txn-val">₱<?php echo number_format($sub,2); ?></span>
            </div>
            <?php if($txn['discount']>0): ?>
            <div class="txn-row">
                <span class="txn-lbl"><i class="fas fa-tag" style="color:var(--green);margin-right:4px;font-size:11px;"></i>Discount</span>
                <span class="txn-val discount">−₱<?php echo number_format($txn['discount'],2); ?></span>
            </div>
            <?php endif; ?>
            <div class="txn-row">
                <span class="txn-lbl" style="font-weight:800;color:var(--navy);">Total</span>
                <span class="txn-val total">₱<?php echo number_format($txn['amount'],2); ?></span>
            </div>
            <?php if($txn['loyalty_points_earned']>0): ?>
            <div class="txn-row" style="border-bottom:none;">
                <span class="txn-lbl"><i class="fas fa-star" style="color:var(--amber);margin-right:4px;font-size:11px;"></i>Points Earned</span>
                <span class="txn-val pts">+<?php echo $txn['loyalty_points_earned']; ?> pts</span>
            </div>
            <?php endif; ?>
        </div>

        <?php if(in_array($txn['status'], ['pending', 'approved'], true) && in_array($cs, ['pending', 'assigned'], true)): ?>
        <form method="POST" class="cancel-order-form" onsubmit="return confirm('Cancel this order? This action cannot be undone.');">
            <input type="hidden" name="cancel_order" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['customer_order_csrf']); ?>">
            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($txn['transaction_id']); ?>">
            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($txn['user_id']); ?>">
            <input type="hidden" name="search_value" value="<?php echo htmlspecialchars($search_value); ?>">
            <button type="submit" class="cancel-order-btn"><i class="fas fa-ban"></i> Cancel Order</button>
        </form>
        <?php endif; ?>

        <?php if($txn['status']==='approved'): ?>
        <div class="dlv dlv-<?php echo $cs; ?>">
            <div class="dlv-hd">
                <div class="dlv-ico">
                    <?php
                    if($cs==='assigned') echo '<i class="fas fa-user-check"></i>';
                    elseif($cs==='pending') echo '<i class="fas fa-clock"></i>';
                    elseif(in_array($cs,['on_the_way','on_way'])) echo '<i class="fas fa-truck-fast"></i>';
                    else echo '<i class="fas fa-circle-check"></i>';
                    ?>
                </div>
                <?php
                if($cs==='assigned') echo 'Rider Assigned';
                elseif($cs==='pending') echo 'Order Preparation';
                elseif(in_array($cs,['on_the_way','on_way'])) echo 'On the Way!';
                else echo 'Delivered ✓';
                ?>
            </div>
            <div class="dlv-bd">
                <?php
                if($cs==='assigned') echo 'A rider has been assigned to your order and will start delivery soon.';
                elseif($cs==='pending') echo 'Your order is being prepared for delivery.';
                elseif(in_array($cs,['on_the_way','on_way'])) echo 'Your order is currently on the way to you.';
                else echo 'Your order has been delivered successfully.';
                ?>
                <?php if(!empty($txn['rider_name'])||!empty($txn['rider_contact_number'])): ?>
                <div>
                    <span class="rider-chip">
                        <i class="fas fa-motorcycle" style="color:var(--orange);"></i>
                        <?php echo htmlspecialchars($txn['rider_name']?:'Assigned Rider'); ?>
                        <?php if(!empty($txn['rider_contact_number'])): ?>
                        · <?php echo htmlspecialchars($txn['rider_contact_number']); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tl">
            <?php
            $tlSteps=[
                ['s'=>'done',                         'ico'=>'fa-check',             'title'=>'Order Confirmed', 'sub'=>date('M d, Y h:i A',strtotime($txn['created_at']))],
                ['s'=>stepState($cs,'preparing'),      'ico'=>'fa-box-open',          'title'=>'Order Prepared',  'sub'=>in_array($cs,['pending','assigned'])?($cs==='assigned'?'Rider assignment complete':'Preparation in progress…'):'Ready for delivery'],
                ['s'=>stepState($cs,'on_the_way'),     'ico'=>'fa-truck',             'title'=>'On the Way',      'sub'=>in_array($cs,['pending','assigned'])?'Waiting for dispatch':'Rider heading to your address'],
                ['s'=>stepState($cs,'delivered'),      'ico'=>'fa-house-circle-check','title'=>'Delivered',       'sub'=>$cs==='delivered'?'Order delivered successfully':'Awaiting delivery'],
            ];
            foreach($tlSteps as $tl):
            ?>
            <div class="tl-it">
                <div class="tl-dot <?php echo $tl['s']; ?>"><i class="fas <?php echo $tl['ico']; ?>"></i></div>
                <div class="tl-cnt">
                    <p class="tl-title"><?php echo $tl['title']; ?></p>
                    <p class="tl-sub"><?php echo $tl['sub']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if($cs === 'delivered'): ?>
        <div class="feedback-card">
            <div class="feedback-head">
                <div class="feedback-title"><i class="fas fa-star"></i> Feedback & Rating</div>
                <?php if($txn_feedback): ?>
                <div class="feedback-summary">
                    <span class="feedback-pill"><?php echo str_repeat('★', (int)$txn_feedback['rating']); ?> <?php echo (int)$txn_feedback['rating']; ?>/5</span>
                    <span>Saved on <?php echo date('M d, Y', strtotime($txn_feedback['updated_at'] ?: $txn_feedback['created_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if(!$txn_feedback): ?>
            <form method="POST">
                <input type="hidden" name="submit_feedback" value="1">
                <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($txn['transaction_id']); ?>">
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($txn['user_id']); ?>">
                <input type="hidden" name="search_value" value="<?php echo htmlspecialchars($search_value); ?>">

                <div class="feedback-stars">
                    <?php for($star = 5; $star >= 1; $star--): ?>
                    <label>
                        <input type="radio" name="rating" value="<?php echo $star; ?>" <?php echo $star === 5 ? 'checked' : ''; ?>>
                        <span><?php echo $star; ?> Star<?php echo $star > 1 ? 's' : ''; ?></span>
                    </label>
                    <?php endfor; ?>
                </div>

                <textarea class="feedback-text" name="feedback_message" placeholder="Share your experience with the delivery, product quality, or service."></textarea>

                <div class="feedback-row">
                    <div class="feedback-note">Customer ratings and messaging help improve service quality.</div>
                    <button type="submit" class="feedback-btn">Submit Feedback</button>
                </div>
            </form>
            <?php else: ?>
            <div class="feedback-note"><i class="fas fa-circle-check"></i> Feedback submitted for this order. Thank you!</div>
            <?php if(trim((string)($txn_feedback['feedback_message'] ?? '')) !== ''): ?><div class="feedback-text"><?php echo nl2br(htmlspecialchars($txn_feedback['feedback_message'])); ?></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php elseif($txn['status']==='pending'): ?>
        <div class="notice n-await">
            <div class="notice-title"><i class="fas fa-hourglass-half" style="margin-right:6px;"></i>Awaiting Approval</div>
            <div class="notice-body">Your order is waiting for approval. You'll be notified once it's approved and on the way.</div>
        </div>
        <?php elseif($txn['status']==='denied'): ?>
        <div class="notice n-deny">
            <div class="notice-title"><i class="fas fa-circle-xmark" style="margin-right:6px;"></i><?php echo str_contains((string)($txn['cancellation_reason'] ?? ''), 'Cancelled by customer') ? 'Order Cancelled' : 'Order Denied'; ?></div>
            <div class="notice-body"><?php echo htmlspecialchars($txn['cancellation_reason'] ?: 'Unfortunately this order was denied. Please contact support for more information.'); ?></div>
        </div>
        <?php else: ?>
        <div style="height:4px;"></div>
        <?php endif; ?>

    </div><!-- /txn -->
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
/* ─── Section toggle ───────────────── */
const bgAnim = document.getElementById('bg-anim');
function showPage(id) {
    document.querySelectorAll('.pg').forEach(p => p.classList.remove('on'));
    const t = document.getElementById(id);
    if (t) { t.classList.add('on'); window.scrollTo({top:0,behavior:'smooth'}); }
    if (bgAnim) bgAnim.style.display = id==='pg-search' ? '' : 'none';
}
document.getElementById('btn-back')?.addEventListener('click', () => {
    showPage('pg-search');
    setTimeout(() => document.getElementById('search_value')?.focus(), 80);
});
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('pg-search')?.classList.contains('on'))
        document.getElementById('search_value')?.focus();
});

/* ─── Mobile menu ──────────────────── */
const mobOv  = document.getElementById('mob-ov');
const mobPn  = document.getElementById('mob-pn');
const mobTog = document.getElementById('mob-toggle');
const mobCls = document.getElementById('mob-close');
const mobIco = document.getElementById('mob-icon');
const mobOrdersPanel = document.getElementById('mob-orders-panel');
const mobOrdersToggle = document.querySelector('[data-toggle-orders]');
function openMob()  { document.body.classList.add('mob-open');    if(mobIco) mobIco.className='fas fa-xmark'; mobTog?.setAttribute('aria-expanded','true'); mobTog?.setAttribute('aria-label','Close menu'); }
function closeMob() { document.body.classList.remove('mob-open'); if(mobIco) mobIco.className='fas fa-bars';  mobTog?.setAttribute('aria-expanded','false'); mobTog?.setAttribute('aria-label','Open menu'); }
function toggleMobOrders(forceState = null) {
    if (!mobOrdersPanel || !mobOrdersToggle) return;
    const nextState = forceState === null ? !mobOrdersPanel.classList.contains('on') : forceState;
    mobOrdersPanel.classList.toggle('on', nextState);
    mobOrdersToggle.classList.toggle('is-active', nextState);
}
mobTog?.addEventListener('click', () => document.body.classList.contains('mob-open')?closeMob():openMob());
mobCls?.addEventListener('click', closeMob);
mobOv?.addEventListener('click',  e => { if(!mobPn.contains(e.target)) closeMob(); });
document.querySelectorAll('[data-open-page]').forEach(btn => {
    btn.addEventListener('click', () => {
        const pageId = btn.getAttribute('data-open-page') || 'pg-search';
        showPage(pageId);
        toggleMobOrders(false);
        closeMob();
        if (pageId === 'pg-search') {
            setTimeout(() => document.getElementById('search_value')?.focus(), 80);
        }
    });
});
mobOrdersToggle?.addEventListener('click', () => toggleMobOrders());
document.addEventListener('keydown', e => { if(e.key==='Escape') closeMob(); });

/* ─── Map ──────────────────────────── */
let map=null, markers=[], poly=null, liveRiderMarker=null, riderMoveFrame=null, hasFittedLiveRoute=false;
const tubigon={lat:9.9509,lng:123.9622};
// HydroMIS water refilling station — Barangay Guiwanon, Tubigon, Bohol.
const station={lat:9.9417,lng:123.9536};

function pillCls(s) {
    s=(s||'').toLowerCase();
    return s==='delivered'?'pill-delivered':(s==='on_the_way'||s==='on_way'?'pill-on_the_way':'pill-preparing');
}
function pillTxt(s) {
    s=(s||'').toLowerCase();
    return s==='delivered'?'Delivered':(s==='on_the_way'||s==='on_way'?'On the way':(s==='assigned'?'Assigned':'Preparing'));
}
function stepStatJS(ds, step) {
    const s=(ds||'').toLowerCase();
    if(step==='confirmed') return 'done';
    if(step==='preparing') return (s==='pending'||s==='assigned')?'active':'done';
    if(step==='on_the_way') { if(s==='pending'||s==='assigned') return 'pending'; if(s==='on_the_way'||s==='on_way') return 'active'; return 'done'; }
    if(step==='delivered')  return s==='delivered'?'done':'pending';
}
function updateHeader(o) {
    const pill = document.getElementById('map-pill');
    const ptxt = document.getElementById('map-pill-txt');
    const rn   = document.getElementById('map-rider-name');
    const rc   = document.getElementById('map-rider-call');
    if(pill) pill.className = 'status-pill '+pillCls(o.status);
    if(ptxt) ptxt.textContent = o.statusText || pillTxt(o.status);
    if(rn)   rn.textContent   = o.riderName  || 'Assigned Rider';
    if(rc) {
        if(o.riderContact){ rc.setAttribute('href','tel:'+o.riderContact); rc.style.opacity='1'; rc.style.cursor='pointer'; }
        else { rc.removeAttribute('href'); rc.style.opacity='.35'; rc.style.cursor='default'; }
    }
    const stepKeys=['confirmed','preparing','on_the_way','delivered'];
    document.querySelectorAll('#map-steps .step-it').forEach((it,i) => {
        const st = stepStatJS(o.status, stepKeys[i]);
        it.querySelector('.step-ico').className = 'step-ico '+st;
        it.querySelector('.step-lb').className  = 'step-lb '+st;
    });
}
function clearMap() {
    if(!map) return;
    if(riderMoveFrame){cancelAnimationFrame(riderMoveFrame);riderMoveFrame=null;}
    markers.forEach(m => map.removeLayer(m)); markers=[];
    if(liveRiderMarker){ map.removeLayer(liveRiderMarker); liveRiderMarker=null; }
    if(poly){ map.removeLayer(poly); poly=null; }
    hasFittedLiveRoute=false;
}
function moveRiderSmoothly(marker,target,duration=1400) {
    if(!marker) return;
    if(riderMoveFrame) cancelAnimationFrame(riderMoveFrame);
    const start=marker.getLatLng();
    const started=performance.now();
    const animate=now=>{
        const raw=Math.min(1,(now-started)/duration);
        const eased=1-Math.pow(1-raw,3);
        marker.setLatLng([start.lat+(target[0]-start.lat)*eased,start.lng+(target[1]-start.lng)*eased]);
        if(raw<1) riderMoveFrame=requestAnimationFrame(animate); else riderMoveFrame=null;
    };
    riderMoveFrame=requestAnimationFrame(animate);
}
function drawRoute() {
    if(!map||!window.L) return;
    clearMap();
    const stationIcon=L.divIcon({className:'',html:'<div style="display:grid;place-items:center;width:30px;height:30px;border:3px solid white;border-radius:50%;background:#f97316;color:white;box-shadow:0 5px 14px rgba(0,0,0,.25)"><i class="fas fa-droplet"></i></div>',iconSize:[30,30],iconAnchor:[15,15]});
    markers.push(L.marker([station.lat,station.lng],{icon:stationIcon,title:'HydroMIS station — Guiwanon'}).addTo(map).bindTooltip('HydroMIS Water Station · Guiwanon',{direction:'top',offset:[0,-15]}));
    map.setView([station.lat,station.lng],14);
}
function updateRiderMarker(location) {
    if(!map || !window.L || !location) return;
    const lat=Number(location.latitude), lng=Number(location.longitude);
    if(!Number.isFinite(lat)||!Number.isFinite(lng)) return;
    if(!liveRiderMarker){
        const riderIcon=L.divIcon({className:'rider-live-icon',html:'<div class="rider-live-wrap"><span class="rider-live-pulse"></span><span class="rider-live-core"><i class="fas fa-motorcycle"></i></span></div>',iconSize:[42,42],iconAnchor:[21,21]});
        liveRiderMarker=L.marker([lat,lng],{icon:riderIcon,title:'Rider live location',zIndexOffset:1000}).addTo(map).bindTooltip('Live rider',{direction:'top',offset:[0,-18]});
    } else {
        moveRiderSmoothly(liveRiderMarker,[lat,lng]);
    }
    if(poly) map.removeLayer(poly);
    poly=L.polyline([[station.lat,station.lng],[lat,lng]],{color:'#1d6fd8',weight:3,dashArray:'8 9',opacity:.8}).addTo(map);
    if(!hasFittedLiveRoute){
        map.fitBounds(L.latLngBounds([[station.lat,station.lng],[lat,lng]]),{padding:[42,42],maxZoom:16});
        hasFittedLiveRoute=true;
    }
}
function renderOrder(o) {
    if(!map) return;
    updateHeader(o);
    drawRoute();
}
function setSel(id) {
    document.querySelectorAll('.txn[data-card-id]').forEach(c=>c.classList.toggle('sel',c.getAttribute('data-card-id')===id));
}
function initOrderMap() {
    const el=document.getElementById('tracking-map');
    if(!el||!window.L) return;
    const panel=document.getElementById('live-map-panel');
    const openMap=o=>{
        if(panel) panel.hidden=false;
        if(!map){
            map=L.map(el,{zoomControl:true,scrollWheelZoom:true}).setView([tubigon.lat,tubigon.lng],14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
        }
        renderOrder(o); setSel(o.transactionId);
        requestAnimationFrame(()=>map?.invalidateSize());
        panel?.scrollIntoView({behavior:'smooth',block:'start'});
        startLiveGPSTracking(o.transactionId);
    };
    document.querySelectorAll('.btn-map').forEach(btn=>{
        btn.addEventListener('click',function(){
            const o={
                transactionId:this.dataset.transactionId,
                address:      this.dataset.address,
                status:       (this.dataset.status||'pending').toLowerCase(),
                statusText:   this.dataset.statusText||pillTxt(this.dataset.status),
                riderName:    this.dataset.riderName    ||'Assigned Rider',
                riderContact: this.dataset.riderContact ||''
            };
            showPage('pg-results');
            toggleMobOrders(false);
            closeMob();
            openMap(o);
        });
    });
    document.getElementById('close-live-map')?.addEventListener('click',()=>{
        if(panel) panel.hidden=true;
        if(liveGPSInterval){clearInterval(liveGPSInterval);liveGPSInterval=null;}
    });
}
if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initOrderMap);
else initOrderMap();
</script>

<!-- Live GPS Tracking Script -->
<script>
/* ─── Live GPS Tracking ────────────── */
let liveGPSInterval = null;
let messageRefreshInterval = null;
const TRACKING_USER_ID = <?php echo json_encode($tracking_info[0]['user_id'] ?? ''); ?>;
const TRACKING_INITIAL_TRANSACTION = <?php echo json_encode($tracking_info[0]['transaction_id'] ?? ''); ?>;
const TRACKING_INITIAL_STATUS = <?php echo json_encode(isset($tracking_info[0]) ? trackingDeliveryStatus($tracking_info[0]) : ''); ?>;
let activeMessageTransaction = '';
let orderStatusRefreshInterval = null;

function selectCustomerConversation(transactionId) {
    if(!transactionId) return;
    activeMessageTransaction = transactionId;
    const list=document.getElementById('customer-message-list');
    if(list) list.innerHTML='<div class="customer-chat-empty">Loading this order\'s conversation…</div>';
    if(messageRefreshInterval) clearInterval(messageRefreshInterval);
    loadCustomerMessages(transactionId);
    messageRefreshInterval=setInterval(()=>loadCustomerMessages(transactionId),5000);
}

function startLiveGPSTracking(transactionId) {
    if(!transactionId) return;
    selectCustomerConversation(transactionId);
    
    // Clear any existing interval
    if(liveGPSInterval) {
        clearInterval(liveGPSInterval);
    }

    // Fetch location immediately
    updateLiveGPSLocation(transactionId);

    // Then update every 5 seconds
    liveGPSInterval = setInterval(() => {
        updateLiveGPSLocation(transactionId);
    }, 5000);
}

function formatMessageTime(value) {
    const date=new Date(String(value).replace(' ','T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
}

function renderCustomerMessages(messages) {
    const list=document.getElementById('customer-message-list');
    if(!list) return;
    list.replaceChildren();
    if(!messages.length){
        const empty=document.createElement('div');
        empty.className='customer-chat-empty';
        empty.textContent='No messages yet. Send your rider an update.';
        list.appendChild(empty);
        return;
    }
    messages.forEach(message=>{
        const item=document.createElement('div');
        item.className='customer-message'+(message.sender===TRACKING_USER_ID?' mine':'');
        const bubble=document.createElement('div');
        bubble.className='customer-message-bubble';
        bubble.textContent=message.message;
        const meta=document.createElement('div');
        meta.className='customer-message-meta';
        const previousOrder=message.transaction_id && message.transaction_id!==activeMessageTransaction ? ' · Previous order' : '';
        meta.textContent=(message.sender===TRACKING_USER_ID?'You':'Rider')+' · '+formatMessageTime(message.created_at)+previousOrder;
        item.append(bubble,meta);
        list.appendChild(item);
    });
    list.scrollTop=list.scrollHeight;
}

function loadCustomerMessages(transactionId) {
    if(!transactionId || !TRACKING_USER_ID) return;
    fetch(`?ajax=messages&transaction_id=${encodeURIComponent(transactionId)}&user_id=${encodeURIComponent(TRACKING_USER_ID)}`)
        .then(response=>response.json())
        .then(data=>{
            if(transactionId!==activeMessageTransaction) return;
            if(data.ok) renderCustomerMessages(data.messages||[]);
            else {
                const list=document.getElementById('customer-message-list');
                if(list) list.innerHTML='<div class="customer-chat-empty">Messaging is available after approval and rider assignment.</div>';
            }
        })
        .catch(()=>{});
}

document.getElementById('customer-chat-form')?.addEventListener('submit', event=>{
    event.preventDefault();
    const input=document.getElementById('customer-chat-input');
    const button=document.getElementById('customer-chat-send');
    const message=input?.value.trim()||'';
    if(!message || !activeMessageTransaction) return;
    button.disabled=true;
    fetch('?ajax=messages',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({transaction_id:activeMessageTransaction,user_id:TRACKING_USER_ID,message})
    }).then(response=>response.json()).then(data=>{
        if(!data.ok) throw new Error(data.error||'Unable to send message.');
        input.value='';
        renderCustomerMessages(data.messages||[]);
    }).catch(error=>alert(error.message)).finally(()=>{button.disabled=false;input?.focus();});
});

if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded',()=>initializeCustomerOrder());
} else {
    initializeCustomerOrder();
}

function initializeCustomerOrder() {
    selectCustomerConversation(TRACKING_INITIAL_TRANSACTION);
    if(!TRACKING_INITIAL_TRANSACTION) return;
    updateLiveGPSLocation(TRACKING_INITIAL_TRANSACTION);
    orderStatusRefreshInterval=setInterval(()=>updateLiveGPSLocation(TRACKING_INITIAL_TRANSACTION),5000);
}

function updateLiveGPSLocation(transactionId) {
    fetch(`../api/delivery_tracker.php?request=get_rider_location&transaction_id=${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if(data.success && data.data) {
                const order = data.data;
                const location = order.rider_location;
                const liveStatus = (order.delivery_status || 'pending').toLowerCase();
                const activePanel = document.getElementById('active-order-panel');
                const isOnWay = liveStatus === 'on_way' || liveStatus === 'on_the_way';
                activePanel?.classList.toggle('is-active-delivery', liveStatus !== 'delivered');
                if(isOnWay && TRACKING_INITIAL_STATUS !== 'on_way' && TRACKING_INITIAL_STATUS !== 'on_the_way') {
                    window.location.reload();
                    return;
                }
                updateHeader({
                    status: liveStatus,
                    statusText: pillTxt(liveStatus),
                    riderName: order.rider_name || 'Assigned Rider',
                    riderContact: order.rider_contact_number || ''
                });
                document.querySelectorAll(`.txn[data-card-id="${CSS.escape(transactionId)}"]`).forEach(card => {
                    const badge = card.querySelector('.txn-bdg');
                    if (badge && liveStatus === 'delivered') {
                        badge.className = 'txn-bdg bdg-approved';
                        badge.textContent = 'Done';
                    }
                    const mapButton = card.querySelector('.btn-map');
                    if (mapButton) {
                        if(liveStatus === 'delivered') {
                            const completed=document.createElement('span');
                            completed.className='map-locked';
                            completed.innerHTML='<i class="fas fa-circle-check"></i> Tracking completed';
                            mapButton.replaceWith(completed);
                        } else {
                            mapButton.dataset.status = liveStatus;
                            mapButton.dataset.statusText = pillTxt(liveStatus);
                            mapButton.dataset.pillClass = pillCls(liveStatus);
                        }
                    }
                });
                if(liveStatus === 'delivered') {
                    const panel=document.getElementById('live-map-panel');
                    if(panel) panel.hidden=true;
                    if(liveGPSInterval){clearInterval(liveGPSInterval);liveGPSInterval=null;}
                }

                // Update live location display
                const latLng = document.getElementById('rider-lat-lng');
                const yourLoc = document.getElementById('your-location');
                const lastUpd = document.getElementById('last-update');

                if(order.has_live_location && location && latLng) {
                    latLng.textContent = `${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}`;
                    updateRiderMarker(location);
                    const gpsTitle=document.getElementById('live-gps-title');
                    if(gpsTitle) gpsTitle.textContent='Rider location is live';
                } else if(latLng) {
                    latLng.textContent = 'Waiting for the rider to enable location';
                }
                if(yourLoc) {
                    yourLoc.textContent = order.address || 'Loading...';
                }
                if(lastUpd && order.has_live_location && location) {
                    const lastUpdateTime = new Date(location.last_update);
                    const now = new Date();
                    const diffSeconds = Math.floor((now - lastUpdateTime) / 1000);
                    if(diffSeconds < 60) {
                        lastUpd.textContent = 'Just now';
                    } else if(diffSeconds < 3600) {
                        lastUpd.textContent = Math.floor(diffSeconds / 60) + 'm ago';
                    } else {
                        lastUpd.textContent = Math.floor(diffSeconds / 3600) + 'h ago';
                    }
                } else if(lastUpd) lastUpd.textContent='No GPS update yet';
            }
        })
        .catch(error => console.log('GPS tracking error:', error));
}

// Stop tracking when leaving the page
window.addEventListener('beforeunload', () => {
    if(liveGPSInterval) {
        clearInterval(liveGPSInterval);
    }
    if(messageRefreshInterval) clearInterval(messageRefreshInterval);
});
</script>
<script src="../js/user-notifications.js"></script>
</body>
</html>
