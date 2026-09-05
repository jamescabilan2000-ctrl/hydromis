<?php
if (!isset($legalTitle, $legalDescription, $legalSummary, $legalActive, $legalSections)) {
    http_response_code(404);
    exit;
}
$escape = static fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= $escape($legalDescription) ?>">
    <title><?= $escape($legalTitle) ?> | HydroMIS</title>
    <link rel="stylesheet" href="css/legal.css">
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="home.php"><img src="imagess/hydromis-logo-v2.png" width="38" height="38" alt="">Hydro<span>MIS</span></a>
        <a class="home-link" href="home.php"><span aria-hidden="true">&larr;</span> Back to home</a>
    </div>
</header>
<main id="content" class="page">
    <div class="hero">
        <p class="eyebrow">HYDROMIS / LEGAL &amp; PRIVACY</p>
        <h1><?= $escape($legalTitle) ?></h1>
        <p class="intro"><?= $escape($legalDescription) ?></p>
        <p class="updated"><span class="date-dot" aria-hidden="true"></span>Last updated <time datetime="2026-09-05">September 5, 2026</time></p>
    </div>
    <nav class="document-tabs" aria-label="Legal documents">
        <a href="terms.php" <?= $legalActive === 'terms' ? 'aria-current="page"' : '' ?>>Terms &amp; Conditions</a>
        <a href="privacy.php" <?= $legalActive === 'privacy' ? 'aria-current="page"' : '' ?>>Privacy Policy</a>
    </nav>
    <div class="document-grid">
        <aside class="sidebar">
            <nav class="contents" aria-label="On this page">
                <p class="nav-heading">On this page</p>
                <ol>
                    <?php foreach ($legalSections as [$id, $heading, $body]): ?>
                    <li><a href="#<?= $escape($id) ?>"><?= $escape($heading) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <div class="help"><strong>Need a hand?</strong><p>We're here to help with your account, orders, or privacy questions.</p><a href="mailto:hydromis.support@gmail.com">Contact support <span aria-hidden="true">&rarr;</span></a></div>
        </aside>
        <article class="document" aria-label="<?= $escape($legalTitle) ?>">
            <div class="summary"><strong>At a glance</strong><p><?= $escape($legalSummary) ?></p></div>
            <?php foreach ($legalSections as $index => [$id, $heading, $body]): ?>
            <section id="<?= $escape($id) ?>" class="legal-section">
                <h2><span class="section-number" aria-hidden="true"><?= sprintf('%02d', $index + 1) ?></span><?= $escape($heading) ?></h2>
                <?= $body /* Trusted, static document HTML defined in terms.php or privacy.php. */ ?>
            </section>
            <?php endforeach; ?>
            <a class="back-top" href="#content">Back to top <span aria-hidden="true">&uarr;</span></a>
        </article>
    </div>
    <footer><span>&copy; <?= date('Y') ?> HydroMIS</span><span>Water station management, made simple.</span></footer>
</main>
</body>
</html>
