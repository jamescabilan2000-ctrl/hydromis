<?php
require_once __DIR__ . '/system_settings.php';

function ensure_inventory_schema($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS inventory_items (id INT AUTO_INCREMENT PRIMARY KEY, item_code VARCHAR(50) NOT NULL UNIQUE, item_name VARCHAR(150) NOT NULL, category VARCHAR(80) NOT NULL DEFAULT 'Container', quantity INT NOT NULL DEFAULT 0, minimum_stock INT NOT NULL DEFAULT 10, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0, updated_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS inventory_movements (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, movement_type VARCHAR(20) NOT NULL, quantity_change INT NOT NULL, previous_quantity INT NOT NULL, new_quantity INT NOT NULL, reason VARCHAR(255) NULL, staff_id VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_inventory_item (item_id), INDEX idx_inventory_created (created_at))");
    $conn->query("CREATE TABLE IF NOT EXISTS user_notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(255) NOT NULL, transaction_id VARCHAR(255) NULL, title VARCHAR(150) NOT NULL, message VARCHAR(500) NOT NULL, notification_type VARCHAR(30) NOT NULL DEFAULT 'info', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_notification (user_id, is_read, created_at))");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS container_size VARCHAR(30) NULL");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS container_status VARCHAR(20) NULL");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS fulfillment_method VARCHAR(20) NULL");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS inventory_item_id INT NULL");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS inventory_reserved TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL");

    $defaults = [
        ['CNT-25-SLIM', '2.5 Gallon Slim Container', 20.00],
        ['CNT-5-SLIM', '5 Gallon Slim Container', 20.00],
        ['CNT-5-ROUND', '5 Gallon Round Container', 20.00],
    ];
    $seed = $conn->prepare("INSERT IGNORE INTO inventory_items (item_code,item_name,category,quantity,minimum_stock,unit_price) VALUES (?,?,'Container',0,10,?)");
    if ($seed) foreach ($defaults as $row) { $seed->bind_param('ssd', $row[0], $row[1], $row[2]); $seed->execute(); }
}

function inventory_code_for_container(string $containerSize): ?string {
    return ['2.5gal-slim'=>'CNT-25-SLIM','5gal-slim'=>'CNT-5-SLIM','5gal-round'=>'CNT-5-ROUND'][$containerSize] ?? null;
}

function add_user_notification($conn, string $userId, ?string $transactionId, string $title, string $message, string $type = 'info'): void {
    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id,transaction_id,title,message,notification_type) VALUES (?,?,?,?,?)");
    if ($stmt) { $stmt->bind_param('sssss', $userId, $transactionId, $title, $message, $type); $stmt->execute(); }
}

function release_order_inventory($conn, array $order, string $actor, string $reason): bool {
    if (empty($order['inventory_reserved']) || empty($order['inventory_item_id']) || (int)$order['quantity'] < 1) return true;
    $itemId=(int)$order['inventory_item_id']; $qty=(int)$order['quantity'];
    $q=$conn->query("SELECT quantity FROM inventory_items WHERE id=$itemId FOR UPDATE");
    $item=$q?$q->fetch_assoc():null; if(!$item)return false;
    $before=(int)$item['quantity'];$after=$before+$qty;
    if(!$conn->query("UPDATE inventory_items SET quantity=$after,updated_by='".$conn->real_escape_string($actor)."' WHERE id=$itemId"))return false;
    $log=$conn->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_change,previous_quantity,new_quantity,reason,staff_id) VALUES (?,'release',?,?,?,?,?)");
    $change=$qty;if($log){$log->bind_param('iiiiss',$itemId,$change,$before,$after,$reason,$actor);$log->execute();}
    return (bool)$conn->query("UPDATE transactions SET inventory_reserved=0 WHERE transaction_id='".$conn->real_escape_string($order['transaction_id'])."'");
}

