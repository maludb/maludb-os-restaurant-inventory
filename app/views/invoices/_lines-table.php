<?php /** An invoice's lines with the receipt line each is matched to and the match (screen `invoice-view`, `invoice-edit`). Data: inv, lines, editable */
$iid = (int) $inv['invoice_id'];
$cols = 8;
?>
<div class="card mb-3" id="invoice-lines">
    <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">Lines <span class="text-muted fs-12">(<?= count($lines) ?>)</span></h5><span class="fw-semibold" id="invoice-lines-total-head"><?= money($inv['total']) ?></span></div>
    <?php if ($lines === []): ?><div class="card-body text-muted" id="invoice-lines-empty">No lines yet<?= $editable ? ' — add the first one below.' : '.' ?></div><?php else: ?>
    <div class="stock-table-wrap d-none d-sm-block"><table class="table align-middle mb-0" id="invoice-lines-table">
        <thead><tr><th>#</th><th>Billed</th><th>Quantity</th><th class="text-end">Price</th><th class="text-end">Line</th><th>Receipt line</th><th>Match</th><th>Difference</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($lines as $l): $lid = $l['invoice_line_id']; ?>
            <tr id="invoice-line-row-<?= $lid ?>">
                <td><?= (int) $l['line_no'] ?></td>
                <td><?= e((string) ($l['description'] ?? $l['ingredient'] ?? '')) ?><?= $l['supplier_sku'] ? '<div class="fs-11 text-muted">' . e($l['supplier_sku']) . '</div>' : '' ?></td>
                <td class="fw-semibold"><?= e(fmt_num($l['qty'], 4) . ' ' . $l['unit_code']) ?></td>
                <td class="text-end"><?= money_price($l['unit_price']) ?> <span class="fs-11 text-muted">/ <?= e($l['unit_code']) ?></span></td>
                <td class="text-end"><?= money($l['line_total']) ?></td>
                <td class="fs-12" id="invoice-line-receipt-<?= $lid ?>"><?php if ($l['receipt_line_id'] !== null): ?><?= hx_link('/receipts/' . $l['rl_receipt_id'], e((string) $l['rl_receipt_number'])) ?> line <?= (int) $l['rl_line_no'] ?><?php if ($l['received_qty'] !== null): ?><div class="text-muted">came <?= e(fmt_num($l['received_qty'], 4) . ' ' . $l['unit_code']) ?><?= $l['received_cost'] !== null ? ' @ ' . e(ri_money($l['received_cost'], '$', 4)) : '' ?></div><?php endif; ?><?php else: ?><span class="text-muted">none</span><?php endif; ?></td>
                <td><?= match_chip($l['match_state'], 'invoice-line-match-' . $lid) ?></td>
                <td class="fs-12" id="invoice-line-diff-<?= $lid ?>"><?php if ($l['diff_qty'] !== null && (float) $l['diff_qty'] != 0.0 && $l['diff_qty_unit'] !== null): ?><?= ((float) $l['diff_qty_unit'] > 0 ? '+' : '') . e(fmt_num($l['diff_qty_unit'], 4)) . ' ' . e($l['unit_code']) ?><?php endif; ?><?= $l['diff_value'] !== null && (float) $l['diff_value'] != 0.0 ? ' · ' . ((float) $l['diff_value'] > 0 ? '+' : '−') . e(ri_money(abs((float) $l['diff_value']), '$', 2)) : '' ?></td>
                <?php if ($editable): ?><td class="text-end text-nowrap"><?= hx_link('/invoices/' . $iid . '/edit?line=' . $lid . '#invoice-line-form', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="invoice-line-edit-' . $lid . '"') ?>
                    <form method="post" action="/invoices/line-remove.php" hx-post="/invoices/line-remove.php" hx-target="#flash" class="d-inline" hx-confirm="Remove line <?= (int) $l['line_no'] ?>?"><?= csrf_field() ?><input type="hidden" name="invoice_line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="invoice-line-remove-<?= $lid ?>" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="d-sm-none" id="invoice-lines-cards">
        <?php foreach ($lines as $l): $lid = $l['invoice_line_id']; ?>
            <div class="border-bottom p-3" id="invoice-line-card-<?= $lid ?>">
                <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= e((string) ($l['description'] ?? $l['ingredient'] ?? '')) ?></span><?= match_chip($l['match_state']) ?></div>
                <div class="fs-4 fw-bold"><?= e(fmt_num($l['qty'], 4) . ' ' . $l['unit_code']) ?></div>
                <div class="fs-12"><?= money_price($l['unit_price']) ?> / <?= e($l['unit_code']) ?> · <?= money($l['line_total']) ?></div>
                <?php if ($l['receipt_line_id'] !== null && $l['received_qty'] !== null): ?><div class="fs-12 text-muted">Receipt <?= e((string) $l['rl_receipt_number']) ?>: came <?= e(fmt_num($l['received_qty'], 4) . ' ' . $l['unit_code']) ?><?= $l['received_cost'] !== null ? ' @ ' . e(ri_money($l['received_cost'], '$', 4)) : '' ?></div><?php endif; ?>
                <?php if ($l['diff_value'] !== null && (float) $l['diff_value'] != 0.0): ?><div class="fs-12 text-danger"><?= ((float) $l['diff_value'] > 0 ? '+' : '−') . e(ri_money(abs((float) $l['diff_value']), '$', 2)) ?></div><?php endif; ?>
                <?php if ($editable): ?><div class="mt-2 d-flex gap-2"><?= hx_link('/invoices/' . $iid . '/edit?line=' . $lid . '#invoice-line-form', 'Edit', 'btn btn-light btn-touch flex-grow-1') ?>
                    <form method="post" action="/invoices/line-remove.php" hx-post="/invoices/line-remove.php" hx-target="#flash" hx-confirm="Remove line <?= (int) $l['line_no'] ?>?"><?= csrf_field() ?><input type="hidden" name="invoice_line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch text-danger" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-footer fs-12" id="invoice-lines-footer">Subtotal <?= money($inv['subtotal']) ?> · Tax <?= money($inv['tax']) ?> · Total <span class="fw-semibold" id="invoice-lines-total"><?= money($inv['total']) ?></span></div>
    <?php endif; ?>
</div>
