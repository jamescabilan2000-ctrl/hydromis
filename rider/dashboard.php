<?php
/**
 * HydroMIS Rider Portal — "My Deliveries"
 * ----------------------------------------------------------------
 * Scope for this page, on purpose:
 *   - A rider only ever sees orders STAFF has assigned to them.
 *     There is no self-serve "available orders" pool here.
 *   - No admin-style KPI dashboard. Just today's/total earnings as
 *     a small strip, the delivery list, and a tracking view per order.
 *   - Tracking = a route timeline (Assigned -> On the Way -> Delivered)
 *     plus a live map using the rider's own GPS ping, reusing the
 *     existing rider_locations table / geolocation workflow.
 * ----------------------------------------------------------------
 */

include 'check_auth.php';
require_once '../config/database.php';
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);

/* ---------- small helpers ---------- */

function column_exists($conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = '$column' LIMIT 1");
    return $result && $result->num_rows > 0;
}

function respond_json($data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/* ---------- schema bootstrap (safe to run every load) ---------- */

$conn->query("CREATE TABLE IF NOT EXISTS rider_users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
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
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS age INT");
$conn->query("ALTER TABLE rider_users ADD COLUMN IF NOT EXISTS address TEXT");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50)");
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
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    INDEX idx_rider_id (rider_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
)");
$conn->query("CREATE TABLE IF NOT EXISTS rider_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_id (rider_id),
    INDEX idx_is_read (is_read)
)");

$transactions_has_assigned_rider = column_exists($conn, 'transactions', 'assigned_rider');
$rider_col_expr = $transactions_has_assigned_rider ? "COALESCE(t.rider_id, t.assigned_rider)" : "t.rider_id";

/* ---------- resolve the logged-in rider ---------- */

$rider_id = sanitize($_SESSION['rider_id'] ?? '');
$session_username = sanitize($_SESSION['rider_auth_username'] ?? ($_SESSION['username'] ?? ''));
$session_full_name = sanitize($_SESSION['rider_auth_full_name'] ?? ($_SESSION['full_name'] ?? ''));

if ($rider_id === '') {
    if ($session_username !== '') {
        $session_username_lookup = sensitive_lookup(htmlspecialchars_decode($session_username));
        $stmt = $conn->prepare("SELECT rider_id FROM rider_users WHERE username_lookup = ? LIMIT 1");
        $stmt->bind_param('s', $session_username_lookup);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $rider_id = $row['rider_id'];
            $_SESSION['rider_id'] = $rider_id;
        }
    }
}

if ($rider_id === '') {
    die('Error: Rider not properly authenticated. Please log in again.');
}

