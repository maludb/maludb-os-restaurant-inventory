<?php /** Ingredient demand (screen `demand-report`). Data: d (ingredient_demand()), from, to, filters, drivers, categories */
$here = here_url();
$q = static fn (array $over = []): string => http_build_query(array_filter(['from' => $from, 'to' => $to, 'category' => $filters['category'], 'ingredient' => $filters['ingredient']] + $over, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'demand-report', 'title' => 'Ingredient demand', 'crumbs' => [['Home', '/'], ['Projection', null], ['Ingredient demand', null]], 'back' => back_link()]) ?>
<div class="main-content" id="demand-report-content">
    <?= view('projection/partials/assets.php') ?>
    <form method="get" action="/projection/demand" hx-get="/projection/demand" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="demand-form"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="demand-form-field-from">From</label><input type="date" name="from" id="demand-form-field-from" class="form-control btn-touch" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="demand-form-field-to">To</label><input type="date" name="to" id="demand-form-field-to" class="form-control btn-touch" value="<?= e($to) ?>"></div>
        <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="demand-form-field-category">Category</label><select name="category" id="demand-form-field-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $filters['category'] === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="demand-apply">Show</button></div>
    </div></form>
    <?php if ($d['rows'] === []): ?>
        <div class="card" id="demand-empty"><div class="card-body text-center text-muted py-5">No ingredient is forecast to be used in this window — nothing has sold recently, or the items sold are not mapped to recipes.</div></div>
    <?php else: ?>
    <div class="card"><div class="fc-table-wrap"><table class="table table-hover align-middle mb-0 fc-table" id="demand-table">
        <thead><tr><th>Ingredient</th><th class="d-none d-md-table-cell">Category</th><?php foreach ($d['dates'] as $dt): ?><th class="num fc-cell" title="<?= e(format_date($dt)) ?>"><?= e(date('D j', (int) strtotime($dt))) ?></th><?php endforeach; ?><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($d['rows'] as $r): $rid = (int) $r['ingredient_id']; ?>
            <tr id="demand-row-<?= $rid ?>">
                <td><?= hx_link(with_back('/projection/stock/' . $rid, $here), e($r['name']), 'fw-semibold') ?></td><td class="d-none d-md-table-cell text-muted"><?= e($r['category']) ?></td>
                <?php foreach ($d['dates'] as $dt): $v = $r['days'][$dt] ?? null; ?><td class="num fc-cell" title="<?= $v === null ? '0' : e(fmt_num($v['base'], 2)) ?> <?= e($r['base_unit']) ?>"><?= $v === null ? '<span class="text-muted">—</span>' : e(fmt_num_adaptive($v['count'])) ?></td><?php endforeach; ?>
                <td class="num fw-semibold" title="<?= e(fmt_num($r['total_base'], 2)) ?> <?= e($r['base_unit']) ?>"><?= e(fmt_num_adaptive($r['total_count'])) ?> <span class="fs-11 text-muted"><?= e($r['count_unit']) ?></span></td>
                <td><?= hx_link('/projection/demand?' . $q(['drivers' => $rid]) . '#demand-drivers', 'What drives it', 'fs-12', 'id="demand-drivers-link-' . $rid . '"') ?></td>
            </tr>
        <?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>
    <?php if ($drivers !== null): ?>
    <div class="card mt-3" id="demand-drivers"><div class="card-header"><h6 class="mb-0">What drives it</h6></div>
        <?php if ($drivers['rows'] === []): ?><div class="card-body text-muted">No menu item's recipe uses this ingredient in the window.</div>
        <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0" id="demand-drivers-table"><thead><tr><th>Menu item</th><th class="num">Forecast sold</th><th class="num">Uses</th><th class="num">Share</th></tr></thead><tbody>
        <?php foreach ($drivers['rows'] as $r): ?><tr id="driver-row-<?= (int) $r['menu_item_id'] ?>"><td><?= hx_link(with_back('/projection/items/' . (int) $r['menu_item_id'], $here), e($r['name'])) ?></td><td class="num"><?= e(fc_qty($r['forecast_qty'])) ?></td><td class="num" title="<?= e(fmt_num($r['qty_base'], 2)) ?> <?= e($r['base_unit']) ?>"><?= e(fmt_num_adaptive($r['count'])) ?> <?= e($r['count_unit']) ?></td><td class="num"><?= e(fmt_num($r['share_pct'], 1)) ?> %</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
    <?php endif; ?>
</div>
