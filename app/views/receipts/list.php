<?php /** The receipts at this site (screen `receipt-list`). Data: rows, total, page, filters, suppliers, mayReceive, notice */
$action = $mayReceive ? hx_link('/receipts/new', '<i class="feather-plus me-1"></i>New receipt', 'btn btn-primary btn-touch', 'id="receipt-list-new-btn"') : '';
$qs = static fn (array $over): string => '/receipts/?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'receipt-list', 'title' => 'Receiving', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Receipts', null]], 'action' => $action]) ?>
<div class="main-content" id="receipt-list-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/receipts/" hx-get="/receipts/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="receipt-list-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="receipt-filter-status">Status</label><select name="status" id="receipt-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (['draft', 'posted', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="receipt-filter-supplier">Supplier</label><select name="supplier" id="receipt-filter-supplier" class="form-select btn-touch"><option value="">Any</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= $filters['supplier'] === (string) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="receipt-filter-from">From</label><input type="date" name="from" id="receipt-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="receipt-filter-to">To</label><input type="date" name="to" id="receipt-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="receipt-filter-apply">Filter</button></div>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="receipt-list-empty"><div class="card-body text-center text-muted py-5">No receipts yet.<?= $mayReceive ? ' A delivery arrives — start one.' : '' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="receipts-table">
        <thead><tr><th>Number</th><th>Status</th><th>Supplier</th><th>PO</th><th>Area</th><th>Received</th><th class="text-end">Lines</th><th>Temp</th><th>Posted</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $g): ?>
            <tr id="receipt-row-<?= $g['goods_receipt_id'] ?>">
                <td><?= hx_link('/receipts/' . $g['goods_receipt_id'], e($g['number']), 'fw-semibold') ?></td>
                <td><?= doc_chip($g['status']) ?></td>
                <td><?= $g['supplier'] === null ? '<span class="text-muted">—</span>' : e($g['supplier']) ?></td>
                <td><?= $g['po_number'] === null ? '' : (is_file(dirname(__DIR__, 3) . '/html/purchasing/view.php') ? hx_link('/purchasing/' . $g['purchase_order_id'], e($g['po_number'])) : e($g['po_number'])) ?></td>
                <td><?= e($g['area']) ?></td>
                <td><?= e(format_date($g['received_on'])) ?></td>
                <td class="text-end"><?= (int) $g['lines'] ?></td>
                <td><?php if ($g['temps_ok'] !== null): ?><i class="feather-thermometer <?= $g['temps_ok'] ? 'text-success' : 'text-danger' ?>" title="<?= $g['temps_ok'] ? 'every temperature OK' : 'a temperature not OK' ?>"></i><?php endif; ?></td>
                <td class="fs-12 text-muted"><?= $g['posted_at'] ? e(($g['posted_by_name'] ?? '') . ' · ' . format_ts($g['posted_at'], site_timezone(), 'M j g:i A')) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php if ($total > STOCK_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="receipt-list-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * STOCK_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
</div>
