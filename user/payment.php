<?php
// Initialize variables to prevent undefined variable warnings
$order_id = isset($_POST['order_id']) ? $_POST['order_id'] : '';
$amount = isset($_POST['amount']) ? $_POST['amount'] : 0;
$method = isset($_POST['method']) ? $_POST['method'] : 'card';
$account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : '';
$cvv = isset($_POST['cvv']) ? trim($_POST['cvv']) : '';
$expiry = isset($_POST['expiry']) ? trim($_POST['expiry']) : '';
$success = false;
$error = null;
$payment_processed = false;

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $method = isset($_POST['method']) ? $_POST['method'] : 'card';
    $account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : '';
    
    // Validate inputs
    if (empty($order_id)) {
        $error = 'Order ID is required';
    } else if ($amount <= 0) {
        $error = 'Invalid payment amount';
    } else if (empty($account_number)) {
        $error = 'Payment account details are required';
    } else if ($method === 'card' && (!preg_match('/^[0-9]{13,19}$/', str_replace(' ', '', $account_number)))) {
        $error = 'Invalid card number';
    } else if ($method === 'transfer' && (!preg_match('/^[0-9]{10,}$/', str_replace(' ', '', $account_number)))) {
        $error = 'Invalid account number';
    } else {
        // TODO: Replace with actual payment gateway integration
        // Simulate payment processing
        $_SESSION['payment_info'] = array(
            'order_id' => $order_id,
            'amount' => $amount,
            'method' => $method,
            'status' => 'completed',
            'transaction_id' => 'TXN' . time(),
            'date' => date('Y-m-d H:i:s')
        );
        
        $success = true;
        $payment_processed = true;
        header('refresh: 2; url=../welcome.php');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/user_payment.css" rel="stylesheet">
</head>
<body>
<?php include 'payment.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>