/* ---------- AJAX endpoints (location ping + fetch) ---------- */
/* Kept in the same file so this remains a drop-in single page,
   same pattern as the existing api/delivery_tracker.php calls. */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_location') {
    $transaction_id = sanitize($_GET['transaction_id'] ?? '');
    $stmt = $conn->prepare("SELECT rider_latitude, rider_longitude, speed, heading, last_update
        FROM rider_locations WHERE transaction_id = ? AND rider_id = ?
        ORDER BY last_update DESC LIMIT 1");
    $stmt->bind_param('ss', $transaction_id, $rider_id);
    $stmt->execute();
    $res = $stmt->get_result();
    respond_json($res->fetch_assoc() ?: null);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'ping_location') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $transaction_id = sanitize($input['transaction_id'] ?? '');
    $lat = (float)($input['latitude'] ?? 0);
    $lng = (float)($input['longitude'] ?? 0);
    $speed = isset($input['speed']) ? (float)$input['speed'] : null;
    $heading = isset($input['heading']) ? (float)$input['heading'] : null;

    if ($transaction_id === '' || ($lat === 0.0 && $lng === 0.0)) {
        respond_json(['ok' => false, 'error' => 'Missing transaction_id or coordinates.']);
    }

    $stmt = $conn->prepare("UPDATE rider_locations SET rider_latitude = ?, rider_longitude = ?, speed = ?, heading = ?, last_update = NOW() WHERE transaction_id = ? AND rider_id = ?");
    $stmt->bind_param('ddddss', $lat, $lng, $speed, $heading, $transaction_id, $rider_id);
    $ok = $stmt->execute();
    $location_exists = false;
    if ($ok && $stmt->affected_rows === 0) {
        $check = $conn->prepare("SELECT id FROM rider_locations WHERE transaction_id = ? AND rider_id = ? LIMIT 1");
        $check->bind_param('ss', $transaction_id, $rider_id);
        $check->execute();
        $location_exists = $check->get_result()->num_rows > 0;
    }
    if ($ok && $stmt->affected_rows === 0 && !$location_exists) {
        $stmt = $conn->prepare("INSERT INTO rider_locations
            (transaction_id, rider_id, rider_latitude, rider_longitude, speed, heading)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssdddd', $transaction_id, $rider_id, $lat, $lng, $speed, $heading);
        $ok = $stmt->execute();
    }
    respond_json(['ok' => (bool)$ok]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delivery_info') {
    $transaction_id = sanitize($_GET['transaction_id'] ?? '');
    if ($transaction_id === '') {
        respond_json(['error' => 'transaction_id is required']);
    }

    if ($transactions_has_assigned_rider) {
        $stmt = $conn->prepare("SELECT t.transaction_id, t.amount, t.delivery_status, t.description, t.water_type, t.quantity, t.price_per_unit, t.payment_method, t.notes, t.user_id,
            COALESCE(u.full_name, 'Unknown Customer') AS customer_name,
            COALESCE(u.contact_number, '-') AS contact_number,
            COALESCE(u.email, '-') AS email,
            COALESCE(u.address, 'No address provided') AS address
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            WHERE t.transaction_id = ? AND (t.rider_id = ? OR t.assigned_rider = ?) LIMIT 1");
        $stmt->bind_param('sss', $transaction_id, $rider_id, $rider_id);
    } else {
        $stmt = $conn->prepare("SELECT t.transaction_id, t.amount, t.delivery_status, t.description, t.water_type, t.quantity, t.price_per_unit, t.payment_method, t.notes, t.user_id,
            COALESCE(u.full_name, 'Unknown Customer') AS customer_name,
            COALESCE(u.contact_number, '-') AS contact_number,
            COALESCE(u.email, '-') AS email,
            COALESCE(u.address, 'No address provided') AS address
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            WHERE t.transaction_id = ? AND t.rider_id = ? LIMIT 1");
        $stmt->bind_param('ss', $transaction_id, $rider_id);
    }
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    if (!$delivery) {
        respond_json(['error' => 'Delivery not found']);
    }

    $messages = [];
    $customer_id = (string)$delivery['user_id'];
    $msgStmt = $conn->prepare("SELECT id, transaction_id, sender, recipient, message, created_at FROM (
        SELECT id, transaction_id, sender, recipient, message, created_at
        FROM rider_messages
        WHERE transaction_id = ?
          AND ((sender = ? AND recipient = ?) OR (sender = ? AND recipient = ?))
        ORDER BY id DESC LIMIT 100
    ) recent_messages ORDER BY id ASC");
    $msgStmt->bind_param('sssss', $transaction_id, $rider_id, $customer_id, $customer_id, $rider_id);
    $msgStmt->execute();
    $msgRes = $msgStmt->get_result();
    while ($msg = $msgRes->fetch_assoc()) {
        $messages[] = $msg;
    }

    $feedback = null;
    $fbStmt = $conn->prepare("SELECT rating, feedback_message, created_at FROM feedback_ratings WHERE transaction_id = ? ORDER BY updated_at DESC LIMIT 1");
    $fbStmt->bind_param('s', $transaction_id);
    $fbStmt->execute();
    $fbRes = $fbStmt->get_result();
    if ($fb = $fbRes->fetch_assoc()) {
        $feedback = $fb;
    }

    respond_json([
        'delivery' => $delivery,
        'messages' => $messages,
        'feedback' => $feedback
    ]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'send_message') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $transaction_id = sanitize($input['transaction_id'] ?? '');
    $text = trim($input['message'] ?? '');

    if ($transaction_id === '' || $text === '') {
        respond_json(['ok' => false, 'error' => 'Transaction and message are required.']);
    }

    if ($transactions_has_assigned_rider) {
        $stmt = $conn->prepare("SELECT user_id FROM transactions WHERE transaction_id = ? AND status = 'approved' AND (rider_id = ? OR assigned_rider = ?) LIMIT 1");
        $stmt->bind_param('sss', $transaction_id, $rider_id, $rider_id);
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM transactions WHERE transaction_id = ? AND status = 'approved' AND rider_id = ? LIMIT 1");
        $stmt->bind_param('ss', $transaction_id, $rider_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        respond_json(['ok' => false, 'error' => 'Delivery not found or not assigned to you.']);
    }

    $recipient = $row['user_id'];
    $insertStmt = $conn->prepare("INSERT INTO rider_messages (transaction_id, sender, recipient, message) VALUES (?, ?, ?, ?)");
    $insertStmt->bind_param('ssss', $transaction_id, $rider_id, $recipient, $text);
    $ok = $insertStmt->execute();
    respond_json(['ok' => (bool)$ok, 'message' => ['transaction_id' => $transaction_id, 'sender' => $rider_id, 'recipient' => $recipient, 'message' => $text, 'created_at' => date('Y-m-d H:i:s')]]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'notifications') {
    $alerts = [];
    $stmt = $conn->prepare("SELECT id, transaction_id, title, message, created_at
        FROM rider_notifications WHERE rider_id = ? AND is_read = 0 ORDER BY id ASC LIMIT 10");
    $stmt->bind_param('s', $rider_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($alert = $result->fetch_assoc()) {
        $alerts[] = $alert;
    }
    if (!empty($alerts)) {
        $last_id = (int)end($alerts)['id'];
        $mark = $conn->prepare("UPDATE rider_notifications SET is_read = 1 WHERE rider_id = ? AND is_read = 0 AND id <= ?");
        $mark->bind_param('si', $rider_id, $last_id);
        $mark->execute();
    }
    respond_json(['ok' => true, 'notifications' => $alerts]);
}

/* ---------- POST actions: start / complete / status change ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');

    if ($action === 'report_delay' && $transaction_id !== '') {
        $delay_reasons = [
            'traffic' => 'Heavy traffic',
            'weather' => 'Bad weather',
            'vehicle' => 'Vehicle problem',
            'refilling' => 'Delay at the water refilling station',
            'location' => 'Difficulty locating the delivery address',
            'other' => 'Unexpected delay',
        ];
        $reason_key = sanitize($_POST['delay_reason'] ?? 'other');
        $reason = $delay_reasons[$reason_key] ?? $delay_reasons['other'];
        $delay_note = trim((string)($_POST['delay_note'] ?? ''));
        $delay_note = mb_substr(strip_tags($delay_note), 0, 180);

        if ($transactions_has_assigned_rider) {
            $delayStmt = $conn->prepare("SELECT user_id FROM transactions WHERE transaction_id = ? AND status = 'approved' AND delivery_status IN ('on_way','on_the_way') AND (rider_id = ? OR assigned_rider = ?) LIMIT 1");
            $delayStmt->bind_param('sss', $transaction_id, $rider_id, $rider_id);
        } else {
            $delayStmt = $conn->prepare("SELECT user_id FROM transactions WHERE transaction_id = ? AND status = 'approved' AND delivery_status IN ('on_way','on_the_way') AND rider_id = ? LIMIT 1");
            $delayStmt->bind_param('ss', $transaction_id, $rider_id);
        }
        $delayStmt->execute();
        $delayOrder = $delayStmt->get_result()->fetch_assoc();
        if ($delayOrder) {
            $delay_message = "Delivery update: Your order is delayed due to {$reason}.";
            if ($delay_note !== '') $delay_message .= " Rider note: {$delay_note}";
            $delay_message .= ' Your rider is still on the way. Thank you for your patience.';
            add_user_notification($conn, (string)$delayOrder['user_id'], $transaction_id, 'Delivery delayed', $delay_message, 'delivery');
            $messageStmt = $conn->prepare("INSERT INTO rider_messages (transaction_id, sender, recipient, message) VALUES (?, ?, ?, ?)");
            $messageStmt->bind_param('ssss', $transaction_id, $rider_id, $delayOrder['user_id'], $delay_message);
            $messageStmt->execute();
            $_SESSION['rider_flash'] = 'Delay update sent to the customer.';
        } else {
            $_SESSION['rider_flash'] = 'Delay update was not sent. This delivery is no longer on the way.';
        }
    }

    if (in_array($action, ['start_delivery', 'complete'], true) && $transaction_id !== '') {
        $new_status = null;
        $flash = '';

        if ($action === 'start_delivery') {
            $new_status = 'on_way';
            $flash = "Status updated: {$transaction_id} is now On the Way.";
        } elseif ($action === 'complete') {
            $new_status = 'delivered';
            $flash = "Delivery completed: {$transaction_id}.";
        }

        if ($new_status !== null) {
            $expected_status_sql = $action === 'start_delivery'
                ? "COALESCE(NULLIF(delivery_status, ''), 'assigned') IN ('assigned', 'pending')"
                : "delivery_status = 'on_way'";
            if ($transactions_has_assigned_rider) {
                $stmt = $conn->prepare("UPDATE transactions SET delivery_status = ?
                    WHERE transaction_id = ? AND status = 'approved' AND (rider_id = ? OR assigned_rider = ?)
                    AND {$expected_status_sql}");
                $stmt->bind_param('ssss', $new_status, $transaction_id, $rider_id, $rider_id);
            } else {
                $stmt = $conn->prepare("UPDATE transactions SET delivery_status = ?
                    WHERE transaction_id = ? AND status = 'approved' AND rider_id = ?
                    AND {$expected_status_sql}");
                $stmt->bind_param('sss', $new_status, $transaction_id, $rider_id);
            }
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['rider_flash'] = $flash;
                } else {
                    $_SESSION['rider_flash'] = 'This delivery has already moved to another stage. The page has been refreshed.';
                }
                if ($action === 'start_delivery' && $stmt->affected_rows > 0) {
                    $customerResult = $conn->query("SELECT user_id FROM transactions WHERE transaction_id='" . $conn->real_escape_string($transaction_id) . "' LIMIT 1");
                    $customerOrder = $customerResult ? $customerResult->fetch_assoc() : null;
                    if ($customerOrder) {
                        add_user_notification(
                            $conn,
                            (string)$customerOrder['user_id'],
                            $transaction_id,
                            'Your delivery is on the way',
                            'Your rider has started the delivery. You can open Track Order to follow its progress.',
                            'delivery'
                        );
                    }
                    // A new run must not show coordinates left over from an older run.
                    $clearLocation = $conn->prepare("DELETE FROM rider_locations WHERE transaction_id = ? AND rider_id = ?");
                    $clearLocation->bind_param('ss', $transaction_id, $rider_id);
                    $clearLocation->execute();
                    $_SESSION['rider_auto_track'] = $transaction_id;
                }
            } else {
                $_SESSION['rider_flash'] = 'Action failed: ' . $conn->error;
            }
        }
    }

    header('Location: dashboard.php');
    exit();
}

$rider_flash = $_SESSION['rider_flash'] ?? '';
unset($_SESSION['rider_flash']);
$rider_auto_track = $_SESSION['rider_auto_track'] ?? '';
unset($_SESSION['rider_auto_track']);

/* ---------- notifications (new assignment alerts) ---------- */

$notifications = [];
$stmt = $conn->prepare("SELECT id, transaction_id, title, message, created_at
    FROM rider_notifications WHERE rider_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param('s', $rider_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $notifications[] = $row;
}
if (!empty($notifications)) {
    $stmt = $conn->prepare("UPDATE rider_notifications SET is_read = 1 WHERE rider_id = ? AND is_read = 0");
    $stmt->bind_param('s', $rider_id);
    $stmt->execute();
}

/* ---------- the rider's assigned deliveries (only these — no open pool) ---------- */

$rider_where = $transactions_has_assigned_rider
    ? "(t.rider_id = ? OR t.assigned_rider = ?)"
    : "t.rider_id = ?";

$sql = "SELECT
        t.transaction_id,
        t.amount,
        t.delivery_status,
        t.description,
        t.water_type,
        t.quantity,
        t.price_per_unit,
        t.payment_method,
        t.notes,
        t.user_id,
        t.created_at,
        t.updated_at,
        COALESCE(u.full_name, 'Unknown Customer') AS full_name,
        COALESCE(u.contact_number, '-') AS contact_number,
        COALESCE(u.email, '-') AS email,
        COALESCE(u.address, 'No address provided') AS address
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'approved'
      AND {$rider_where}
      AND COALESCE(NULLIF(t.delivery_status, ''), 'assigned') IN ('assigned', 'pending', 'on_way', 'delivered')
    ORDER BY CASE COALESCE(NULLIF(t.delivery_status,''),'assigned')
                 WHEN 'on_way' THEN 1
                 WHEN 'assigned' THEN 2
                 WHEN 'pending' THEN 3
                 WHEN 'delivered' THEN 4
                 ELSE 5
             END,
             t.created_at DESC";

$stmt = $conn->prepare($sql);
if ($transactions_has_assigned_rider) {
    $stmt->bind_param('ss', $rider_id, $rider_id);
} else {
    $stmt->bind_param('s', $rider_id);
}
$stmt->execute();
$res = $stmt->get_result();

$deliveries = [];
while ($row = $res->fetch_assoc()) {
    $status = trim((string)($row['delivery_status'] ?? ''));
    if ($status === '' || $status === 'pending') {
        $status = 'assigned';
    }
    $quantity = (int)($row['quantity'] ?? 1);
    $price_per_unit = isset($row['price_per_unit']) ? (float)$row['price_per_unit'] : 0;
    $item_summary = ucfirst($row['water_type'] ?? 'item');
    $item_summary .= ' · ' . $quantity . ' pcs';
    if ($price_per_unit > 0) {
        $item_summary .= ' · ₱' . number_format($price_per_unit, 2) . ' each';
    }
    $payment_method = strtolower(trim((string)($row['payment_method'] ?? 'cash')));
    $payment_labels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya'];
    $payment_label = $payment_labels[$payment_method] ?? 'Cash';
    $deliveries[] = [
        'id' => $row['transaction_id'],
        'customer' => $row['full_name'],
        'phone' => $row['contact_number'],
        'email' => $row['email'],
        'address' => $row['address'],
        'amount' => (float)$row['amount'],
        'status' => $status,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'description' => $row['description'],
        'water_type' => $row['water_type'],
        'quantity' => $quantity,
        'price_per_unit' => $price_per_unit,
        'payment_method' => $payment_method,
        'payment_label' => $payment_label,
        'notes' => $row['notes'],
        'user_id' => $row['user_id'],
        'item_summary' => $item_summary,
    ];
}

$active_deliveries = array_values(array_filter($deliveries, fn($d) => $d['status'] !== 'delivered'));
$completed_deliveries = array_values(array_filter($deliveries, fn($d) => $d['status'] === 'delivered'));
$delivery_history_by_day = [];
foreach ($completed_deliveries as $delivery) {
    $day = date('Y-m-d', strtotime($delivery['updated_at']));
    $delivery_history_by_day[$day][] = $delivery;
}

/* ---------- earnings: today + total (per rider preference, kept simple) ---------- */

$rider_col = $transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id";

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS trips
    FROM transactions WHERE {$rider_col} = ? AND delivery_status = 'delivered' AND DATE(updated_at) = CURRENT_DATE");
$stmt->bind_param('s', $rider_id);
$stmt->execute();
$today_row = $stmt->get_result()->fetch_assoc();
$earnings_today = (float)($today_row['total'] ?? 0);
$trips_today = (int)($today_row['trips'] ?? 0);

$display_name = $_SESSION['rider_auth_full_name'] ?? ($_SESSION['full_name'] ?? 'Rider');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Deliveries — HydroMIS Rider Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" rel="stylesheet">
<style>
:root{
  --ink:#16202b;
  --paper:#f7f5f0;
  --card:#ffffff;
  --amber:#f59e0b;
  --amber-dark:#b45309;
  --teal:#0f766e;
  --green:#16a34a;
  --steel:#64748b;
  --line:#e7e2d6;
  --radius:14px;
}
*{box-sizing:border-box;}
html{scroll-behavior:smooth;scroll-padding-top:82px}
body{
  margin:0;
  background:var(--paper);
  color:var(--ink);
  font-family:'Inter',sans-serif;
  -webkit-font-smoothing:antialiased;
}
.mono{font-family:'JetBrains Mono',monospace;}
.display{font-family:'Barlow Condensed',sans-serif; font-weight:700; letter-spacing:.01em;}

/* ---- top bar ---- */
.topbar{
  background:var(--ink);
  color:#fff;
  padding:12px 18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
  position:sticky; top:0; z-index:40;
}
.topbar .brand{display:flex; align-items:center; gap:10px;}
.topbar .brand-logo{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;border-radius:12px;background:linear-gradient(145deg,rgba(255,255,255,.12),rgba(255,255,255,.04));border:1px solid rgba(125,211,252,.18);box-shadow:0 8px 22px rgba(0,0,0,.2)}
.topbar .brand-logo img{display:block;width:37px;height:37px;object-fit:contain;filter:drop-shadow(0 4px 6px rgba(0,0,0,.28))}
.topbar .brand-text{display:flex; flex-direction:column; line-height:1.15;}
.topbar .brand-text b{font-family:'Barlow Condensed',sans-serif; font-size:20px; letter-spacing:.02em;}
.topbar .brand-text span{font-size:11px; color:#7dd3fc;}
.topbar .rider-chip{display:flex;align-items:center;justify-content:flex-end;gap:9px;min-width:0}
.topbar .rider-identity{min-width:0;text-align:right}
.topbar .rider-name{display:block;color:#fff;font-size:14px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar .rider-id-row{display:flex;align-items:center;justify-content:flex-end;gap:5px;margin-top:3px}
.topbar .rider-id{color:#b8c7d5;font-family:'JetBrains Mono',monospace;font-size:10px;white-space:nowrap}
.copy-rider-id{display:grid;place-items:center;width:22px;height:22px;padding:0;border:0;border-radius:6px;background:transparent;color:#7dd3fc;cursor:pointer}
.copy-rider-id:hover,.copy-rider-id:focus-visible{background:rgba(125,211,252,.12);color:#fff;outline:none}
.rider-menu{position:relative}.menu-toggle{display:grid;place-items:center;width:38px;height:38px;padding:0;border:0;background:transparent;color:#e2e8f0;font-size:19px;cursor:pointer}.menu-toggle:hover,.menu-toggle:focus-visible{color:#fff;outline:none}.rider-menu.open .menu-toggle{color:#7dd3fc}.rider-menu-panel{position:absolute;right:0;top:calc(100% + 10px);display:none;min-width:220px;padding:7px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 16px 36px rgba(15,23,42,.22);z-index:70}.rider-menu.open .rider-menu-panel{display:block}.rider-menu-panel button,.rider-menu-panel a{display:flex;align-items:center;gap:10px;width:100%;padding:10px;border:0;border-radius:8px;background:transparent;color:#253344;text-align:left;text-decoration:none;font:600 13px 'Inter',sans-serif;cursor:pointer}.rider-menu-panel button:hover,.rider-menu-panel a:hover,.rider-menu-panel button:focus-visible,.rider-menu-panel a:focus-visible{background:#f1f5f9;outline:none}.rider-menu-panel .menu-logout{color:#b91c1c}.rider-menu-panel .menu-logout:hover{background:#fef2f2}.rider-menu-panel .menu-divider{height:1px;margin:6px 2px;background:#e2e8f0}.notification-toggle{position:relative}.notification-toggle .notify-dot{width:7px;height:7px;border-radius:50%;background:#f59e0b}
.rider-menu{position:relative}.menu-toggle{display:grid;place-items:center;width:38px;height:38px;padding:0;border:0;background:transparent;color:#e2e8f0;font-size:19px;cursor:pointer}.menu-toggle:hover,.menu-toggle:focus-visible{color:#fff;outline:none}.rider-menu.open .menu-toggle{color:#7dd3fc}.rider-menu-panel{position:absolute;right:0;top:calc(100% + 10px);display:none;min-width:220px;padding:7px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 16px 36px rgba(15,23,42,.22);z-index:70}.rider-menu.open .rider-menu-panel{display:block}.rider-menu-panel button,.rider-menu-panel a{display:flex;align-items:center;gap:10px;width:100%;padding:10px;border:0;border-radius:8px;background:transparent;color:#253344;text-align:left;text-decoration:none;font:600 13px 'Inter',sans-serif;cursor:pointer}.rider-menu-panel button:hover,.rider-menu-panel a:hover,.rider-menu-panel button:focus-visible,.rider-menu-panel a:focus-visible{background:#f1f5f9;outline:none}.rider-menu-panel .menu-logout{color:#b91c1c}.rider-menu-panel .menu-logout:hover{background:#fef2f2}.rider-menu-panel .menu-divider{height:1px;margin:6px 2px;background:#e2e8f0}.menu-rider-id{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 10px 8px;color:#64748b;font:700 11px 'JetBrains Mono',monospace}.menu-rider-id .copy-rider-id{color:#0f766e}.notification-toggle{position:relative}.notification-toggle .notify-dot{width:7px;height:7px;border-radius:50%;background:#f59e0b}

/* ---- shell ---- */
.shell{max-width:720px; margin:0 auto; padding:16px 14px 40px;}

/* ---- notification banner ---- */
.notif{
  background:linear-gradient(135deg,#fff7ed,#ffedd5);
  border:1px solid #fdba74;
  border-radius:var(--radius);
  padding:12px 14px; margin-bottom:14px;
}
.notif .notif-head{font-weight:700; color:var(--amber-dark); font-size:13px; display:flex; align-items:center; gap:6px;}
.notif .notif-item{background:rgba(255,255,255,.75); border-radius:8px; padding:8px 10px; margin-top:8px;}
.notif .notif-item b{font-size:13px;}
.notif .notif-item div{font-size:12px; color:#7c5b23; margin-top:2px;}
.assignment-toast{position:fixed;z-index:100;top:82px;right:14px;width:min(360px,calc(100% - 28px));padding:14px 16px;border:1px solid #a7f3d0;border-radius:14px;background:#fff;color:var(--ink);box-shadow:0 18px 45px rgba(15,23,42,.2);animation:toastIn .25s ease}.assignment-toast b{display:block;margin-bottom:3px;color:var(--teal);font-size:13px}.assignment-toast p{margin:0;color:var(--steel);font-size:12px;line-height:1.45}@keyframes toastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}

/* ---- earnings strip ---- */
.earnings-strip{
  display:flex; background:var(--card); border-radius:var(--radius);
  border:1px solid var(--line); overflow:hidden; margin-bottom:18px;
  box-shadow:0 1px 2px rgba(16,32,43,.04);
}
.earnings-strip .cell{flex:1; padding:14px 12px; text-align:center;}
.earnings-strip .cell + .cell{border-left:1px solid var(--line);}
.earnings-strip .cell .amt{font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:24px; color:var(--green);}
.earnings-strip .cell .lbl{font-size:11px; color:var(--steel); text-transform:uppercase; letter-spacing:.06em; margin-top:2px;}
.earnings-strip .cell .sub{font-size:11px; color:var(--steel); margin-top:2px;}

/* Road-ready rider dashboard */
.shift-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
.metric-card{min-width:0;padding:14px;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 6px 18px rgba(16,32,43,.05)}
.metric-icon{display:grid;place-items:center;width:30px;height:30px;margin-bottom:10px;border-radius:9px;background:#ecfdf5;color:var(--teal)}
.metric-value{font-family:'Barlow Condensed',sans-serif;font-size:24px;font-weight:700;line-height:1;color:var(--ink)}
.metric-label{margin-top:5px;color:var(--steel);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.metric-card.primary{background:linear-gradient(145deg,#0f766e,#0a5f59);border-color:transparent;color:#fff}
.metric-card.primary .metric-icon{background:rgba(255,255,255,.14);color:#fff}.metric-card.primary .metric-value,.metric-card.primary .metric-label{color:#fff}

/* ---- section heading ---- */
.section-head{
  display:flex; align-items:center; justify-content:space-between;
  margin:22px 0 10px;
}
.section-head h2{
  font-family:'Barlow Condensed',sans-serif; font-size:20px; font-weight:700;
  margin:0; display:flex; align-items:center; gap:8px;
}
.count-badge{
  background:var(--ink); color:#fff; font-size:12px; font-weight:600;
  padding:2px 9px; border-radius:20px;
}

/* ---- delivery card ---- */
.card{
  background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
  padding:14px; margin-bottom:12px;
  box-shadow:0 1px 2px rgba(16,32,43,.04);
}
.card-top{display:flex; justify-content:space-between; align-items:flex-start; gap:10px;}
.card-id{font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--steel);}
.card-customer{font-weight:700; font-size:15px; margin-top:2px;}
.card-amount{font-family:'JetBrains Mono',monospace; font-weight:700; color:var(--green); font-size:16px; white-space:nowrap;}

.status-badge{
  display:inline-flex; align-items:center; gap:5px;
  font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px;
  text-transform:uppercase; letter-spacing:.03em;
}
.status-assigned{background:#dbeafe; color:#1d4ed8;}
.status-on_way{background:#fef3c7; color:#b45309;}
.status-delivered{background:#dcfce7; color:#15803d;}

/* route-line: the signature element, compact form inside cards */
.route-line{display:flex; align-items:center; gap:6px; margin:12px 0 10px;}
.route-line .seg{flex:1; height:3px; background:var(--line); border-radius:2px; position:relative;}
.route-line .seg.done{background:var(--teal);}
.route-line .node{
  width:11px; height:11px; border-radius:50%; background:var(--line);
  border:2px solid #fff; box-shadow:0 0 0 1px var(--line); flex-shrink:0;
}
.route-line .node.done{background:var(--teal); box-shadow:0 0 0 1px var(--teal);}
.route-line .node.current{background:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.25);}
.delivery-flow{display:grid;grid-template-columns:auto 1fr auto 1fr auto;align-items:start;margin:16px 0 14px}
.flow-step{position:relative;display:flex;min-width:58px;flex-direction:column;align-items:center;gap:6px;color:#94a3b8;font-size:9px;font-weight:700;text-align:center}
.flow-step .flow-node{display:grid;place-items:center;width:28px;height:28px;border:2px solid #e2e8f0;border-radius:50%;background:#f8fafc;color:#94a3b8;font-size:10px;transition:.2s ease}
.flow-step.done,.flow-step.current{color:var(--ink)}.flow-step.done .flow-node{border-color:var(--teal);background:var(--teal);color:#fff}.flow-step.current .flow-node{border-color:var(--amber);background:#fff7ed;color:var(--amber-dark);box-shadow:0 0 0 4px rgba(245,158,11,.13)}
.flow-connector{height:3px;margin-top:13px;border-radius:4px;background:#e2e8f0}.flow-connector.done{background:var(--teal)}

.card-address{font-size:13px; color:var(--steel); display:flex; gap:6px; align-items:flex-start; margin-bottom:10px;}
.card-address i{margin-top:2px; color:var(--teal);}

.card-actions{display:flex; flex-wrap:wrap; gap:8px;}
.btn{
  border:none; border-radius:8px; padding:9px 14px; font-size:13px; font-weight:600;
  cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:'Inter',sans-serif;
  transition:transform .1s ease;
}
.btn:active{transform:scale(.97);}
.btn-primary{background:var(--amber); color:#fff;}
.btn-secondary{background:var(--ink); color:#fff;}
.btn-ghost{background:#f1f0ea; color:var(--ink);}
.btn-track{background:var(--teal); color:#fff;}
.delay-report{width:100%}.delay-report summary{list-style:none}.delay-report summary::-webkit-details-marker{display:none}.btn-delay{width:100%;justify-content:center;background:#fff7ed;color:#b45309;border:1px solid #fed7aa}.delay-form{display:grid;gap:8px;margin-top:8px;padding:12px;border:1px solid #fed7aa;border-radius:10px;background:#fffbeb}.delay-form label{font-size:11px;font-weight:700;color:#7c4a03}.delay-form select,.delay-form input{width:100%;min-width:0;padding:10px;border:1px solid #e5d3ad;border-radius:8px;background:#fff;color:var(--ink);font:12px 'Inter',sans-serif}.delay-form .btn{justify-content:center;background:#f59e0b;color:#fff}

.empty-state{
  text-align:center; padding:38px 20px; color:var(--steel);
  background:var(--card); border:1px dashed var(--line); border-radius:var(--radius);
}
.empty-state i{font-size:26px; color:#cbd1d9; margin-bottom:8px; display:block;}
.empty-state h3{margin:4px 0 5px;color:var(--ink);font-size:15px}.empty-state p{max-width:430px;margin:0 auto;font-size:12.5px;line-height:1.5}
.waiting-status{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:7px 10px;border-radius:20px;background:#ecfdf5;color:#087d69;font-size:11px;font-weight:700}
.waiting-status::before{content:'';width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.12);animation:waitingPulse 1.8s infinite}
@keyframes waitingPulse{50%{opacity:.45}}

/* ---- completed list (compact) ---- */
.completed-row{
  display:flex; justify-content:space-between; align-items:center;
  padding:10px 14px; background:var(--card); border:1px solid var(--line);
  border-radius:10px; margin-bottom:8px; font-size:13px;
}
.completed-row .c-left b{display:block; font-size:13.5px;}
.completed-row .c-left span{font-size:11.5px; color:var(--steel);}
.completed-row .c-right{text-align:right;}
.completed-row .c-right b{color:var(--green); font-family:'JetBrains Mono',monospace;}
.completed-row .c-right span{display:block; font-size:11px; color:var(--steel);}
.completed-row{transition:transform .15s ease,box-shadow .15s ease}.completed-row:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(16,32,43,.06)}
.completed-row .destination{display:block;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#486477;font-size:11px}

#home,#earnings,#deliveries,#completed{scroll-margin-top:82px}.history-day{margin:18px 0 8px;font-size:13px;color:var(--steel);font-weight:700}.history-day:first-of-type{margin-top:0}
.history-panel{display:none}.deliveries-panel,.earnings-panel{display:block}.history-panel .section-head,.deliveries-panel .section-head,.earnings-panel .section-head{margin-top:22px}.history-intro{margin:-3px 0 14px;color:var(--steel);font-size:12px}

/* ---- tracking drawer ---- */
.drawer-overlay{
  position:fixed; inset:0; background:rgba(15,23,32,.5);
  display:none; z-index:60; align-items:flex-end; justify-content:center;
}
.drawer-overlay.open{display:flex;}
.drawer{
  background:var(--paper); width:100%; max-width:720px; max-height:92vh;
  border-radius:20px 20px 0 0; overflow-y:auto; animation:slideUp .25s ease-out;
}
@keyframes slideUp{from{transform:translateY(30px); opacity:.6;} to{transform:translateY(0); opacity:1;}}
.drawer-handle{width:40px; height:4px; background:var(--line); border-radius:3px; margin:10px auto;}
.drawer-head{padding:0 18px 10px; display:flex; justify-content:space-between; align-items:flex-start;}
.drawer-head h3{font-family:'Barlow Condensed',sans-serif; font-size:22px; margin:0;}
.drawer-head .drawer-sub{font-size:12.5px; color:var(--steel); margin-top:2px;}
.drawer-close{background:none; border:none; font-size:18px; color:var(--steel); cursor:pointer; padding:4px;}

#trackMap{height:230px; width:100%; background:#e2e8f0;}
.map-toggle-wrap{padding:0 18px 10px;}
.map-toggle{width:100%; display:flex; align-items:center; justify-content:space-between; border:1px solid var(--line); border-radius:12px; padding:10px 12px; background:var(--card); color:var(--ink); font-size:13px; font-weight:700; cursor:pointer;}
.map-toggle i{color:var(--teal);}
.map-toggle .toggle-chevron{transition:transform .2s ease;}
.map-toggle[aria-expanded="true"] .toggle-chevron{transform:rotate(180deg);}
.map-body{position:relative;}
.map-body[hidden]{display:none;}
.map-gps-control{position:absolute; top:10px; right:10px; z-index:1001; box-shadow:0 3px 10px rgba(16,32,43,.2);}

.delivery-details{
  display:grid; grid-template-columns:1fr 1fr; gap:12px 18px; margin-bottom:18px;
  padding:14px; background:var(--card); border:1px solid var(--line); border-radius:16px;
}
.detail-group{min-width:0;}
.detail-group .label{font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--steel); margin-bottom:4px;}
.detail-group .value{font-size:13px; color:var(--ink); line-height:1.4;}
.detail-group .value::before{content:''; display:block; height:0;}

.message-panel, .feedback-panel{
  background:var(--card); border:1px solid var(--line); border-radius:16px; padding:14px;
  margin-bottom:14px;
}
.message-head, .feedback-head{
  font-size:13px; font-weight:700; margin-bottom:10px; color:var(--ink);
}
.message-head{margin-bottom:0;}
.message-toggle{width:100%; display:flex; align-items:center; justify-content:space-between; background:none; border:0; padding:0; color:var(--ink); cursor:pointer; text-align:left;}
.message-toggle i{color:var(--teal); transition:transform .2s ease;}
.message-toggle[aria-expanded="true"] i{transform:rotate(180deg);}
.message-body{margin-top:10px;}
.message-body[hidden]{display:none;}
.message-list{max-height:220px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right:4px;}
.message-item{display:flex; gap:10px;}
.message-item.rider{justify-content:flex-end;}
.message-item .bubble{max-width:76%; padding:10px 12px; border-radius:16px; font-size:13px; line-height:1.4;}
.message-item.rider .bubble{background:var(--teal); color:#fff; border-bottom-right-radius:4px;}
.message-item.customer .bubble{background:#f1f0ea; color:var(--ink); border-bottom-left-radius:4px;}
.message-item .meta{font-size:10px; color:var(--steel); margin-top:4px;}
.message-input-row{display:flex; gap:8px; margin-top:12px;}
.message-input-row input{flex:1; border:1px solid var(--line); border-radius:12px; padding:10px 12px; font-size:13px;}
.message-input-row input:focus{outline:none; border-color:var(--teal);}

.feedback-content{font-size:13px; color:var(--ink); line-height:1.5; min-height:60px;}.feedback-rating{display:flex;align-items:center;gap:4px;margin-bottom:7px;font-size:20px;color:#d7dce2}.feedback-rating .filled{color:var(--amber)}.feedback-rating .rating-value{margin-left:6px;color:var(--ink);font:700 12px 'Inter',sans-serif}.feedback-empty{color:var(--steel);font-size:12px}

.route-line.expanded{padding:18px 20px; margin:0;}
.route-line.expanded .step{display:flex; flex-direction:column; align-items:center; flex:1; position:relative;}
.route-line.expanded .step .lbl{font-size:11px; margin-top:6px; color:var(--steel); text-align:center; font-weight:600;}
.route-line.expanded .step.done .lbl, .route-line.expanded .step.current .lbl{color:var(--ink);}
.route-line.expanded .node{width:16px; height:16px;}
.route-line.expanded .seg.done{background:var(--teal)!important}

.drawer-body{padding:16px 18px 26px;}
@media (max-width:480px){
  .topbar{padding:9px 10px;gap:8px}.topbar .brand{gap:7px}.topbar .brand-logo{width:38px;height:38px;flex-basis:38px;border-radius:10px}.topbar .brand-logo img{width:34px;height:34px}.topbar .brand-text b{font-size:18px}.topbar .brand-text span{font-size:10px}.topbar .rider-chip{gap:6px}.topbar .rider-identity{max-width:128px}.topbar .rider-name{font-size:12px}.topbar .rider-id{font-size:8px}.menu-toggle{width:34px;height:34px}.shift-summary{gap:7px}.metric-card{padding:11px}.metric-value{font-size:21px}.metric-icon{width:27px;height:27px;margin-bottom:8px}
  .card-top{flex-direction:column;}
  .card-amount{font-size:15px;}
  .card-top>div:last-child{text-align:left!important;display:flex;align-items:center;gap:8px}.card-actions{display:grid;grid-template-columns:1fr 1fr}.card-actions form{display:block!important}.card-actions .btn{width:100%;justify-content:center;min-height:44px}.card-actions>.delay-report,.card-actions>.btn-ghost{grid-column:1 / -1}.card-actions>.btn-ghost{order:1}.card-actions>.delay-report{order:2}.completed-row .destination{max-width:210px}
}
</style>
<script src="../js/ui-protection.js" defer></script>
</head>
<body id="home">

<div class="topbar">
  <div class="brand">
    <span class="brand-logo"><img src="../imagess/hydromis-logo-v2.png?v=20260802" alt="HydroMIS logo"></span>
    <div class="brand-text">
      <b>HydroMIS</b>
      <span>Rider Portal</span>
    </div>
  </div>
  <div class="rider-chip">
    <div class="rider-identity">
      <strong class="rider-name"><?php echo htmlspecialchars($display_name); ?></strong>
    </div>
    <div class="rider-menu" id="riderMenu">
      <button class="menu-toggle" id="menuToggle" type="button" aria-expanded="false" aria-controls="riderMenuPanel" aria-label="Open rider menu"><i class="fas fa-bars"></i></button>
      <div class="rider-menu-panel" id="riderMenuPanel">
        <div class="menu-rider-id"><span id="riderIdValue"><?php echo htmlspecialchars($rider_id); ?></span><button class="copy-rider-id" type="button" onclick="copyRiderId(this)" aria-label="Copy rider ID" title="Copy rider ID"><i class="far fa-copy"></i></button></div>
        <div class="menu-divider"></div>
        <button class="notification-toggle" id="notificationToggle" type="button" onclick="enablePushNotifications()"><i class="fas fa-bell"></i><span>Notifications</span><span class="notify-dot" id="notifyDot" aria-hidden="true"></span></button>
        <a href="history.php" class="menu-history"><i class="fas fa-clock-rotate-left"></i><span>Delivery history by day</span></a>
        <div class="menu-divider"></div>
        <a href="../logout.php" class="menu-logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
      </div>
    </div>
  </div>
</div>

<div class="shell">

  <div class="dashboard-primary">

  <?php if (!empty($notifications)): ?>
  <div class="notif">
    <div class="notif-head"><i class="fas fa-bell"></i> New delivery assigned to you</div>
    <?php foreach ($notifications as $n): ?>
      <div class="notif-item">
        <b><?php echo htmlspecialchars($n['title']); ?></b>
        <div><?php echo htmlspecialchars($n['message']); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <section class="earnings-panel" id="earnings" aria-labelledby="earningsTitle">
  <div class="section-head">
    <h2 id="earningsTitle"><i class="fas fa-wallet" style="color:var(--teal); font-size:17px;"></i>My Earnings</h2>
  </div>
  <div class="shift-summary" aria-label="Today's rider earnings summary">
    <div class="metric-card primary">
      <div class="metric-icon"><i class="fas fa-wallet"></i></div>
      <div class="metric-value">₱<?php echo number_format($earnings_today, 2); ?></div>
      <div class="metric-label">Delivered amount today</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon"><i class="fas fa-check"></i></div>
      <div class="metric-value"><?php echo $trips_today; ?></div>
      <div class="metric-label">Completed</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon"><i class="fas fa-motorcycle"></i></div>
      <div class="metric-value"><?php echo count($active_deliveries); ?></div>
      <div class="metric-label">Active now</div>
    </div>
  </div>
  </section>

  <section class="deliveries-panel" id="deliveries" aria-labelledby="deliveriesTitle">
  <div class="section-head">
    <h2 id="deliveriesTitle"><i class="fas fa-route" style="color:var(--teal); font-size:17px;"></i>New Deliveries</h2>
    <span class="count-badge"><?php echo count($active_deliveries); ?> active</span>
  </div>

  <?php if (empty($active_deliveries)): ?>
    <div class="empty-state">
      <i class="fas fa-box-open"></i>
      <h3>You're all caught up</h3>
      <p>New assignments from staff will appear automatically while you remain online.</p>
      <span class="waiting-status">Waiting for assignments</span>
    </div>
  <?php else: foreach ($active_deliveries as $d):
    $status = $d['status'];
    $status_label = ['assigned' => 'Assigned', 'on_way' => 'On the Way', 'delivered' => 'Delivered'][$status] ?? ucfirst($status);
    $step_index = ['assigned' => 0, 'on_way' => 1, 'delivered' => 2][$status] ?? 0;
  ?>
    <div class="card" data-id="<?php echo htmlspecialchars($d['id']); ?>">
      <div class="card-top">
        <div>
          <div class="card-id">#<?php echo htmlspecialchars($d['id']); ?></div>
          <div class="card-customer"><?php echo htmlspecialchars($d['customer']); ?></div>
        </div>
        <div style="text-align:right;">
          <div class="card-amount">₱<?php echo number_format($d['amount'], 2); ?></div>
        </div>
      </div>

      <div class="delivery-flow" role="list" aria-label="Delivery progress: <?php echo htmlspecialchars($status_label); ?>">
        <div class="flow-step <?php echo $step_index === 0 ? 'current' : 'done'; ?>" role="listitem">
          <span class="flow-node"><i class="fas fa-clipboard-check"></i></span><span>Assigned</span>
        </div>
        <div class="flow-connector <?php echo $step_index >= 1 ? 'done' : ''; ?>"></div>
        <div class="flow-step <?php echo $step_index === 1 ? 'current' : ($step_index > 1 ? 'done' : ''); ?>" role="listitem">
          <span class="flow-node"><i class="fas fa-motorcycle"></i></span><span>On the Way</span>
        </div>
        <div class="flow-connector <?php echo $step_index >= 2 ? 'done' : ''; ?>"></div>
        <div class="flow-step <?php echo $step_index === 2 ? 'done' : ''; ?>" role="listitem">
          <span class="flow-node"><i class="fas fa-check"></i></span><span>Delivered</span>
        </div>
      </div>

      <div class="card-address"><i class="fas fa-map-marker-alt"></i><span><?php echo htmlspecialchars($d['address']); ?></span></div>

      <div class="card-actions">
        <button class="btn btn-track" onclick='openTracking(<?php echo json_encode($d); ?>)'>
          <i class="fas fa-location-arrow"></i> Details
        </button>

        <?php if ($status === 'assigned'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="start_delivery">
            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($d['id']); ?>">
            <button class="btn btn-secondary" type="submit"><i class="fas fa-play"></i> Start Delivery</button>
          </form>
        <?php elseif ($status === 'on_way'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($d['id']); ?>">
            <button class="btn btn-secondary" type="submit"><i class="fas fa-check"></i> Mark Delivered</button>
          </form>
          <details class="delay-report">
            <summary class="btn btn-delay"><i class="fas fa-clock"></i> Report delivery delay</summary>
            <form method="POST" class="delay-form">
              <input type="hidden" name="action" value="report_delay">
              <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($d['id']); ?>">
              <label>Reason for delay
                <select name="delay_reason" required>
                  <option value="traffic">Heavy traffic</option>
                  <option value="weather">Bad weather</option>
                  <option value="vehicle">Vehicle problem</option>
                  <option value="refilling">Delay at the water refilling station</option>
                  <option value="location">Difficulty locating the address</option>
                  <option value="other">Other unexpected delay</option>
                </select>
              </label>
              <label>Additional update (optional)
                <input type="text" name="delay_note" maxlength="180" placeholder="Example: Expected arrival in 15 minutes">
              </label>
              <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> Notify customer</button>
            </form>
          </details>
        <?php endif; ?>

        <a class="btn btn-ghost" href="tel:<?php echo htmlspecialchars($d['phone']); ?>"><i class="fas fa-phone"></i> Call</a>
      </div>
    </div>
  <?php endforeach; endif; ?>
  </section>

  </div>

  <section class="history-panel" id="completed" aria-labelledby="historyTitle">
  <div class="section-head">
    <h2 id="historyTitle"><i class="fas fa-clock-rotate-left" style="color:var(--green); font-size:17px;"></i>Delivery History</h2>
  </div>
  <p class="history-intro">Your completed deliveries, grouped by delivery day.</p>

  <?php if (empty($delivery_history_by_day)): ?>
    <div class="empty-state">
      <i class="fas fa-inbox"></i>
      <p>No completed deliveries yet. Your delivery history will appear here.</p>
    </div>
  <?php else: foreach ($delivery_history_by_day as $day => $day_deliveries): ?>
    <div class="history-day"><?php echo date('l, F j, Y', strtotime($day)); ?></div>
    <?php foreach ($day_deliveries as $d): ?>
    <div class="completed-row">
      <div class="c-left">
        <b><?php echo htmlspecialchars($d['customer']); ?></b>
        <span class="destination"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($d['address']); ?></span>
        <span>#<?php echo htmlspecialchars($d['id']); ?> · <?php echo date('h:i A', strtotime($d['created_at'])); ?></span>
      </div>
      <div class="c-right">
        <b>₱<?php echo number_format($d['amount'], 2); ?></b>
        <span>Delivered</span>
      </div>
    </div>
    <?php endforeach; endforeach; endif; ?>
  </section>

</div>

<!-- Tracking drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="if(event.target===this) closeTracking()">
  <div class="drawer">
    <div class="drawer-handle"></div>
    <div class="drawer-head">
      <div>
        <h3 id="drawerCustomer">Customer</h3>
        <div class="drawer-sub" id="drawerAddress">Address</div>
      </div>
      <button class="drawer-close" onclick="closeTracking()"><i class="fas fa-times"></i></button>
    </div>

    <div class="map-toggle-wrap">
      <button class="map-toggle" id="mapToggleBtn" type="button" aria-expanded="false" aria-controls="mapBody" onclick="toggleMap()">
        <span><i class="fas fa-map-marked-alt" aria-hidden="true" style="margin-right:7px;"></i>Map</span>
        <i class="fas fa-chevron-down toggle-chevron" aria-hidden="true"></i>
      </button>
    </div>
    <div class="map-body" id="mapBody" hidden>
      <div id="trackMap"></div>
      <button class="btn btn-track map-gps-control" id="gpsToggleBtn" type="button" onclick="toggleGPS()"><i class="fas fa-satellite-dish"></i> Share GPS</button>
    </div>

    <div class="route-line expanded" id="drawerRoute">
      <div class="step" data-step="0"><div class="node"></div><div class="lbl">Assigned</div></div>
      <div class="seg" style="flex:1;height:3px;background:var(--line);margin-top:8px;"></div>
      <div class="step" data-step="1"><div class="node"></div><div class="lbl">On the Way</div></div>
      <div class="seg" style="flex:1;height:3px;background:var(--line);margin-top:8px;"></div>
      <div class="step" data-step="2"><div class="node"></div><div class="lbl">Delivered</div></div>
    </div>

    <div class="drawer-body">
      <div class="delivery-details" id="deliveryDetails">
        <div class="detail-group">
          <div class="label">Order</div>
          <div class="value" id="detailDescription">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Items</div>
          <div class="value" id="detailItems">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Customer</div>
          <div class="value" id="detailCustomer">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Phone</div>
          <div class="value" id="detailPhone">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Email</div>
          <div class="value" id="detailEmail">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Payment Method</div>
          <div class="value" id="detailPaymentMethod">–</div>
        </div>
        <div class="detail-group">
          <div class="label">Notes</div>
          <div class="value" id="detailNotes">–</div>
        </div>
      </div>

      <div class="message-panel">
        <div class="message-head">
          <button class="message-toggle" id="messageToggleBtn" type="button" aria-expanded="false" aria-controls="messageBody" onclick="toggleMessages()">
            <span>Messages</span><i class="fas fa-chevron-down" aria-hidden="true"></i>
          </button>
        </div>
        <div class="message-body" id="messageBody" hidden>
          <div class="message-list" id="messageList">No messages yet.</div>
          <div class="message-input-row">
            <input type="text" id="messageInput" placeholder="Type a message to the customer" />
            <button class="btn btn-primary" id="sendMessageBtn">Send</button>
          </div>
        </div>
      </div>

      <div class="feedback-panel">
        <div class="feedback-head">Customer Feedback</div>
        <div class="feedback-content" id="feedbackContent"><div class="feedback-rating" aria-label="No customer rating yet"><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></div><div class="feedback-empty">Awaiting the customer's rating.</div></div>
      </div>

      <div class="card-actions" id="drawerActions"></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
/* ---------- rider assignment notifications ---------- */
const initialAssignmentNotifications = <?php echo json_encode($notifications, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const notificationToggle = document.getElementById('notificationToggle');
const notifyDot = document.getElementById('notifyDot');
const riderMenu = document.getElementById('riderMenu');
const menuToggle = document.getElementById('menuToggle');

function closeRiderMenu(){
  riderMenu.classList.remove('open');
  menuToggle.setAttribute('aria-expanded', 'false');
}

menuToggle.addEventListener('click', () => {
  const isOpen = riderMenu.classList.toggle('open');
  menuToggle.setAttribute('aria-expanded', String(isOpen));
});
document.addEventListener('click', (event) => {
  if (!riderMenu.contains(event.target)) closeRiderMenu();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeRiderMenu();
});
document.querySelector('.menu-history').addEventListener('click', closeRiderMenu);

function syncNotificationButton(){
  const supported = 'Notification' in window;
  const granted = supported && Notification.permission === 'granted';
  notificationToggle.classList.toggle('enabled', granted);
  notificationToggle.setAttribute('aria-label', granted ? 'Assignment notifications enabled' : 'Enable assignment notifications');
  notificationToggle.title = supported ? (granted ? 'Assignment notifications enabled' : 'Enable assignment notifications') : 'Browser notifications are not supported';
  notifyDot.style.display = granted ? 'none' : 'block';
  notificationToggle.disabled = !supported;
}

function showAssignmentToast(alert){
  const existing = document.querySelector('.assignment-toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.className = 'assignment-toast';
  toast.setAttribute('role', 'status');
  toast.innerHTML = `<b><i class="fas fa-motorcycle"></i> ${escapeHtml(alert.title || 'New delivery assigned')}</b><p>${escapeHtml(alert.message || 'Open the dashboard to review your new assignment.')}</p>`;
  toast.title = 'Open new delivery';
  toast.addEventListener('click', () => window.location.reload());
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 8000);
}

function showAssignmentNotification(alert){
  showAssignmentToast(alert);
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const browserAlert = new Notification(alert.title || 'New HydroMIS delivery', {
    body: alert.message || 'A staff member assigned a delivery to you.',
    icon: '../imagess/hydromis-logo-v2.png',
    tag: `rider-assignment-${alert.transaction_id || alert.id}`,
    renotify: true
  });
  browserAlert.onclick = () => {
    window.focus();
    browserAlert.close();
    window.location.reload();
  };
}

async function enablePushNotifications(){
  if (!('Notification' in window)) return;
  try {
    const permission = await Notification.requestPermission();
    syncNotificationButton();
    if (permission === 'granted') {
      showAssignmentToast({title:'Notifications enabled', message:'You will be alerted when staff assigns a delivery.'});
    }
  } catch (error) {
    console.warn('Unable to enable assignment notifications.', error);
  }
}

async function pollAssignmentNotifications(){
  try {
    const response = await fetch('?ajax=notifications', {headers:{'Accept':'application/json'}, cache:'no-store'});
    if (!response.ok) return;
    const data = await response.json();
    (data.notifications || []).forEach(showAssignmentNotification);
  } catch (error) {
    console.warn('Assignment notification check failed.', error);
  }
}

syncNotificationButton();
if ('Notification' in window && Notification.permission === 'granted') initialAssignmentNotifications.forEach(showAssignmentNotification);
setInterval(pollAssignmentNotifications, 10000);

async function copyRiderId(button){
  const value = document.getElementById('riderIdValue')?.textContent.trim();
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    button.innerHTML = '<i class="fas fa-check"></i>';
    button.setAttribute('aria-label', 'Rider ID copied');
    setTimeout(() => {
      button.innerHTML = '<i class="far fa-copy"></i>';
      button.setAttribute('aria-label', 'Copy rider ID');
    }, 1600);
  } catch (error) {
    window.prompt('Copy your rider ID:', value);
  }
}

/* ---------- tracking drawer ---------- */
let map, marker, pollTimer, watchId = null;
let currentDelivery = null;
const RIDER_ID = <?php echo json_encode($rider_id); ?>;

const STATUS_LABEL = { assigned: 'Assigned', on_way: 'On the Way', delivered: 'Delivered' };
const STEP_INDEX = { assigned: 0, on_way: 1, delivered: 2 };

function openTracking(delivery){
  currentDelivery = delivery;
  setMessagesVisible(false);
  setMapVisible(false);
  document.getElementById('drawerOverlay').classList.add('open');
  updateDrawerRoute(delivery.status);
  updateDrawerActions(delivery);
  fillDeliveryDetails(delivery);
  setTimeout(() => initMap(delivery), 50);
  loadDeliveryInfo(delivery.id);
  pollLocation(delivery.id);
  pollTimer = setInterval(() => {
    pollLocation(delivery.id);
    loadDeliveryInfo(delivery.id);
  }, 6000);
  if (watchId === null && delivery.status !== 'delivered') {
    setTimeout(() => toggleGPS(), 250);
  }
}

function setMessagesVisible(visible){
  const body = document.getElementById('messageBody');
  const button = document.getElementById('messageToggleBtn');
  if (!body || !button) return;
  body.hidden = !visible;
  button.setAttribute('aria-expanded', visible ? 'true' : 'false');
}

function toggleMessages(){
  const button = document.getElementById('messageToggleBtn');
  setMessagesVisible(button?.getAttribute('aria-expanded') !== 'true');
}

function setMapVisible(visible){
  const body = document.getElementById('mapBody');
  const button = document.getElementById('mapToggleBtn');
  if (!body || !button) return;
  body.hidden = !visible;
  button.setAttribute('aria-expanded', visible ? 'true' : 'false');
  if (visible && map) setTimeout(() => map.invalidateSize(), 50);
}

function toggleMap(){
  const button = document.getElementById('mapToggleBtn');
  setMapVisible(button?.getAttribute('aria-expanded') !== 'true');
}

function closeTracking(){
  document.getElementById('drawerOverlay').classList.remove('open');
  if (pollTimer) clearInterval(pollTimer);
}

function updateDrawerRoute(status){
  const idx = STEP_INDEX[status] ?? 0;
  document.querySelectorAll('#drawerRoute .step').forEach(step => {
    const stepIdx = parseInt(step.dataset.step, 10);
    const isDone = stepIdx < idx || (stepIdx === idx && status === 'delivered');
    const isCurrent = stepIdx === idx && status !== 'delivered';
    step.classList.toggle('done', isDone);
    step.classList.toggle('current', isCurrent);
    const node = step.querySelector('.node');
    node.classList.toggle('done', isDone);
    node.classList.toggle('current', isCurrent);
  });
  document.querySelectorAll('#drawerRoute .seg').forEach((segment, segmentIdx) => {
    segment.classList.toggle('done', segmentIdx < idx);
  });
  document.getElementById('drawerRoute').setAttribute('aria-label', `Delivery progress: ${STATUS_LABEL[status] || 'Assigned'}`);
}

function updateDrawerActions(delivery){
  const box = document.getElementById('drawerActions');
  box.innerHTML = '';
  if (delivery.status === 'assigned'){
    box.innerHTML = `
      <form method="POST"><input type="hidden" name="action" value="start_delivery">
      <input type="hidden" name="transaction_id" value="${delivery.id}">
      <button class="btn btn-primary" type="submit"><i class="fas fa-play"></i> Start Delivery</button></form>`;
  } else if (delivery.status === 'on_way'){
    box.innerHTML = `
      <form method="POST"><input type="hidden" name="action" value="complete">
      <input type="hidden" name="transaction_id" value="${delivery.id}">
      <button class="btn btn-secondary" type="submit"><i class="fas fa-check"></i> Mark Delivered</button></form>`;
  } else {
    box.innerHTML = `<span class="status-badge status-delivered">Delivered</span>`;
  }
}

function fillDeliveryDetails(delivery){
  document.getElementById('drawerCustomer').textContent = delivery.customer;
  document.getElementById('drawerAddress').textContent = delivery.address;
  document.getElementById('detailDescription').textContent = delivery.description || 'No order description';
  document.getElementById('detailItems').textContent = delivery.item_summary || 'No item details';
  document.getElementById('detailCustomer').textContent = delivery.customer;
  document.getElementById('detailPhone').textContent = delivery.phone || '-';
  document.getElementById('detailEmail').textContent = delivery.email || '-';
  document.getElementById('detailPaymentMethod').textContent = delivery.payment_label || 'Cash';
  document.getElementById('detailNotes').textContent = delivery.notes || '-';
}

function fillMessages(messages){
  const list = document.getElementById('messageList');
  if (!messages || messages.length === 0) {
    list.innerHTML = '<div class="message-item"><div class="bubble">No messages yet.</div></div>';
    return;
  }
  list.innerHTML = messages.map(msg => {
    const isRider = msg.sender === RIDER_ID ? 'rider' : 'customer';
    return `<div class="message-item ${isRider}"><div>
      <div class="bubble">${escapeHtml(msg.message)}</div>
      <div class="meta">${isRider === 'rider' ? 'You' : 'Customer'} · ${formatDate(msg.created_at)}${msg.transaction_id && currentDelivery && msg.transaction_id !== currentDelivery.id ? ' · Previous order' : ''}</div>
    </div></div>`;
  }).join('');
}

function fillFeedback(feedback){
  const box = document.getElementById('feedbackContent');
  if (!feedback) {
    box.innerHTML = `<div class="feedback-rating" aria-label="No customer rating yet">${'<i class="far fa-star"></i>'.repeat(5)}</div><div class="feedback-empty">Awaiting the customer's rating.</div>`;
    return;
  }
  const rating = Math.max(1, Math.min(5, parseInt(feedback.rating, 10) || 0));
  const stars = Array.from({length: 5}, (_, index) => `<i class="fa${index < rating ? 's filled' : 'r'} fa-star"></i>`).join('');
  box.innerHTML = `<div class="feedback-rating" aria-label="Customer rated ${rating} out of 5 stars">${stars}<span class="rating-value">${rating}/5</span></div><div>${escapeHtml(feedback.feedback_message || 'No written feedback provided.')}</div><div class="meta" style="margin-top:8px;">${formatDate(feedback.created_at)}</div>`;
}

function escapeHtml(str){
  return String(str || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[c]);
}

function formatDate(value){
  const date = new Date(value);
  if (isNaN(date)) return value || '';
  return date.toLocaleString();
}

function initMap(delivery){
  const el = document.getElementById('trackMap');
  if (map) map.remove();
  el.innerHTML = '';
  map = L.map(el, { zoomControl: false }).setView([10.3157, 123.8854], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(map);
  marker = L.marker([10.3157, 123.8854]).addTo(map).bindPopup('Waiting for GPS signal…');
}

function pollLocation(transactionId){
  fetch(`?ajax=get_location&transaction_id=${encodeURIComponent(transactionId)}`)
    .then(r => r.json())
    .then(loc => {
      if (!loc || !map) return;
      const lat = parseFloat(loc.rider_latitude);
      const lng = parseFloat(loc.rider_longitude);
      if (isNaN(lat) || isNaN(lng)) return;
      map.setView([lat, lng], 15);
      marker.setLatLng([lat, lng]).bindPopup('Rider\'s last known position').openPopup();
    })
    .catch(() => {});
}

/* ---------- live GPS sharing (reuses geolocation workflow) ---------- */
function loadDeliveryInfo(transactionId){
  fetch(`?ajax=delivery_info&transaction_id=${encodeURIComponent(transactionId)}`)
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        document.getElementById('feedbackContent').textContent = data.error;
        return;
      }
      const delivery = data.delivery;
      if (!delivery) return;
      const previousStatus = currentDelivery ? currentDelivery.status : '';
      currentDelivery = Object.assign(currentDelivery || {}, {
        id: delivery.transaction_id,
        status: (delivery.delivery_status || 'assigned').toLowerCase(),
        customer: delivery.customer_name,
        address: delivery.address,
        phone: delivery.contact_number,
        email: delivery.email,
        description: delivery.description,
        payment_method: (delivery.payment_method || 'cash').toLowerCase(),
        payment_label: ({ cash: 'Cash', gcash: 'GCash', maya: 'Maya' })[(delivery.payment_method || 'cash').toLowerCase()] || 'Cash',
        notes: delivery.notes,
        item_summary: `${delivery.water_type || 'Item'} · ${delivery.quantity || 1} pcs${delivery.price_per_unit ? ' · ₱' + parseFloat(delivery.price_per_unit).toFixed(2) + ' each' : ''}`
      });
      updateDrawerRoute(currentDelivery.status);
      updateDrawerActions(currentDelivery);
      if (currentDelivery.status === 'delivered' && watchId !== null) {
        toggleGPS();
      }
      fillDeliveryDetails(currentDelivery);
      fillMessages(data.messages || []);
      fillFeedback(data.feedback || null);
      if (previousStatus && previousStatus !== 'delivered' && currentDelivery.status === 'delivered') {
        setTimeout(() => window.location.reload(), 700);
      }
    })
    .catch(() => {
      document.getElementById('feedbackContent').textContent = 'Unable to load delivery details.';
    });
}

function toggleGPS(){
  const btn = document.getElementById('gpsToggleBtn');

  if (watchId !== null){
    navigator.geolocation.clearWatch(watchId);
    watchId = null;
    btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Share GPS';
    return;
  }

  if (!navigator.geolocation){
    alert('Geolocation is not supported on this device.');
    return;
  }

  watchId = navigator.geolocation.watchPosition((pos) => {
    fetch('?ajax=ping_location', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        transaction_id: currentDelivery.id,
        latitude: pos.coords.latitude,
        longitude: pos.coords.longitude,
        speed: pos.coords.speed,
        heading: pos.coords.heading
      })
    }).catch(() => {});
    if (map){
      map.setView([pos.coords.latitude, pos.coords.longitude], 15);
      marker.setLatLng([pos.coords.latitude, pos.coords.longitude]).bindPopup('You are here');
    }
  }, () => { alert('Could not get your location. Check location permissions.'); }, { enableHighAccuracy: true, maximumAge: 3000, timeout: 8000 });

  btn.innerHTML = '<i class="fas fa-stop"></i> Stop Sharing';
}

function sendMessage(){
  const input = document.getElementById('messageInput');
  const text = input.value.trim();
  if (!text || !currentDelivery || !currentDelivery.id) return;

  fetch('?ajax=send_message', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ transaction_id: currentDelivery.id, message: text })
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) {
      alert(data.error || 'Message failed to send.');
      return;
    }
    input.value = '';
    loadDeliveryInfo(currentDelivery.id);
  })
  .catch(() => alert('Unable to send message.'));
}

document.addEventListener('DOMContentLoaded', () => {
  const sendBtn = document.getElementById('sendMessageBtn');
  if (sendBtn) sendBtn.addEventListener('click', sendMessage);
  const messageInput = document.getElementById('messageInput');
  if (messageInput) {
    messageInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
      }
    });
  }

  const autoTrackId = <?php echo json_encode($rider_auto_track); ?>;
  if (autoTrackId) {
    const deliveries = <?php echo json_encode($deliveries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const delivery = deliveries.find(item => item.id === autoTrackId);
    if (delivery) openTracking(delivery);
  }
});

window.addEventListener('beforeunload', () => {
  if (watchId) navigator.geolocation.clearWatch(watchId);
});

<?php if (!empty($rider_flash)): ?>
window.addEventListener('DOMContentLoaded', () => {
  const t = document.createElement('div');
  t.textContent = <?php echo json_encode($rider_flash); ?>;
  t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,.2);';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
});
<?php endif; ?>
</script>
</body>
</html>
