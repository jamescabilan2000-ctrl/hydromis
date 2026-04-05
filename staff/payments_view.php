<?php
include 'check_auth.php';

// DummyPaymentsResult class
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

// Initialize variables to prevent undefined variable warnings
$payments_data = [];
$error = null;
$filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
$total_collected = 0;
$pending_amount = 0;
$total_payments = 0;
$pending_payments = 0;
$verified_payments = 0;

// TODO: Replace with actual database query
$payments_data = array(
    array('payment_id' => 'PAY001', 'order_id' => 'ORD001', 'full_name' => 'John Doe', 'amount' => 5000, 'status' => 'paid', 'created_at' => '2026-03-20', 'payment_method' => 'card', 'contact_number' => '09012345001'),
    array('payment_id' => 'PAY002', 'order_id' => 'ORD002', 'full_name' => 'Jane Smith', 'amount' => 3500, 'status' => 'paid', 'created_at' => '2026-03-20', 'payment_method' => 'transfer', 'contact_number' => '09012345002'),
    array('payment_id' => 'PAY003', 'order_id' => 'ORD003', 'full_name' => 'Bob Johnson', 'amount' => 2500, 'status' => 'pending', 'created_at' => '2026-03-21', 'payment_method' => 'card', 'contact_number' => '09012345003'),
    array('payment_id' => 'PAY004', 'order_id' => 'ORD004', 'full_name' => 'Alice Brown', 'amount' => 1800, 'status' => 'paid', 'created_at' => '2026-03-19', 'payment_method' => 'transfer', 'contact_number' => '09012345004')
);

$total_collected = 10300;
$pending_amount = 2500;
$total_payments = count($payments_data);
$pending_payments = count(array_filter($payments_data, fn($p) => $p['status'] === 'pending'));
$verified_payments = count(array_filter($payments_data, fn($p) => $p['status'] === 'paid'));

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
    <link href="../css/staff_payments_view.css" rel="stylesheet">
</head>
<body>
<?php include 'payments_view.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>