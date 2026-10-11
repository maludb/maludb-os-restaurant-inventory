<?php /** An invoice's header form (`invoice-form`, ids invoice-form-field-{name}). Data: cur (the invoice or null), prefill (supplier, receipt, purchase_order), receipts, pos, today, errors? */
$errors = $errors ?? [];
$id = $cur['invoice_id'] ?? null;
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="invoice-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$supNow = $val('supplier', (string) ($cur['supplier_id'] ?? $prefill['supplier'] ?? ''));
$receiptNow = $val('receipt', (string) ($cur['goods_receipt_id'] ?? $prefill['receipt'] ?? ''));
$poNow = $val('purchase_order', (string) ($cur['purchase_order_id'] ?? $prefill['purchase_order'] ?? ''));
?>
<form method="post" action="/invoices/save.php" hx-post="/invoices/save.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="invoice-form" class="card mb-3">
    <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="invoice" value="<?= (int) $id ?>"><?php endif; ?>
    <div class="card-header"><h5 class="card-title mb-0">The invoice</h5></div>
    <div class="card-body">
        <div class="mb-3"><label class="form-label fs-12 text-muted" id="invoice-form-field-supplier-label">Supplier</label>
            <?= picker_field(['id' => 'invoice-form-field-supplier', 'name' => 'supplier', 'source' => 'supplier', 'value' => $supNow, 'invalid' => isset($errors['supplier']), 'labelledby' => 'invoice-form-field-supplier-label', 'required' => true, 'disabled' => !empty($hasLines),
                'extra' => ' hx-get="/invoices/options" hx-trigger="change" hx-target="#invoice-form-refs" hx-swap="outerHTML" hx-vals=\'js:{supplier: document.getElementById("invoice-form-field-supplier").value}\'']) ?><?= $err('supplier') ?></div>
        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="invoice-form-field-invoice_no">Their invoice number</label><input type="text" name="invoice_no" id="invoice-form-field-invoice_no" maxlength="60" class="form-control btn-touch<?= isset($errors['invoice_no']) ? ' is-invalid' : '' ?>" value="<?= e($val('invoice_no', (string) ($cur['invoice_no'] ?? ''))) ?>" required><?= $err('invoice_no') ?></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-form-field-kind">Kind</label><select name="kind" id="invoice-form-field-kind" class="form-select btn-touch"><?php foreach (INVOICE_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $val('kind', (string) ($cur['kind'] ?? 'invoice')) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select><?= $err('kind') ?></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-form-field-invoice_date">Invoice date</label><input type="date" name="invoice_date" id="invoice-form-field-invoice_date" class="form-control btn-touch<?= isset($errors['invoice_date']) ? ' is-invalid' : '' ?>" value="<?= e($val('invoice_date', (string) ($cur['invoice_date'] ?? $today))) ?>" required><?= $err('invoice_date') ?></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoice-form-field-received_on">Received on</label><input type="date" name="received_on" id="invoice-form-field-received_on" class="form-control btn-touch<?= isset($errors['received_on']) ? ' is-invalid' : '' ?>" value="<?= e($val('received_on', (string) ($cur['received_on'] ?? $today))) ?>"><?= $err('received_on') ?></div>
        </div>
        <?= view('invoices/_refs.php', ['receipts' => $receipts, 'pos' => $pos, 'receiptNow' => $receiptNow, 'poNow' => $poNow, 'errors' => $errors]) ?>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-form-field-tax">Tax</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" inputmode="decimal" name="tax" id="invoice-form-field-tax" class="form-control btn-touch<?= isset($errors['tax']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($val('tax', (string) ($cur['tax'] ?? '0')), 2)) ?>"></div><?= $err('tax') ?></div>
            <div class="col-12 col-md-9"><label class="form-label fs-12 text-muted" for="invoice-form-field-file">The paper (a photo or a PDF)<?= !empty($cur['attachment_id']) ? ' — a new file replaces the one kept' : '' ?></label><input type="file" name="file" id="invoice-form-field-file" accept="image/*,application/pdf" class="form-control btn-touch"></div>
        </div>
        <?php if ($id === null): ?><div class="form-check mb-3"><input type="hidden" name="prefill" value="no"><input type="checkbox" class="form-check-input" name="prefill" value="yes" id="invoice-form-field-prefill" checked><label class="form-check-label" for="invoice-form-field-prefill">Start with the receipt's lines — I will correct them against the paper</label></div><?php endif; ?>
        <div><label class="form-label fs-12 text-muted" for="invoice-form-field-notes">Notes (a credit note names the invoice it credits here)</label><textarea name="notes" id="invoice-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($val('notes', (string) ($cur['notes'] ?? ''))) ?></textarea><?= $err('notes') ?></div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="invoice-form-submit"><?= $id === null ? 'Create — then correct the lines' : 'Save the header' ?></button></div>
</form>
