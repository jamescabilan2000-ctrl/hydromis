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
        WHERE (sender = ? AND recipient = ?) OR (sender = ? AND recipient = ?)
        ORDER BY id DESC LIMIT 100
    ) recent_messages ORDER BY id ASC");
    $msgStmt->bind_param('ssss', $rider_id, $customer_id, $customer_id, $rider_id);
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

/* ---------- POST actions: start / complete / status change ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');

    if (in_array($action, ['start_delivery', 'complete', 'update_status'], true) && $transaction_id !== '') {
        $new_status = null;
        $flash = '';

        if ($action === 'start_delivery') {
            $new_status = 'on_way';
            $flash = "Status updated: {$transaction_id} is now On the Way.";
        } elseif ($action === 'complete') {
            $new_status = 'delivered';
            $flash = "Delivery completed: {$transaction_id}.";
        } elseif ($action === 'update_status') {
            $candidate = sanitize($_POST['new_status'] ?? '');
            $allowed = ['assigned', 'pending', 'on_way', 'delivered'];
            if (in_array($candidate, $allowed, true)) {
                $new_status = $candidate;
                $label = $new_status === 'on_way' ? 'On the Way' : ucfirst($new_status);
                $flash = "Status updated: {$transaction_id} -> {$label}.";
            } else {
                $_SESSION['rider_flash'] = 'Invalid delivery status selected.';
            }
        }

        if ($new_status !== null) {
            if ($transactions_has_assigned_rider) {
                $stmt = $conn->prepare("UPDATE transactions SET delivery_status = ?
                    WHERE transaction_id = ? AND status = 'approved' AND (rider_id = ? OR assigned_rider = ?)");
                $stmt->bind_param('ssss', $new_status, $transaction_id, $rider_id, $rider_id);
            } else {
                $stmt = $conn->prepare("UPDATE transactions SET delivery_status = ?
                    WHERE transaction_id = ? AND status = 'approved' AND rider_id = ?");
                $stmt->bind_param('sss', $new_status, $transaction_id, $rider_id);
            }
            if ($stmt->execute()) {
                $_SESSION['rider_flash'] = $flash;
                if ($action === 'start_delivery' && $stmt->affected_rows > 0) {
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
        COALESCE(u.full_name, 'Unknown Customer') AS full_name,
        COALESCE(u.contact_number, '-') AS contact_number,
        COALESCE(u.email, '-') AS email,
        COALESCE(u.address, 'No address provided') AS address
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'approved'
      AND {$rider_where}
      AND COALESCE(NULLIF(t.delivery_status, ''), 'assigned') IN ('assigned', 'pending', 'on_way', 'delivered')
    ORDER BY FIELD(COALESCE(NULLIF(t.delivery_status,''),'assigned'), 'on_way', 'assigned', 'pending', 'delivered'),
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

/* ---------- earnings: today + total (per rider preference, kept simple) ---------- */

$rider_col = $transactions_has_assigned_rider ? "COALESCE(rider_id, assigned_rider)" : "rider_id";

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS trips
    FROM transactions WHERE {$rider_col} = ? AND delivery_status = 'delivered' AND DATE(updated_at) = CURRENT_DATE");
