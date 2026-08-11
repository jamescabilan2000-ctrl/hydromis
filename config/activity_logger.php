<?php

if (!function_exists('ensure_activity_log_schema')) {
    function ensure_activity_log_schema($conn): void {
        static $ready = false;
        if ($ready) return;
        $conn->query("CREATE TABLE IF NOT EXISTS system_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id VARCHAR(80) NULL,
            actor_name VARCHAR(255) NULL,
            actor_role VARCHAR(30) NOT NULL DEFAULT 'guest',
            action VARCHAR(80) NOT NULL,
            description VARCHAR(500) NOT NULL,
            page VARCHAR(255) NULL,
            request_method VARCHAR(10) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            metadata LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_created (created_at),
            INDEX idx_activity_actor (actor_role, actor_id),
            INDEX idx_activity_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
    }

    function activity_actor(): array {
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        $actorId = '';
        if ($role === 'admin') $actorId = (string)($_SESSION['admin_auth_id'] ?? $_SESSION['admin_id'] ?? '');
        elseif ($role === 'staff') $actorId = (string)($_SESSION['staff_auth_id'] ?? $_SESSION['admin_id'] ?? '');
        elseif ($role === 'rider') $actorId = (string)($_SESSION['rider_auth_id'] ?? $_SESSION['rider_id'] ?? '');
        else {
            $customerId = (string)($_SESSION['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? '');
            if ($customerId !== '') {
                $role = 'customer';
                $actorId = $customerId;
            }
        }
        return [
            'id' => $actorId !== '' ? $actorId : null,
            'name' => (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ($role === 'customer' ? 'Customer' : 'Guest')),
            'role' => $role !== '' ? $role : 'guest'
        ];
    }

    function activity_safe_metadata(array $extra = []): array {
        $allowed = ['action', 'transaction_id', 'view', 'role', 'status', 'delivery_status', 'payment_status', 'rider_id', 'filter', 'page'];
        $metadata = $extra;
        foreach ($allowed as $key) {
            if (isset($_POST[$key]) && is_scalar($_POST[$key])) $metadata[$key] = substr((string)$_POST[$key], 0, 160);
            elseif (isset($_GET[$key]) && is_scalar($_GET[$key])) $metadata[$key] = substr((string)$_GET[$key], 0, 160);
        }
        return $metadata;
    }

    function log_system_activity($conn, string $action, string $description, array $metadata = [], ?array $actorOverride = null): bool {
        ensure_activity_log_schema($conn);
        $actor = $actorOverride ?: activity_actor();
        $page = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 500);
        $encodedMetadata = json_encode(activity_safe_metadata($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT INTO system_activity_logs
            (actor_id, actor_name, actor_role, action, description, page, request_method, ip_address, user_agent, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $actorId = $actor['id'];
        $actorName = substr((string)$actor['name'], 0, 255);
        $actorRole = substr((string)$actor['role'], 0, 30);
        $action = substr($action, 0, 80);
        $description = substr($description, 0, 500);
        $stmt->bind_param('ssssssssss', $actorId, $actorName, $actorRole, $action, $description, $page, $method, $ip, $agent, $encodedMetadata);
        return $stmt->execute();
    }

    function auto_log_system_request($conn): void {
        if (defined('HYDROMIS_DISABLE_AUTO_ACTIVITY') && HYDROMIS_DISABLE_AUTO_ACTIVITY) return;
        if (PHP_SAPI === 'cli') return;
        $ajax = (string)($_GET['ajax'] ?? '');
        if (in_array($ajax, ['get_location', 'notifications', 'delivery_info'], true)) return;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $page = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'page');
        if ($method === 'POST') {
            $action = (string)($_POST['action'] ?? (isset($_POST['login_submit']) ? 'login_attempt' : 'form_submit'));
            log_system_activity($conn, $action, "Submitted {$page}");
        } else {
            $action = $ajax !== '' ? 'ajax_' . $ajax : 'page_view';
            log_system_activity($conn, $action, "Viewed {$page}");
        }
    }
}
