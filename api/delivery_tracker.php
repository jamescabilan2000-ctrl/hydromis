<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Ensure rider_locations table exists
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

function hasColumn($table, $column) {
    global $conn;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column' LIMIT 1");
    return $result && $result->num_rows > 0;
}

function riderJoinExpr() {
    static $expr = null;
    if ($expr !== null) {
        return $expr;
    }
    $expr = hasColumn('transactions', 'assigned_rider') ? "COALESCE(t.rider_id, t.assigned_rider)" : "t.rider_id";
    return $expr;
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$request = isset($_GET['request']) ? $_GET['request'] : '';

if ($method == 'GET') {
    if ($request == 'get_rider_location') {
        getRiderLocation();
    } elseif ($request == 'get_delivery_details') {
        getDeliveryDetails();
    } elseif ($request == 'get_all_deliveries') {
        getAllDeliveries();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
    }
} elseif ($method == 'POST') {
    if ($request == 'update_rider_location') {
        updateRiderLocation();
    } elseif ($request == 'complete_delivery') {
        completeDelivery();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

/**
 * GET: Get current rider location for a specific delivery
 * Required: transaction_id
 */
function getRiderLocation() {
    global $conn;
    
    $transaction_id = isset($_GET['transaction_id']) ? sanitize($_GET['transaction_id']) : '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id is required']);
        return;
    }

    $riderExpr = riderJoinExpr();
    $sql = "SELECT 
                t.transaction_id,
                t.user_id,
                t.rider_id,
                t.delivery_status,
                t.status,
                u.address as destination,
                t.created_at,
                u.full_name as customer_name,
                u.contact_number,
                u.address,
                ru.full_name as rider_name,
                ru.contact_number as rider_contact_number,
                r.rider_latitude,
                r.rider_longitude,
                r.accuracy,
                r.last_update,
                t.updated_at
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_users ru ON {$riderExpr} = ru.rider_id
            LEFT JOIN rider_locations r ON r.id = (
                SELECT rl.id FROM rider_locations rl
                WHERE rl.transaction_id = t.transaction_id
                ORDER BY rl.last_update DESC, rl.id DESC LIMIT 1
            )
            WHERE t.transaction_id = '$transaction_id'";

    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $delivery = $result->fetch_assoc();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'transaction_id' => $delivery['transaction_id'],
                'delivery_status' => $delivery['delivery_status'],
                'status' => $delivery['status'],
                'destination' => $delivery['destination'],
                'customer_name' => $delivery['customer_name'],
                'contact_number' => $delivery['contact_number'],
                'address' => $delivery['address'],
                'rider_name' => $delivery['rider_name'],
                'rider_contact_number' => $delivery['rider_contact_number'],
                'has_live_location' => $delivery['rider_latitude'] !== null && $delivery['rider_longitude'] !== null,
                'rider_location' => $delivery['rider_latitude'] !== null && $delivery['rider_longitude'] !== null ? [
                    'latitude' => (float)$delivery['rider_latitude'],
                    'longitude' => (float)$delivery['rider_longitude'],
                    'accuracy' => $delivery['accuracy'] !== null ? (float)$delivery['accuracy'] : null,
                    'last_update' => $delivery['last_update']
                ] : null,
                'created_at' => $delivery['created_at'],
                'updated_at' => $delivery['updated_at']
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Delivery not found']);
    }
}

/**
 * GET: Get complete delivery details
 * Required: transaction_id
 */
function getDeliveryDetails() {
    global $conn;
    
    $transaction_id = isset($_GET['transaction_id']) ? sanitize($_GET['transaction_id']) : '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id is required']);
        return;
    }

    $riderExpr = riderJoinExpr();
    $sql = "SELECT 
                t.*,
                u.full_name,
                u.contact_number,
                u.address,
                ru.full_name as rider_name,
                ru.contact_number as rider_contact_number,
                COALESCE(r.rider_latitude, 12.8797) as rider_latitude,
                COALESCE(r.rider_longitude, 121.7740) as rider_longitude,
                COALESCE(r.last_update, NOW()) as rider_last_update
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_users ru ON {$riderExpr} = ru.rider_id
            LEFT JOIN rider_locations r ON t.transaction_id = r.transaction_id
            WHERE t.transaction_id = '$transaction_id'";

    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $delivery = $result->fetch_assoc();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $delivery
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Delivery not found']);
    }
}

/**
 * GET: Get all active deliveries
 */
