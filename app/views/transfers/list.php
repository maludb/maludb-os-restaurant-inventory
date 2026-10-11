<?php /** Transfers from or to this site (screen `transfer-list`). Data: rows, total, page, filters, mayTransfer, site, notice */ $tz = site_timezone(); ?>
<?= view('shared/header.php', ['id' => 'transfer-list', 'title' => 'Transfers', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Transfers', null]], 'action' => $mayTransfer ? hx_link('/transfers/new', '<i class="feather-plus me-1"></i>New transfer', 'btn btn-primary btn-touch', 'id="transfer-list-new-btn"') : '']) ?>
<div class="main-content" id="transfer-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/transfers/" hx-get="/transfers/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select" class="card mb-3" id="transfer-list-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="transfer-filter-status">Status</label><select name="status" id="transfer-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (['draft', 'in_transit', 'received', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="transfer-filter-apply">Filter</button></div></div></form>
    <?php if ($rows === []): ?><div class="card" id="transfer-list-empty"><div class="card-body text-center text-muted py-5">No transfers from or to this site.</div></div><?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="transfers-table">
        <thead><tr><th>Number</th><th>Status</th><th>From</th><th>To</th><th class="text-end">Lines</th><th>Sent</th><th>Received</th></tr></thead><tbody>
        <?php foreach ($rows as $t): ?><tr id="transfer-row-<?= $t['transfer_id'] ?>">
            <td><?= hx_link('/transfers/' . $t['transfer_id'], e($t['number']), 'fw-semibold') ?></td><td><?= doc_chip($t['status']) ?></td>
            <td><?= e($t['from_site'] . ' · ' . $t['from_area']) ?></td><td><?= e($t['to_site'] . ' · ' . $t['to_area']) ?></td><td class="text-end"><?= (int) $t['line_count'] ?></td>
            <td class="fs-12"><?= $t['shipped_at'] ? e(($t['shipped_by_name'] ?? '') . ' · ' . format_ts($t['shipped_at'], $tz, 'M j g:i A')) : '' ?></td>
            <td class="fs-12"><?= $t['received_at'] ? e(($t['received_by_name'] ?? '') . ' · ' . format_ts($t['received_at'], $tz, 'M j g:i A')) : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
</div>
<?= view('stock/_assets.php') ?>
