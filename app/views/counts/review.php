<?php /** The review (screen `count-review`). Data: count, rows, skipped, overOnly, units (by line), me, mayFill, mayApprove, seesCost, notice */
$id = (int) $count['count_id'];
$tz = site_timezone((int) $count['scope_id']);
$status = $count['status'];
$reviewed = in_array($status, ['review', 'recount', 'approved'], true);
$corrections = array_values(array_filter($rows, static fn (array $l): bool => in_array($l['state'], ['counted', 'zero'], true) && $l['variance_base'] !== null && (float) $l['variance_base'] !== 0.0));
$areaNames = array_values(array_unique(array_map(static fn (array $l): string => $l['area'], $corrections)));
$confirmApprove = 'Approve ' . $count['number'] . ': ' . count($corrections) . ' correction' . (count($corrections) === 1 ? '' : 's') . ($areaNames !== [] ? ' at ' . (count($areaNames) > 1 ? implode(', ', array_slice($areaNames, 0, -1)) . ' and ' . end($areaNames) : $areaNames[0]) : '') . '?';
$open = in_array($status, ['open', 'review', 'recount'], true);
$awaiting = (int) $count['lines_awaiting_recount'];
$qs = static fn (bool $over): string => '/counts/' . $id . '/review' . ($over ? '?over_only=1' : '');
?>
<?= view('shared/header.php', ['id' => 'count-review', 'title' => 'Review ' . $count['number'], 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], [$count['number'], $mayFill && $status === 'open' ? '/counts/' . $id : null], ['Review', null]], 'back' => back_link() ?? ['/counts/', 'Counts']]) ?>
<div class="main-content" id="count-review-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3 fs-12" id="count-header">
        <?= count_status_chip($status, 'count-status') ?> <?= chip(COUNT_KINDS[$count['kind']] ?? $count['kind'], SHEET_KIND_CHIPS[$count['kind']] ?? 'secondary') ?>
        <span id="count-as-of">as of <?= e(format_ts($count['as_of'], $tz, 'M j, g:i A')) ?></span><?php if ($count['sheet_name']): ?><span class="text-muted">· <?= e($count['sheet_name']) ?></span><?php endif; ?>
        <?php if ($status === 'approved'): ?><span class="text-muted">· approved by <?= e($count['approved_by_name'] ?? '') ?> <?= e(format_ts($count['approved_at'], $tz, 'M j g:i A')) ?> — <?= hx_link('/counts/' . $id . '/history', 'what it posted') ?></span><?php endif; ?>
    </div>
    <?php if ($status === 'open'): ?><div class="alert alert-warning" id="count-review-open-note" role="status"><?= e($count['number']) ?> is not reviewed yet.<?= $mayFill ? ' ' . hx_link('/counts/' . $id, 'Finish the count', 'alert-link') . ' and press Review.' : '' ?></div>
    <?php elseif ($status === 'recount'): ?><div class="alert alert-danger" id="count-recount-note" role="status"><?= $awaiting ?> line<?= $awaiting === 1 ? '' : 's' ?> over tolerance <?= $awaiting === 1 ? 'needs' : 'need' ?> a second count by another person<?= $mayApprove ? ' — or approve anyway' : '' ?>.</div><?php endif; ?>
    <?= view('counts/partials/skipped.php', ['skipped' => $skipped]) ?>
    <div class="d-flex gap-2 mb-2" id="review-filter">
        <?= hx_link($qs(false), 'All lines', 'btn btn-sm ' . ($overOnly ? 'btn-light' : 'btn-primary') . ' btn-touch', 'id="review-filter-all"') ?>
        <?= hx_link($qs(true), 'Over tolerance only', 'btn btn-sm ' . ($overOnly ? 'btn-primary' : 'btn-light') . ' btn-touch', 'id="review-filter-over"') ?>
    </div>
    <?php if ($rows === []): ?><div class="card" id="review-empty"><div class="card-body text-center text-muted py-4"><?= $overOnly ? 'No line is over tolerance.' : 'This count has no lines.' ?></div></div>
    <?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0 review-table" id="review-table"><thead><tr><th class="d-none d-md-table-cell">Area</th><th>Ingredient</th><th class="text-end">Book</th><th class="text-end">Counted</th><th class="text-end">Variance</th><th class="text-end d-none d-md-table-cell">At cost</th><th class="d-none d-md-table-cell">Tolerance</th><th class="d-none d-md-table-cell"></th><th class="d-none d-md-table-cell">State</th><th class="d-none d-md-table-cell">Counted by</th></tr></thead><tbody>
        <?php foreach ($rows as $l): ?><?= view('counts/partials/review-row.php', ['l' => $l, 'count' => $count, 'units' => $units, 'me' => $me, 'mayFill' => $mayFill, 'seesCost' => $seesCost]) ?><?php endforeach; ?>
    </tbody><tfoot><tr id="review-totals" class="fw-semibold"><td class="d-none d-md-table-cell"></td><td><?= (int) $count['lines'] ?> lines · <span id="review-totals-over"><?= (int) $count['lines_over'] ?> over tolerance</span></td><td></td><td></td><td class="text-end" colspan="1"></td><td class="text-end d-none d-md-table-cell" id="review-totals-value"><?= $seesCost && $count['total_variance_value'] !== null ? '<span class="' . ((float) $count['total_variance_value'] < 0 ? 'text-danger' : '') . '">' . money((string) $count['total_variance_value']) . '</span>' : money(null) ?></td><td colspan="4" class="d-none d-md-table-cell"></td></tr></tfoot></table></div></div>
    <?php if ($seesCost && $count['total_variance_value'] !== null): ?><div class="fs-12 text-muted mt-1 d-md-none" id="review-totals-value-mobile">Total variance <?= money((string) $count['total_variance_value']) ?></div><?php endif; ?>
    <?php endif; ?>
