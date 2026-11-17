<?php
require_once __DIR__.'/customer_address.php';
require_once __DIR__.'/delivery_destination.php';
class CartValidationException extends InvalidArgumentException {
    public array $fields;
    public function __construct(array $fields) { $this->fields=$fields;parent::__construct((string)reset($fields)); }
}

function cart_mobile(string $value): string {
    if (preg_match('/[\x00-\x1F\x7F]/',$value)) throw new InvalidArgumentException('Remove invalid characters from the mobile number.');
    $value=trim($value);
    if (preg_match('/\A09\d{9}\z/',$value)) return $value;
    if (preg_match('/\A\+?639\d{9}\z/',$value)) return '0'.substr(ltrim($value,'+'),2);
    throw new InvalidArgumentException('Enter 09 followed by 9 digits, or the equivalent +63 mobile number.');
}

/** Validate the complete form before uploading files or starting checkout. */
function validate_cart_checkout(array $input,string $fulfillment): array {
    // A map pin supplies navigation coordinates; the customer's typed
    // street/purok and barangay remain the authoritative address label.
    $errors=[];$clean=[];
    if (!in_array($fulfillment,['delivery','pickup'],true)) $errors['fulfillment']='Choose delivery or pickup in the cart.';
    foreach (['mobile_number','payment_method','expected_total','notes','delivery_address','delivery_pin_address','delivery_barangay','delivery_street','delivery_latitude','delivery_longitude','wallet_number'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {$errors[$field]='Enter a valid value for this field.';$clean[$field]='';}
        else {
            $raw=$input[$field]??'';
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$raw)) $errors[$field]='Remove invalid control characters from this field.';
            $clean[$field]=trim($raw);
        }
    }
    try {$clean['mobile_number']=cart_mobile($clean['mobile_number']);}
    catch(InvalidArgumentException $e){$errors['mobile_number']=$e->getMessage();}
    if (!in_array($clean['payment_method'],['cash','gcash','maya'],true)) $errors['payment_method']='Choose cash, GCash or Maya.';
    if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/',$clean['expected_total'])) $errors['expected_total']='The order total is invalid. Refresh the cart and review it again.';
    foreach (['notes'=>500,'delivery_address'=>500,'delivery_pin_address'=>500] as $field=>$limit) {
        if (mb_strlen($clean[$field])>$limit) $errors[$field]='Use no more than '.$limit.' characters.';
        elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$clean[$field])) $errors[$field]='Remove invalid control characters from this field.';
    }
    if ($fulfillment==='delivery') {
        $barangay=$clean['delivery_barangay'];$street=$clean['delivery_street'];
        if ($barangay!=='' && !in_array($barangay,customer_barangays(),true)) $errors['delivery_barangay']='Select a barangay from the registration choices.';
        if ($street!=='' && (mb_strlen($street)>100 || !preg_match('/\A[\p{L}\d ]+\z/u',$street))) $errors['delivery_street']='Street or purok must use letters, numbers and spaces only, up to 100 characters.';
        if ($barangay!=='' && $street==='') $errors['delivery_street']='Enter the street number or purok for this barangay.';
        if ($street!=='' && $barangay==='') $errors['delivery_barangay']='Select the barangay for this street or purok.';
        if ($clean['delivery_address']!=='' && $barangay==='' && $street==='') $errors['delivery_barangay']='Use the barangay and Street / Purok fields to enter the delivery address.';
        $clean['delivery_address']=$barangay!=='' && $street!=='' ? $street.', '.$barangay : '';
        $lat=$clean['delivery_latitude'];$lng=$clean['delivery_longitude'];
        if (($lat==='')!==($lng==='')) {$errors['delivery_latitude']='Choose a complete location pin or clear both coordinates.';$errors['delivery_longitude']=$errors['delivery_latitude'];}
        else foreach (['delivery_latitude'=>90,'delivery_longitude'=>180] as $field=>$limit) {
            if ($clean[$field]!=='' && (!preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/',$clean[$field]) || !is_finite((float)$clean[$field]) || abs((float)$clean[$field])>$limit)) $errors[$field]='Enter a coordinate between -'.$limit.' and '.$limit.'.';
        }
        if ($clean['delivery_address']==='' && $lat!=='' && $lng!=='' && delivery_destination($lat,$lng) && $clean['delivery_pin_address']!=='') {
            $clean['delivery_address']=$clean['delivery_pin_address'];
        }
    } else {$clean['delivery_address']='';$clean['delivery_latitude']='';$clean['delivery_longitude']='';}
    if (in_array($clean['payment_method'],['gcash','maya'],true)) {
        if (!preg_match('/\A09\d{9}\z/',$clean['wallet_number'])) $errors['wallet_number']='Enter the 11-digit wallet number beginning with 09.';
    }
    if ($errors) throw new CartValidationException($errors);
    return $clean;
}
