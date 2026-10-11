<?php /** The receiving line form at 375 px (ledger.md receipt-view). Data: receipt, line (an edited line or null), unitOptions, facts, areas, poLines, seesCost, errors */
$errors = $errors ?? [];
$p = 'receipt-line-form';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="' . $p . '-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$v = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$l = $line;
$ingNow = $v('ingredient', (string) ($l['ingredient_id'] ?? ''));
$known = $ingNow !== '';
$showCw = !$known || !empty($facts['catch_weight']);
$showTemp = !$known || !empty($facts['temperature']);
$okNow = $v('temperature_ok', $l === null || $l['temperature_ok'] === null ? '' : ($l['temperature_ok'] ? 'yes' : 'no'));
?>
<form method="post" action="/receipts/line.php" hx-post="/receipts/line.php" hx-target="#flash" id="<?= $p ?>" class="card mb-3 stock-line-form" data-stock-form="receive" data-site="<?= (int) $receipt['scope_id'] ?>">
    <?= csrf_field() ?><input type="hidden" name="receipt" value="<?= (int) $receipt['goods_receipt_id'] ?>"><?php if ($l !== null): ?><input type="hidden" name="receipt_line" value="<?= (int) $l['goods_receipt_line_id'] ?>"><?php endif; ?>
    <div class="card-header"><h5 class="card-title mb-0"><?= $l === null ? 'Add a line' : 'Change line ' . (int) $l['line_no'] ?></h5></div>
    <div class="card-body">
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-ingredient-label">Ingredient</label>
            <?= picker_field(['id' => $p . '-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $ingNow, 'invalid' => isset($errors['ingredient']), 'labelledby' => $p . '-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?><?= $err('ingredient') ?></div>
        <?php if (!empty($receipt['supplier_id'])): ?>
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-supplier_item-label">or the supplier's item (fills the unit and the price)</label>
            <?= picker_field(['id' => $p . '-field-supplier_item', 'name' => 'supplier_item', 'source' => 'supplier_item', 'value' => $v('supplier_item', (string) ($l['supplier_item_id'] ?? '')), 'params' => ['supplier' => (int) $receipt['supplier_id']], 'labelledby' => $p . '-field-supplier_item-label', 'extra' => ' data-stock-supplier-item']) ?><?= $err('supplier_item') ?></div>
        <?php endif; ?>
        <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => $p, 'name' => 'qty', 'unitName' => 'unit', 'label' => 'Quantity', 'qty' => $v('qty', (string) ($l['qty_received'] ?? '')), 'unit' => $v('unit', (string) ($l['purchase_unit_code'] ?? '')), 'options' => $unitOptions, 'errors' => $errors]) ?></div>
        <div class="mb-3 stock-cw<?= $showCw ? '' : ' d-none' ?>" id="<?= $p ?>-catch-weight">
            <label class="form-label fs-12 text-muted" for="<?= $p ?>-field-weight_qty">Declared weight (catch weight — what the box says)</label>
            <div class="input-group stock-qty-unit"><input type="number" step="any" min="0" inputmode="decimal" name="weight_qty" id="<?= $p ?>-field-weight_qty" class="form-control stock-qty<?= isset($errors['weight_qty']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('weight_qty', (string) ($l['weight_qty'] ?? '')) ?: null)) ?>">
                <select name="weight_unit" id="<?= $p ?>-field-weight_unit" class="form-select stock-unit"><?php foreach (['lb', 'kg', 'oz', 'g'] as $u): ?><option value="<?= $u ?>" <?= $v('weight_unit', (string) ($l['weight_unit_code'] ?? 'lb')) === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></div>
            <?= $err('weight_qty') ?><?= $err('weight_unit') ?>
            <div class="form-text">The weight lands, and the cost is per <?= e('lb') ?> — the price per weight unit.</div>
        </div>
        <?php if ($seesCost): ?>
        <div class="mb-3"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-unit_cost">Cost per purchase unit (per weight unit for catch weight) — blank lands at the running cost</label>
            <div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" inputmode="decimal" name="unit_cost" id="<?= $p ?>-field-unit_cost" class="form-control btn-touch<?= isset($errors['unit_cost']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('unit_cost', (string) ($l['unit_cost'] ?? '')) ?: null)) ?>"></div><?= $err('unit_cost') ?>
            <div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="allow_zero_cost" value="yes" id="<?= $p ?>-field-allow_zero_cost" <?= ($l['allow_zero_cost'] ?? false) ? 'checked' : '' ?>><label class="form-check-label fs-12" for="<?= $p ?>-field-allow_zero_cost">A free sample — it truly costs nothing</label></div>
        </div>
        <?php else: ?><?= $err('unit_cost') ?><?php endif; ?>
        <div class="mb-3 stock-temp<?= $showTemp ? '' : ' d-none' ?>" id="<?= $p ?>-temperature">
            <label class="form-label fs-12 text-muted" for="<?= $p ?>-field-temperature_c">Temperature at the door (°C)</label>
            <input type="number" step="0.1" inputmode="decimal" name="temperature_c" id="<?= $p ?>-field-temperature_c" class="form-control stock-qty mb-2<?= isset($errors['temperature_c']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('temperature_c', (string) ($l['temperature_c'] ?? '')) ?: null, 1)) ?>"><?= $err('temperature_c') ?>
            <div class="btn-group w-100" role="group" aria-label="Temperature OK?">
                <input type="radio" class="btn-check" name="temperature_ok" value="yes" id="<?= $p ?>-field-temperature_ok-yes" <?= $okNow === 'yes' ? 'checked' : '' ?>><label class="btn btn-outline-success btn-touch w-50" for="<?= $p ?>-field-temperature_ok-yes"><i class="feather-check me-1"></i>OK</label>
                <input type="radio" class="btn-check" name="temperature_ok" value="no" id="<?= $p ?>-field-temperature_ok-no" <?= $okNow === 'no' ? 'checked' : '' ?>><label class="btn btn-outline-danger btn-touch w-50" for="<?= $p ?>-field-temperature_ok-no"><i class="feather-x me-1"></i>Not OK</label>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-discrepancy_kind">Discrepancy</label><select name="discrepancy_kind" id="<?= $p ?>-field-discrepancy_kind" class="form-select btn-touch"><?php foreach (DISCREPANCY_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $v('discrepancy_kind', (string) ($l['discrepancy_kind'] ?? 'none')) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-putaway_area">Put away in</label><select name="putaway_area" id="<?= $p ?>-field-putaway_area" class="form-select btn-touch"><option value="">The receipt's area</option><?php foreach ($areas as $a): ?><option value="<?= $a['storage_area_id'] ?>" <?= $v('putaway_area', (string) ($l['putaway_area_id'] ?? '')) === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select><?= $err('putaway_area') ?></div>
        </div>
        <div class="mt-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-discrepancy_note">Note</label><input type="text" maxlength="500" name="discrepancy_note" id="<?= $p ?>-field-discrepancy_note" class="form-control btn-touch" value="<?= e($v('discrepancy_note', (string) ($l['discrepancy_note'] ?? ''))) ?>"></div>
        <?php if ($poLines !== []): ?>
        <div class="mt-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-purchase_order_line">Purchase-order line</label><select name="purchase_order_line" id="<?= $p ?>-field-purchase_order_line" class="form-select btn-touch"><option value="">None</option><?php foreach ($poLines as $pl): ?><option value="<?= $pl['purchase_order_line_id'] ?>" <?= $v('purchase_order_line', (string) ($l['purchase_order_line_id'] ?? '')) === (string) $pl['purchase_order_line_id'] ? 'selected' : '' ?>><?= e($pl['line_no'] . ' · ' . $pl['ingredient'] . ' · ' . fmt_num($pl['qty_open']) . ' ' . $pl['purchase_unit_code'] . ' to come') ?></option><?php endforeach; ?></select><?= $err('purchase_order_line') ?></div>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="<?= $p ?>-submit"><?= $l === null ? '<i class="feather-plus me-1"></i>Add the line' : 'Save the line' ?></button><?php if ($l !== null): ?><?= hx_link('/receipts/' . (int) $receipt['goods_receipt_id'], 'Cancel', 'btn btn-light btn-touch') ?><?php endif; ?></div>
</form>
