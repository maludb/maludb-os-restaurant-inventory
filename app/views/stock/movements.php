<?php /** The ledger at this site (screen `stock-movements`). Data: rows, total, page, filters, areas, seesCost, mayReverse, notice */
$pdo = db(); $tz = site_timezone();
$qs = static fn (array $over): string => '/stock/movements?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'stock-movements', 'title' => 'Movements', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Movements', null]], 'back' => back_link()]) ?>
<div class="main-content" id="stock-movements-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/stock/movements" hx-get="/stock/movements" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="stock-movements-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="movement-filter-type">Type</label><select name="type" id="movement-filter-type" class="form-select btn-touch"><option value="">Any</option><?php foreach (TXN_WORDS as $k => $w): ?><option value="<?= $k ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="movement-filter-area">Area</label><select name="area" id="movement-filter-area" class="form-select btn-touch"><option value="">All</option><?php foreach ($areas as $a): ?><option value="<?= $a['storage_area_id'] ?>" <?= $filters['area'] === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="movement-filter-from">From</label><input type="date" name="from" id="movement-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="movement-filter-to">To</label><input type="date" name="to" id="movement-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
            <input type="hidden" name="ingredient" value="<?= e($filters['ingredient']) ?>">
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="movement-filter-apply">Filter</button></div>
        </div>
    </form>
    <?php if ($rows === []): ?><div class="card" id="stock-movements-empty"><div class="card-body text-center text-muted py-5">No movements in the window.</div></div><?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table align-middle mb-0 fs-12" id="movements-table">
        <thead><tr><th>When</th><th>Type</th><th>Ingredient</th><th>Area</th><th class="text-end">Quantity</th><th class="text-end">Unit cost</th><th>Document</th><th>Counterparty</th><th>Reason</th><th>By</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $t): $neg = (float) $t['qty_base'] < 0; $link = record_url($t['reference_kind'] === 'production' ? 'production_batch' : $t['reference_kind'], $t['reference_id']); ?>
            <tr id="movement-row-<?= $t['transaction_id'] ?>">
                <td class="text-nowrap"><?= e(format_ts($t['occurred_at'], $tz, 'M j g:i A')) ?><div class="fs-11 text-muted" title="the business day"><?= e($t['business_date']) ?><?= $t['late'] ? ' <span class="badge bg-soft-warning text-warning" id="movement-late-' . $t['transaction_id'] . '" title="' . e((string) $t['note']) . '">late</span>' : '' ?></div></td>
                <td><?= txn_chip($t['txn_type']) ?></td>
                <td><?= hx_link('/ingredients/' . $t['ingredient_id'], e($t['ingredient'])) ?></td>
                <td><?= e($t['area']) ?></td>
                <td class="text-end text-nowrap <?= $neg ? 'text-danger' : '' ?>" title="<?= e(fmt_num($t['qty_base'], 4) . ' ' . $t['base_unit_code']) ?>"><?= e(format_qty($pdo, $t['ingredient_id'], $t['qty_base'])) ?></td>
                <td class="text-end"><?= $seesCost && $t['unit_cost_per_base'] !== null ? '$' . e(fmt_num($t['unit_cost_per_base'], 6)) : money(null) ?></td>
                <td><?= $t['document'] === null ? '' : ($link !== null && $t['reference_kind'] !== 'reversal' ? hx_link($link, e($t['document'])) : e($t['document'])) ?></td>
                <td><?= e((string) $t['counterparty']) ?></td>
                <td><?= e((string) $t['reason']) ?></td>
                <td><?= e((string) $t['actor']) ?></td>
                <td><?php if ($mayReverse && $t['reversible']): ?><form method="post" action="/stock/reverse.php" hx-post="/stock/reverse.php" hx-target="#flash" hx-confirm="Reverse this <?= e(TXN_WORDS[$t['txn_type']] ?? $t['txn_type']) ?> of <?= e($t['ingredient']) ?>? The opposite quantity is posted."><?= csrf_field() ?><input type="hidden" name="transaction" value="<?= $t['transaction_id'] ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="movement-reverse-<?= $t['transaction_id'] ?>">Reverse</button></form><?php elseif ($t['reversed']): ?><span class="text-muted">reversed</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php if ($total > STOCK_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="stock-movements-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * STOCK_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
</div>
