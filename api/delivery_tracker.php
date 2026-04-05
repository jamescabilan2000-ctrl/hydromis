<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

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

    $sql = "SELECT 
                t.transaction_id,
                t.user_id,
                t.rider_id,
                t.delivery_status,
                t.status,
                t.address as destination,
                t.created_at,
                u.full_name as customer_name,
                u.contact_number,
                u.address,
                COALESCE(r.rider_latitude, 12.8797) as rider_latitude,
                COALESCE(r.rider_longitude, 121.7740) as rider_longitude,
                COALESCE(r.last_update, NOW()) as last_update,
                t.updated_at
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_locations r ON t.transaction_id = r.transaction_id
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
                'rider_location' => [
                    'latitude' => (float)$delivery['rider_latitude'],
                    'longitude' => (float)$delivery['rider_longitude'],
                    'last_update' => $delivery['last_update']
                ],
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

    $sql = "SELECT 
                t.*,
                u.full_name,
                u.contact_number,
                u.address,
                COALESCE(r.rider_latitude, 12.8797) as rider_latitude,
                COALESCE(r.rider_longitude, 121.7740) as rider_longitude,
                COALESCE(r.last_update, NOW()) as rider_last_update
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
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
 * GET: Get all active deliveries (on_the_way status)
 */
function getAllDeliveries() {
    global $conn;
    
    $sql = "SELECT 
                t.transaction_id,
                t.user_id,
                t.delivery_status,
                t.status,
                t.address as destination,
                u.full_name,
                u.contact_number,
                COALESCE(r.rider_latitude, 12.8797) as rider_latitude,
                COALESCE(r.rider_longitude, 121.7740) as rider_longitude,
                COALESCE(r.last_update, NOW()) as last_update
            FROM transactions t
            JOIN users u ON t.user_id = u.user_id
            LEFT JOIN rider_locations r ON t.transaction_id = r.transaction_id
            WHERE t.delivery_status IN ('pending', 'on_the_way')
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
    
    if (empty($transaction_id) || $latitude == 0 || $longitude == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id, latitude, and longitude are required']);
        return;
    }

    // Check if record exists
    $check_sql = "SELECT id FROM rider_locations WHERE transaction_id = '$transaction_id'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        // Update existing record
        $sql = "UPDATE rider_locations 
                SET rider_latitude = $latitude, 
                    rider_longitude = $longitude,
                    last_update = NOW()
                WHERE transaction_id = '$transaction_id'";
    } else {
        // Insert new record
        $sql = "INSERT INTO rider_locations 
                (transaction_id, rider_latitude, rider_longitude, last_update)
                VALUES ('$transaction_id', $latitude, $longitude, NOW())";
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

/**
 * Sanitize input to prevent SQL injection
 */
function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}
?>
