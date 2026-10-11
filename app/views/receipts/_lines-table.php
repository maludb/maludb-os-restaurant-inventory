<?php /** A receipt's lines: a table at ≥ 576 px, cards under it (ledger.md "Mobile rule"). Data: receipt, lines, seesCost, mayEdit */
$draft = $receipt['status'] === 'draft';
$rid = (int) $receipt['goods_receipt_id'];
$pdo = db();
$total = 0.0;
?>
<div class="card mb-3" id="receipt-lines">
    <div class="card-header"><h5 class="card-title mb-0">Lines <span class="text-muted fs-12">(<?= count($lines) ?>)</span></h5></div>
    <?php if ($lines === []): ?><div class="card-body text-muted" id="receipt-lines-empty">No lines yet<?= $draft && $mayEdit ? ' — add the first one.' : '.' ?></div><?php else: ?>
    <div class="table-responsive d-none d-sm-block"><table class="table align-middle mb-0" id="receipt-lines-table">
        <thead><tr><th>#</th><th>Ingredient</th><th>Quantity</th><th>Weight</th><th class="text-end">Cost</th><th>Temp</th><th>Discrepancy</th><th>Put away</th><th></th></tr></thead><tbody>
        <?php foreach ($lines as $l): $lid = $l['goods_receipt_line_id']; $val = $l['cost_per_base'] === null ? null : (float) $l['qty_base'] * (float) $l['cost_per_base']; if ($val !== null) { $total += $val; } ?>
            <tr id="receipt-line-row-<?= $lid ?>">
                <td><?= (int) $l['line_no'] ?></td>
                <td><?= hx_link('/ingredients/' . $l['ingredient_id'], e($l['ingredient'])) ?><?= $l['supplier_sku'] ? '<div class="fs-11 text-muted">' . e($l['supplier_sku']) . '</div>' : '' ?></td>
                <td><span class="fw-semibold"><?= e(fmt_num($l['qty_received'], 4) . ' ' . $l['purchase_unit_code']) ?></span> <span class="fs-12 text-muted">(<?= e(fmt_num($l['qty_base'], 3) . ' ' . $l['base_unit_code']) ?>)</span></td>
                <td><?= $l['weight_qty'] === null ? '' : e(fmt_num($l['weight_qty'], 3) . ' ' . $l['weight_unit_code']) ?></td>
                <td class="text-end"><?= $seesCost ? ($l['unit_cost'] === null ? '<span class="text-muted">running</span>' : money_price($l['unit_cost']) . ' / ' . e($l['weight_unit_code'] ?? $l['purchase_unit_code'])) : money(null) ?></td>
                <td><?php if ($l['temperature_c'] !== null || $l['temperature_ok'] !== null): ?><span class="<?= $l['temperature_ok'] === false ? 'text-danger' : 'text-success' ?>"><?= $l['temperature_c'] === null ? '' : e(fmt_num($l['temperature_c'], 1)) . ' °C ' ?><?= $l['temperature_ok'] === null ? '' : ($l['temperature_ok'] ? 'OK' : 'not OK') ?></span><?php endif; ?></td>
                <td><?= $l['discrepancy_kind'] === 'none' ? '' : chip(DISCREPANCY_KINDS[$l['discrepancy_kind']], 'warning') ?><?= $l['discrepancy_note'] ? '<div class="fs-11 text-muted">' . e($l['discrepancy_note']) . '</div>' : '' ?></td>
                <td><?= e($l['putaway_area'] ?? '') ?></td>
                <td class="text-end text-nowrap"><?php if ($draft && $mayEdit): ?><?= hx_link('/receipts/' . $rid . '?line=' . $lid . '#receipt-line-form', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="receipt-line-edit-' . $lid . '"') ?>
                    <form method="post" action="/receipts/line-remove.php" hx-post="/receipts/line-remove.php" hx-target="#flash" class="d-inline" hx-confirm="Remove line <?= (int) $l['line_no'] ?>?"><?= csrf_field() ?><input type="hidden" name="receipt_line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="receipt-line-remove-<?= $lid ?>" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="d-sm-none">
        <?php foreach ($lines as $l): $lid = $l['goods_receipt_line_id']; ?>
            <div class="border-bottom p-3" id="receipt-line-card-<?= $lid ?>">
                <div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($l['ingredient']) ?></span><span class="fs-12 text-muted">#<?= (int) $l['line_no'] ?></span></div>
                <div class="fs-4 fw-bold"><?= e(fmt_num($l['qty_received'], 4) . ' ' . $l['purchase_unit_code']) ?></div>
                <div class="fs-12 text-muted"><?= e(fmt_num($l['qty_base'], 3) . ' ' . $l['base_unit_code']) ?><?= $l['weight_qty'] === null ? '' : ' · declared ' . e(fmt_num($l['weight_qty'], 3) . ' ' . $l['weight_unit_code']) ?><?= $l['temperature_ok'] === null ? '' : ' · ' . ($l['temperature_ok'] ? 'temp OK' : 'temp NOT OK') ?></div>
                <?php if ($draft && $mayEdit): ?><div class="mt-2 d-flex gap-2"><?= hx_link('/receipts/' . $rid . '?line=' . $lid . '#receipt-line-form', 'Edit', 'btn btn-light btn-touch flex-grow-1') ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex justify-content-between fs-12" id="receipt-lines-footer"><span><?= count($lines) ?> lines</span><span>At cost: <span id="receipt-lines-total"><?= $seesCost ? '$' . e(number_format($total, 2)) : money(null) ?></span></span></div>
    <?php endif; ?>
</div>
