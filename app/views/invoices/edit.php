<?php /** A draft or disputed invoice: the header, the lines, the line form (screen `invoice-edit`). Data: inv, lines, line, unitOptions, receipts, pos, rlines, today, notice */
$iid = (int) $inv['invoice_id'];
?>
<?= view('shared/header.php', ['id' => 'invoice-edit', 'title' => 'Change ' . $inv['number'], 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], [$inv['number'], '/invoices/' . $iid], ['Edit', null]],
    'back' => back_link() ?? ['/invoices/' . $iid, $inv['number']], 'action' => hx_link('/invoices/' . $iid, '<i class="feather-check me-1"></i>Done', 'btn btn-light btn-touch', 'id="invoice-edit-done-btn"')]) ?>
<div class="main-content" id="invoice-edit-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('invoices/_invoice-form.php', ['cur' => $inv, 'prefill' => [], 'receipts' => $receipts, 'pos' => $pos, 'today' => $today, 'hasLines' => $lines !== []]) ?>
    <?= view('invoices/_lines-table.php', ['inv' => $inv, 'lines' => $lines, 'editable' => true]) ?>
    <?= view('invoices/_line-form.php', ['inv' => $inv, 'line' => $line, 'unitOptions' => $unitOptions, 'rlines' => $rlines]) ?>
</div>
