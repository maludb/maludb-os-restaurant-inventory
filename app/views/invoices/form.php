<?php /** Enter an invoice (screen `invoice-add`). Data: prefill, receipts, pos, receipt, receiptLines, today */ ?>
<?= view('shared/header.php', ['id' => 'invoice-add', 'title' => 'Enter an invoice', 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], ['New', null]], 'back' => back_link() ?? ['/invoices/', 'Invoices']]) ?>
<div class="main-content" id="invoice-add-content">
    <?= view('stock/_assets.php') ?>
    <?php if ($receipt !== null): ?><div class="alert alert-info" id="invoice-add-receipt-note">Billing receipt <?= hx_link('/receipts/' . $receipt['goods_receipt_id'], e($receipt['number']), 'alert-link') ?> — its <?= count($receiptLines) ?> line<?= count($receiptLines) === 1 ? '' : 's' ?> will be filled in at the price it was received at. Correct them against the paper on the next screen.</div><?php endif; ?>
    <?= view('invoices/_invoice-form.php', ['cur' => null, 'prefill' => $prefill, 'receipts' => $receipts, 'pos' => $pos, 'today' => $today, 'hasLines' => false]) ?>
</div>
