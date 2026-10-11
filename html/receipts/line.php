<?php
declare(strict_types=1);
/** Action `receipt_line_add`: stock.receive; a draft's line — INSERT, or UPDATE when `receipt_line` names one. The trigger converts (catch weight lands at the declared weight). A cost sent where the caller sees no cost is refused in words. Log receipt.line_add (ingredient_id). Location /receipts/{id}#receipt-line-row-{line}. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.receive');
$pdo = db();
$lineId = req_has('receipt_line') && (string) req_val('receipt_line') !== '' ? (int) req_val('receipt_line') : null;
$cur = null;
if ($lineId !== null) {
    $cur = q_row($pdo, 'SELECT * FROM mcp_goods_receipt_lines WHERE goods_receipt_line_id = :id', ['id' => $lineId]);
    if ($cur === null) { respond_not_found('That line is not here.'); }
}
$g = receipt_or_404($pdo, $cur !== null ? (int) $cur['goods_receipt_id'] : (req_has('receipt') ? (int) req_val('receipt') : null));
$errors = [];
$in = [];
foreach (['ingredient', 'supplier_item', 'qty', 'unit', 'weight_qty', 'weight_unit', 'unit_cost', 'allow_zero_cost', 'discrepancy_kind', 'discrepancy_note', 'temperature_c', 'temperature_ok', 'putaway_area', 'purchase_order_line'] as $k) {
    $in[$k] = req_has($k) ? req_val($k) : ($cur === null ? null : match ($k) {
        'ingredient' => (string) $cur['ingredient_id'], 'supplier_item' => (string) ($cur['supplier_item_id'] ?? ''), 'qty' => (string) $cur['qty_received'], 'unit' => $cur['purchase_unit_code'], 'weight_qty' => $cur['weight_qty'],
        'weight_unit' => $cur['weight_unit_code'], 'unit_cost' => null, 'discrepancy_kind' => $cur['discrepancy_kind'], 'discrepancy_note' => $cur['discrepancy_note'], 'temperature_c' => $cur['temperature_c'],
        'temperature_ok' => $cur['temperature_ok'] === null ? null : ($cur['temperature_ok'] ? 'yes' : 'no'), 'putaway_area' => (string) ($cur['putaway_area_id'] ?? ''), 'purchase_order_line' => (string) ($cur['purchase_order_line_id'] ?? ''), default => null,
    });
}
$l = receipt_line_fields($pdo, $in, $g, $errors);
if ($cur !== null && $l !== null && !req_has('unit_cost')) {   // a line edited without its cost keeps the cost it had (the base row's — a walled caller never sends one)
    $l['unit_cost'] = one_value($pdo, 'SELECT unit_cost FROM goods_receipt_lines WHERE id = :id', ['id' => $lineId]); }
if ($errors !== [] || $l === null) { ri_refuse_fields($errors ?: ['ingredient' => 'Choose the ingredient.']); }
$id = ri_guard($pdo, static function () use ($pdo, $g, $lineId, $l): int {
    $pdo->beginTransaction();
    $id = save_receipt_line($pdo, (int) $g['goods_receipt_id'], $lineId, $l);
    $row = q_row($pdo, 'SELECT qty_received, purchase_unit_code, qty_base, weight_qty, weight_unit_code, temperature_c, temperature_ok, discrepancy_kind FROM goods_receipt_lines WHERE id = :id', ['id' => $id]);
    stock_log($pdo, 'receipt.line_add', 'goods_receipt', (int) $g['goods_receipt_id'], ['ingredient_id' => $l['ingredient_id'], 'purchase_order_id' => $g['purchase_order_id'], 'after' => ['line_id' => $id, 'ingredient_id' => $l['ingredient_id']] + $row + ($lineId === null ? [] : ['changed' => true])]);
    $pdo->commit();
    return $id;
});
$row = q_row($pdo, 'SELECT qty_base, cost_per_base FROM mcp_goods_receipt_lines WHERE goods_receipt_line_id = :id', ['id' => $id]);
$extra = ['receipt_id' => $g['goods_receipt_id'], 'line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $l['weight_qty'] ?? $l['qty'], 'unit' => $l['weight_unit'] ?? $l['unit'], 'qty_base' => $row['qty_base']];
if (sees_cost()) { $extra['cost_per_base'] = $row['cost_per_base']; }
ri_done(($lineId === null ? 'Added ' : 'Changed ') . fmt_num($l['qty'], 4) . ' ' . $l['unit'] . ' of ' . $l['ingredient'] . ($l['weight_qty'] !== null ? ' (declared ' . fmt_num($l['weight_qty'], 3) . ' ' . $l['weight_unit'] . ')' : '') . ' on ' . $g['number'], $id,
    ri_land('/receipts/' . $g['goods_receipt_id'], 'line_saved', 'receipt-line-row-' . $id), 'receiptChanged', $extra);
