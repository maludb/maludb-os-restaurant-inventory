<?php
declare(strict_types=1);
/** Action `adjustment_line_remove`: counts.approve; a draft's line (the trigger refuses one of a posted adjustment). Log adjustment.line_remove. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$lineId = (int) (req_val('line') ?? 0);
$row = $lineId > 0 ? q_row($pdo, 'SELECT adjustment_id, ingredient_id, line_no FROM inventory_adjustment_lines WHERE id = :id', ['id' => $lineId]) : null;
if ($row === null) { respond_not_found('That line is not here.'); }
$j = adjustment_or_404($pdo, (int) $row['adjustment_id']);
ri_guard($pdo, static function () use ($pdo, $j, $row, $lineId): void {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM inventory_adjustment_lines WHERE id = :id')->execute(['id' => $lineId]);
    stock_log($pdo, 'adjustment.line_remove', 'inventory_adjustment', (int) $j['adjustment_id'], ['ingredient_id' => (int) $row['ingredient_id'], 'before' => ['line_id' => $lineId]]);
    $pdo->commit();
});
ri_done('Removed line ' . (int) $row['line_no'] . ' of ' . $j['number'], $lineId, ri_land('/adjustments/' . $j['adjustment_id'], 'line_removed'), 'adjustmentChanged');
