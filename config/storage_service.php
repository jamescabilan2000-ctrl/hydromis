<?php

// Uploaded files are stored under the application root on Hostinger.
function hydromis_local_object_path(string $objectPath): string {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    if (!preg_match('#^(uploads|qrcodes)/#', $objectPath) ||
        str_contains($objectPath, "\0") || in_array('..', explode('/', $objectPath), true)) {
        throw new InvalidArgumentException('Invalid upload path.');
    }
    return dirname(__DIR__) . '/' . $objectPath;
}

function hydromis_store_bytes(string $objectPath, string $contents, string $mimeType): bool {
    $destination = hydromis_local_object_path($objectPath);
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) return false;
    return file_put_contents($destination, $contents, LOCK_EX) !== false;
}

function hydromis_store_upload(string $temporaryFile, string $objectPath, string $mimeType): bool {
    $contents = file_get_contents($temporaryFile);
    return $contents !== false && hydromis_store_bytes($objectPath, $contents, $mimeType);
}

function hydromis_read_bytes(string $objectPath): ?string {
    $local = hydromis_local_object_path($objectPath);
    if (!is_file($local)) return null;
    $contents = file_get_contents($local);
    return $contents === false ? null : $contents;
}

function hydromis_delete_object(string $objectPath): bool {
    $local = hydromis_local_object_path($objectPath);
    return !is_file($local) || unlink($local);
}

function hydromis_object_exists(string $objectPath): bool {
    return is_file(hydromis_local_object_path($objectPath));
}

function hydromis_storage_url(string $objectPath): string {
    if ($objectPath === '' || preg_match('#^https?://#i', $objectPath)) return $objectPath;
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    // Support domain-root hosting and local installations in a subfolder.
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if (in_array(basename($base), ['admin', 'staff', 'rider', 'user', 'api'], true)) $base = str_replace('\\', '/', dirname($base));
    $base = ($base === '.' || $base === '/') ? '' : rtrim($base, '/');
    return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
}

function hydromis_asset_url(string $path, string $localPrefix = ''): string {
    if (preg_match('#^(uploads|qrcodes)/#', $path)) return hydromis_storage_url($path);
    if (preg_match('#^https?://#i', $path)) return $path;
    return $localPrefix . $path;
}

function hydromis_payment_proof_url(string $path): string {
    return hydromis_storage_url($path);
}
