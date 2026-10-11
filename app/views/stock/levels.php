<?php /** On hand at this site (screen `stock-levels`). Data: rows, total, totalValue, withheld, page, filters, areas, categories, may (receive, waste, produce), notice */
$pdo = db();
$qs = static fn (array $over): string => '/stock/?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
$big = '';
if ($may['produce']) { $big .= '<div class="col-12 col-sm-4">' . hx_link('/production/new', '<i class="feather-coffee me-1"></i>Record a prep batch', 'btn btn-outline-warning stock-big-btn w-100', 'id="stock-levels-produce-btn"') . '</div>'; }
if ($may['waste']) { $big .= '<div class="col-12 col-sm-4">' . hx_link('/waste/new', '<i class="feather-trash-2 me-1"></i>Log waste', 'btn btn-outline-danger stock-big-btn w-100', 'id="stock-levels-waste-btn"') . '</div>'; }
if ($may['receive']) { $big .= '<div class="col-12 col-sm-4">' . hx_link('/receipts/new', '<i class="feather-download me-1"></i>Receive', 'btn btn-primary stock-big-btn w-100', 'id="stock-levels-receive-btn"') . '</div>'; }
?>
<?= view('shared/header.php', ['id' => 'stock-levels', 'title' => 'On hand', 'crumbs' => [['Home', '/'], ['Stock', null]], 'action' => hx_link('/stock/movements', '<i class="feather-activity me-1"></i>Movements', 'btn btn-light btn-touch', 'id="stock-levels-movements-btn"')]) ?>
<div class="main-content" id="stock-levels-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($big !== ''): ?><div class="row g-2 mb-3" id="stock-levels-big-buttons"><?= $big ?></div><?php endif; ?>
    <form method="get" action="/stock/" hx-get="/stock/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="stock-levels-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="stock-filter-q">Search</label><input type="search" name="q" id="stock-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>" placeholder="name or code"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="stock-filter-area">Area</label><select name="area" id="stock-filter-area" class="form-select btn-touch"><option value="">All</option><?php foreach ($areas as $a): ?><option value="<?= $a['storage_area_id'] ?>" <?= $filters['area'] === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="stock-filter-category">Category</label><select name="category" id="stock-filter-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $filters['category'] === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><div class="form-check mt-4"><input type="checkbox" class="form-check-input" name="below_par" value="1" id="stock-filter-below-par" <?= $filters['below_par'] ? 'checked' : '' ?>><label class="form-check-label" for="stock-filter-below-par">Below par</label></div></div>
            <div class="col-6 col-md-12 d-flex gap-2 justify-content-md-end"><button type="submit" class="btn btn-light btn-touch" id="stock-filter-apply">Filter</button><a class="btn btn-light btn-touch" id="stock-levels-csv" href="<?= e($qs(['format' => 'csv'])) ?>">CSV</a></div>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="stock-levels-empty"><div class="card-body text-center text-muted py-5"><?= $total === 0 && $filters['q'] === '' && $filters['area'] === '' ? 'Nothing on hand at this site yet — receive the first delivery.' : 'Nothing matches.' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="levels-table">
        <thead><tr><th>Ingredient</th><th>Category</th><th>Area</th><th class="text-end">On hand</th><th class="text-end">Days</th><th class="text-end">Value</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr id="level-row-<?= $r['ingredient_id'] ?>-<?= $r['storage_area_id'] ?>">
                <td><?= hx_link('/ingredients/' . $r['ingredient_id'], e($r['name'])) ?><?= $r['below_par'] ? ' <span class="badge bg-soft-warning text-warning" id="level-below-par-' . $r['ingredient_id'] . '-' . $r['storage_area_id'] . '">below par</span>' : '' ?></td>
                <td class="fs-12"><?= e($r['category']) ?></td>
                <td><?= e($r['area']) ?></td>
                <td class="text-end"><span class="fw-semibold"><?= e(format_qty($pdo, $r['ingredient_id'], $r['qty_base'], $r['count_unit'])) ?></span><?= $r['count_unit'] !== null ? ' <span class="fs-12 text-muted">(' . e(fmt_num($r['qty_base'], 3) . ' ' . $r['base_unit_code']) . ')</span>' : '' ?></td>
                <td class="text-end fs-12"><?= $r['days_on_hand'] === null ? '—' : e(fmt_num($r['days_on_hand'], 1)) ?></td>
                <td class="text-end"><?= money($r['value']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-end">Site total</th><th class="text-end" id="levels-total-value"><?= money($totalValue) ?></th></tr></tfoot>
    </table></div></div>
    <?php if ($total > STOCK_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="stock-levels-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * STOCK_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
</div>
