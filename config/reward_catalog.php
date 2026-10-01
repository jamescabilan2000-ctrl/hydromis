<?php
require_once __DIR__ . '/system_settings.php';

function default_reward_catalog(): array {
return [
    [
        'code' => 'free_1_gallon',
        'title' => 'Free 1 Gallon Regular Water',
        'description' => 'Instantly redeem at cashier after purchase.',
        'points' => 50,
        'tag' => 'Water Reward'
    ],
    [
        'code' => 'voucher_20',
        'title' => 'Discount Voucher',
        'description' => 'Get P20 off on your next refill order.',
        'points' => 100,
        'tag' => 'Voucher'
    ],
    [
        'code' => 'delivery_discount',
        'title' => 'Delivery Fee Discount',
        'description' => 'Get P20 off the delivery fee on your next order.',
        'points' => 125,
        'tag' => 'Delivery Perk'
    ],
    [
        'code' => 'bundle_fast_lane',
        'title' => 'Free 1 Gallons Bundle',
        'description' => 'Fast-lane service on your next visit.',
        'points' => 150,
        'tag' => 'Service Perk'
    ],
    [
        'code' => 'free_delivery',
        'title' => 'Free Delivery',
        'description' => 'Enjoy free delivery on your next eligible water order.',
        'points' => 200,
        'tag' => 'Delivery Reward'
    ],
    [
        'code' => 'bundle_2_gallons',
        'title' => 'Free 2 Gallons Bundle',
        'description' => 'Best value bundle for loyal customers.',
        'points' => 250,
        'tag' => 'Premium Reward'
    ],
];
}

function ensure_reward_catalog_schema($conn): void {
    if (!$conn->query("CREATE TABLE IF NOT EXISTS custom_rewards (
        code VARCHAR(80) PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        description VARCHAR(500) NOT NULL,
        points INT NOT NULL,
        tag VARCHAR(40) NOT NULL,
        created_by VARCHAR(80) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Unable to load reward catalog.');
    }
}

function system_reward_catalog($conn): array {
    ensure_reward_catalog_schema($conn);
    $catalog = array_column(default_reward_catalog(), null, 'code');
    $result = $conn->query('SELECT code, title, description, points, tag FROM custom_rewards ORDER BY created_at, code');
    if (!$result) throw new RuntimeException('Unable to load rewards.');
    while ($row = $result->fetch_assoc()) {
        $row['points'] = (int)$row['points'];
        $catalog[$row['code']] = $row;
    }
    return array_values($catalog);
}

function validate_new_reward(array $input): array {
    $reward = [];
    foreach (['title' => 120, 'description' => 500, 'tag' => 40] as $field => $limit) {
        if (!isset($input[$field]) || !is_string($input[$field])) throw new InvalidArgumentException('Enter a reward title, description, and category.');
        $value = trim($input[$field]);
        if ($value === '' || mb_strlen($value) > $limit) throw new InvalidArgumentException(ucfirst($field) . ' is required and must be at most ' . $limit . ' characters.');
        $reward[$field] = $value;
    }
    $points = $input['points'] ?? null;
    if (!is_scalar($points) || !preg_match('/\A[1-9][0-9]{0,5}\z/', (string)$points)) throw new InvalidArgumentException('Points must be a whole number between 1 and 999999.');
    $reward['points'] = (int)$points;
    return $reward;
}
