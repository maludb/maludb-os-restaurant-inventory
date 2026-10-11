<?php
declare(strict_types=1);
/** Action `receipt_line_remove`: stock.receive; a draft's line (the trigger refuses one of a posted receipt). Log receipt.line_remove (ingredient_id). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.receive');
$pdo = db();
$lineId = (int) (req_val('receipt_line') ?? req_val('line') ?? 0);
$cur = $lineId > 0 ? q_row($pdo, 'SELECT * FROM mcp_goods_receipt_lines WHERE goods_receipt_line_id = :id', ['id' => $lineId]) : null;
if ($cur === null) { respond_not_found('That line is not here.'); }
$g = receipt_or_404($pdo, (int) $cur['goods_receipt_id']);
ri_guard($pdo, static function () use ($pdo, $g, $cur, $lineId): void {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM goods_receipt_lines WHERE id = :id')->execute(['id' => $lineId]);
    stock_log($pdo, 'receipt.line_remove', 'goods_receipt', (int) $g['goods_receipt_id'], ['ingredient_id' => (int) $cur['ingredient_id'], 'before' => ['line_id' => $lineId, 'ingredient_id' => (int) $cur['ingredient_id'], 'qty' => $cur['qty_received'], 'unit' => $cur['purchase_unit_code']]]);
    $pdo->commit();
});
ri_done('Removed line ' . (int) $cur['line_no'] . ' of ' . $g['number'], $lineId, ri_land('/receipts/' . $g['goods_receipt_id'], 'line_removed'), 'receiptChanged', ['receipt_id' => $g['goods_receipt_id']]);
