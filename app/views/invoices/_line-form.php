<?php /** The invoice line form (`invoice-line-form`, ids invoice-line-form-field-{name}). Data: inv, line (an edited line or null), unitOptions, rlines (the billed receipt's lines), errors? */
$errors = $errors ?? [];
$p = 'invoice-line-form';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="' . $p . '-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$v = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$l = $line;
$rlNow = $v('receipt_line', (string) ($l['receipt_line_id'] ?? ''));
?>
<script src="/assets/js/purchasing-forms.js"></script>
<form method="post" action="/invoices/line.php" hx-post="/invoices/line.php" hx-target="#flash" id="<?= $p ?>" class="card mb-3 stock-line-form" data-stock-form="receive" data-invoice-line-form>
    <?= csrf_field() ?><input type="hidden" name="invoice" value="<?= (int) $inv['invoice_id'] ?>"><?php if ($l !== null): ?><input type="hidden" name="invoice_line" value="<?= (int) $l['invoice_line_id'] ?>"><?php endif; ?>
    <div class="card-header"><h5 class="card-title mb-0"><?= $l === null ? 'Add a line' : 'Change line ' . (int) $l['line_no'] ?></h5></div>
    <div class="card-body">
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-supplier_item-label"><?= e($inv['supplier']) ?>'s item</label>
            <?= picker_field(['id' => $p . '-field-supplier_item', 'name' => 'supplier_item', 'source' => 'supplier_item', 'value' => $v('supplier_item', (string) ($l['supplier_item_id'] ?? '')), 'params' => ['supplier' => (int) $inv['supplier_id']], 'invalid' => isset($errors['supplier_item']), 'labelledby' => $p . '-field-supplier_item-label', 'extra' => ' data-stock-supplier-item']) ?><?= $err('supplier_item') ?></div>
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="<?= $p ?>-field-ingredient-label">or an ingredient (a line with neither is only a description)</label>
            <?= picker_field(['id' => $p . '-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $v('ingredient', (string) ($l['ingredient_id'] ?? '')), 'invalid' => isset($errors['ingredient']), 'labelledby' => $p . '-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?><?= $err('ingredient') ?></div>
        <div class="mb-3"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-description">Description on the paper</label><input type="text" name="description" id="<?= $p ?>-field-description" maxlength="300" class="form-control btn-touch<?= isset($errors['description']) ? ' is-invalid' : '' ?>" value="<?= e($v('description', (string) ($l['description'] ?? ''))) ?>"><?= $err('description') ?></div>
        <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => $p, 'name' => 'qty', 'unitName' => 'unit', 'label' => 'Quantity billed', 'qty' => $v('qty', (string) ($l['qty'] ?? '')), 'unit' => $v('unit', (string) ($l['unit_code'] ?? '')), 'options' => $unitOptions, 'errors' => $errors]) ?></div>
        <div class="mb-3"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-unit_price">Price per that unit</label>
            <div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" inputmode="decimal" name="unit_price" id="<?= $p ?>-field-unit_price" class="form-control btn-touch<?= isset($errors['unit_price']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('unit_price', (string) ($l['unit_price'] ?? '')) ?: null)) ?>"></div><?= $err('unit_price') ?></div>
        <div><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-receipt_line">The receipt line it bills (found by supplier item when left blank)</label>
            <select name="receipt_line" id="<?= $p ?>-field-receipt_line" class="form-select btn-touch<?= isset($errors['receipt_line']) ? ' is-invalid' : '' ?>" data-narrow-receipt-lines><option value="">Find it for me</option>
                <?php foreach ($rlines as $r): ?><option value="<?= (int) $r['goods_receipt_line_id'] ?>" data-ing="<?= (int) $r['ingredient_id'] ?>" data-si="<?= (int) ($r['supplier_item_id'] ?? 0) ?>" <?= $rlNow === (string) $r['goods_receipt_line_id'] ? 'selected' : '' ?>><?= e('Line ' . $r['line_no'] . ' · ' . $r['ingredient'] . ' · ' . fmt_num($r['qty'], 4) . ' ' . $r['unit']) ?></option><?php endforeach; ?></select><?= $err('receipt_line') ?>
            <?php if ($rlines === []): ?><div class="form-text">Name a receipt on the header to list its lines here.</div><?php endif; ?></div>
    </div>
    <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="<?= $p ?>-submit"><?= $l === null ? '<i class="feather-plus me-1"></i>Add the line' : 'Save the line' ?></button><?php if ($l !== null): ?><?= hx_link('/invoices/' . (int) $inv['invoice_id'] . '/edit', 'Cancel', 'btn btn-light btn-touch') ?><?php endif; ?></div>
</form>
