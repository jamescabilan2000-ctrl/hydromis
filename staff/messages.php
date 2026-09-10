<?php
require_once 'check_auth.php';
require_once '../config/database.php';
if (empty($_SESSION['station_message_csrf'])) $_SESSION['station_message_csrf'] = bin2hex(random_bytes(32));
$transaction = trim((string)($_GET['transaction_id'] ?? $_POST['transaction_id'] ?? ''));
$error = '';
$order = null;
if ($transaction !== '') {
    $stmt = $conn->prepare("SELECT transaction_id, user_id FROM transactions WHERE transaction_id = ? AND fulfillment_method = 'pickup' LIMIT 1");
    $stmt->bind_param('s', $transaction);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim((string)($_POST['message'] ?? ''));
    if (!hash_equals($_SESSION['station_message_csrf'], (string)($_POST['csrf'] ?? '')) || !$order || $message === '' || strlen($message) > 500) {
        $error = 'Unable to send. Refresh and enter a message up to 500 characters.';
    } else {
        $stmt = $conn->prepare("INSERT INTO rider_messages (transaction_id, sender, recipient, message) VALUES (?, 'station', ?, ?)");
        $stmt->bind_param('sss', $transaction, $order['user_id'], $message);
        if ($stmt->execute()) {
            header('Location: messages.php?transaction_id=' . rawurlencode($transaction));
            exit;
        }
        $error = 'Message could not be sent. Please try again.';
    }
}
$threads = $conn->query("SELECT t.transaction_id, MAX(m.created_at) AS last_message FROM transactions t JOIN rider_messages m ON m.transaction_id = t.transaction_id AND (m.sender = 'station' OR m.recipient = 'station') WHERE t.fulfillment_method = 'pickup' GROUP BY t.transaction_id ORDER BY last_message DESC LIMIT 100");
$messages = [];
if ($order) {
    $stmt = $conn->prepare("SELECT sender, message, created_at FROM (SELECT id, sender, message, created_at FROM rider_messages WHERE transaction_id = ? AND ((sender = 'station' AND recipient = ?) OR (recipient = 'station' AND sender = ?)) ORDER BY id DESC LIMIT 100) recent ORDER BY id ASC");
    $stmt->bind_param('sss', $transaction, $order['user_id'], $order['user_id']);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
function message_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pickup Messages | HydroMIS</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef7fb;color:#17324d;font:16px system-ui,sans-serif}header{background:linear-gradient(120deg,#1769d2,#06abc1);color:white;padding:22px}header a{color:white}main{max-width:1000px;margin:24px auto;padding:16px;display:grid;grid-template-columns:260px 1fr;gap:20px}section,nav{padding:20px;background:white;border:1px solid #d9eaf2;border-radius:20px}nav a{display:block;padding:14px 8px;color:#1769d2;overflow-wrap:anywhere}article{background:#edf6fa;border-radius:14px;padding:12px;margin:12px 0;white-space:pre-wrap;overflow-wrap:anywhere}article.mine{background:#d9f3f5}small{display:block;color:#526b80}textarea{width:100%;min-height:90px;padding:12px;border:1px solid #b8d9e5;border-radius:12px;font:inherit}button,.refresh{display:inline-block;background:#1769d2;color:white;border:0;border-radius:12px;padding:12px 18px;font:inherit;cursor:pointer}.error{color:#b91c1c}@media(max-width:650px){main{grid-template-columns:1fr}}
</style></head><body><header><strong>HydroMIS · Pickup Messages</strong><p><a href="dashboard.php">Back to dashboard</a></p></header><main><nav aria-label="Pickup conversations"><h2>Conversations</h2>
<?php if (!$threads || !$threads->num_rows): ?><p>No pickup messages yet.</p><?php endif; ?>
<?php if ($threads) while ($thread = $threads->fetch_assoc()): ?><a href="?transaction_id=<?php echo rawurlencode($thread['transaction_id']); ?>"><?php echo message_escape($thread['transaction_id']); ?><small><?php echo message_escape($thread['last_message']); ?></small></a><?php endwhile; ?>
</nav><section><h2><?php echo $order ? message_escape($transaction) : 'Select a conversation'; ?></h2><a class="refresh" href="?transaction_id=<?php echo rawurlencode($transaction); ?>">Refresh messages</a>
<?php if ($error): ?><p class="error" role="alert"><?php echo message_escape($error); ?></p><?php endif; ?>
<?php foreach ($messages as $message): ?><article class="<?php echo $message['sender'] === 'station' ? 'mine' : ''; ?>"><small><?php echo $message['sender'] === 'station' ? 'Station' : 'Customer'; ?> · <?php echo message_escape($message['created_at']); ?></small><?php echo message_escape($message['message']); ?></article><?php endforeach; ?>
<?php if ($order): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo message_escape($_SESSION['station_message_csrf']); ?>"><input type="hidden" name="transaction_id" value="<?php echo message_escape($transaction); ?>"><label for="message">Reply to customer</label><textarea id="message" name="message" maxlength="500" required></textarea><button type="submit">Send message</button></form><?php endif; ?>
</section></main></body></html>
