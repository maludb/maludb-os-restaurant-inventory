<?php /** A PO's open lines as checked rows to prefill the draft (ledger.md receipt-add). Data: lines */ ?>
<div class="card mb-3" id="receipt-po-prefill"><div class="card-header"><h5 class="card-title mb-0">The purchase order's open lines</h5></div>
    <div class="table-responsive"><table class="table align-middle mb-0" id="receipt-po-prefill-table">
        <thead><tr><th style="width:3rem"></th><th>Ingredient</th><th>Still to come</th><th style="width:12rem">Arrived</th></tr></thead><tbody>
        <?php foreach ($lines as $l): $lid = $l['purchase_order_line_id']; ?>
            <tr id="receipt-po-prefill-row-<?= $lid ?>">
                <td><input type="checkbox" class="form-check-input" name="prefill[]" value="<?= $lid ?>" id="receipt-po-prefill-check-<?= $lid ?>" checked></td>
                <td><label for="receipt-po-prefill-check-<?= $lid ?>"><?= e($l['ingredient']) ?></label></td>
                <td class="fs-12"><?= e(fmt_num($l['qty_open'], 2) . ' ' . $l['purchase_unit_code']) ?> <span class="text-muted">(<?= e(fmt_num($l['qty_open_base'], 2) . ' ' . $l['base_unit_code']) ?>)</span></td>
                <td><div class="input-group"><input type="number" step="any" min="0" class="form-control btn-touch" name="prefill_qty[<?= $lid ?>]" id="receipt-po-prefill-qty-<?= $lid ?>" value="<?= e(fmt_input($l['qty_open'])) ?>"><span class="input-group-text"><?= e($l['purchase_unit_code']) ?></span></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-footer fs-12 text-muted">Uncheck a line that did not come; change a quantity that came short.</div>
</div>
