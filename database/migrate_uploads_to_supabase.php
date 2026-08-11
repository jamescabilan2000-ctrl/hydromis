<?php
require_once __DIR__ . '/../config/storage_service.php';

if (PHP_SAPI !== 'cli') exit("Run this migration from the command line.\n");
if (!hydromis_storage_enabled()) exit("Supabase Storage configuration is incomplete.\n");
if (!hydromis_ensure_storage_bucket()) exit("Unable to create or access the Supabase Storage bucket.\n");

$root = dirname(__DIR__);
$folders = ['qrcodes', 'uploads'];
$uploaded = 0;
$failed = 0;
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($folders as $folder) {
    $directory = $root . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if ($file->getSize() === 0) {
            echo "skipped empty " . $file->getFilename() . "\n";
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $mime = $finfo->file($file->getPathname()) ?: 'application/octet-stream';
        $contents = file_get_contents($file->getPathname());
        if ($contents !== false && hydromis_store_bytes($relative, $contents, $mime)) {
            $uploaded++;
            echo "uploaded {$relative}\n";
        } else {
            $failed++;
            fwrite(STDERR, "failed {$relative}\n");
        }
    }
}

echo "complete uploaded={$uploaded} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
