<?php
include 'check_auth.php';

// DummyDeliveriesResult class
class DummyDeliveriesResult {
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
$completed = 45;
$pending = 3;
$earnings = 28500; // Today's earnings
$total_delivered = 125; // Total lifetime deliveries
$rating = 4.8; // Star rating
$completed_deliveries = 45;
$cash_collected = 85000;
$active_count = 3;
$completed_count = 45;

// Simulate assigned deliveries
$assigned_deliveries = array(
    array(
        'id' => 'DEL001',
        'order_id' => 'ORD001',
        'customer' => 'John Doe',
        'phone' => '08012345678',
        'pickup' => '123 Water Street, Lagos',
        'delivery' => '456 Oak Avenue, Ikoyi',
        'amount' => 5000,
        'status' => 'in-transit',
        'distance' => '12km',
        'eta' => '15 mins'
    ),
    array(
        'id' => 'DEL002',
        'order_id' => 'ORD002',
        'customer' => 'Jane Smith',
        'phone' => '08012345679',
        'pickup' => '789 Market Lane, Lekki',
        'delivery' => '321 Park Road, VI',
        'amount' => 3500,
        'status' => 'assigned',
        'distance' => '8km',
        'eta' => '45 mins'
    ),
    array(
        'id' => 'DEL003',
        'order_id' => 'ORD003',
        'customer' => 'Bob Johnson',
        'phone' => '08012345680',
        'pickup' => '555 Shop Street, Surulere',
        'delivery' => '777 Office Complex, MD',
        'amount' => 2500,
        'status' => 'assigned',
        'distance' => '15km',
        'eta' => '1 hour'
    )
);

// Create DummyDeliveriesResult object
$available = new DummyDeliveriesResult($assigned_deliveries);
$active_deliveries = $assigned_deliveries;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/rider_dashboard.css" rel="stylesheet">
</head>
<body>
<?php include 'dashboard.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>