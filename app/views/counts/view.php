<?php /** The count sheet (screen `count-view`), designed at 375 px: one storage area at a time. Data: count, areas, areaId, lines, progress, units (by line), containerOptions (by line), mayEdit, mayApprove, seesCost, notice */
$id = (int) $count['count_id'];
$tz = site_timezone((int) $count['scope_id']);
$pending = $progress['pending'];
$ids = array_column($areas, 'storage_area_id');
$at = array_search($areaId, $ids, true);
$next = $at !== false && $at < count($areas) - 1 ? $areas[$at + 1] : null;
$pendingAreas = array_values(array_filter($areas, static fn (array $a): bool => $a['pending'] > 0));
$blindHidden = $count['blind'] && $count['status'] === 'open' && !$mayApprove;
$reviewBtn = '';
if ($mayEdit) {
    $label = $count['status'] === 'open' ? 'Review' : 'Review again';
    $blocked = $count['status'] === 'open' && $pending > 0;
    $reviewBtn = '<form method="post" action="/counts/review.php" hx-post="/counts/review.php" hx-target="#flash" class="d-inline" id="count-review-form">' . csrf_field() . '<input type="hidden" name="count" value="' . $id . '">'
        . '<button type="submit" class="btn btn-primary btn-touch" id="count-review-btn"' . ($blocked ? ' disabled' : '') . '><i class="feather-check-circle me-1"></i>' . e($label) . '</button></form>';
} elseif (in_array($count['status'], ['review', 'recount'], true)) {
    $reviewBtn = hx_link('/counts/' . $id . '/review', 'Open the review', 'btn btn-primary btn-touch', 'id="count-review-btn"');
}
?>
<?= view('shared/header.php', ['id' => 'count-view', 'title' => $count['number'], 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], [$count['number'], null]], 'back' => back_link() ?? ['/counts/', 'Counts'], 'action' => $reviewBtn]) ?>
<div class="main-content" id="count-view-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2 fs-12" id="count-header">
        <?= count_status_chip($count['status'], 'count-status') ?> <?= chip(COUNT_KINDS[$count['kind']] ?? $count['kind'], SHEET_KIND_CHIPS[$count['kind']] ?? 'secondary') ?>
        <span id="count-as-of" title="the book figure is frozen as of this moment">as of <?= e(format_ts($count['as_of'], $tz, 'M j, g:i A')) ?></span>
        <?php if ($count['sheet_name']): ?><span class="text-muted">· <?= e($count['sheet_name']) ?></span><?php endif; ?>
        <?php if ($blindHidden): ?><span class="text-muted" id="count-blind-note"><i class="feather-eye-off me-1"></i>blind — the book figure shows at review</span><?php endif; ?>
    </div>
    <?php if (in_array($count['status'], ['review', 'recount'], true)): ?><div class="alert alert-info" id="count-review-note" role="status"><?= e($count['number']) ?> is <?= e(count_status_words($count['status'])) ?>. A line changed here needs <?= $mayEdit ? 'Review again' : 'a new review' ?>. <?= hx_link('/counts/' . $id . '/review', 'Open the review', 'alert-link') ?></div>
    <?php elseif (!$mayEdit): ?><div class="alert alert-secondary" id="count-closed-note" role="status"><?= e($count['number']) ?> is <?= e(count_status_words($count['status'])) ?> — its lines no longer change. <?= $count['status'] === 'approved' ? hx_link('/counts/' . $id . '/history', 'See what it posted', 'alert-link') : '' ?></div><?php endif; ?>
    <?= view('counts/partials/area-pager.php', ['count' => $count, 'areas' => $areas, 'areaId' => $areaId, 'progress' => $progress]) ?>
    <?php if ($mayEdit && $pending > 0 && $count['status'] === 'open'): ?><div class="fs-12 text-muted mb-2" id="count-pending-note"><?= $pending ?> line<?= $pending === 1 ? ' is' : 's are' ?> still pending — count them, zero them or skip them<?php if ($pendingAreas): ?> (<?= e(implode(', ', array_map(static fn (array $a): string => $a['name'] . ' ' . $a['pending'], $pendingAreas))) ?>)<?php endif; ?>.</div><?php endif; ?>
    <div id="count-lines" data-count="<?= $id ?>" data-area="<?= (int) $areaId ?>" data-status="<?= e($count['status']) ?>">
    <?php if ($lines === []): ?><div class="card" id="count-lines-empty"><div class="card-body text-center text-muted py-4"><?= $areas === [] ? 'This count has no lines yet.' : 'Nothing to count in this area.' ?><?= $mayEdit ? ' Add what you find below.' : '' ?></div></div>
    <?php else: foreach ($lines as $line): ?><?= view('counts/partials/count-line.php', ['line' => $line, 'count' => $count, 'units' => $units[$line['count_line_id']], 'mayEdit' => $mayEdit, 'containerOptions' => $containerOptions[$line['count_line_id']] ?? [], 'error' => null, 'saved' => false, 'seesCost' => $seesCost]) ?><?php endforeach; endif; ?>
    </div>
    <?php if ($next): ?><div class="mb-3"><?= hx_link('/counts/' . $id . '?area=' . $next['storage_area_id'], 'Next area: ' . e($next['name']) . ' <i class="feather-chevron-right ms-1"></i>', 'btn btn-outline-primary btn-touch w-100', 'id="count-next-area-btn"') ?></div><?php endif; ?>
    <?php if ($mayEdit && $areaId !== null): ?><?= view('counts/partials/add-line-form.php', ['count' => $count, 'areaId' => $areaId]) ?><?php endif; ?>
</div>
<div id="count-offline" class="count-offline" role="status" aria-live="polite" hidden></div>
