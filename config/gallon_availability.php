<?php
require_once __DIR__.'/inventory_service.php';

/** Read current inventory without creating or changing stock. */
function gallon_inventory_snapshot($conn): array {
    $snapshot=['sizes'=>[],'new'=>null];
    if (!$conn || !empty($conn->connect_error)) return $snapshot;
    $result=$conn->query('SELECT item_code,item_name,category,quantity,minimum_stock FROM inventory_items ORDER BY id');
    if (!$result) return $snapshot;
    while($row=$result->fetch_assoc()) {
        foreach(['5gal-round','5gal-slim','2.5gal-slim'] as $size) {
            if ($row['item_code']===inventory_code_for_container($size)) $snapshot['sizes'][$size]=['quantity'=>max(0,(int)$row['quantity']),'minimum'=>(int)$row['minimum_stock']];
        }
        if ($snapshot['new']===null && $row['category']==='Container' && strtolower(trim($row['item_name']))==='new container') $snapshot['new']=['quantity'=>max(0,(int)$row['quantity']),'minimum'=>(int)$row['minimum_stock']];
    }
    return $snapshot;
}

function gallon_availability(array $snapshot,string $size,bool $new=false): array {
    $stock=$snapshot['sizes'][$size]??null;
    if ($new && $stock) {
        $extra=$snapshot['new']??['quantity'=>0,'minimum'=>0];
        $stock=['quantity'=>min($stock['quantity'],$extra['quantity']),'minimum'=>max($stock['minimum'],$extra['minimum'])];
    }
    if ($stock===null) return ['state'=>'unknown','quantity'=>null,'label'=>'Stock unavailable','available'=>false];
    $quantity=$stock['quantity'];
    if ($quantity===0) return ['state'=>'out','quantity'=>0,'label'=>'Out of stock','available'=>false];
    $low=$quantity<=$stock['minimum'];
    return ['state'=>$low?'low':'in','quantity'=>$quantity,'label'=>($low?'Low stock':'Available').' · '.number_format($quantity).' '.($quantity===1?'gallon':'gallons'),'available'=>true];
}

function gallon_stock_badge(array $availability): string {
    return '<span class="gallon-stock stock-'.htmlspecialchars($availability['state']).'">'.htmlspecialchars($availability['label'],ENT_QUOTES,'UTF-8').'</span>';
}
