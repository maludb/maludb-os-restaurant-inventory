<?php /** The adjustments at this site (screen `adjustment-list`). Data: rows, total, page, filters, reasons, mayAdjust, notice */ $tz = site_timezone(); ?>
<?= view('shared/header.php', ['id' => 'adjustment-list', 'title' => 'Adjustments', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Adjustments', null]], 'action' => $mayAdjust ? hx_link('/adjustments/new', '<i class="feather-plus me-1"></i>New adjustment', 'btn btn-primary btn-touch', 'id="adjustment-list-new-btn"') : '']) ?>
<div class="main-content" id="adjustment-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/adjustments/" hx-get="/adjustments/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select" class="card mb-3" id="adjustment-list-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="adjustment-filter-status">Status</label><select name="status" id="adjustment-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (['draft', 'posted', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="adjustment-filter-reason">Reason</label><select name="reason" id="adjustment-filter-reason" class="form-select btn-touch"><option value="">Any</option><?php foreach ($reasons as $r): ?><option value="<?= e($r['code']) ?>" <?= $filters['reason'] === $r['code'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="adjustment-filter-apply">Filter</button></div></div></form>
    <?php if ($rows === []): ?><div class="card" id="adjustment-list-empty"><div class="card-body text-center text-muted py-5">No adjustments.</div></div><?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="adjustments-table"><thead><tr><th>Number</th><th>Status</th><th>Area</th><th>Reason</th><th class="text-end">Lines</th><th>Posted</th></tr></thead><tbody>
        <?php foreach ($rows as $j): ?><tr id="adjustment-row-<?= $j['adjustment_id'] ?>"><td><?= hx_link('/adjustments/' . $j['adjustment_id'], e($j['number']), 'fw-semibold') ?></td><td><?= doc_chip($j['status']) ?></td><td><?= e($j['area']) ?></td><td><?= e($j['reason']) ?></td><td class="text-end"><?= (int) $j['line_count'] ?></td>
            <td class="fs-12"><?= $j['posted_at'] ? e(($j['posted_by_name'] ?? '') . ' · ' . format_ts($j['posted_at'], $tz, 'M j g:i A')) : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
</div>
<?= view('stock/_assets.php') ?>
