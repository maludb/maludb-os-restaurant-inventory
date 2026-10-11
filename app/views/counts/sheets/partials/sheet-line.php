<?php /** One line of a sheet (Pattern C: the unit select saves on change and the row is swapped back). Data: l, sheetId, areaLines (the area's lines in order), index, units [{code, label}], mayWrite, saved? */
$id = (int) $l['sheet_line_id'];
$area = (int) $l['storage_area_id'];
$order = array_column($areaLines, 'sheet_line_id');
$move = static function (int $from, int $to) use ($order): array { $o = $order; [$o[$from], $o[$to]] = [$o[$to], $o[$from]]; return $o; };
$default = $l['default_count_unit'] ?? $l['base_unit_code'];
?>
<div class="sheet-line d-flex align-items-center gap-2 px-2 py-2 border-bottom" id="sheet-line-<?= $id ?>" data-line="<?= $id ?>">
<?php if ($mayWrite): ?><span class="sheet-handle d-none d-md-inline text-muted" title="Drag to reorder" aria-hidden="true"><i class="feather-menu"></i></span><?php endif; ?>
    <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= hx_link('/ingredients/' . (int) $l['ingredient_id'], e($l['name'])) ?></div><div class="fs-11 text-muted"><?= e($l['code']) ?><?= $saved ?? false ? ' · <span class="text-success" id="sheet-line-' . $id . '-saved">saved</span>' : '' ?></div></div>
<?php if ($mayWrite): ?>
    <form method="post" action="/counts/sheets/line.php" hx-post="/counts/sheets/line.php" hx-trigger="change" hx-target="#sheet-line-<?= $id ?>" hx-swap="outerHTML" class="flex-shrink-0"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= (int) $sheetId ?>"><input type="hidden" name="sheet_line" value="<?= $id ?>">
        <select name="count_unit" id="sheet-line-<?= $id ?>-unit" class="form-select btn-touch sheet-unit" aria-label="Counted in"><option value="">default (<?= e($default) ?>)</option><?php foreach ($units as $u): ?><option value="<?= e($u['code']) ?>" <?= (string) $l['count_unit_code'] === (string) $u['code'] ? 'selected' : '' ?>><?= e($u['code']) ?></option><?php endforeach; ?></select>
        <noscript><button type="submit" class="btn btn-light btn-touch mt-1">Save</button></noscript></form>
    <div class="d-flex flex-shrink-0 gap-1">
        <form method="post" action="/counts/sheets/reorder.php" data-move="up"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= (int) $sheetId ?>"><input type="hidden" name="area" value="<?= $area ?>"><?php if ($index > 0): foreach ($move($index, $index - 1) as $x): ?><input type="hidden" name="lines[]" value="<?= (int) $x ?>"><?php endforeach; endif; ?><button type="submit" class="btn btn-light btn-touch px-2" id="sheet-line-<?= $id ?>-up" aria-label="Move up" <?= $index === 0 ? 'disabled' : '' ?>>▲</button></form>
        <form method="post" action="/counts/sheets/reorder.php" data-move="down"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= (int) $sheetId ?>"><input type="hidden" name="area" value="<?= $area ?>"><?php if ($index < count($order) - 1): foreach ($move($index, $index + 1) as $x): ?><input type="hidden" name="lines[]" value="<?= (int) $x ?>"><?php endforeach; endif; ?><button type="submit" class="btn btn-light btn-touch px-2" id="sheet-line-<?= $id ?>-down" aria-label="Move down" <?= $index >= count($order) - 1 ? 'disabled' : '' ?>>▼</button></form>
        <form method="post" action="/counts/sheets/line-remove.php" hx-post="/counts/sheets/line-remove.php" hx-target="#flash" hx-confirm="Remove <?= e($l['name']) ?> from the sheet?"><?= csrf_field() ?><input type="hidden" name="sheet_line" value="<?= $id ?>"><button type="submit" class="btn btn-outline-danger btn-touch px-2" id="sheet-line-<?= $id ?>-remove" aria-label="Remove <?= e($l['name']) ?>"><i class="feather-x"></i></button></form>
    </div>
<?php else: ?>
    <div class="fs-12 text-muted flex-shrink-0"><?= e($l['count_unit_code'] ?? $default) ?></div>
<?php endif; ?>
</div>
