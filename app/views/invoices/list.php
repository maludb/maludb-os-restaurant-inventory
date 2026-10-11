<?php /** The supplier invoices at this site (screen `invoice-list`). Whole-screen cost. Data: rows, total, page, filters, suppliers, seesCost, mayWrite, notice */
$action = $mayWrite && $seesCost ? hx_link('/invoices/new', '<i class="feather-plus me-1"></i>Enter an invoice', 'btn btn-primary btn-touch', 'id="invoice-list-new-btn"') : '';
$qs = static fn (array $over): string => '/invoices/?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'invoice-list', 'title' => 'Invoices', 'crumbs' => [['Home', '/'], ['Purchasing', null], ['Invoices', null]], 'action' => $action]) ?>
<div class="main-content" id="invoice-list-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$seesCost): ?>
        <div class="card" id="invoices-withheld"><div class="card-body text-center text-muted py-5">Invoices are money, which your role does not see here.</div></div>
    <?php else: ?>
    <form method="get" action="/invoices/" hx-get="/invoices/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="invoice-list-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-filter-status">Status</label><select name="status" id="invoice-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (INVOICE_STATUS_CHIPS as $k => $_): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-filter-kind">Kind</label><select name="kind" id="invoice-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (INVOICE_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-filter-supplier">Supplier</label><select name="supplier" id="invoice-filter-supplier" class="form-select btn-touch"><option value="">Any</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= $filters['supplier'] === (string) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-filter-from">From</label><input type="date" name="from" id="invoice-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-filter-to">To</label><input type="date" name="to" id="invoice-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
            <div class="col-12 col-md-1"><button type="submit" class="btn btn-light btn-touch w-100" id="invoice-filter-apply">Filter</button></div>
        </div>
    </form>
    <?php if ($rows === []): ?><div class="card" id="invoice-list-empty"><div class="card-body text-center text-muted py-5">No invoices<?= array_filter($filters) !== [] ? ' match.' : ' yet.' ?><?= $mayWrite ? ' Enter the first from a receipt.' : '' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="invoices-table"><thead><tr><th>Ours</th><th>Theirs</th><th>Supplier</th><th>Invoice date</th><th>Received</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $i): ?>
        <tr id="invoice-row-<?= (int) $i['invoice_id'] ?>">
            <td><?= hx_link('/invoices/' . $i['invoice_id'], e($i['number']), 'fw-semibold') ?></td>
            <td><?= e($i['invoice_no']) ?><?= $i['kind'] === 'credit' ? ' ' . chip('credit', 'warning') : '' ?></td>
            <td><?= e($i['supplier']) ?></td>
            <td><?= e(format_date($i['invoice_date'])) ?></td>
            <td><?= e(format_date($i['received_on'])) ?></td>
            <td class="text-end"><?= money($i['total']) ?></td>
            <td><?= invoice_chip($i['status']) ?><?= (int) $i['differences'] > 0 ? ' ' . chip((int) $i['differences'] . ' differ', 'danger') : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <?php if ($total > PO_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="invoice-list-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * PO_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
