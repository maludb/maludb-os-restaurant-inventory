<?php /** The PO line form (`po-line-form`, ids po-line-form-field-{name}). Data: po, line (an edited line or null), unitOptions, seesCost, errors? */
$errors = $errors ?? [];
$p = 'po-line-form';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="' . $p . '-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$v = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$l = $line;
?>
<form method="post" action="/purchasing/line.php" hx-post="/purchasing/line.php" hx-target="#flash" id="<?= $p ?>" class="card mb-3 stock-line-form" data-stock-form="receive">
    <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= (int) $po['purchase_order_id'] ?>"><?php if ($l !== null): ?><input type="hidden" name="line" value="<?= (int) $l['purchase_order_line_id'] ?>"><?php endif; ?>
    <div class="card-header"><h5 class="card-title mb-0"><?= $l === null ? 'Add a line' : 'Change line ' . (int) $l['line_no'] ?></h5></div>
    <div class="card-body">
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-supplier_item-label"><?= e($po['supplier']) ?>'s item (fills the unit and the price)</label>
            <?= picker_field(['id' => $p . '-field-supplier_item', 'name' => 'supplier_item', 'source' => 'supplier_item', 'value' => $v('supplier_item', (string) ($l['supplier_item_id'] ?? '')), 'params' => ['supplier' => (int) $po['supplier_id']], 'invalid' => isset($errors['supplier_item']), 'labelledby' => $p . '-field-supplier_item-label', 'extra' => ' data-stock-supplier-item']) ?><?= $err('supplier_item') ?></div>
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-ingredient-label">or an ingredient this supplier has no item for yet</label>
            <?= picker_field(['id' => $p . '-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $v('ingredient', ''), 'invalid' => isset($errors['ingredient']), 'labelledby' => $p . '-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?><?= $err('ingredient') ?></div>
        <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => $p, 'name' => 'qty', 'unitName' => 'unit', 'label' => 'Quantity (in the unit the supplier reads)', 'qty' => $v('qty', (string) ($l['qty_ordered'] ?? '')), 'unit' => $v('unit', (string) ($l['purchase_unit_code'] ?? '')), 'options' => $unitOptions, 'errors' => $errors]) ?></div>
        <?php if ($seesCost): ?>
        <div class="mb-3"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-unit_cost">Cost per purchase unit — blank takes the item's contract or last price</label>
            <div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" inputmode="decimal" name="unit_cost" id="<?= $p ?>-field-unit_cost" class="form-control btn-touch<?= isset($errors['unit_cost']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('unit_cost', (string) ($l['unit_cost'] ?? '')) ?: null)) ?>"></div><?= $err('unit_cost') ?></div>
        <?php endif; ?>
        <div><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-expected_on">Expected on (this line)</label><input type="date" name="expected_on" id="<?= $p ?>-field-expected_on" class="form-control btn-touch<?= isset($errors['expected_on']) ? ' is-invalid' : '' ?>" value="<?= e($v('expected_on', (string) ($l['expected_on'] ?? ''))) ?>"><?= $err('expected_on') ?></div>
    </div>
    <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="<?= $p ?>-submit"><?= $l === null ? '<i class="feather-plus me-1"></i>Add the line' : 'Save the line' ?></button><?php if ($l !== null): ?><?= hx_link('/purchasing/' . (int) $po['purchase_order_id'] . '/edit', 'Cancel', 'btn btn-light btn-touch') ?><?php endif; ?></div>
</form>