function approve_or_cancel_order_for_stock($conn, string $transactionId, string $staffId): array {
    $safeId=$conn->real_escape_string($transactionId);$safeStaff=$conn->real_escape_string($staffId);
    $conn->begin_transaction();
    $result=$conn->query("SELECT * FROM transactions WHERE transaction_id='$safeId' FOR UPDATE");
    $order=$result?$result->fetch_assoc():null;
    if(!$order){$conn->rollback();return [false,'Order not found.'];}
    if((string)($order['status']??'')!=='pending'){$conn->rollback();return [false,'Only pending orders can be approved.'];}

    $containerStatus=(string)($order['container_status']??'');
    $containerSize=(string)($order['container_size']??'');
    // Support orders created before dedicated inventory columns were added.
    if($containerStatus==='' && stripos((string)($order['description']??''),'(New)')!==false)$containerStatus='new';
    if($containerSize===''){
        $description=(string)($order['description']??'');
        if(stripos($description,'2.5 Gallon')!==false)$containerSize='2.5gal-slim';
        elseif(stripos($description,'5 Gallon (Slim)')!==false)$containerSize='5gal-slim';
        elseif(stripos($description,'5 Gallon (Round)')!==false)$containerSize='5gal-round';
    }
    $isNew=$containerStatus==='new';
    if($isNew && empty($order['inventory_reserved'])){
        $code=inventory_code_for_container($containerSize);$safeCode=$conn->real_escape_string((string)$code);
        $stock=$code?$conn->query("SELECT id,quantity,item_name FROM inventory_items WHERE item_code='$safeCode' FOR UPDATE"):false;
        $item=$stock?$stock->fetch_assoc():null;$needed=(int)$order['quantity'];
        if(!$item || (int)$item['quantity']<$needed){
            $reason='Automatically cancelled: requested container is out of stock.';
            $safeReason=$conn->real_escape_string($reason);
            $conn->query("UPDATE transactions SET status='denied',cancellation_reason='$safeReason',approved_by='$safeStaff' WHERE transaction_id='$safeId'");
            add_user_notification($conn,(string)$order['user_id'],$transactionId,'Order cancelled — out of stock',$reason,'stock');
            $conn->commit();return [false,$reason];
        }
        $itemId=(int)$item['id'];$before=(int)$item['quantity'];$after=$before-$needed;
        $conn->query("UPDATE inventory_items SET quantity=$after,updated_by='$safeStaff' WHERE id=$itemId");
        $reason="Reserved for order $transactionId";$log=$conn->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_change,previous_quantity,new_quantity,reason,staff_id) VALUES (?,'order',?,?,?,?,?)");
        $change=-$needed;if($log){$log->bind_param('iiiiss',$itemId,$change,$before,$after,$reason,$staffId);$log->execute();}
        $conn->query("UPDATE transactions SET inventory_item_id=$itemId,inventory_reserved=1 WHERE transaction_id='$safeId'");
    }
    $fulfillment=(string)($order['fulfillment_method']??'delivery');
    $nextDeliveryStatus=$fulfillment==='pickup'?'ready_for_pickup':'pending';
    $pointsPerGallon=system_int_setting($conn,'points_per_gallon',1,0,100);
    $earnedPoints=max(0,(int)($order['quantity']??0))*$pointsPerGallon;
    if($earnedPoints>0){
        $safeUserId=$conn->real_escape_string((string)$order['user_id']);
        if(!$conn->query("UPDATE users SET loyalty_points=COALESCE(loyalty_points,0)+$earnedPoints WHERE user_id='$safeUserId'")){
            $conn->rollback();return [false,'The order was not approved because loyalty points could not be recorded.'];
        }
    }
    if(!$conn->query("UPDATE transactions SET status='approved',delivery_status='$nextDeliveryStatus',rider_id=NULL,approved_by='$safeStaff',loyalty_points_earned=$earnedPoints WHERE transaction_id='$safeId'")){
        $conn->rollback();return [false,'The order approval could not be saved.'];
    }
    $approvalMessage=$fulfillment==='pickup'
        ? 'Your order has been approved and will be prepared for pickup at the station.'
        : 'Your order has been approved and is being prepared for delivery.';
    add_user_notification($conn,(string)$order['user_id'],$transactionId,'Order approved',$approvalMessage,'success');
    $conn->commit();return [true,'Order approved successfully.'];
}

function deny_order_and_release_stock($conn, string $transactionId, string $staffId, string $reason='Order cancelled by staff.'): bool {
    $safeId=$conn->real_escape_string($transactionId);$safeStaff=$conn->real_escape_string($staffId);$safeReason=$conn->real_escape_string($reason);
    $conn->begin_transaction();$result=$conn->query("SELECT * FROM transactions WHERE transaction_id='$safeId' FOR UPDATE");$order=$result?$result->fetch_assoc():null;
    if(!$order){$conn->rollback();return false;}
    if(!release_order_inventory($conn,$order,$staffId,"Released: $reason")){$conn->rollback();return false;}
    $conn->query("UPDATE transactions SET status='denied',rider_id=NULL,approved_by='$safeStaff',cancellation_reason='$safeReason' WHERE transaction_id='$safeId'");
    add_user_notification($conn,(string)$order['user_id'],$transactionId,'Order cancelled',$reason,'warning');$conn->commit();return true;
}
