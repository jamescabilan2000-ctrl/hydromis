<?php
require_once __DIR__ . '/guest_ordering.php';

function order_checkout_contact(array $customer, array $input, string $fulfillment): array {
    if (guest_order_identity((string)$customer['user_id'])) {
        [$data, $errors] = validate_order_contact($input, $fulfillment);
        return ['data' => $data, 'errors' => $errors];
    }
    // Existing account orders retain their saved destination and identity.
    $data = ['full_name' => (string)$customer['full_name'], 'contact_number' => (string)$customer['contact_number'], 'address' => $fulfillment === 'delivery' ? (string)($customer['address'] ?? '') : '', 'barangay' => '', 'purok' => ''];
    $errors = $fulfillment === 'delivery' && trim($data['address']) === '' ? ['A delivery address is required. Update your address before ordering.'] : [];
    return ['data' => $data, 'errors' => $errors];
}
