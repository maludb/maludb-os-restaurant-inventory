<?php /** The supplier's order guide at this site as rows to tick (a quantity in the item's purchase unit each) — Add lines posts them as `guide[]` / `guide_qty[id]`. Data: po, guide (guide_rows_for), seesCost */
?>
<form method="post" action="/purchasing/save.php" hx-post="/purchasing/save.php" hx-target="#flash" id="po-guide" class="card mb-3">
    <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= (int) $po['purchase_order_id'] ?>">
    <div class="card-header"><h5 class="card-title mb-0"><?= e($po['supplier']) ?>'s order guide <span class="text-muted fs-12">(tick what to order)</span></h5></div>
    <?php if ($guide === []): ?><div class="card-body text-muted" id="po-guide-empty">Nothing left in the order guide to add<?= has_right('purchasing.draft') ? ' — ' . hx_link('/suppliers/' . (int) $po['supplier_id'] . '/guide', 'edit the guide') . '.' : '.' ?></div><?php else: ?>
    <div class="stock-table-wrap"><table class="table align-middle mb-0" id="po-guide-table"><thead><tr><th style="width:3rem"></th><th>Item</th><?php if ($seesCost): ?><th class="text-end">Last price</th><?php endif; ?><th style="width:12rem">Quantity</th></tr></thead><tbody>
    <?php foreach ($guide as $g): $sid = (int) $g['supplier_item_id']; ?>
        <tr id="po-guide-row-<?= $sid ?>">
            <td><input type="checkbox" class="form-check-input" name="guide[]" value="<?= $sid ?>" id="po-guide-check-<?= $sid ?>"></td>
            <td><label for="po-guide-check-<?= $sid ?>"><?= e($g['ingredient']) ?></label><div class="fs-11 text-muted"><?= e(trim(($g['supplier_sku'] ?? '') . ' ' . ($g['pack'] ?? ''))) ?></div></td>
            <?php if ($seesCost): ?><td class="text-end"><?= $g['last_price'] === null ? '<span class="text-muted">—</span>' : money_price($g['last_price']) . ' <span class="fs-11 text-muted">/ ' . e($g['purchase_unit_code']) . '</span>' ?></td><?php endif; ?>
            <td><div class="input-group"><input type="number" step="any" min="0" class="form-control btn-touch" name="guide_qty[<?= $sid ?>]" id="po-guide-qty-<?= $sid ?>" inputmode="decimal"><span class="input-group-text"><?= e($g['purchase_unit_code']) ?></span></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="po-guide-submit">Add lines</button></div>
    <?php endif; ?>
</form>
