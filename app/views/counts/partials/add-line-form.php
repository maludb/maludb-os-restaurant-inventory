<?php /** A line found on the shelf (the ingredient is not on the sheet). Data: count, areaId, errors? */
$errors = $errors ?? [];
?>
<form method="post" action="/counts/line-add.php" hx-post="/counts/line-add.php" hx-target="#flash" id="count-add-line-form" class="card mb-3 stock-line-form" data-stock-form="count">
    <?= csrf_field() ?><input type="hidden" name="count" value="<?= (int) $count['count_id'] ?>"><input type="hidden" name="area" value="<?= (int) $areaId ?>">
    <div class="card-header"><h5 class="card-title mb-0">Found on the shelf</h5></div>
    <div class="card-body">
        <div class="fs-12 text-muted mb-2">Something here that is not on the list for this area? Add it and say how much there is.</div>
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="count-add-line-form-field-ingredient-label">Ingredient</label><?= picker_field(['id' => 'count-add-line-form-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $_POST['ingredient'] ?? '', 'invalid' => isset($errors['ingredient']), 'labelledby' => 'count-add-line-form-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?></div>
        <div class="mb-0"><?= view('stock/_qty-unit.php', ['prefix' => 'count-add-line-form', 'name' => 'qty', 'unitName' => 'unit', 'label' => 'How much', 'qty' => $_POST['qty'] ?? '', 'unit' => $_POST['unit'] ?? '', 'options' => [], 'errors' => $errors]) ?></div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="count-add-line-form-submit"><i class="feather-plus me-1"></i>Add to the count</button></div>
</form>
