<?php /** The area pager and the progress bar (sticky under the header). Data: count, areas, areaId, progress */
$ids = array_column($areas, 'storage_area_id');
$at = array_search($areaId, $ids, true);
$cur = $at === false ? null : $areas[$at];
$prev = $at !== false && $at > 0 ? $areas[$at - 1] : null;
$next = $at !== false && $at < count($areas) - 1 ? $areas[$at + 1] : null;
$cid = (int) $count['count_id'];
$done = $progress['lines'] - $progress['pending'];
$pct = $progress['lines'] > 0 ? (int) floor($done * 100 / $progress['lines']) : 0;
?>
<div class="scan-sticky card mb-2" id="count-sticky"><div class="card-body p-2">
    <div class="d-flex align-items-center gap-2" id="count-area-pager">
        <?php if ($prev): ?><?= hx_link('/counts/' . $cid . '?area=' . $prev['storage_area_id'], '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="count-area-prev" aria-label="Previous area: ' . e($prev['name']) . '" title="' . e($prev['name']) . '"') ?><?php else: ?><span class="btn btn-light btn-touch disabled" id="count-area-prev" aria-disabled="true"><i class="feather-chevron-left"></i></span><?php endif; ?>
        <div class="flex-grow-1 text-center min-w-0"><div class="fw-semibold text-truncate" id="count-area-name"><?= e($cur['name'] ?? 'No area') ?></div>
            <div class="fs-11 text-muted">Area <?= $at === false ? 0 : $at + 1 ?> of <?= count($areas) ?> · <span id="count-area-progress" data-area="<?= (int) ($cur['storage_area_id'] ?? 0) ?>"><?= (int) ($cur['done'] ?? 0) ?> of <?= (int) ($cur['lines'] ?? 0) ?></span></div></div>
        <?php if ($next): ?><?= hx_link('/counts/' . $cid . '?area=' . $next['storage_area_id'], '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="count-area-next" aria-label="Next area: ' . e($next['name']) . '" title="' . e($next['name']) . '"') ?><?php else: ?><span class="btn btn-light btn-touch disabled" id="count-area-next" aria-disabled="true"><i class="feather-chevron-right"></i></span><?php endif; ?>
    </div>
    <div class="progress mt-2" style="height: 6px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" id="count-progress-bar" style="width: <?= $pct ?>%"></div></div>
    <div class="fs-11 text-muted mt-1" id="count-progress"><span id="count-progress-done"><?= $done ?></span> of <span id="count-progress-total"><?= (int) $progress['lines'] ?></span> lines</div>
</div></div>
