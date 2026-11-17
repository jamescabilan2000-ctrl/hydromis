<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../config/cart_service.php';
if (empty($_SESSION['cart_csrf'])) $_SESSION['cart_csrf']=bin2hex(random_bytes(32));
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $input=json_decode(file_get_contents('php://input'),true);
        if (!is_array($input) || !is_string($input['csrf_token']??null) || !hash_equals($_SESSION['cart_csrf'],$input['csrf_token'])) {
            http_response_code(403); echo json_encode(['success'=>false,'error'=>'Refresh this page before changing your cart.']); exit;
        }
        if (isset($input['fulfillment']) && !in_array($input['fulfillment'],['delivery','pickup'],true)) throw new InvalidArgumentException('Choose delivery or pickup.');
        cart_change($input);
        if (isset($input['fulfillment'])) $_SESSION['cart_fulfillment']=$input['fulfillment'];
    } elseif ($_SERVER['REQUEST_METHOD']!=='GET') {
        http_response_code(405); header('Allow: GET, POST'); exit;
    }
    $items=cart_items(); $lines=[];
    foreach ($items as $key=>$item) $lines[]=['key'=>$key,'name'=>cart_catalog()[$key]['name'],'quantity'=>$item['quantity']];
    $gallons=array_sum(array_map(static fn($item)=>str_starts_with($item['container_size'],'cup')?0:$item['quantity'],$items));
    echo json_encode(['success'=>true,'csrf_token'=>$_SESSION['cart_csrf'],'count'=>array_sum(array_column($items,'quantity')),'gallons'=>$gallons,'items'=>$lines]);
} catch (InvalidArgumentException $e) { http_response_code(422); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
