<?php /** One line of the review (and its recount row). Data: l, count, units, me, mayFill, seesCost */
$id = (int) $l['count_line_id'];
$awaiting = $l['over_tolerance'] && $l['recount_qty_base'] === null;
$recounted = $l['recount_qty_base'] !== null;
$mine = $l['counted_by'] !== null && (int) $l['counted_by'] === $me;
$var = $l['variance_qty_count'];
$tolPct = $l['variance_tolerance_pct'] ?? COUNT_TOLERANCE_PCT;
$tolVal = $l['variance_tolerance_value'] ?? COUNT_TOLERANCE_VALUE;
$tol = '±' . fmt_num($tolPct) . ' %' . ($seesCost ? ' or $' . fmt_num($tolVal) : '');
$counted = $l['state'] === 'skipped' ? null : ($recounted ? $l['recount_qty_count'] : $l['counted_qty_count']);
?>
<tr id="review-row-<?= $id ?>" class="<?= $awaiting ? 'table-warning' : '' ?>" data-state="<?= e($l['state']) ?>">
    <td class="d-none d-md-table-cell fs-12"><?= e($l['area']) ?></td>
    <td><div class="fw-semibold"><?= e($l['name']) ?></div><div class="fs-11 text-muted d-md-none"><?= e($l['area']) ?></div>
        <details class="d-md-none fs-11 text-muted"><summary>more</summary><?= count_line_chip($l['state']) ?> <?= $l['over_tolerance'] ? chip('over tolerance', 'danger') : '' ?> tolerance <?= e($tol) ?><?= $l['counted_by_name'] ? ' · counted by ' . e($l['counted_by_name']) : '' ?><?= $l['variance_value'] !== null ? ' · ' . money((string) $l['variance_value']) : '' ?></details></td>
    <td class="text-end text-nowrap"><?= $l['system_qty_count'] !== null ? e(fmt_num($l['system_qty_count'], 2)) . ' <span class="text-muted fs-11">' . e($l['count_unit']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
    <td class="text-end text-nowrap"><?= $counted !== null ? e(fmt_num($counted, 2)) . ' <span class="text-muted fs-11">' . e($l['count_unit']) . '</span>' . ($recounted ? ' <span class="badge bg-soft-info text-info" title="recounted by ' . e($l['recount_by_name'] ?? '') . '">recount</span>' : '') : '<span class="text-muted">' . ($l['state'] === 'skipped' ? 'skipped' : '—') . '</span>' ?></td>
    <td class="text-end text-nowrap <?= $l['over_tolerance'] ? 'text-danger fw-semibold' : '' ?>" id="review-row-<?= $id ?>-variance" <?= $l['over_tolerance'] ? 'title="over tolerance: ' . e($tol) . '"' : '' ?>><?= $var !== null ? ((float) $var > 0 ? '+' : '') . e(fmt_num($var, 2)) . ' <span class="fs-11">' . e($l['count_unit']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
    <td class="text-end d-none d-md-table-cell"><?= $seesCost && $l['variance_value'] !== null ? '<span class="' . ((float) $l['variance_value'] < 0 ? 'text-danger' : '') . '">' . money((string) $l['variance_value']) . '</span>' : money(null) ?></td>
    <td class="d-none d-md-table-cell fs-12 text-nowrap"><?= e($tol) ?></td>
    <td class="d-none d-md-table-cell"><?= $awaiting ? chip('over', 'danger') : ($recounted ? chip('recounted', 'info') : '') ?></td>
    <td class="d-none d-md-table-cell"><?= count_line_chip($l['state']) ?></td>
    <td class="d-none d-md-table-cell fs-12 <?= $mine ? 'text-muted' : '' ?>" id="review-row-<?= $id ?>-by"><?= e($l['counted_by_name'] ?? '') ?><?= $mine ? ' (you)' : '' ?></td>
</tr>
<?php if ($awaiting && $mayFill && $count['status'] === 'recount'): ?>
<tr id="review-row-<?= $id ?>-recount" class="table-warning"><td colspan="9">
<?php if ($mine): ?>
    <div class="fs-12 text-muted" id="review-row-<?= $id ?>-recount-note">You counted <?= e($l['name']) ?> — another person recounts it.</div>
<?php else: ?>
    <form method="post" action="/counts/recount.php" hx-post="/counts/recount.php" hx-target="#flash" class="row g-2 align-items-end" id="review-row-<?= $id ?>-recount-form">
        <?= csrf_field() ?><input type="hidden" name="count_line" value="<?= $id ?>">
        <div class="col-12 fs-12 fw-semibold">Recount <?= e($l['name']) ?> <span class="fw-normal text-muted">— counted by <?= e($l['counted_by_name'] ?? 'someone') ?>; count it again without looking at theirs.</span></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="review-row-<?= $id ?>-recount-whole">Whole</label><input type="number" step="any" min="0" inputmode="decimal" name="whole" id="review-row-<?= $id ?>-recount-whole" class="form-control btn-touch">
            <select name="whole_unit" class="form-select btn-touch mt-1" aria-label="Unit"><?php foreach ($units[$id]['options'] as $u): ?><option value="<?= e($u['code']) ?>" <?= $u['code'] === $units[$id]['whole'] ? 'selected' : '' ?>><?= e($u['code']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="review-row-<?= $id ?>-recount-loose">Loose</label><input type="number" step="any" min="0" inputmode="decimal" name="loose" id="review-row-<?= $id ?>-recount-loose" class="form-control btn-touch">
            <select name="loose_unit" class="form-select btn-touch mt-1" aria-label="Unit"><?php foreach ($units[$id]['options'] as $u): ?><option value="<?= e($u['code']) ?>" <?= $u['code'] === $units[$id]['loose'] ? 'selected' : '' ?>><?= e($u['code']) ?></option><?php endforeach; ?></select></div>
<?php if ($l['default_container_id'] !== null): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="review-row-<?= $id ?>-recount-gross">Scale (g)</label><input type="number" step="any" min="0" inputmode="decimal" name="gross_weight_g" id="review-row-<?= $id ?>-recount-gross" class="form-control btn-touch"></div>
<?php endif; ?>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-warning btn-touch w-100" id="review-row-<?= $id ?>-recount-btn">Recount</button></div>
    </form>
<?php endif; ?>
</td></tr>
<?php endif; ?>
