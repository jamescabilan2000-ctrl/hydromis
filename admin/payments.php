<?php
include 'check_auth.php';

// DummyPaymentsResult class - mimics database query result object
class DummyPaymentsResult {
    private $data;
    private $index = 0;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function fetch_assoc() {
        if ($this->index < count($this->data)) {
            return $this->data[$this->index++];
        }
        return null;
    }
    
    public function __get($name) {
        if ($name === 'num_rows') {
            return count($this->data);
        }
        return null;
    }
}

// Initialize variables
$payments_data = [];
$error = null;
$success_message = '';

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $payment_id = isset($_POST['payment_id']) ? $_POST['payment_id'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if ($action == 'verify' && !empty($payment_id)) {
        $success_message = 'Payment verified successfully';
    } elseif ($action == 'reject' && !empty($payment_id)) {
        $success_message = 'Payment rejected';
    }
}

// TODO: Replace with actual database queries
// Simulate payments data
$payments_data = array(
    array('payment_id' => 'PAY001', 'order_id' => 'ORD001', 'full_name' => 'John Doe', 'amount' => 5000, 'status' => 'verified', 'created_at' => '2026-03-20', 'payment_method' => 'card', 'contact_number' => '09012345678'),
    array('payment_id' => 'PAY002', 'order_id' => 'ORD002', 'full_name' => 'Jane Smith', 'amount' => 3500, 'status' => 'verified', 'created_at' => '2026-03-20', 'payment_method' => 'transfer', 'contact_number' => '09012345679'),
    array('payment_id' => 'PAY003', 'order_id' => 'ORD003', 'full_name' => 'Bob Johnson', 'amount' => 2500, 'status' => 'pending', 'created_at' => '2026-03-21', 'payment_method' => 'card', 'contact_number' => '09012345680'),
    array('payment_id' => 'PAY004', 'order_id' => 'ORD004', 'full_name' => 'Alice Brown', 'amount' => 1800, 'status' => 'verified', 'created_at' => '2026-03-19', 'payment_method' => 'transfer', 'contact_number' => '09012345681'),
    array('payment_id' => 'PAY005', 'order_id' => 'ORD005', 'full_name' => 'Charlie Wilson', 'amount' => 4200, 'status' => 'verified', 'created_at' => '2026-03-19', 'payment_method' => 'card', 'contact_number' => '09012345682')
);

// Calculate statistics
$total_payments = count($payments_data);
$verified_payments = count(array_filter($payments_data, fn($p) => $p['status'] === 'verified'));
$pending_payments = count(array_filter($payments_data, fn($p) => $p['status'] === 'pending'));
$total_amount = array_sum(array_column(array_filter($payments_data, fn($p) => $p['status'] === 'verified'), 'amount'));

// Create DummyPaymentsResult object
$payments = new DummyPaymentsResult($payments_data);

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
</head>
<body>
<?php include 'payments.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
