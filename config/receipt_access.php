<?php
function can_access_checkout_receipt(array $order,array $session): bool {
    $owner=(string)($session['checkout_receipts'][(string)$order['transaction_id']]??'');
    return $owner!=='' && hash_equals((string)$order['user_id'],$owner);
}
