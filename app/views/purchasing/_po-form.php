<?php /** A purchase order's header form (`po-form`, ids po-form-field-{name}). Data: cur (the draft or null), supplier (row or null), window, areas, today, seesCost, hasLines, errors? */
$errors = $errors ?? [];
$id = $cur['purchase_order_id'] ?? null;
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="po-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$supNow = $val('supplier', (string) ($cur['supplier_id'] ?? $supplier['supplier_id'] ?? ''));
$areaNow = $val('area', (string) ($cur['storage_area_id'] ?? ''));
$expNow = $val('expected_on', (string) ($cur['expected_on'] ?? ($window['delivery_on'] ?? '')));
?>
<form method="post" action="/purchasing/save.php" hx-post="/purchasing/save.php" hx-target="#flash" id="po-form" class="card mb-3">
    <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="purchase_order" value="<?= (int) $id ?>"><?php endif; ?>
    <div class="card-header"><h5 class="card-title mb-0">The order</h5></div>
    <div class="card-body">
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="po-form-field-supplier-label">Supplier</label>
            <?= picker_field(['id' => 'po-form-field-supplier', 'name' => 'supplier', 'source' => 'supplier', 'value' => $supNow, 'invalid' => isset($errors['supplier']), 'labelledby' => 'po-form-field-supplier-label', 'disabled' => !empty($hasLines), 'required' => true]) ?><?= $err('supplier') ?>
            <?php if (!empty($hasLines)): ?><div class="form-text">The supplier is fixed once a line is on the order.</div><?php endif; ?></div>
        <div class="row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-form-field-area">Area it will be received in</label>
                <select name="area" id="po-form-field-area" class="form-select btn-touch<?= isset($errors['area']) ? ' is-invalid' : '' ?>"><option value="">The receipt chooses</option><?php foreach ($areas as $a): ?><option value="<?= (int) $a['storage_area_id'] ?>" <?= $areaNow === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select><?= $err('area') ?></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-form-field-expected_on">Delivery wanted</label>
                <input type="date" name="expected_on" id="po-form-field-expected_on" class="form-control btn-touch<?= isset($errors['expected_on']) ? ' is-invalid' : '' ?>" value="<?= e($expNow) ?>" min="<?= e($today) ?>"><?= $err('expected_on') ?>
                <div class="form-text" id="po-form-window"><?php if ($window !== null && $window['has_schedule']): ?>Next cut-off <?= e(window_words($window)) ?>.<?php else: ?>Left blank, it is the supplier's next delivery.<?php endif; ?></div></div>
        </div>
        <div class="mt-3"><label class="form-label fs-12 text-muted" for="po-form-field-notes">Notes to the supplier (on their page and in the email)</label><textarea name="notes" id="po-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($val('notes', (string) ($cur['notes'] ?? ''))) ?></textarea><?= $err('notes') ?></div>
        <?php if ($seesCost): ?><div class="mt-3"><label class="form-label fs-12 text-muted" for="po-form-field-internal_notes">Internal notes (never on their page)</label><textarea name="internal_notes" id="po-form-field-internal_notes" maxlength="2000" rows="2" class="form-control"><?= e($val('internal_notes', (string) ($cur['internal_notes'] ?? ''))) ?></textarea><?= $err('internal_notes') ?></div><?php endif; ?>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="po-form-submit"><?= $id === null ? 'Create — then add the lines' : 'Save the header' ?></button></div>
</form>
