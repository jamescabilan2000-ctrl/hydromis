<?php
/** Shared ownership rules for delivery pages and API responses. */
function delivery_assigned_rider(array $order): string {
    return trim((string)($order['rider_id'] ?? '')) ?: trim((string)($order['assigned_rider'] ?? ''));
}

function delivery_actor_can_access(array $order, array $session, bool $write = false): bool {
    $rider = (string)($session['rider_auth_id'] ?? (($session['role'] ?? '') === 'rider' ? ($session['rider_id'] ?? '') : ''));
    if ($write) {
        return $rider !== '' && hash_equals(delivery_assigned_rider($order), $rider)
            && ($order['status'] ?? '') === 'approved'
            && ($order['fulfillment_method'] ?? 'delivery') === 'delivery';
    }
    $customer = (string)($session['customer_user_id'] ?? '');
    return ($customer !== '' && hash_equals((string)$order['user_id'], $customer))
        || ($rider !== '' && hash_equals(delivery_assigned_rider($order), $rider));
}

function delivery_coordinates_valid($latitude, $longitude): bool {
    return is_numeric($latitude) && is_numeric($longitude)
        && is_finite((float)$latitude) && is_finite((float)$longitude)
        && (float)$latitude >= -90 && (float)$latitude <= 90
        && (float)$longitude >= -180 && (float)$longitude <= 180;
}
