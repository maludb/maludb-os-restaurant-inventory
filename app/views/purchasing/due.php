<?php /** The deliveries expected on or before a day (screen `deliveries-due`). Data: rows, on, today, mayReceive, notice */ ?>
<?= view('shared/header.php', ['id' => 'deliveries-due', 'title' => 'Deliveries due', 'crumbs' => [['Home', '/'], ['Purchasing', null], ['Deliveries due', null]]]) ?>
<div class="main-content" id="deliveries-due-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/purchasing/due" hx-get="/purchasing/due" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:input" class="card mb-3" id="due-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="due-filter-on">Expected on or before</label><input type="date" name="on" id="due-filter-on" class="form-control btn-touch" value="<?= e($on) ?>"></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="due-filter-apply">Show</button></div>
        <div class="col-12 col-md-6 fs-12 text-muted">Today is <?= e(format_date($today)) ?>. A delivery expected before today with nothing received is overdue.</div>
    </div></form>
    <?php if ($rows === []): ?><div class="card" id="due-empty"><div class="card-body text-center text-muted py-5">No deliveries are expected by <?= e(format_date($on)) ?>.</div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="due-table"><thead><tr><th>Order</th><th>Supplier</th><th>Expected</th><th>Status</th><th>Received</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $d): ?>
        <tr id="due-row-<?= (int) $d['purchase_order_id'] ?>" class="<?= $d['overdue'] ? 'table-danger' : '' ?>">
            <td><?= hx_link('/purchasing/' . $d['purchase_order_id'], e($d['number']), 'fw-semibold') ?></td>
            <td><?= e($d['supplier']) ?></td>
            <td><?= e(format_date($d['expected_on'])) ?><?= $d['overdue'] ? ' ' . chip('overdue', 'danger', 'due-overdue-' . (int) $d['purchase_order_id']) : '' ?></td>
            <td><?= po_chip($d['status']) ?></td>
            <td><?= $d['received'] ? chip('yes', 'success') : chip('no', 'secondary') ?></td>
            <td class="text-end"><?php if ($mayReceive): ?><?= hx_link('/receipts/new?purchase_order=' . $d['purchase_order_id'], 'Receive', 'btn btn-primary btn-sm btn-touch', 'id="due-receive-' . (int) $d['purchase_order_id'] . '"') ?><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <?php endif; ?>
</div>
