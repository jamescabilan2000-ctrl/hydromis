<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);
$page = max(1, (int)($_GET['page'] ?? 1));
$summary = $conn->query('SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average FROM feedback_ratings')->fetch_assoc();
$pages = max(1, (int)ceil((int)$summary['total'] / 20));
$page = min($page, $pages);
$offset = ($page - 1) * 20;
$reviews = $conn->query("SELECT f.*, COALESCE(u.full_name, 'Customer') AS customer FROM feedback_ratings f LEFT JOIN users u ON u.user_id = f.user_id ORDER BY f.created_at DESC, f.id DESC LIMIT 20 OFFSET $offset");
function feedback_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer Feedback | HydroMIS Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="../css/admin-sidebar-hover.css" rel="stylesheet">
<link href="../css/animations.css" rel="stylesheet">
<link href="../css/admin-feedback.css" rel="stylesheet">
<link href="../css/admin-theme.css" rel="stylesheet">
<script src="../js/admin-theme.js"></script>
<script src="../js/ui-protection.js" defer></script>
</head><body><div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">
                <div class="brand-icon"><img src="<?= htmlspecialchars(hydromis_asset_url($systemLogo, '../')) ?>" alt="Logo" style="width: 24px; height: 24px; object-fit: contain;"></div>
                <div>
                    <div class="brand-name">HydroMIS</div>
                    <div class="brand-sub">Admin</div>
                </div>
            </div>
        </div>

        <nav style="display:flex;flex-direction:column;gap:24px;">
            <div>
                <div class="nav-section-label">Main</div>
                <div class="nav-group">
                    <a href="dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
                    <a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i> Transactions</a>
                    <a href="reports.php" class="nav-item" style="position:relative;"><i class="fas fa-chart-bar"></i> Reports</a>
                    <a href="feedback.php" class="nav-item active" aria-current="page"><i class="fas fa-comments"></i> Customer Feedback</a>
                    <a href="inventory.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Inventory</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">People</div>
                <div class="nav-group">
                    <a href="users.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
                    <a href="staff_account.php" class="nav-item"><i class="fas fa-user-shield"></i> Staff Account</a>
                    <a href="manage_riders.php" class="nav-item"><i class="fas fa-motorcycle"></i> Riders</a>
                </div>
            </div>
            <div>
                <div class="nav-section-label">System</div>
                <div class="nav-group">
                    <a href="activity_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
                    <a href="dashboard.php?open_settings=1" class="nav-item"><i class="fas fa-cog"></i> Settings</a>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-card">
                <div class="admin-avatar"><?php include __DIR__ . "/profile_avatar.php"; ?></div>
                <div>
                    <div class="admin-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
                <a href="../logout.php" class="logout-link" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>
<main class="main"><div class="topbar"><div class="breadcrumb"><i class="fas fa-comments" aria-hidden="true"></i><span>Customer Feedback</span></div></div><div class="page-content">
<h1>Customer Feedback</h1><p class="intro">Ratings and comments from customer orders.</p><div class="summary"><div><strong><?php echo (int)$summary['total']; ?></strong>Customer reviews</div><div><strong><?php echo number_format((float)$summary['average'], 1); ?> / 5</strong>Average rating</div></div>
<?php if ((int)$summary['total'] === 0): ?><p class="empty">No customer feedback yet.</p><?php endif; ?>
<?php while ($review = $reviews->fetch_assoc()): ?>
<article class="review"><div class="review-head"><strong><?php echo feedback_escape($review['customer']); ?></strong><span class="rating" aria-label="<?php echo (int)$review['rating']; ?> out of 5 stars"><?php echo str_repeat('★', max(0,min(5,(int)$review['rating']))); ?> <?php echo (int)$review['rating']; ?>/5</span></div><div class="meta">Order <?php echo feedback_escape($review['transaction_id']); ?> · <?php echo feedback_escape(date('M j, Y · g:i A', strtotime($review['created_at']))); ?></div><p class="comment"><?php echo feedback_escape(trim((string)$review['feedback_message']) ?: 'No written comment.'); ?></p></article>
<?php endwhile; ?>
<nav class="pagination" aria-label="Feedback pages"><span><?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>">Previous</a><?php endif; ?></span><span>Page <?php echo $page; ?> of <?php echo $pages; ?></span><span><?php if ($page < $pages): ?><a href="?page=<?php echo $page+1; ?>">Next</a><?php endif; ?></span></nav></div></main></div></body></html>
