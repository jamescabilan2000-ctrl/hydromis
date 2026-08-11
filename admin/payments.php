<?php
include 'check_auth.php';
require_once '../config/database.php';

class PaymentsArrayResult {
    private $data;
    private $index = 0;
    public $num_rows = 0;

    public function __construct($data) {
        $this->data = $data;
        $this->num_rows = count($data);
    }

    public function fetch_assoc() {
        if ($this->index < count($this->data)) {
            return $this->data[$this->index++];
        }

        return null;
    }
}

$payments_data = [];
$error = null;
$success_message = '';

$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) DEFAULT 'cash'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(255)");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_date TIMESTAMP NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255)");
$conn->query("CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(255) UNIQUE NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL,
    payment_reference VARCHAR(255),
    payment_status VARCHAR(20) DEFAULT 'pending',
    payment_proof VARCHAR(255),
    gcash_number VARCHAR(20),
    maya_number VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['payment_id'], $_POST['transaction_id'])) {
    $payment_id = sanitize($_POST['payment_id']);
    $transaction_id = sanitize($_POST['transaction_id']);
    $action = sanitize($_POST['action']);

    if ($action === 'verify') {
        $conn->query("UPDATE payments SET payment_status = 'paid' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'paid', payment_date = NOW() WHERE transaction_id = '$transaction_id'");
        $success_message = 'Payment verified successfully.';
    } elseif ($action === 'reject') {
        $conn->query("UPDATE payments SET payment_status = 'failed' WHERE payment_id = '$payment_id'");
        $conn->query("UPDATE transactions SET payment_status = 'failed', payment_date = NULL WHERE transaction_id = '$transaction_id'");
        $success_message = 'Payment rejected.';
    }
}

$payments_result = $conn->query("SELECT p.payment_id, p.transaction_id, p.transaction_id AS order_id, p.amount, p.payment_status AS status, p.created_at, p.payment_method, p.payment_reference, p.payment_proof, p.gcash_number, p.maya_number, u.full_name, u.contact_number
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.user_id
    ORDER BY p.created_at DESC");

if ($payments_result) {
    while ($row = $payments_result->fetch_assoc()) {
        $payments_data[] = $row;
    }
} else {
    $error = 'Unable to load payments: ' . $conn->error;
}

$total_payments = count($payments_data);
$verified_payments = count(array_filter($payments_data, fn($p) => strtolower($p['status'] ?? '') === 'paid'));
$pending_payments = count(array_filter($payments_data, fn($p) => in_array(strtolower($p['status'] ?? ''), ['pending', 'processing'], true)));
$total_amount = array_reduce($payments_data, fn($carry, $p) => $carry + (strtolower($p['status'] ?? '') === 'paid' ? (float) ($p['amount'] ?? 0) : 0), 0);
$payments = new PaymentsArrayResult($payments_data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/admin_transactions.css" rel="stylesheet">
    <script src="../js/ui-protection.js" defer></script>
    <link rel="stylesheet" href="../css/admin-theme.css">
    <script src="../js/admin-theme.js"></script>
</head>
<body>
<?php include 'payments.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
