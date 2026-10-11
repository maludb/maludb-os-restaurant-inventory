<?php
declare(strict_types=1);
/** Action `purchase_order_line_remove`: purchasing.draft; a draft's line (the trigger refuses one of a sent order). Log purchase_order.line_remove. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
[$l, $po] = po_line_from_request($pdo);
po_require_draft($po);
ri_guard($pdo, static function () use ($pdo, $po, $l): void {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM purchase_order_lines WHERE id = :id')->execute(['id' => $l['purchase_order_line_id']]);
    po_log($pdo, 'purchase_order.line_remove', (int) $po['purchase_order_id'], ['ingredient_id' => (int) $l['ingredient_id'], 'before' => ['line_id' => (int) $l['purchase_order_line_id'], 'ingredient_id' => (int) $l['ingredient_id'],
        'qty' => $l['qty_ordered'], 'unit' => $l['purchase_unit_code']]]);
    $pdo->commit();
});
ri_done('Removed line ' . (int) $l['line_no'] . ' of ' . $po['number'], (int) $l['purchase_order_line_id'], ri_land('/purchasing/' . $po['purchase_order_id'] . '/edit', 'line_removed'), 'purchaseOrderChanged', ['purchase_order_id' => $po['purchase_order_id']]);
