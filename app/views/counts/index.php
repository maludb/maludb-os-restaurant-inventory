<?php /** The counts at this site (screen `count-list`). Data: rows, total, page, filters, mayFill, seesCost, notice */
$tz = site_timezone();
$qs = static function (array $over) use ($filters): string { return '/counts/?' . http_build_query(array_filter(array_merge($filters, $over), static fn ($v): bool => $v !== '' && $v !== null)); };
?>
<?= view('shared/header.php', ['id' => 'count-list', 'title' => 'Counts', 'crumbs' => [['Home', '/'], ['Counts', null]], 'action' => $mayFill ? hx_link('/counts/new', '<i class="feather-plus me-1"></i>Start a count', 'btn btn-primary btn-touch', 'id="count-list-new-btn"') : '']) ?>
<div class="main-content" id="count-list-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/counts/" hx-get="/counts/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select, change from:input" class="card mb-3" id="count-list-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="count-filter-status">Status</label><select name="status" id="count-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (COUNT_STATUS_WORDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e(ucfirst($w)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="count-filter-kind">Kind</label><select name="kind" id="count-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (COUNT_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="count-filter-from">From</label><input type="date" name="from" id="count-filter-from" value="<?= e($filters['from']) ?>" class="form-control btn-touch"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="count-filter-to">To</label><input type="date" name="to" id="count-filter-to" value="<?= e($filters['to']) ?>" class="form-control btn-touch"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="count-filter-apply">Filter</button></div>
    </div></form>
    <?php if ($rows === []): ?><div class="card" id="count-list-empty"><div class="card-body text-center text-muted py-5">No counts<?= array_filter($filters) ? ' match those filters' : ' yet' ?>.<?php if ($mayFill && !array_filter($filters)): ?> <?= hx_link('/counts/new', 'Start the first one', 'fw-semibold') ?>.<?php endif; ?></div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="counts-table"><thead><tr><th>Count</th><th>Kind</th><th>As of</th><th>Status</th><th>Started</th><th class="text-end">Lines</th><th class="text-end">Over tolerance</th><th class="text-end">Variance</th><th>Approved</th></tr></thead><tbody>
        <?php foreach ($rows as $c): ?><?= view('counts/partials/count-row.php', ['c' => $c, 'mayFill' => $mayFill, 'seesCost' => $seesCost, 'tz' => $tz]) ?><?php endforeach; ?>
    </tbody></table></div></div>
    <?php if ($total > COUNT_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="count-list-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * COUNT_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
</div>
