<?php
/** One invoice (screen `invoice-view`). Data: inv, lines, pair, mayWrite, tz, trail, notice */
$iid = (int) $inv['invoice_id'];
$st = $inv['status'];
$editable = $mayWrite && in_array($st, ['draft', 'disputed'], true);
$prices = count(array_filter($lines, static fn (array $l): bool => in_array($l['match_state'], ['price_diff', 'both_diff'], true)));
$qtys = count(array_filter($lines, static fn (array $l): bool => in_array($l['match_state'], ['qty_diff', 'both_diff'], true) && (float) $l['diff_qty'] != 0.0));
$confirm = 'Settle ' . $inv['invoice_no'] . ': the invoice becomes the truth — ' . $prices . ' price difference' . ($prices === 1 ? '' : 's') . ' write' . ($prices === 1 ? 's' : '') . ' cost history, ' . $qtys . ' quantity correction' . ($qtys === 1 ? '' : 's') . '?';
$action = $editable ? hx_link('/invoices/' . $iid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="invoice-edit-btn"') : '';
?>
<?= view('shared/header.php', ['id' => 'invoice-view', 'title' => $inv['number'] . ' · ' . $inv['supplier'] . ' ' . $inv['invoice_no'], 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], [$inv['number'], null]], 'back' => back_link() ?? ['/invoices/', 'Invoices'], 'action' => $action]) ?>
<div class="main-content" id="invoice-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="invoice-header"><div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center fs-12">
            <span><?= invoice_chip($st, 'invoice-status-chip') ?></span>
            <span><?= chip(INVOICE_KINDS[$inv['kind']] ?? $inv['kind'], $inv['kind'] === 'credit' ? 'warning' : 'secondary', 'invoice-kind-chip') ?></span>
            <span><i class="feather-truck me-1"></i><?= hx_link('/suppliers/' . $inv['supplier_id'], e($inv['supplier'])) ?></span>
            <span id="invoice-theirs">Theirs <span class="fw-semibold"><?= e($inv['invoice_no']) ?></span></span>
            <span><i class="feather-calendar me-1"></i>Dated <?= e(format_date($inv['invoice_date'])) ?></span>
            <span>Received <?= e(format_date($inv['received_on'])) ?></span>
            <?php if ($inv['receipt_number']): ?><span id="invoice-receipt">Bills receipt <?= hx_link('/receipts/' . $inv['goods_receipt_id'], e($inv['receipt_number'])) ?></span><?php endif; ?>
            <?php if ($inv['po_number']): ?><span id="invoice-po">PO <?= hx_link('/purchasing/' . $inv['purchase_order_id'], e($inv['po_number'])) ?></span><?php endif; ?>
            <?php if ($inv['attachment_id']): ?><span><a href="/files/<?= (int) $inv['attachment_id'] ?>" target="_blank" rel="noopener" id="invoice-file"><i class="feather-paperclip me-1"></i>The paper</a></span><?php endif; ?>
        </div>
        <div class="mt-2 fs-12" id="invoice-totals">Subtotal <?= money($inv['subtotal']) ?> · Tax <?= money($inv['tax']) ?> · Total <span class="fw-semibold"><?= money($inv['total']) ?></span></div>
        <?php if ($inv['settled_at']): ?><div class="mt-1 fs-12 text-muted" id="invoice-settled">Settled by <?= e((string) ($inv['settled_by_name'] ?? '')) ?> · <?= e(format_ts($inv['settled_at'], $tz, 'M j g:i A')) ?></div><?php endif; ?>
        <?php if ($pair !== []): ?><div class="mt-2 fs-12" id="invoice-pair"><?= $inv['kind'] === 'invoice' ? 'Credit note' : 'Credits' ?>: <?php foreach ($pair as $q): ?><?= hx_link('/invoices/' . $q['invoice_id'], e($q['number'] . ' · ' . $q['invoice_no']), 'me-2') ?><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php if (trim((string) ($inv['notes'] ?? '')) !== ''): ?><div class="card-footer fs-12" id="invoice-notes"><?= nl2br(e((string) $inv['notes'])) ?></div><?php endif; ?></div>

    <?= view('invoices/_lines-table.php', ['inv' => $inv, 'lines' => $lines, 'editable' => false]) ?>

    <?php if ($mayWrite && !in_array($st, ['settled', 'void'], true)): ?>
    <div class="card mb-3" id="invoice-actions"><div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <?php if (in_array($st, ['draft', 'disputed'], true)): ?>
            <form method="post" action="/invoices/match.php" hx-post="/invoices/match.php" hx-target="#flash" class="flex-grow-1 flex-md-grow-0"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $iid ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="invoice-match-btn" <?= $lines === [] ? 'disabled' : '' ?>><i class="feather-git-merge me-1"></i>Match</button></form>
            <?php endif; ?>
            <?php if (in_array($st, ['matched', 'disputed'], true)): ?>
            <form method="post" action="/invoices/settle.php" hx-post="/invoices/settle.php" hx-target="#flash" hx-confirm="<?= e($confirm) ?>" class="flex-grow-1 flex-md-grow-0 d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $iid ?>">
                <input type="text" name="note" class="form-control btn-touch" maxlength="500" placeholder="Note (optional)" id="invoice-settle-note" style="min-width: 10rem"><button type="submit" class="btn btn-success btn-touch" id="invoice-settle-btn">Settle</button></form>
            <?php endif; ?>
            <form method="post" action="/invoices/void.php" hx-post="/invoices/void.php" hx-target="#flash" hx-confirm="Void <?= e($inv['number']) ?>? Nothing was settled, so nothing else changes." class="flex-grow-1 flex-md-grow-0 d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $iid ?>">
                <input type="text" name="reason" class="form-control btn-touch" maxlength="300" placeholder="Why (optional)" id="invoice-void-reason" style="min-width: 10rem"><button type="submit" class="btn btn-outline-danger btn-touch" id="invoice-void-btn">Void</button></form>
        </div>
        <?php if ($st === 'disputed'): ?><p class="fs-12 text-muted mb-0 mt-2" id="invoice-disputed-help">Some lines differ from what was received. Correct the paper and Match again — or Settle, and the invoice becomes the truth (a price difference is written to cost history on the receipt's date, a quantity difference is posted as a correction).</p><?php endif; ?>
    </div></div>
    <?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'invoice-trail', 'rows' => $trail]) ?>
</div>
