<?php /** A purchase order's lines: a table at ≥ 576 px, cards under it with the arithmetic folded under a "why" toggle (purchasing.md "The 375 px rule"). Data: po, lines, seesCost, editable (a draft being edited), mayDecline (record a supplier's decline), returnTo? */
$poId = (int) $po['purchase_order_id'];
$draft = $po['status'] === 'draft';
$open = in_array($po['status'], PO_OPEN_STATES, true);
$showReceived = !$draft;
$pdo = db();
$cols = 6 + ($seesCost ? 2 : 0) + ($showReceived ? 1 : 0);
?>
<div class="card mb-3" id="po-lines">
    <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">Lines <span class="text-muted fs-12">(<?= count($lines) ?>)</span></h5>
        <?php if ($seesCost && $po['total'] !== null): ?><span class="fw-semibold" id="po-lines-total-head"><?= money($po['total']) ?></span><?php endif; ?></div>
    <?php if ($lines === []): ?><div class="card-body text-muted" id="po-lines-empty">No lines yet<?= $editable ? ' — add the first one below, or tick rows of the order guide.' : '.' ?></div><?php else: ?>
    <div class="stock-table-wrap d-none d-sm-block"><table class="table align-middle mb-0" id="po-lines-table">
        <thead><tr><th>#</th><th>Item</th><th>Quantity</th><?php if ($seesCost): ?><th class="text-end">Unit cost</th><th class="text-end">Total</th><?php endif; ?><th>Expected</th><?php if ($showReceived): ?><th>Received</th><?php endif; ?><th>Status</th><th>Supplier's note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $lid = $l['purchase_order_line_id']; ?>
            <tr id="po-line-row-<?= $lid ?>">
                <td><?= (int) $l['line_no'] ?></td>
                <td><?= hx_link('/ingredients/' . $l['ingredient_id'], e($l['ingredient'])) ?><?= $l['item_sku'] ?? $l['supplier_sku'] ? '<div class="fs-11 text-muted">' . e($l['item_sku'] ?? $l['supplier_sku']) . '</div>' : '' ?></td>
                <td><span class="fw-semibold"><?= e(fmt_num($l['qty_ordered'], 4) . ' ' . $l['purchase_unit_code']) ?></span> <span class="fs-12 text-muted">(<?= e(fmt_num($l['qty_ordered_base'], 3) . ' ' . $l['base_unit_code']) ?>)</span></td>
                <?php if ($seesCost): ?><td class="text-end"><?= $l['unit_cost'] === null ? money(null) : money_price($l['unit_cost']) . ' <span class="fs-11 text-muted">/ ' . e($l['purchase_unit_code']) . '</span>' ?></td><td class="text-end"><?= money($l['line_total'] ?? null) ?></td><?php endif; ?>
                <td><?= $l['expected_on'] ? e(format_date($l['expected_on'])) : '<span class="text-muted">—</span>' ?></td>
                <?php if ($showReceived): ?><td><?= e(fmt_num($l['qty_received'] ?? '0', 4) . ' ' . $l['purchase_unit_code']) ?></td><?php endif; ?>
                <td><?= po_line_chip($l['status'], 'po-line-status-' . $lid) ?></td>
                <td class="fs-12"><?= e((string) ($l['supplier_note'] ?? '')) ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($editable): ?><?= hx_link('/purchasing/' . $poId . '/edit?line=' . $lid . '#po-line-form', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="po-line-edit-' . $lid . '"') ?>
                    <form method="post" action="/purchasing/line-remove.php" hx-post="/purchasing/line-remove.php" hx-target="#flash" class="d-inline" hx-confirm="Remove line <?= (int) $l['line_no'] ?>?"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="po-line-remove-<?= $lid ?>" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form>
                    <?php elseif (!empty($mayDecline) && $open && in_array($l['status'], ['open', 'acknowledged'], true)): ?>
                    <details class="d-inline-block text-start"><summary class="btn btn-light btn-sm btn-touch" id="po-line-decline-open-<?= $lid ?>">Supplier declined</summary>
                        <form method="post" action="/purchasing/line-decline.php" hx-post="/purchasing/line-decline.php" hx-target="#flash" class="mt-1" id="po-line-decline-form-<?= $lid ?>" style="min-width: 14rem"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>">
                            <input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Why (out of stock …)" maxlength="300" required id="po-line-decline-reason-<?= $lid ?>"><button type="submit" class="btn btn-outline-danger btn-sm w-100" id="po-line-decline-submit-<?= $lid ?>">Record the decline</button></form></details>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($l['has_math']): ?><tr id="po-line-math-row-<?= $lid ?>" class="table-light"><td></td><td colspan="<?= $cols ?>"><?= view('purchasing/_line-math.php', ['line' => $l, 'id' => 'po-line-math-' . $lid]) ?></td></tr><?php endif; ?>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="d-sm-none" id="po-lines-cards">
        <?php foreach ($lines as $l): $lid = $l['purchase_order_line_id']; ?>
            <div class="border-bottom p-3" id="po-line-card-<?= $lid ?>">
                <div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($l['ingredient']) ?></span><?= po_line_chip($l['status']) ?></div>
                <div class="fs-4 fw-bold"><?= e(fmt_num($l['qty_ordered'], 4) . ' ' . $l['purchase_unit_code']) ?></div>
                <div class="fs-12 text-muted"><?= e(fmt_num($l['qty_ordered_base'], 3) . ' ' . $l['base_unit_code']) ?><?= ($l['item_sku'] ?? $l['supplier_sku']) ? ' · ' . e($l['item_sku'] ?? $l['supplier_sku']) : '' ?><?= $showReceived ? ' · received ' . e(fmt_num($l['qty_received'] ?? '0', 4)) : '' ?></div>
                <?php if ($seesCost && $l['unit_cost'] !== null): ?><div class="fs-12"><?= money_price($l['unit_cost']) ?> / <?= e($l['purchase_unit_code']) ?> · <?= money($l['line_total'] ?? null) ?></div><?php endif; ?>
                <?php if (!empty($l['supplier_note'])): ?><div class="fs-12 mt-1">Supplier: <?= e($l['supplier_note']) ?></div><?php endif; ?>
                <?php if ($l['has_math']): ?><details class="mt-1" id="po-line-why-<?= $lid ?>"><summary class="fs-12 fw-semibold">Why this much</summary><?= view('purchasing/_line-math.php', ['line' => $l, 'id' => 'po-line-math-card-' . $lid]) ?></details><?php endif; ?>
                <?php if ($editable): ?><div class="mt-2 d-flex gap-2"><?= hx_link('/purchasing/' . $poId . '/edit?line=' . $lid . '#po-line-form', 'Edit', 'btn btn-light btn-touch flex-grow-1') ?>
                    <form method="post" action="/purchasing/line-remove.php" hx-post="/purchasing/line-remove.php" hx-target="#flash" hx-confirm="Remove line <?= (int) $l['line_no'] ?>?"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch text-danger" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($seesCost): ?><div class="card-footer d-flex justify-content-between fs-12" id="po-lines-footer"><span><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span><span>Total <span class="fw-semibold" id="po-lines-total"><?= money($po['total']) ?></span></span></div><?php endif; ?>
    <?php endif; ?>
</div>
