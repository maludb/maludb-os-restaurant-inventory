<?php
declare(strict_types=1);
/** Action `purchase_order_line_set`: purchasing.draft; a draft's line — INSERT, or UPDATE when `line` names one (what is left out stays). supplier_item (an id or SKU of THIS supplier) | ingredient, qty + unit (the unit the person gave is the unit the line keeps), unit_cost per purchase unit (walled; the item's contract or last price by default), expected_on. Log purchase_order.line_set. Location /purchasing/{id}/edit#po-line-row-{line}. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
$sees = sees_cost((int) current_site_id());
$cur = null;
if (req_has('line') && trim((string) req_val('line')) !== '') { [$cur, $po] = po_line_from_request($pdo); }
else { $po = po_from_request($pdo); }
po_require_draft($po);
$in = [];
foreach (['supplier_item', 'ingredient', 'qty', 'unit', 'unit_cost', 'expected_on'] as $k) { if (req_has($k)) { $in[$k] = req_val($k); } }
$errors = [];
$l = po_line_fields($pdo, $in, $po, $cur, $errors);
if ($errors !== [] || $l === null) { ri_refuse_fields($errors ?: ['supplier_item' => 'Choose the supplier item (or the ingredient).']); }
$lineId = $cur === null ? null : (int) $cur['purchase_order_line_id'];
$id = ri_guard($pdo, static function () use ($pdo, $po, $lineId, $l, $sees): int {
    $pdo->beginTransaction();
    $id = save_po_line($pdo, (int) $po['purchase_order_id'], $lineId, $l);
    $row = q_row($pdo, 'SELECT qty_ordered, purchase_unit_code, qty_ordered_base, unit_cost FROM purchase_order_lines WHERE id = :id', ['id' => $id]);
    po_log($pdo, 'purchase_order.line_set', (int) $po['purchase_order_id'], ['ingredient_id' => $l['ingredient_id'], 'after' => ['line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $row['qty_ordered'], 'unit' => $row['purchase_unit_code'],
        'qty_base' => $row['qty_ordered_base']] + ($sees ? ['unit_cost' => $row['unit_cost']] : []) + ($lineId === null ? [] : ['changed' => true])]);
    $pdo->commit();
    return $id;
});
$row = q_row($pdo, 'SELECT qty_ordered_base, unit_cost FROM mcp_purchase_order_lines WHERE purchase_order_line_id = :id', ['id' => $id]);
$extra = ['purchase_order_id' => $po['purchase_order_id'], 'line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'qty_base' => $row['qty_ordered_base']];
if ($sees) { $extra['unit_cost'] = $row['unit_cost']; $extra['total'] = po_for($pdo, (int) $po['purchase_order_id'])['total']; }
ri_done(($lineId === null ? 'Added ' : 'Changed ') . fmt_num($l['qty'], 4) . ' ' . $l['unit'] . ' of ' . $l['ingredient'] . ' on ' . $po['number'], $id,
    ri_land('/purchasing/' . $po['purchase_order_id'] . '/edit', 'line_saved', 'po-line-row-' . $id), 'purchaseOrderChanged', $extra);
