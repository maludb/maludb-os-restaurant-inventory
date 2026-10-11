<?php /** Start or change a receipt's header (screens `receipt-add`, `receipt-edit`). Data: cur, areas, suppliers, poOptions, poLines, prefill (supplier, purchase_order, area), errors? */
$id = $cur['goods_receipt_id'] ?? null;
$screen = $id === null ? 'receipt-add' : 'receipt-edit';
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="receipt-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$areaNow = $val('area', (string) ($cur['storage_area_id'] ?? $prefill['area'] ?? ''));
$supNow = $val('supplier', (string) ($cur['supplier_id'] ?? $prefill['supplier'] ?? ''));
$poNow = $val('purchase_order', (string) ($cur['purchase_order_id'] ?? $prefill['purchase_order'] ?? ''));
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $id === null ? 'New receipt' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], [$id === null ? 'New' : $cur['number'], $id === null ? null : '/receipts/' . $id]], 'back' => back_link() ?? ($id === null ? ['/receipts/', 'Receipts'] : ['/receipts/' . $id, $cur['number']])]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?= view('stock/_assets.php') ?>
    <form method="post" action="/receipts/save.php" hx-post="/receipts/save.php" hx-target="#flash" id="receipt-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="receipt" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The delivery</h5></div><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="receipt-form-field-area">Area it lands in</label>
                <select name="area" id="receipt-form-field-area" class="form-select btn-touch<?= isset($errors['area']) ? ' is-invalid' : '' ?>" required>
                    <option value="">Choose…</option><?php foreach ($areas as $a): ?><option value="<?= $a['storage_area_id'] ?>" <?= $areaNow === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                </select><?= $err('area') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" id="receipt-form-field-supplier-label">Supplier (none for a delivery note alone)</label>
                <?= picker_field(['id' => 'receipt-form-field-supplier', 'name' => 'supplier', 'source' => 'supplier', 'value' => $supNow, 'invalid' => isset($errors['supplier']), 'labelledby' => 'receipt-form-field-supplier-label',
                    'extra' => ' hx-get="/receipts/po-options" hx-trigger="change" hx-target="#receipt-form-field-purchase_order" hx-swap="innerHTML" hx-vals=\'js:{supplier: document.getElementById("receipt-form-field-supplier").value}\'']) ?><?= $err('supplier') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="receipt-form-field-purchase_order">Purchase order</label>
                <select name="purchase_order" id="receipt-form-field-purchase_order" class="form-select btn-touch<?= isset($errors['purchase_order']) ? ' is-invalid' : '' ?>"><?= view('receipts/_po-options.php', ['options' => $poOptions, 'selected' => $poNow]) ?></select><?= $err('purchase_order') ?></div>
            <div class="row g-2">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="receipt-form-field-received_on">Received on</label><input type="date" name="received_on" id="receipt-form-field-received_on" class="form-control btn-touch<?= isset($errors['received_on']) ? ' is-invalid' : '' ?>" value="<?= e($val('received_on', (string) ($cur['received_on'] ?? site_today()))) ?>" max="<?= e(site_today()) ?>"><?= $err('received_on') ?></div>
                <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="receipt-form-field-delivery_note_ref">Delivery note</label><input type="text" name="delivery_note_ref" id="receipt-form-field-delivery_note_ref" maxlength="100" class="form-control btn-touch" value="<?= e($val('delivery_note_ref', (string) ($cur['delivery_note_ref'] ?? ''))) ?>"><?= $err('delivery_note_ref') ?></div>
                <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="receipt-form-field-invoice_ref">Invoice number</label><input type="text" name="invoice_ref" id="receipt-form-field-invoice_ref" maxlength="100" class="form-control btn-touch" value="<?= e($val('invoice_ref', (string) ($cur['invoice_ref'] ?? ''))) ?>"><?= $err('invoice_ref') ?></div>
            </div>
            <div class="mt-2"><label class="form-label fs-12 text-muted" for="receipt-form-field-notes">Notes</label><textarea name="notes" id="receipt-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($val('notes', (string) ($cur['notes'] ?? ''))) ?></textarea><?= $err('notes') ?></div>
        </div></div>
        <?php if ($id === null && $poLines !== []): ?><?= view('receipts/_po-prefill.php', ['lines' => $poLines]) ?><?php endif; ?>
        <?= $err('lines') ?>
        <div class="stock-post-bar"><button type="submit" class="btn btn-primary btn-touch w-100" id="receipt-form-submit"><?= $id === null ? 'Create — then add the lines' : 'Save' ?></button></div>
    </form>
</div>
