<?php /** One count line (the page and the Pattern C answer render it). Data: line, count, units {options, whole, loose}, mayEdit, containerOptions, error, saved, seesCost
 * The book figure is rendered only when the lines view let it through (a blind open count hides it from the counter): the page never receives it otherwise. */
$id = (int) $line['count_line_id'];
$state = (string) $line['state'];
$entries = $line['entries'];
$w = $entries[0] ?? null;
$l = $entries[1] ?? null;
$unitSel = static function (string $name, string $fieldId, string $dataField, ?string $chosen, array $options): string {
    $o = '';
    foreach ($options as $u) { $o .= '<option value="' . e($u['code']) . '"' . ((string) $chosen === (string) $u['code'] ? ' selected' : '') . '>' . e($u['code']) . '</option>'; }
    return '<select name="' . e($name) . '" id="' . e($fieldId) . '" data-field="' . e($dataField) . '" class="form-select count-unit" aria-label="Unit">' . $o . '</select>';
};
$hasWeight = $line['default_container_id'] !== null;
$book = $line['system_qty_count'];
$overNote = $line['over_tolerance'] ? 'over tolerance: ±' . fmt_num($line['variance_tolerance_pct'] ?? COUNT_TOLERANCE_PCT) . ' %' . ($seesCost ? ' or $' . fmt_num($line['variance_tolerance_value'] ?? COUNT_TOLERANCE_VALUE) : '') : '';
?>
<div class="card mb-2 count-line state-<?= e($state) ?>" id="count-line-<?= $id ?>" data-line="<?= $id ?>" data-count="<?= (int) $line['count_id'] ?>" data-state="<?= e($state) ?>">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div class="min-w-0"><div class="fw-semibold text-truncate" id="count-line-<?= $id ?>-name"><?= e($line['name']) ?></div>
                <div class="fs-11 text-muted"><?= e($line['code']) ?> · counted in <?= e($line['count_unit']) ?><?php if ($book !== null): ?> · <span id="count-line-<?= $id ?>-book" title="the book figure as of <?= e(format_ts($count['as_of'], site_timezone((int) $count['scope_id']), 'M j g:i A')) ?>">book <?= e(fmt_num($book, 2)) ?> <?= e($line['count_unit']) ?></span><?php endif; ?></div></div>
            <div class="text-end flex-shrink-0"><span class="count-pending-dot" title="saved on this phone — not yet sent"></span><?= count_line_chip($state, 'count-line-' . $id . '-state') ?></div>
        </div>
<?php if ($mayEdit): ?>
        <form method="post" action="/counts/line.php" class="count-line-form" data-count-line-form data-line="<?= $id ?>" id="count-line-<?= $id ?>-form">
            <?= csrf_field() ?><input type="hidden" name="count_line" value="<?= $id ?>">
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-11 text-muted mb-1" for="count-line-<?= $id ?>-whole">Whole</label>
                    <input type="number" step="any" min="0" inputmode="decimal" name="whole" id="count-line-<?= $id ?>-whole" data-field="whole" class="form-control count-qty" value="<?= e(fmt_input($w['qty'] ?? null)) ?>" autocomplete="off">
                    <?= $unitSel('whole_unit', 'count-line-' . $id . '-whole-unit', 'whole_unit', $w['unit_code'] ?? $units['whole'], $units['options']) ?></div>
                <div class="col-6"><label class="form-label fs-11 text-muted mb-1" for="count-line-<?= $id ?>-loose">Loose</label>
                    <input type="number" step="any" min="0" inputmode="decimal" name="loose" id="count-line-<?= $id ?>-loose" data-field="loose" class="form-control count-qty" value="<?= e(fmt_input($l['qty'] ?? null)) ?>" autocomplete="off">
                    <?= $unitSel('loose_unit', 'count-line-' . $id . '-loose-unit', 'loose_unit', $l['unit_code'] ?? $units['loose'], $units['options']) ?></div>
<?php if ($hasWeight): ?>
                <div class="col-12"><label class="form-label fs-11 text-muted mb-1" for="count-line-<?= $id ?>-gross">Scale — gross weight of the bottle with its pourer (g)</label>
                    <div class="input-group"><input type="number" step="any" min="0" inputmode="decimal" name="gross_weight_g" id="count-line-<?= $id ?>-gross" data-field="gross" class="form-control count-qty" value="<?= e(fmt_input($line['gross_weight_g'])) ?>" autocomplete="off"><span class="input-group-text">g</span></div>
<?php if (count($containerOptions) > 1): ?>
                    <select name="container" id="count-line-<?= $id ?>-container" data-field="container" class="form-select count-unit mt-1" aria-label="Which bottle or keg"><?php foreach ($containerOptions as $c): ?><option value="<?= (int) $c['container_id'] ?>" <?= (int) ($line['container_id'] ?? $line['default_container_id']) === (int) $c['container_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
<?php endif; ?></div>
<?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-2 count-save" id="count-line-<?= $id ?>-save"><i class="feather-check me-1"></i>Save</button>
        </form>
        <div class="d-flex gap-2 mt-2">
            <form method="post" action="/counts/line.php" data-count-line-form data-line="<?= $id ?>" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="count_line" value="<?= $id ?>"><input type="hidden" name="state" value="zero"><button type="submit" class="btn btn-light btn-touch w-100" id="count-line-<?= $id ?>-zero" title="None on the shelf">0</button></form>
            <form method="post" action="/counts/line.php" data-count-line-form data-line="<?= $id ?>" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="count_line" value="<?= $id ?>"><input type="hidden" name="state" value="skipped"><button type="submit" class="btn btn-light btn-touch w-100" id="count-line-<?= $id ?>-skip" title="Not counted — posts nothing">Skip</button></form>
        </div>
<?php elseif ($state !== 'pending'): ?>
        <div class="fs-12" id="count-line-<?= $id ?>-counted"><?= $state === 'skipped' ? 'Skipped' : 'Counted ' . e(fmt_num($line['counted_qty_count'], 2)) . ' ' . e($line['count_unit']) ?></div>
<?php endif; ?>
<?php if ($state === 'counted' || $state === 'zero'): ?>
        <div class="fs-12 text-muted mt-2" id="count-line-<?= $id ?>-total"><?= $state === 'zero' ? 'None on the shelf' : 'Counted ' . e(fmt_num($line['counted_qty_count'], 2)) . ' ' . e($line['count_unit']) . ' · ' . e(fmt_num($line['counted_qty_base'], 2)) . ' ' . e($line['base_unit_code']) ?><?= $saved ? ' <span class="text-success" id="count-line-' . $id . '-saved">saved</span>' : '' ?><?php if ($line['variance_qty_count'] !== null): ?> · <span class="<?= $line['over_tolerance'] ? 'text-danger' : '' ?>" <?= $overNote !== '' ? 'title="' . e($overNote) . '"' : '' ?>>variance <?= ((float) $line['variance_qty_count'] > 0 ? '+' : '') . e(fmt_num($line['variance_qty_count'], 2)) ?> <?= e($line['count_unit']) ?></span><?php endif; ?></div>
<?php endif; ?>
        <div class="invalid-feedback d-block count-line-error" id="count-line-<?= $id ?>-error"><?= $error !== null ? e($error) : '' ?></div>
    </div>
</div>