$stmt->bind_param('s', $rider_id);
$stmt->execute();
$today_row = $stmt->get_result()->fetch_assoc();
$earnings_today = (float)($today_row['total'] ?? 0);
$trips_today = (int)($today_row['trips'] ?? 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS trips
    FROM transactions WHERE {$rider_col} = ? AND delivery_status = 'delivered'");
$stmt->bind_param('s', $rider_id);
$stmt->execute();
$total_row = $stmt->get_result()->fetch_assoc();
$earnings_total = (float)($total_row['total'] ?? 0);
$trips_total = (int)($total_row['trips'] ?? 0);

$commission_per_trip = 150;
$commission_today = $trips_today * $commission_per_trip;
$commission_total = $trips_total * $commission_per_trip;

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
  padding:14px 18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:sticky; top:0; z-index:40;
}
.topbar .brand{display:flex; align-items:center; gap:10px;}
.topbar .brand i{color:var(--amber); font-size:20px;}
.topbar .brand-text{display:flex; flex-direction:column; line-height:1.15;}
.topbar .brand-text b{font-family:'Barlow Condensed',sans-serif; font-size:18px; letter-spacing:.02em;}
.topbar .brand-text span{font-size:11px; color:#9fb0c2;}
.topbar .rider-chip{display:flex; align-items:center; gap:10px;}
.topbar .rider-name{font-size:13px; color:#dbe6f0; text-align:right;}
.topbar .rider-name b{display:block; color:#fff; font-size:14px;}
.topbar .logout{color:#f2c9c9; text-decoration:none; font-size:12px; margin-left:6px;}
.online-pill{
  display:inline-flex; align-items:center; gap:6px;
  background:rgba(16,185,129,.15); border:1px solid rgba(16,185,129,.4);
  color:#6ee7b7; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;
  cursor:pointer; user-select:none;
}
.online-pill.offline{background:rgba(148,163,184,.15); border-color:rgba(148,163,184,.4); color:#cbd5e1;}
.online-pill .dot{width:7px; height:7px; border-radius:50%; background:#34d399;}
.online-pill.offline .dot{background:#94a3b8;}

/* ---- shell ---- */
.shell{max-width:720px; margin:0 auto; padding:16px 14px 90px;}

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

.empty-state{
  text-align:center; padding:38px 20px; color:var(--steel);
  background:var(--card); border:1px dashed var(--line); border-radius:var(--radius);
}
.empty-state i{font-size:26px; color:#cbd1d9; margin-bottom:8px; display:block;}

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

.feedback-content{font-size:13px; color:var(--ink); line-height:1.5; min-height:60px;}
.feedback-rating{display:flex; gap:4px; margin-bottom:6px;}
.feedback-rating span{color:var(--amber);}

.route-line.expanded{padding:18px 20px; margin:0;}
.route-line.expanded .step{display:flex; flex-direction:column; align-items:center; flex:1; position:relative;}
.route-line.expanded .step .lbl{font-size:11px; margin-top:6px; color:var(--steel); text-align:center; font-weight:600;}
.route-line.expanded .step.done .lbl, .route-line.expanded .step.current .lbl{color:var(--ink);}
.route-line.expanded .node{width:16px; height:16px;}

.drawer-body{padding:16px 18px 26px;}
.gps-live{
  display:flex; align-items:center; justify-content:space-between;
  background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:12px 14px; margin-bottom:14px;
}
.gps-live .g-info{font-size:12.5px; color:var(--steel);}
.gps-live .g-info b{display:block; color:var(--ink); font-size:13.5px;}

@media (max-width:480px){
  .card-top{flex-direction:column;}
  .card-amount{font-size:15px;}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <i class="fas fa-motorcycle"></i>
    <div class="brand-text">
      <b>HydroMIS</b>
      <span>Rider Portal</span>
    </div>
  </div>
  <div class="rider-chip">
    <span class="online-pill" id="onlinePill" onclick="toggleOnline()"><span class="dot"></span><span id="onlineLabel">Online</span></span>
    <div class="rider-name"><b><?php echo htmlspecialchars($display_name); ?></b><?php echo htmlspecialchars($rider_id); ?></div>
    <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</div>

<div class="shell">

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

  <div class="earnings-strip">
    <div class="cell">
      <div class="amt">₱<?php echo number_format($earnings_today, 0); ?></div>
      <div class="lbl">Today</div>
      <div class="sub"><?php echo $trips_today; ?> trip<?php echo $trips_today === 1 ? '' : 's'; ?> · ₱<?php echo number_format($commission_today, 0); ?> commission</div>
    </div>
    <div class="cell">
      <div class="amt">₱<?php echo number_format($earnings_total, 0); ?></div>
      <div class="lbl">Total</div>
      <div class="sub"><?php echo $trips_total; ?> trip<?php echo $trips_total === 1 ? '' : 's'; ?> · ₱<?php echo number_format($commission_total, 0); ?> commission</div>
    </div>
  </div>

  <div class="section-head">
    <h2><i class="fas fa-route" style="color:var(--teal); font-size:17px;"></i>My Deliveries</h2>
    <span class="count-badge"><?php echo count($active_deliveries); ?> active</span>
  </div>

  <?php if (empty($active_deliveries)): ?>
    <div class="empty-state">
      <i class="fas fa-box-open"></i>
      <p>No deliveries assigned right now. New assignments from staff will show up here automatically.</p>
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
          <span class="status-badge status-<?php echo $status; ?>"><?php echo $status_label; ?></span>
        </div>
      </div>

      <div class="route-line">
        <div class="node <?php echo $step_index >= 0 ? 'done' : ''; ?> <?php echo $step_index === 0 ? 'current' : ''; ?>"></div>
        <div class="seg <?php echo $step_index >= 1 ? 'done' : ''; ?>"></div>
        <div class="node <?php echo $step_index >= 1 ? 'done' : ''; ?> <?php echo $step_index === 1 ? 'current' : ''; ?>"></div>
        <div class="seg <?php echo $step_index >= 2 ? 'done' : ''; ?>"></div>
        <div class="node <?php echo $step_index >= 2 ? 'done' : ''; ?>"></div>
      </div>

      <div class="card-address"><i class="fas fa-map-marker-alt"></i><span><?php echo htmlspecialchars($d['address']); ?></span></div>

      <div class="card-actions">
        <button class="btn btn-track" onclick='openTracking(<?php echo json_encode($d); ?>)'>
          <i class="fas fa-location-arrow"></i> Track
        </button>

        <?php if ($status === 'assigned'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="start_delivery">
            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($d['id']); ?>">
            <button class="btn btn-primary" type="submit"><i class="fas fa-play"></i> Start</button>
          </form>
        <?php elseif ($status === 'on_way'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($d['id']); ?>">
            <button class="btn btn-secondary" type="submit"><i class="fas fa-check"></i> Mark Delivered</button>
          </form>
        <?php endif; ?>

        <a class="btn btn-ghost" href="tel:<?php echo htmlspecialchars($d['phone']); ?>"><i class="fas fa-phone"></i> Call</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <div class="section-head">
    <h2><i class="fas fa-check-circle" style="color:var(--green); font-size:17px;"></i>Completed Today</h2>
  </div>

  <?php if (empty($completed_deliveries)): ?>
    <div class="empty-state">
      <i class="fas fa-inbox"></i>
      <p>Nothing delivered yet today. Completed drop-offs will appear here.</p>
    </div>
  <?php else: foreach (array_slice($completed_deliveries, 0, 10) as $d): ?>
    <div class="completed-row">
      <div class="c-left">
        <b><?php echo htmlspecialchars($d['customer']); ?></b>
        <span>#<?php echo htmlspecialchars($d['id']); ?></span>
      </div>
      <div class="c-right">
        <b>₱<?php echo number_format($d['amount'], 2); ?></b>
        <span>Delivered</span>
      </div>
    </div>
  <?php endforeach; endif; ?>

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

    <div id="trackMap"></div>

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

      <div class="gps-live">
        <div class="g-info">
          <b id="gpsStatusLabel">Live location off</b>
          <span id="gpsStatusSub">Turn on to share your position with dispatch</span>
        </div>
        <button class="btn btn-track" id="gpsToggleBtn" onclick="toggleGPS()"><i class="fas fa-satellite-dish"></i> Share GPS</button>
      </div>

      <div class="message-panel">
        <div class="message-head">Messages</div>
        <div class="message-list" id="messageList">No messages yet.</div>
        <div class="message-input-row">
          <input type="text" id="messageInput" placeholder="Type a message to the customer" />
          <button class="btn btn-primary" id="sendMessageBtn">Send</button>
        </div>
      </div>

      <div class="feedback-panel">
        <div class="feedback-head">Customer Feedback</div>
        <div class="feedback-content" id="feedbackContent">No feedback submitted yet.</div>
      </div>

      <div class="card-actions" id="drawerActions"></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
/* ---------- online/offline toggle (visual — wire to your presence endpoint if you have one) ---------- */
function toggleOnline(){
  const pill = document.getElementById('onlinePill');
  const label = document.getElementById('onlineLabel');
  const isOnline = !pill.classList.contains('offline');
  pill.classList.toggle('offline', isOnline);
  label.textContent = isOnline ? 'Offline' : 'Online';
}

/* ---------- tracking drawer ---------- */
let map, marker, pollTimer, watchId = null;
let currentDelivery = null;
const RIDER_ID = <?php echo json_encode($rider_id); ?>;

const STATUS_LABEL = { assigned: 'Assigned', on_way: 'On the Way', delivered: 'Delivered' };
const STEP_INDEX = { assigned: 0, on_way: 1, delivered: 2 };

function openTracking(delivery){
  currentDelivery = delivery;
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

function closeTracking(){
  document.getElementById('drawerOverlay').classList.remove('open');
  if (pollTimer) clearInterval(pollTimer);
}

function updateDrawerRoute(status){
  const idx = STEP_INDEX[status] ?? 0;
  document.querySelectorAll('#drawerRoute .step').forEach(step => {
    const stepIdx = parseInt(step.dataset.step, 10);
    step.classList.toggle('done', stepIdx < idx || (stepIdx === idx && status === 'delivered'));
    step.classList.toggle('current', stepIdx === idx && status !== 'delivered');
    const node = step.querySelector('.node');
    node.style.background = stepIdx <= idx ? 'var(--teal)' : 'var(--line)';
  });
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
    box.textContent = 'No feedback submitted yet.';
    return;
  }
  const stars = '★'.repeat(parseInt(feedback.rating, 10)) + '☆'.repeat(5 - parseInt(feedback.rating, 10));
  box.innerHTML = `<div class="feedback-rating">${stars}</div><div>${escapeHtml(feedback.feedback_message || 'No written feedback provided.')}</div><div class="meta" style="margin-top:8px;">${formatDate(feedback.created_at)}</div>`;
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
  const label = document.getElementById('gpsStatusLabel');
  const sub = document.getElementById('gpsStatusSub');

  if (watchId !== null){
    navigator.geolocation.clearWatch(watchId);
    watchId = null;
    btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Share GPS';
    label.textContent = 'Live location off';
    sub.textContent = 'Turn on to share your position with dispatch';
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
  label.textContent = 'Live location on';
  sub.textContent = 'Dispatch and the customer can see your position';
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
