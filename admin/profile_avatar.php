<?php
require_once __DIR__ . '/../config/storage_service.php';
$sidebarPhoto = (string)($_SESSION['avatar_path'] ?? '');
?>
<?php if ($sidebarPhoto !== '' && hydromis_object_exists($sidebarPhoto)): ?>
<img src="<?= htmlspecialchars(hydromis_storage_url($sidebarPhoto), ENT_QUOTES, 'UTF-8') ?>" alt="Admin profile photo" style="display:block;width:100%;height:100%;max-width:100%;object-fit:cover;border-radius:inherit;">
<?php else: ?>
<?= htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
<?php endif; ?>
