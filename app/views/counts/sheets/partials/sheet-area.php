<?php /** One storage area of a sheet. Data: a, sheetId, unitsByIngredient, mayWrite */
$aid = (int) $a['storage_area_id'];
?>
<div class="card mb-3" id="sheet-area-<?= $aid ?>" data-area="<?= $aid ?>">
    <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0"><?= e($a['name']) ?></h5><span class="fs-12 text-muted"><?= count($a['lines']) ?> line<?= count($a['lines']) === 1 ? '' : 's' ?></span></div>
    <div class="sheet-lines" id="sheet-lines-<?= $aid ?>" data-sheet="<?= (int) $sheetId ?>" data-area="<?= $aid ?>">
<?php foreach ($a['lines'] as $i => $l): ?><?= view('counts/sheets/partials/sheet-line.php', ['l' => $l, 'sheetId' => $sheetId, 'areaLines' => $a['lines'], 'index' => $i, 'units' => $unitsByIngredient[$l['ingredient_id']] ?? [], 'mayWrite' => $mayWrite, 'saved' => false]) ?><?php endforeach; ?>
<?php if ($a['lines'] === []): ?><div class="px-3 py-3 text-muted fs-12" id="sheet-area-<?= $aid ?>-empty">Nothing on the sheet for this area yet.</div><?php endif; ?>
    </div>
<?php if ($mayWrite): ?>
    <form method="post" action="/counts/sheets/line.php" hx-post="/counts/sheets/line.php" hx-target="#flash" id="sheet-add-form-<?= $aid ?>" class="card-footer"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= (int) $sheetId ?>"><input type="hidden" name="area" value="<?= $aid ?>">
        <label class="form-label fs-12 text-muted" id="sheet-add-form-<?= $aid ?>-field-ingredient-label">Add an ingredient to <?= e($a['name']) ?></label>
        <div class="row g-2"><div class="col-12 col-md-9"><?= picker_field(['id' => 'sheet-add-form-' . $aid . '-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => '', 'labelledby' => 'sheet-add-form-' . $aid . '-field-ingredient-label']) ?></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="sheet-add-form-<?= $aid ?>-submit"><i class="feather-plus me-1"></i>Add</button></div></div>
    </form>
<?php endif; ?>
</div>
