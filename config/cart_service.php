<?php
require_once __DIR__ . '/inventory_service.php';
require_once __DIR__ . '/delivery_destination.php';
require_once __DIR__ . '/cart_validation.php';

function cart_customer_by_mobile($conn,string $input): ?array {
    $mobile=cart_mobile($input);
    $lookup=sensitive_lookup($mobile);
    $stmt=$conn->prepare('SELECT user_id,status FROM users WHERE contact_lookup=? LIMIT 1');
    $stmt->bind_param('s',$lookup); $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function cart_catalog(): array {
    return [
        'cup:accessory'=>['name'=>'Cups','image'=>''],
        'cup-slim:accessory'=>['name'=>'Slim Cup','image'=>'slimcup.jpg'],
        'cup-round:accessory'=>['name'=>'Round Cup','image'=>'Gemini_Generated_Image_bie8b4bie8b4bie8.jpg'],
        '5gal-round:existing'=>['name'=>'19L Round Refill','image'=>'water5.webp'],
        '5gal-slim:existing'=>['name'=>'19L Slim Refill','image'=>'water4.webp'],
        '2.5gal-slim:existing'=>['name'=>'9.5L Slim Refill','image'=>'water3.jpg'],
        '5gal-round:new'=>['name'=>'19L Round New Gallon','image'=>'water5.webp'],
        '5gal-slim:new'=>['name'=>'19L Slim New Gallon','image'=>'water4.webp'],
        '2.5gal-slim:new'=>['name'=>'9.5L Slim New Gallon','image'=>'water3.jpg'],
    ];
}

function cart_items(): array {
    $items=[];
    $saved=$_SESSION['hydromis_cart']??[];
    foreach ((is_array($saved)?$saved:[]) as $key=>$item) {
        if (!isset(cart_catalog()[$key]) || !is_array($item)) continue;
        [$size,$status]=explode(':',$key);
        $quantity=filter_var($item['quantity'] ?? null,FILTER_VALIDATE_INT);
        if ($quantity===false || $quantity<1 || $quantity>99) continue;
        $items[$key]=['container_size'=>$size,'container_status'=>$status,'quantity'=>$quantity];
    }
    return $items;
}

function cart_change(array $input): void {
    if (!is_string($input['action']??null)) throw new InvalidArgumentException('Invalid cart request.');
    if ($input['action']==='clear') { $_SESSION['hydromis_cart']=[];return; }
    if ($input['action']==='container_status') {
        $status=$input['container_status']??null;
        if (!in_array($status,['existing','new'],true)) throw new InvalidArgumentException('Choose refill or new gallon.');
        $converted=[];
        foreach (cart_items() as $key=>$item) {
            if (str_starts_with($item['container_size'],'cup')) { $converted[$key]=$item; continue; }
            $key=$item['container_size'].':'.$status;
            $quantity=($converted[$key]['quantity']??0)+$item['quantity'];
            if ($quantity>99) throw new InvalidArgumentException('Maximum 99 gallons per size. Update your cart before changing container type.');
            $converted[$key]=['container_size'=>$item['container_size'],'container_status'=>$status,'quantity'=>$quantity];
        }
        $_SESSION['hydromis_cart']=$converted;
        return;
    }
    if (!is_string($input['key']??null)) throw new InvalidArgumentException('Invalid cart request.');
    $items=cart_items(); $action=$input['action'];
    $key=$input['key'];
    if (!isset(cart_catalog()[$key])) throw new InvalidArgumentException('Choose a valid product.');
    if ($action==='remove') unset($items[$key]);
    elseif ($action==='decrement') {
        if (isset($items[$key])) {
            if ($items[$key]['quantity']<=1) unset($items[$key]);
            else $items[$key]['quantity']--;
        }
    }
    elseif (in_array($action,['add','quantity'],true)) {
        $raw=array_key_exists('quantity',$input)?$input['quantity']:($action==='add'?1:null);
        if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A[1-9]\d?\z/',(string)$raw)) throw new InvalidArgumentException('Quantity must be a whole number from 1 to 99.');
        $quantity=(int)$raw;
        if ($quantity===false || $quantity<1 || $quantity>99) throw new InvalidArgumentException('Quantity must be from 1 to 99.');
        if ($action==='add') $quantity+=(int)($items[$key]['quantity']??0);
        if ($quantity>99) throw new InvalidArgumentException('Maximum 99 units per product.');
        [$size,$status]=explode(':',$key);
        $items[$key]=['container_size'=>$size,'container_status'=>$status,'quantity'=>$quantity];
    } else throw new InvalidArgumentException('Invalid cart action.');
    $_SESSION['hydromis_cart']=$items;
}

function ensure_cart_schema($conn): void {
    ensure_inventory_schema($conn);
    $sql=file_get_contents(__DIR__.'/../database/migrations/20261024_add_transaction_items.sql');
    if (!$conn->query($sql)) throw new RuntimeException('Cart storage could not be prepared.');
}

function cart_free_delivery_claim($conn,string $userId,bool $lock=false): ?array {
    if ($userId==='') return null;
    $table=$conn->query("SHOW TABLES LIKE 'reward_claims'");
    if (!$table || !$table->num_rows) return null;
    $sql="SELECT id,transaction_id FROM reward_claims WHERE user_id=? AND reward_code='free_delivery' AND claim_status='approved' ORDER BY created_at,id LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$conn->prepare($sql); if (!$stmt) return null;
    $stmt->bind_param('s',$userId);$stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function cart_cup_product($conn): array {
    return ['item_name'=>'Cups (any size)','unit_price'=>20.00];
}

function cart_quote(array $items, array $prices, string $mode, array $rules, string $fulfillment, ?array $cup=null): array {
    if (!in_array($fulfillment,['delivery','pickup'],true)) throw new InvalidArgumentException('Choose delivery or pickup.');
    $tierQuantity=0; $quantity=0; $subtotal=0; $lines=[];
    foreach ($items as $key=>$item) {
        if (!isset(cart_catalog()[$key]) || !is_array($item) || !isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity']<1 || $item['quantity']>99 || $key!==($item['container_size']??'').':'.($item['container_status']??'')) throw new InvalidArgumentException('Invalid cart item or quantity.');
        if ($item['container_status']==='existing' && refill_unit_price($item['container_size'],$prices,$mode)===null) $tierQuantity+=$item['quantity'];
    }
    $tierUnit=$tierQuantity ? water_order_total($tierQuantity,null,$rules)/$tierQuantity : 0;
    foreach ($items as $key=>$item) {
        if (str_starts_with($item['container_size'],'cup')) {
            if (!$cup || (float)$cup['unit_price']<=0 || !is_finite((float)$cup['unit_price'])) throw new InvalidArgumentException('Cups are currently unavailable. Remove them to continue.');
            $unit=round((float)$cup['unit_price'],2);
            $total=round($unit*$item['quantity'],2);
            $lines[$key]=$item+['name'=>cart_catalog()[$key]['name'],'image'=>cart_catalog()[$key]['image'],'unit_price'=>$unit,'line_total'=>$total];
            $subtotal+=$total;
            continue;
        }
        $unit=$item['container_status']==='new' ? $prices[$item['container_size']]['container'] : (refill_unit_price($item['container_size'],$prices,$mode) ?? $tierUnit);
        $total=round($unit*$item['quantity'],2);
        $lines[$key]=$item+cart_catalog()[$key]+['unit_price'=>$unit,'line_total'=>$total];
        $quantity+=$item['quantity']; $subtotal+=$total;
    }
    $delivery=$fulfillment==='delivery' ? round($rules['delivery_unit']*$quantity,2) : 0;
    return ['lines'=>$lines,'quantity'=>$quantity,'subtotal'=>round($subtotal,2),'delivery'=>$delivery,'total'=>round($subtotal+$delivery,2)];
}

/** One parent transaction, payment and reservation set for the entire cart. */
function place_cart_order($conn,string $userId,array $items,string $fulfillment,string $payment,string $notes,?float $expectedTotal=null,array $destination=[],array $wallet=[]): string {
    if (!$items) throw new InvalidArgumentException('Your cart is empty.');
    if (mb_strlen($notes)>500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$notes)) throw new InvalidArgumentException('Order notes must contain no more than 500 valid text characters.');
    if ($expectedTotal!==null && (!is_finite($expectedTotal) || $expectedTotal<0)) throw new InvalidArgumentException('Refresh the cart to review a valid order total.');
    if (!in_array($payment,['cash','gcash','maya'],true)) throw new InvalidArgumentException('Choose a payment method.');
    $paymentStatus='pending';$proof=null;$gcash=null;$maya=null;
    if ($payment!=='cash') {
        if (!is_string($wallet['number']??null) || !is_string($wallet['proof']??null) || !preg_match('/\A09\d{9}\z/',$wallet['number']) || !preg_match('#\Auploads/payment_proofs/[a-zA-Z0-9_.-]+\z#',$wallet['proof']) || !is_file(__DIR__.'/../'.$wallet['proof'])) throw new InvalidArgumentException('A valid wallet mobile number and payment proof are required before placing the order.');
        $paymentStatus='processing';$proof=$wallet['proof'];
        if ($payment==='gcash') $gcash=$wallet['number'];else $maya=$wallet['number'];
    }
    // Schema/settings work must finish before opening a transaction (DDL commits implicitly).
    ensure_cart_schema($conn);
    $cup=cart_cup_product($conn);
    $quote=cart_quote($items,system_container_prices($conn),system_refill_pricing_mode($conn),system_order_pricing_rules($conn),$fulfillment,$cup);
    if ($quote['quantity']<1) throw new InvalidArgumentException('Add a refill or a new gallon with your cups.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start checkout.');
    try {
        $stmt=$conn->prepare('SELECT status,address FROM users WHERE user_id=? FOR UPDATE');
        $stmt->bind_param('s',$userId); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
        if (!$user || !in_array($user['status'],['approved','pending'],true)) throw new RuntimeException('This customer account is unavailable.');
        foreach (['address','latitude','longitude'] as $field) if (isset($destination[$field]) && !is_scalar($destination[$field])) throw new InvalidArgumentException('Invalid delivery destination.');
        $address=trim((string)($destination['address']??''));
        if (mb_strlen($address)>500) throw new InvalidArgumentException('Delivery address must be 500 characters or fewer.');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$address)) throw new InvalidArgumentException('Remove invalid control characters from the delivery address.');
        $pin=delivery_destination($destination['latitude']??null,$destination['longitude']??null);
        if ((($destination['latitude']??'')!=='' || ($destination['longitude']??'')!=='') && !$pin) throw new InvalidArgumentException('Choose a valid location pin or leave both coordinates empty.');
        $registeredAddress=trim((string)($user['address']??''));
        $address=$fulfillment==='delivery' ? ($address!==''?$address:($registeredAddress!==''?$registeredAddress:($pin?'Delivery to selected map pin':''))) : '';
        if ($fulfillment==='delivery' && $address==='' && !$pin) throw new InvalidArgumentException('Add a delivery address or a location pin.');
        $latitude=$fulfillment==='delivery' ? ($pin[0]??null) : null;
        $longitude=$fulfillment==='delivery' ? ($pin[1]??null) : null;
        $savedAddress=encrypt_sensitive($address);
        $stmt=$conn->prepare("SELECT transaction_id FROM transactions WHERE user_id=? AND transaction_id NOT LIKE 'RWD-%' AND (status='pending' OR (status='approved' AND COALESCE(delivery_status,'pending')<>'delivered')) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('s',$userId); $stmt->execute();
        if ($stmt->get_result()->num_rows) throw new RuntimeException('You already have an active order. Complete or cancel it before placing another.');
        $reward=$fulfillment==='delivery' ? cart_free_delivery_claim($conn,$userId,true) : null;
        if ($reward) {
            $quote['total']=round($quote['total']-$quote['delivery'],2);$quote['delivery']=0;
            $rewardId=(int)$reward['id'];
            if (!$conn->query("UPDATE reward_claims SET claim_status='claimed',claimed_by='ONLINE-ORDER',claimed_at=NOW(),customer_seen_at=NOW() WHERE id=$rewardId AND claim_status='approved'")) throw new RuntimeException('Unable to apply your free delivery reward.');
            $notes=trim($notes."\nFree Delivery reward applied: ".$reward['transaction_id']);
        }
        if ($expectedTotal!==null && abs($expectedTotal-$quote['total'])>0.005) throw new RuntimeException('Prices or delivery rewards changed. Review the refreshed total before placing your order.');
        $id=generateID('TXN');
        $needs=[];
        foreach ($items as $item) {
            if (str_starts_with($item['container_size'],'cup')) {
                continue;
            }
            $code=$conn->real_escape_string(inventory_code_for_container($item['container_size']));
            $result=$conn->query("SELECT id FROM inventory_items WHERE item_code='$code' LIMIT 1");
            $stock=$result?$result->fetch_assoc():null;
            if (!$stock) throw new RuntimeException('A selected gallon is unavailable.');
            $itemId=(int)$stock['id']; $needs[$itemId]=($needs[$itemId]??0)+$item['quantity'];
            if ($item['container_status']==='new') {
                $new=new_container_inventory_item($conn);
                if (!$new) throw new RuntimeException('New containers are unavailable. Please choose a refill or contact the station.');
                $itemId=(int)$new['id']; $needs[$itemId]=($needs[$itemId]??0)+$item['quantity'];
            }
        }
        ksort($needs);
        foreach ($needs as $itemId=>$needed) {
            $result=$conn->query("SELECT quantity,item_name FROM inventory_items WHERE id=$itemId FOR UPDATE");
            $stock=$result?$result->fetch_assoc():null;
            if (!$stock || (int)$stock['quantity']<$needed) throw new RuntimeException(($stock['item_name']??'Selected gallon').' has insufficient stock. Your cart has been kept.');
            $before=(int)$stock['quantity']; $after=$before-$needed;
            if (!$conn->query("UPDATE inventory_items SET quantity=$after,updated_by='CART' WHERE id=$itemId")) throw new RuntimeException('Unable to reserve stock.');
            $reason='Reserved for cart order '.$id; $change=-$needed;
            $stmt=$conn->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_change,previous_quantity,new_quantity,reason,staff_id) VALUES (?,'order',?,?,?,?, 'CART')");
            $stmt->bind_param('iiiis',$itemId,$change,$before,$after,$reason);
            if (!$stmt->execute()) throw new RuntimeException('Unable to record stock reservation.');
        }
        $description='Cart order: '.implode('; ',array_map(static fn($line)=>$line['quantity'].' x '.$line['name'],$quote['lines']));
        $first=reset($items); $size=$first['container_size']; $status=$first['container_status'];
        $quantity=$quote['quantity']; $total=$quote['total']; $unit=$quote['subtotal']/$quantity;
        $stmt=$conn->prepare("INSERT INTO transactions (transaction_id,user_id,amount,description,quantity,price_per_unit,container_size,container_status,fulfillment_method,payment_method,payment_status,status,inventory_reserved,notes) VALUES (?,?,?,?,?,?,?,?,?,?,'pending','pending',1,?)");
        $stmt->bind_param('ssdsidsssss',$id,$userId,$total,$description,$quantity,$unit,$size,$status,$fulfillment,$payment,$notes);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save your order.');
        $stmt=$conn->prepare('UPDATE transactions SET delivery_address=?,delivery_latitude=?,delivery_longitude=?,payment_status=?,payment_proof=? WHERE transaction_id=?');
        $stmt->bind_param('sddsss',$savedAddress,$latitude,$longitude,$paymentStatus,$proof,$id);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save the delivery destination.');
        foreach ($quote['lines'] as $line) {
            $stmt=$conn->prepare('INSERT INTO transaction_items (transaction_id,container_size,container_status,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('sssidd',$id,$line['container_size'],$line['container_status'],$line['quantity'],$line['unit_price'],$line['line_total']);
            if (!$stmt->execute()) throw new RuntimeException('Unable to save all order items.');
        }
        $paymentId=generateID('PAY');
        $stmt=$conn->prepare('INSERT INTO payments (payment_id,transaction_id,user_id,amount,payment_method,payment_status,payment_proof,gcash_number,maya_number) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sssdsssss',$paymentId,$id,$userId,$total,$payment,$paymentStatus,$proof,$gcash,$maya);
        if (!$stmt->execute() || !$conn->commit()) throw new RuntimeException('Unable to save payment.');
        return $id;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
