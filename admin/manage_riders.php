<?php
include 'check_auth.php';

// Initialize variables to prevent undefined variable warnings
$riders = [];
$total_count = 0;
$active_count = 0;
$inactive_count = 0;
$error = null;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$rider_id = isset($_GET['id']) ? $_GET['id'] : null;
$success_message = '';

// Handle rider actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if ($action === 'add') {
        $name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        
        if (!empty($name) && !empty($phone)) {
            $success_message = 'Rider added successfully';
        } else {
            $error = 'All fields required';
        }
    } else if ($action === 'approve') {
        $rider_id = isset($_POST['rider_id']) ? $_POST['rider_id'] : '';
        if (!empty($rider_id)) {
            $success_message = 'Rider approved successfully';
        }
    } else if ($action === 'delete') {
        $rider_id = isset($_POST['rider_id']) ? $_POST['rider_id'] : '';
        if (!empty($rider_id)) {
            $success_message = 'Rider deleted successfully';
        }
    }
}

// TODO: Replace with actual database query
// Simulate rider data
$riders_data = array(
    array('id' => 'RID001', 'rider_id' => 'RID001', 'username' => 'mgreen', 'full_name' => 'Michael Green', 'email' => 'michael@example.com', 'contact_number' => '08012345670', 'status' => 'active', 'deliveries' => 45, 'created_at' => '2026-01-15'),
    array('id' => 'RID002', 'rider_id' => 'RID002', 'username' => 'swhite', 'full_name' => 'Sandra White', 'email' => 'sandra@example.com', 'contact_number' => '08012345671', 'status' => 'pending', 'deliveries' => 0, 'created_at' => '2026-03-20'),
    array('id' => 'RID003', 'rider_id' => 'RID003', 'username' => 'dblack', 'full_name' => 'David Black', 'email' => 'david@example.com', 'contact_number' => '08012345672', 'status' => 'active', 'deliveries' => 38, 'created_at' => '2026-02-01'),
    array('id' => 'RID004', 'rider_id' => 'RID004', 'username' => 'lpurple', 'full_name' => 'Lisa Purple', 'email' => 'lisa@example.com', 'contact_number' => '08012345673', 'status' => 'inactive', 'deliveries' => 22, 'created_at' => '2025-12-10'),
    array('id' => 'RID005', 'rider_id' => 'RID005', 'username' => 'korange', 'full_name' => 'Kevin Orange', 'email' => 'kevin@example.com', 'contact_number' => '08012345674', 'status' => 'active', 'deliveries' => 51, 'created_at' => '2026-01-05')
);

// Calculate stats
$total_count = count($riders_data);
foreach ($riders_data as $rider) {
    if ($rider['status'] === 'active') {
        $active_count++;
    } else if ($rider['status'] === 'inactive') {
        $inactive_count++;
    }
}

// Create a result object that mimics database query behavior
class DummyRidersResult {
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

$riders = new DummyRidersResult($riders_data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Riders</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/admin_manage_riders.css" rel="stylesheet">
</head>
<body>
<?php include 'manage_riders.html'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>