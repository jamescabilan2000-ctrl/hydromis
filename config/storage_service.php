<?php

function hydromis_storage_config(): array {
    static $config;
    if (is_array($config)) return $config;
    $config = [
        'url' => getenv('SUPABASE_URL') ?: '',
        'secret_key' => getenv('SUPABASE_SECRET_KEY') ?: '',
        'bucket' => getenv('SUPABASE_STORAGE_BUCKET') ?: 'hydromis',
    ];
    $local = __DIR__ . '/supabase.local.php';
    if (is_file($local)) {
        $loaded = require $local;
        if (is_array($loaded)) $config = array_replace($config, array_intersect_key($loaded, $config));
    }
    return $config;
}

function hydromis_storage_enabled(): bool {
    $config = hydromis_storage_config();
    return $config['url'] !== '' && $config['secret_key'] !== '' && $config['bucket'] !== '';
}

function hydromis_storage_request(string $method, string $endpoint, ?string $body = null, array $headers = []): array {
    $config = hydromis_storage_config();
    $key = $config['secret_key'];
    $curl = curl_init(rtrim($config['url'], '/') . $endpoint);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge([
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
        ], $headers),
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return [$status, $response === false ? '' : $response, $error];
}

function hydromis_ensure_storage_bucket(): bool {
    static $ready = false;
    if ($ready) return true;
    if (!hydromis_storage_enabled()) return false;
    $config = hydromis_storage_config();
    [$existingStatus, $existingBody] = hydromis_storage_request('GET', '/storage/v1/bucket/' . rawurlencode($config['bucket']));
    if ($existingStatus >= 200 && $existingStatus < 300) {
        $bucket = json_decode($existingBody, true);
        if (($bucket['public'] ?? true) !== false) {
            [$updateStatus] = hydromis_storage_request('PUT', '/storage/v1/bucket/' . rawurlencode($config['bucket']), json_encode([
                'public' => false,
                'file_size_limit' => 5242880,
                'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
            ]), ['Content-Type: application/json']);
            if ($updateStatus < 200 || $updateStatus >= 300) return false;
        }
        return $ready = true;
    }
    [$status] = hydromis_storage_request('POST', '/storage/v1/bucket', json_encode([
        'id' => $config['bucket'],
        'name' => $config['bucket'],
        'public' => false,
        'file_size_limit' => 5242880,
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
    ]), ['Content-Type: application/json']);
    return $ready = (($status >= 200 && $status < 300) || $status === 409);
}

function hydromis_store_bytes(string $objectPath, string $contents, string $mimeType): bool {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    if (hydromis_storage_enabled()) {
        if (!hydromis_ensure_storage_bucket()) return false;
        $config = hydromis_storage_config();
        $encoded = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        [$status] = hydromis_storage_request('POST', '/storage/v1/object/' . rawurlencode($config['bucket']) . '/' . $encoded, $contents, [
            'Content-Type: ' . $mimeType,
            'x-upsert: true',
        ]);
        if ($status < 200 || $status >= 300) return false;
    }
    if (!getenv('VERCEL')) {
        $destination = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectPath);
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) return false;
        if (file_put_contents($destination, $contents, LOCK_EX) === false) return false;
    }
    return hydromis_storage_enabled() || !getenv('VERCEL');
}

function hydromis_store_upload(string $temporaryFile, string $objectPath, string $mimeType): bool {
    $contents = file_get_contents($temporaryFile);
    return $contents !== false && hydromis_store_bytes($objectPath, $contents, $mimeType);
}

function hydromis_read_bytes(string $objectPath): ?string {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    if (hydromis_storage_enabled()) {
        $config = hydromis_storage_config();
        $encoded = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        [$status, $body] = hydromis_storage_request('GET', '/storage/v1/object/authenticated/' . rawurlencode($config['bucket']) . '/' . $encoded);
        return $status >= 200 && $status < 300 ? $body : null;
    }
    $local = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectPath);
    if (!is_file($local)) return null;
    $contents = file_get_contents($local);
    return $contents === false ? null : $contents;
}

function hydromis_delete_object(string $objectPath): bool {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    $deleted = true;
    if (hydromis_storage_enabled()) {
        $config = hydromis_storage_config();
        [$status] = hydromis_storage_request('DELETE', '/storage/v1/object/' . rawurlencode($config['bucket']), json_encode([
            'prefixes' => [$objectPath],
        ]), ['Content-Type: application/json']);
        $deleted = $status >= 200 && $status < 300;
    }
    if (!getenv('VERCEL')) {
        $local = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectPath);
        if (is_file($local)) @unlink($local);
    }
    return $deleted;
}

function hydromis_object_exists(string $objectPath): bool {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    if (hydromis_storage_enabled()) {
        $config = hydromis_storage_config();
        $encoded = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        [$status] = hydromis_storage_request('HEAD', '/storage/v1/object/authenticated/' . rawurlencode($config['bucket']) . '/' . $encoded);
        return $status >= 200 && $status < 300;
    }
    return is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectPath));
}

function hydromis_storage_url(string $objectPath, int $expiresIn = 900): string {
    static $cache = [];
    if ($objectPath === '' || preg_match('#^https?://#i', $objectPath)) return $objectPath;
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    if (!hydromis_storage_enabled()) return $objectPath;
    if (isset($cache[$objectPath])) return $cache[$objectPath];
    $config = hydromis_storage_config();
    $encoded = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
    [$status, $body] = hydromis_storage_request('POST', '/storage/v1/object/sign/' . rawurlencode($config['bucket']) . '/' . $encoded, json_encode([
        'expiresIn' => max(60, min($expiresIn, 3600)),
    ]), ['Content-Type: application/json']);
    if ($status < 200 || $status >= 300) return '';
    $payload = json_decode($body, true);
    $signed = (string)($payload['signedURL'] ?? $payload['signedUrl'] ?? '');
    if (str_starts_with($signed, '/object/')) $signed = '/storage/v1' . $signed;
    return $cache[$objectPath] = ($signed === '' ? '' : rtrim($config['url'], '/') . $signed);
}

function hydromis_asset_url(string $path, string $localPrefix = ''): string {
    if (preg_match('#^(uploads|qrcodes)/#', $path)) return hydromis_storage_url($path);
    return $localPrefix . $path;
}
