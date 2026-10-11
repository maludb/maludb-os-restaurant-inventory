<?php /** One count sheet as a card. Data: s, mayWrite, mayCount */
$id = (int) $s['count_sheet_id'];
$tz = site_timezone();
?>
<div class="col-12 col-md-6 col-xl-4" id="sheet-col-<?= $id ?>"><div class="card h-100 <?= $s['active'] ? '' : 'border-dark' ?>" id="sheet-card-<?= $id ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2"><h5 class="card-title mb-1 min-w-0 text-truncate"><?= hx_link('/counts/sheets/' . $id, e($s['name']), $s['active'] ? '' : 'text-muted') ?></h5><span class="flex-shrink-0"><?= sheet_kind_chip($s['kind']) ?><?= $s['active'] ? '' : ' ' . chip('archived', 'dark') ?></span></div>
    <div class="fs-12 text-muted mb-2"><?= (int) $s['lines'] ?> line<?= (int) $s['lines'] === 1 ? '' : 's' ?> in <?= (int) $s['areas'] ?> area<?= (int) $s['areas'] === 1 ? '' : 's' ?><?= $s['area_names'] ? ': ' . e($s['area_names']) : '' ?></div>
    <div class="fs-12"><?php if ($s['last_count_id']): ?>Last count: <?= hx_link('/counts/' . $s['last_count_id'] . '/' . ($s['last_count_status'] === 'approved' ? 'history' : 'review'), e($s['last_count_number'])) ?> · <?= e(format_ts($s['last_count_as_of'], $tz, 'M j')) ?> · <?= e(count_status_words($s['last_count_status'])) ?><?php else: ?><span class="text-muted">No count made from it yet.</span><?php endif; ?></div>
</div>
<?php if ($mayCount && $s['active']): ?><div class="card-footer"><?= hx_link('/counts/new?sheet=' . $id, 'Start a count from this sheet', 'btn btn-light btn-touch w-100', 'id="sheet-card-' . $id . '-start"') ?></div><?php endif; ?></div></div>
