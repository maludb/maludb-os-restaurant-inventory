<?php /** Actual vs theoretical (screen `usage-report`). Data: u, w, filters, categories, mayExport, seesCost */
$keep = ['category' => $filters['category'], 'basis' => $filters['basis'], 'sort' => $filters['sort']];
$q = static fn (array $o): string => '/usage/?' . http_build_query(array_filter($o + $keep, static fn ($v): bool => $v !== '' && $v !== null));
$exportQ = http_build_query(array_filter(['from' => $w['from'], 'to' => $w['to'], 'basis' => $u['basis'], 'category' => $filters['category']]));
?>
<?= view('shared/header.php', ['id' => 'usage-report', 'title' => 'Actual vs theoretical', 'crumbs' => [['Home', '/'], ['Usage', null], ['Actual vs theoretical', null]], 'back' => back_link(),
    'action' => '<a href="/usage/?' . e($exportQ) . '&amp;format=csv" class="btn btn-light btn-touch" id="usage-csv"><i class="feather-download me-1"></i>CSV</a>']) ?>
<div class="main-content" id="usage-report-content">
    <?= view('usage/partials/assets.php') ?>
    <div class="usage-picks d-flex flex-wrap gap-1 mb-2" id="usage-picks">
        <?php foreach (['this' => 'This period', 'last' => 'Last period', '4weeks' => 'Last 4 weeks', 'month' => 'Last month'] as $k => $label): ?>
            <?= hx_link($q(['pick' => $k]), e($label), 'btn btn-sm ' . ($w['pick'] === $k ? 'btn-primary' : 'btn-light'), 'id="usage-pick-' . e($k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <form method="get" action="/usage/" hx-get="/usage/" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="usage-form"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-form-field-from">From</label><input type="date" name="from" id="usage-form-field-from" value="<?= e($w['from']) ?>" class="form-control btn-touch" required></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-form-field-to">To</label><input type="date" name="to" id="usage-form-field-to" value="<?= e($w['to']) ?>" class="form-control btn-touch" required></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-form-field-category">Category</label><select name="category" id="usage-form-field-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $filters['category'] === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-form-field-basis">Cost basis</label><select name="basis" id="usage-form-field-basis" class="form-select btn-touch"><?php foreach (USAGE_BASES as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['basis'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-form-field-sort">Sort</label><select name="sort" id="usage-form-field-sort" class="form-select btn-touch"><?php foreach (USAGE_SORTS as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="usage-form-apply">Show</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="usage-window">From <?= e(format_date($w['from'])) ?> to <?= e(format_date($w['to'])) ?> · <?= e(USAGE_BASES[$u['basis']] ?? $u['basis']) ?> cost<?= $seesCost ? '' : ' · cost is withheld from you at this site — the quantities are shown' ?></div>
    <?php if ($u['rows'] === []): ?>
        <div class="card" id="usage-empty"><div class="card-body text-center text-muted py-5">Nothing moved in that window<?= $filters['category'] !== '' ? ' for that category' : '' ?>.</div></div>
    <?php else: ?>
        <?= view('usage/partials/usage-table.php', ['u' => $u, 'w' => $w, 'seesCost' => $seesCost]) ?>
        <?= view('usage/partials/legend.php') ?>
        <?php if ($mayExport): ?><div class="mt-2 fs-12" id="usage-export-links">Export: <a href="/usage/export.php?<?= e($exportQ) ?>&amp;format=csv" id="usage-export-csv">CSV</a> · <a href="/usage/export.php?<?= e($exportQ) ?>&amp;format=json" id="usage-export-json">JSON</a> · <a href="/usage/export.php?<?= e($exportQ) ?>&amp;by=category&amp;format=csv" id="usage-export-category">by category (CSV)</a></div><?php endif; ?>
    <?php endif; ?>
</div>
