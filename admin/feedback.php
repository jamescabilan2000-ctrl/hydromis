<?php
require_once 'check_auth.php';
require_once '../config/database.php';
$page = max(1, (int)($_GET['page'] ?? 1));
$summary = $conn->query('SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average FROM feedback_ratings')->fetch_assoc();
$pages = max(1, (int)ceil((int)$summary['total'] / 20));
$page = min($page, $pages);
$offset = ($page - 1) * 20;
$reviews = $conn->query("SELECT f.*, COALESCE(u.full_name, 'Customer') AS customer FROM feedback_ratings f LEFT JOIN users u ON u.user_id = f.user_id ORDER BY f.created_at DESC, f.id DESC LIMIT 20 OFFSET $offset");
function feedback_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer Feedback | HydroMIS Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0b1120;color:#e2edf9;font:15px system-ui,sans-serif}header{padding:22px max(20px,calc((100vw - 1000px)/2));background:#121d30;border-bottom:1px solid #29364b}a{color:#80caff;text-decoration:none}a:hover{text-decoration:underline}a:focus-visible{outline:3px solid #38c9df;outline-offset:4px}main{max-width:1040px;margin:auto;padding:28px 20px}h1{font-size:28px;margin:0 0 8px}.intro,.meta{color:#9bb0c9;line-height:1.6}.summary{display:flex;gap:16px;flex-wrap:wrap;margin:24px 0}.summary>div{padding:18px 24px;background:#15243a;border:1px solid #2c435d;border-radius:16px}.summary strong{display:block;font-size:26px;color:#63d9de}.review{padding:22px;margin:14px 0;border:1px solid #293c53;background:#131f32;border-radius:18px;overflow-wrap:anywhere}.review-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}.rating{color:#fbbf24;white-space:nowrap}.meta{font-size:12px;margin-top:8px}.comment{line-height:1.7;white-space:pre-wrap;margin-bottom:0}.pagination{display:flex;justify-content:space-between;gap:20px;margin:24px 0}.empty{padding:40px;text-align:center;color:#9bb0c9}
</style></head><body><header><strong>HydroMIS · Admin</strong> <a href="dashboard.php" style="float:right">Back to dashboard</a></header><main><h1>Customer Feedback</h1><p class="intro">Ratings and comments from customer orders.</p><div class="summary"><div><strong><?php echo (int)$summary['total']; ?></strong>Customer reviews</div><div><strong><?php echo number_format((float)$summary['average'], 1); ?> / 5</strong>Average rating</div></div>
<?php if ((int)$summary['total'] === 0): ?><p class="empty">No customer feedback yet.</p><?php endif; ?>
<?php while ($review = $reviews->fetch_assoc()): ?>
<article class="review"><div class="review-head"><strong><?php echo feedback_escape($review['customer']); ?></strong><span class="rating" aria-label="<?php echo (int)$review['rating']; ?> out of 5 stars"><?php echo str_repeat('★', max(0,min(5,(int)$review['rating']))); ?> <?php echo (int)$review['rating']; ?>/5</span></div><div class="meta">Order <?php echo feedback_escape($review['transaction_id']); ?> · <?php echo feedback_escape(date('M j, Y · g:i A', strtotime($review['created_at']))); ?></div><p class="comment"><?php echo feedback_escape(trim((string)$review['feedback_message']) ?: 'No written comment.'); ?></p></article>
<?php endwhile; ?>
<nav class="pagination" aria-label="Feedback pages"><span><?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>">Previous</a><?php endif; ?></span><span>Page <?php echo $page; ?> of <?php echo $pages; ?></span><span><?php if ($page < $pages): ?><a href="?page=<?php echo $page+1; ?>">Next</a><?php endif; ?></span></nav></main></body></html>
