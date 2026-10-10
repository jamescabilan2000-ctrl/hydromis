<?php
function transaction_cancelled_by_customer(array $order): bool {
    return in_array(strtolower((string)($order['status'] ?? '')), ['denied', 'cancelled'], true)
        && str_starts_with(trim((string)($order['cancellation_reason'] ?? '')), 'Cancelled by customer');
}
