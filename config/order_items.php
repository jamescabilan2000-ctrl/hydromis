<?php
function render_order_items($conn,array $order): void {
    if (!str_starts_with((string)($order['description']??''),'Cart order:')) return;
    $stmt=$conn->prepare('SELECT container_size,container_status,quantity,line_total FROM transaction_items WHERE transaction_id=? ORDER BY id');
    if (!$stmt) return;
    $stmt->bind_param('s',$order['transaction_id']); $stmt->execute(); $rows=$stmt->get_result();
    $names=['5gal-round'=>'19L Round','5gal-slim'=>'19L Slim','2.5gal-slim'=>'9.5L Slim'];
    echo '<section style="padding:14px 0;border-bottom:1px solid #dce7ed"><strong>Order items</strong><ul style="padding-left:20px;margin:10px 0;line-height:1.8">';
    while($line=$rows->fetch_assoc()) {
        $label=str_starts_with($line['container_size'],'cup')?(['cup-slim'=>'Slim Cup','cup-round'=>'Round Cup'][$line['container_size']]??'Cups'):($names[$line['container_size']]??$line['container_size']).($line['container_status']==='new'?' New Gallon':' Refill');
        echo '<li>'.(int)$line['quantity'].' &times; '.htmlspecialchars($label,ENT_QUOTES,'UTF-8').' &mdash; PHP '.number_format($line['line_total'],2).'</li>';
    }
    echo '</ul></section>';
}
