<?php /** The receipt and purchase-order selects of the invoice header form (replaced as a block when the supplier changes). Data: receipts, pos, receiptNow, poNow, errors? */
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="invoice-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
?>
<div class="row g-2 mb-3" id="invoice-form-refs">
    <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-receipt">The receipt it bills</label>
        <select name="receipt" id="invoice-form-field-receipt" class="form-select btn-touch<?= isset($errors['receipt']) ? ' is-invalid' : '' ?>"><option value="">None</option><?php foreach ($receipts as $g): ?><option value="<?= (int) $g['goods_receipt_id'] ?>" <?= (string) $receiptNow === (string) $g['goods_receipt_id'] ? 'selected' : '' ?>><?= e($g['number'] . ' · ' . format_date($g['received_on'])) ?></option><?php endforeach; ?></select><?= $err('receipt') ?>
        <?php if ($receipts === []): ?><div class="form-text">Choose the supplier to list its receipts of the last 60 days.</div><?php endif; ?></div>
    <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-purchase_order">or the purchase order</label>
        <select name="purchase_order" id="invoice-form-field-purchase_order" class="form-select btn-touch<?= isset($errors['purchase_order']) ? ' is-invalid' : '' ?>"><option value="">None</option><?php foreach ($pos as $p): ?><option value="<?= (int) $p['purchase_order_id'] ?>" <?= (string) $poNow === (string) $p['purchase_order_id'] ? 'selected' : '' ?>><?= e($p['number'] . ' · ' . $p['status'] . ' · ' . format_date($p['ordered_on'])) ?></option><?php endforeach; ?></select><?= $err('purchase_order') ?></div>
</div>
