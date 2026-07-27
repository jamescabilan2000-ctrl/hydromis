<?php
require_once '../config/database.php';
require_once '../config/inventory_service.php';
ensure_inventory_schema($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = trim((string)($_REQUEST['user_id'] ?? ''));
if ($userId === '' || strlen($userId) > 255) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'A valid user ID is required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notificationId=(int)($_POST['notification_id']??0);
    $stmt=$conn->prepare("UPDATE user_notifications SET is_read=1 WHERE id=? AND user_id=?");
    $stmt->bind_param('is',$notificationId,$userId);$stmt->execute();
    echo json_encode(['success'=>true]);exit;
}

$stmt=$conn->prepare("SELECT id,transaction_id,title,message,notification_type,created_at FROM user_notifications WHERE user_id=? AND is_read=0 ORDER BY created_at ASC LIMIT 20");
$stmt->bind_param('s',$userId);$stmt->execute();$result=$stmt->get_result();$rows=[];
while($row=$result->fetch_assoc()){$rows[]=$row;}
echo json_encode(['success'=>true,'notifications'=>$rows]);