<?php if ($open && ($mayFill || $mayApprove)): ?>
    <div class="stock-post-bar d-flex flex-wrap gap-2 mt-3" id="count-review-actions">
<?php if ($mayFill): ?>
        <form method="post" action="/counts/review.php" hx-post="/counts/review.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="count" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="count-review-btn"><i class="feather-refresh-cw me-1"></i><?= $status === 'open' ? 'Review' : 'Review again' ?></button></form>
<?php endif; ?>
<?php if ($mayApprove && $status === 'review'): ?>
        <form method="post" action="/counts/approve.php" hx-post="/counts/approve.php" hx-target="#flash" class="flex-grow-1" hx-confirm="<?= e($confirmApprove) ?>"><?= csrf_field() ?><input type="hidden" name="count" value="<?= $id ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="count-approve"><i class="feather-check me-1"></i>Approve</button></form>
<?php elseif ($mayApprove && $status === 'recount'): ?>
        <form method="post" action="/counts/approve.php" hx-post="/counts/approve.php" hx-target="#flash" class="flex-grow-1" hx-confirm="<?= e($confirmApprove . ' ' . $awaiting . ' line' . ($awaiting === 1 ? '' : 's') . ' will post as first counted, without a recount.') ?>"><?= csrf_field() ?><input type="hidden" name="count" value="<?= $id ?>"><input type="hidden" name="force" value="yes"><button type="submit" class="btn btn-warning btn-touch w-100" id="count-approve-force"><i class="feather-check me-1"></i>Approve anyway</button></form>
<?php endif; ?>
<?php if ($mayApprove): ?>
        <form method="post" action="/counts/discard.php" hx-post="/counts/discard.php" hx-target="#flash" hx-confirm="Discard <?= e($count['number']) ?>? Nothing will be posted."><?= csrf_field() ?><input type="hidden" name="count" value="<?= $id ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="count-discard">Discard</button></form>
<?php endif; ?>
    </div>
<?php endif; ?>
</div>