function getAllDeliveries() {
    global $conn;
    
    $riderExpr = riderJoinExpr();
    $sql = "SELECT 
                t.transaction_id,
                t.user_id,
                t.delivery_status,
                t.status,
                u.address as destination,
                u.full_name,
                u.contact_number,
                ru.full_name as rider_name,
                ru.contact_number as rider_contact_number,
                COALESCE(r.rider_latitude, 12.8797) as rider_latitude,
                COALESCE(r.rider_longitude, 121.7740) as rider_longitude,
                COALESCE(r.last_update, NOW()) as last_update
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_users ru ON {$riderExpr} = ru.rider_id
            LEFT JOIN rider_locations r ON t.transaction_id = r.transaction_id
            WHERE t.delivery_status IN ('assigned', 'pending', 'on_way')
            ORDER BY t.created_at DESC
            LIMIT 50";

    $result = $conn->query($sql);
    
    $deliveries = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $deliveries[] = $row;
        }
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'count' => count($deliveries),
            'data' => $deliveries
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'count' => 0,
            'data' => []
        ]);
    }
}

/**
 * POST: Update rider location
 * Required: transaction_id, latitude, longitude
 */
function updateRiderLocation() {
    global $conn;
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    $transaction_id = isset($data['transaction_id']) ? sanitize($data['transaction_id']) : '';
    $latitude = isset($data['latitude']) ? floatval($data['latitude']) : 0;
    $longitude = isset($data['longitude']) ? floatval($data['longitude']) : 0;
    $accuracy = isset($data['accuracy']) ? floatval($data['accuracy']) : null;
    $speed = isset($data['speed']) ? floatval($data['speed']) : null;
    $heading = isset($data['heading']) ? floatval($data['heading']) : null;
    
    if (empty($transaction_id) || $latitude == 0 || $longitude == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id, latitude, and longitude are required']);
        return;
    }

    // Get rider_id from transaction
    $riderExpr = hasColumn('transactions', 'assigned_rider') ? "COALESCE(rider_id, assigned_rider)" : "rider_id";
    $rider_sql = "SELECT {$riderExpr} AS rider_id FROM transactions WHERE transaction_id = '$transaction_id' LIMIT 1";
    $rider_result = $conn->query($rider_sql);
    $rider_id = '';
    if ($rider_result && $rider_result->num_rows > 0) {
        $rider_row = $rider_result->fetch_assoc();
        $rider_id = $rider_row['rider_id'] ?? '';
    }

    // Check if record exists
    $check_sql = "SELECT id FROM rider_locations WHERE transaction_id = '$transaction_id'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        // Update existing record
        $sql = "UPDATE rider_locations 
                SET rider_latitude = $latitude, 
                    rider_longitude = $longitude,
                    accuracy = " . ($accuracy !== null ? $accuracy : 'NULL') . ",
                    speed = " . ($speed !== null ? $speed : 'NULL') . ",
                    heading = " . ($heading !== null ? $heading : 'NULL') . ",
                    last_update = NOW()
                WHERE transaction_id = '$transaction_id'";
    } else {
        // Insert new record
        $sql = "INSERT INTO rider_locations 
                (transaction_id, rider_id, rider_latitude, rider_longitude, accuracy, speed, heading, last_update)
                VALUES ('$transaction_id', " . ($rider_id ? "'$rider_id'" : "NULL") . ", $latitude, $longitude, " . 
                ($accuracy !== null ? $accuracy : 'NULL') . ", " . 
                ($speed !== null ? $speed : 'NULL') . ", " . 
                ($heading !== null ? $heading : 'NULL') . ", NOW())";
    }

    if ($conn->query($sql)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Rider location updated successfully',
            'data' => [
                'transaction_id' => $transaction_id,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update rider location: ' . $conn->error]);
    }
}

/**
 * POST: Mark delivery as complete
 * Required: transaction_id
 */
function completeDelivery() {
    global $conn;
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    $transaction_id = isset($data['transaction_id']) ? sanitize($data['transaction_id']) : '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id is required']);
        return;
    }

    $sql = "UPDATE transactions 
            SET delivery_status = 'delivered',
                updated_at = NOW()
            WHERE transaction_id = '$transaction_id'";

    if ($conn->query($sql)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Delivery marked as complete',
            'data' => [
                'transaction_id' => $transaction_id,
                'delivery_status' => 'delivered'
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to complete delivery: ' . $conn->error]);
    }
}

?>
