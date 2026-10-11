<?php /** One editable line of the draft (Pattern C: each field saves on change; the row re-renders). Data: l (a recipe_lines row), version */
$id = (int) $l['recipe_line_id']; $vid = (int) $version['recipe_version_id'];
$kindChip = $l['ingredient_id'] !== null ? '' : ' <span class="badge bg-soft-' . (RECIPE_KIND_COLOR[$l['sub_recipe_kind']] ?? 'secondary') . ' text-' . (RECIPE_KIND_COLOR[$l['sub_recipe_kind']] ?? 'secondary') . '">' . e(RECIPE_KINDS[$l['sub_recipe_kind']] ?? $l['sub_recipe_kind']) . '</span>';
$unitsQuery = $l['ingredient_id'] !== null ? 'ingredient=' . (int) $l['ingredient_id'] : 'sub_recipe=' . (int) $l['sub_recipe_id'];
?>
<tr id="editor-line-row-<?= $id ?>" class="editor-line-row" data-line-id="<?= $id ?>">
    <td class="editor-handle text-muted" title="Drag to reorder"><i class="feather-menu"></i> <span class="editor-seq"><?= (int) $l['seq'] ?></span></td>
    <td class="editor-what"><?= $l['ingredient_id'] !== null ? e($l['ingredient_name']) : '↳ ' . e($l['sub_recipe_name']) . $kindChip ?><div class="fs-11 text-muted editor-base" id="editor-line-row-<?= $id ?>-base">= <?= e($l['base_display']) ?></div></td>
    <td>
        <form method="post" action="/recipes/versions/line.php" hx-post="/recipes/versions/line.php" hx-target="#editor-line-row-<?= $id ?>" hx-swap="outerHTML" hx-trigger="change delay:150ms, submit" class="editor-line-form" id="editor-line-form-<?= $id ?>">
            <?= csrf_field() ?><input type="hidden" name="line" value="<?= $id ?>">
            <div class="d-flex gap-1 flex-wrap editor-line-fields">
                <input type="number" step="0.0001" min="0.0001" name="qty" value="<?= e(ri_fmt($l['qty'], 4)) ?>" class="form-control btn-touch editor-qty" id="editor-line-row-<?= $id ?>-field-qty" aria-label="Quantity" style="width: 7rem">
                <select name="unit" class="form-select btn-touch editor-unit" id="editor-line-row-<?= $id ?>-field-unit" aria-label="Unit" style="width: 9rem" hx-get="/recipes/line-units?<?= e($unitsQuery) ?>&selected=<?= e($l['unit_code']) ?>" hx-trigger="load" hx-target="this" hx-swap="innerHTML"><option value="<?= e($l['unit_code']) ?>" selected><?= e($l['unit_code']) ?></option></select>
                <div class="input-group" style="width: 7rem"><input type="number" step="0.01" min="0.01" max="100" name="line_yield_pct" value="<?= e(ri_fmt($l['line_yield_pct'], 2)) ?>" class="form-control btn-touch" id="editor-line-row-<?= $id ?>-field-line-yield-pct" aria-label="Line yield" title="a dice yields less than a julienne"><span class="input-group-text">%</span></div>
                <input type="text" name="group_name" value="<?= e((string) $l['group_name']) ?>" class="form-control btn-touch" id="editor-line-row-<?= $id ?>-field-group-name" placeholder="group" maxlength="40" style="width: 7rem" aria-label="Group">
                <label class="border rounded px-2 d-flex align-items-center gap-1 btn-touch" for="editor-line-row-<?= $id ?>-field-optional"><input type="hidden" name="optional" value="no"><input type="checkbox" class="form-check-input mt-0" name="optional" value="yes" id="editor-line-row-<?= $id ?>-field-optional" <?= $l['optional'] ? 'checked' : '' ?>> optional</label>
                <input type="text" name="notes" value="<?= e((string) $l['notes']) ?>" class="form-control btn-touch" id="editor-line-row-<?= $id ?>-field-notes" placeholder="notes" maxlength="200" style="flex: 1 1 9rem; min-width: 9rem" aria-label="Notes">
                <noscript><button type="submit" class="btn btn-light-brand btn-touch">Save</button></noscript>
            </div>
        </form>
    </td>
    <td class="text-end">
        <form method="post" action="/recipes/versions/line-remove.php" hx-post="/recipes/versions/line-remove.php" hx-target="#editor-line-row-<?= $id ?>" hx-swap="outerHTML" hx-confirm="Remove this line?" class="d-inline" id="editor-line-remove-<?= $id ?>"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light btn-touch" aria-label="Remove" id="editor-line-row-<?= $id ?>-remove"><i class="feather-x"></i></button></form>
    </td>
</tr>
