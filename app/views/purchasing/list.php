<?php /** The purchase orders at this site (screen `purchase-order-list`). Data: rows, total, page, filters, suppliers, windows, seesCost, mayDraft, notice */
$tz = site_timezone();
$action = ($mayDraft ? hx_link('/purchasing/suggested', '<i class="feather-shopping-cart me-1"></i>Suggested orders', 'btn btn-light btn-touch me-1', 'id="po-list-suggested-btn"') . hx_link('/purchasing/new', '<i class="feather-plus me-1"></i>New purchase order', 'btn btn-primary btn-touch', 'id="po-list-new-btn"') : '');
$qs = static fn (array $over): string => '/purchasing/?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'purchase-order-list', 'title' => 'Purchase orders', 'crumbs' => [['Home', '/'], ['Purchasing', null], ['Purchase orders', null]], 'action' => $action]) ?>
<div class="main-content" id="purchase-order-list-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/purchasing/" hx-get="/purchasing/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="po-list-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="po-filter-status">Status</label><select name="status" id="po-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (PO_STATUS_WORDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e(ucfirst($w)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="po-filter-supplier">Supplier</label><select name="supplier" id="po-filter-supplier" class="form-select btn-touch"><option value="">Any</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= $filters['supplier'] === (string) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="po-filter-from">Ordered from</label><input type="date" name="from" id="po-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="po-filter-to">to</label><input type="date" name="to" id="po-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="po-filter-apply">Filter</button></div>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="po-list-empty"><div class="card-body text-center text-muted py-5">No purchase orders<?= array_filter($filters) !== [] ? ' match.' : ' yet.' ?><?= $mayDraft ? ' Draft the first one from a supplier\'s order guide.' : '' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="po-table">
        <thead><tr><th>Number</th><th>Status</th><th>Supplier</th><th>Origin</th><th>Ordered</th><th>Expected</th><?php if ($seesCost): ?><th class="text-end">Total</th><?php endif; ?><th>Acknowledged</th><th>Next event</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): $w = $windows[$p['supplier_id']] ?? null; ?>
            <tr id="po-row-<?= (int) $p['purchase_order_id'] ?>">
                <td><?= hx_link('/purchasing/' . $p['purchase_order_id'], e($p['number']), 'fw-semibold') ?></td>
                <td><?= po_chip($p['status']) ?></td>
                <td><?= e($p['supplier']) ?><?php if ($p['status'] === 'draft' && $w !== null && $w['has_schedule']): ?><div class="fs-11 text-muted" id="po-row-cutoff-<?= (int) $p['purchase_order_id'] ?>">next cut-off <?= e(window_words($w)) ?></div><?php endif; ?></td>
                <td><?= chip(PO_ORIGINS[$p['origin']] ?? $p['origin'], PO_ORIGIN_CHIPS[$p['origin']] ?? 'secondary') ?></td>
                <td><?= e(format_date($p['ordered_on'])) ?></td>
                <td><?= $p['expected_on'] ? e(format_date($p['expected_on'])) : '<span class="text-muted">—</span>' ?></td>
                <?php if ($seesCost): ?><td class="text-end"><?= money($p['total']) ?></td><?php endif; ?>
                <td class="fs-12"><?= $p['acknowledged_at'] ? e(format_ts($p['acknowledged_at'], $tz, 'M j g:i A')) : '<span class="text-muted">—</span>' ?></td>
                <td class="fs-12"><?php if ($p['last_event_kind']): ?><?= e(PO_EVENT_WORDS[$p['last_event_kind']] ?? $p['last_event_kind']) ?> <?= chip($p['last_event_source'], PO_EVENT_SOURCES[$p['last_event_source']] ?? 'secondary') ?><div class="text-muted"><?= e(format_ts($p['last_event_at'], $tz, 'M j g:i A')) ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php if ($total > PO_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="po-list-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * PO_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
</div>
