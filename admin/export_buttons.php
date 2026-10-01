<?php
$exportFilters = http_build_query([
    'date' => $filter_date ?? '',
    'method' => $filter_method ?? 'all',
]);
?>
<div style="display:flex;flex-wrap:wrap;gap:10px;margin:0 0 20px;">
    <a class="csv-export-button" href="export_transactions.php?type=transactions&amp;<?= htmlspecialchars($exportFilters, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-csv" aria-hidden="true"></i> Export Transactions CSV</a>
    <a class="csv-export-button" href="export_transactions.php?type=sales&amp;<?= htmlspecialchars($exportFilters, ENT_QUOTES, 'UTF-8') ?>" title="Export approved transactions only"><i class="fas fa-file-csv" aria-hidden="true"></i> Export Sales CSV</a>
</div>
<style>
.csv-export-button{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border:1px solid var(--border2);border-radius:10px;background:var(--bg3);color:var(--aqua);text-decoration:none;font-size:13px;font-weight:700}
.csv-export-button:hover{border-color:var(--aqua)}
.csv-export-button:focus-visible{outline:2px solid var(--aqua);outline-offset:3px}
</style>
