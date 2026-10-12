<?php /** Projected stock (screen `projected-stock`). Data: p (projected_stock()), filters, categories */
$here = here_url();
?>
<?= view('shared/header.php', ['id' => 'projected-stock', 'title' => 'Projected stock', 'crumbs' => [['Home', '/'], ['Projection', null], ['Projected stock', null]], 'back' => back_link()]) ?>
<div class="main-content" id="projected-stock-content">
    <?= view('projection/partials/assets.php') ?>
    <form method="get" action="/projection/stock" hx-get="/projection/stock" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select, change from:input[type=checkbox], submit" class="card mb-3" id="projected-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="projected-filter-q">Find</label><input type="search" name="q" id="projected-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>" placeholder="Ingredient"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="projected-filter-category">Category</label><select name="category" id="projected-filter-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $filters['category'] === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-1"><label class="form-label fs-12 text-muted" for="projected-filter-days">Days</label><select name="days" id="projected-filter-days" class="form-select btn-touch"><?php foreach (PROJECTION_DAYS as $n): ?><option value="<?= $n ?>" <?= $p['days'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="stockouts_only" value="1" id="projected-filter-stockouts" <?= $filters['stockouts_only'] === 'yes' ? 'checked' : '' ?>><label class="form-check-label fs-12" for="projected-filter-stockouts">Stock-outs only</label></div></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="projected-apply">Show</button></div>
    </div></form>
    <?php if ($p['rows'] === []): ?>
        <div class="card" id="projected-empty"><div class="card-body text-center text-muted py-5"><?= $filters['stockouts_only'] === 'yes' ? 'Nothing is projected to run out in these ' . (int) $p['days'] . ' days.' : 'Nothing to project yet — no ingredient has stock, a par, a forecast demand or an order due.' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="fc-table-wrap"><table class="table table-hover align-middle mb-0 fc-table" id="projected-table">
        <thead><tr><th>Ingredient</th><th class="num">On hand</th><?php foreach ($p['dates'] as $dt): ?><th class="num fc-cell" title="<?= e(format_date($dt)) ?>"><?= e(date('D j', (int) strtotime($dt))) ?></th><?php endforeach; ?><th class="d-none d-md-table-cell">Par</th></tr></thead>
        <tbody><?php foreach ($p['rows'] as $r): ?><?= view('projection/partials/projected-row.php', ['r' => $r, 'here' => $here]) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2" id="projected-legend">Each day: on hand + deliveries due − forecast demand − planned waste. <span class="fc-table"><span class="fc-recv px-1">+</span></span> a delivery lands that day; <span class="fc-table"><span class="fc-neg px-1">red</span></span> the day it runs out.</div>
    <?php endif; ?>
</div>
