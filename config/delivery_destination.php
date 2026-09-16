<?php
// A delivery destination is customer-confirmed, never a station fallback.
function delivery_destination($latitude, $longitude): ?array {
    if (!is_numeric($latitude) || !is_numeric($longitude)) return null;
    $lat = (float)$latitude;
    $lng = (float)$longitude;
    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat, $lng];
}
