<?php
declare(strict_types=1);
/** Action `transfer_line_remove`: stock.transfer; a draft's line. Log transfer.line_remove. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.transfer');
$pdo = db();
$lineId = (int) (req_val('line') ?? 0);
$row = $lineId > 0 ? q_row($pdo, 'SELECT transfer_id, ingredient_id, line_no FROM inventory_transfer_lines WHERE id = :id', ['id' => $lineId]) : null;
if ($row === null) { respond_not_found('That line is not here.'); }
$t = transfer_or_404($pdo, (int) $row['transfer_id']);
if ($t['scope_id'] !== (int) current_site_id()) { respond_not_found('That transfer is not here.'); }
ri_guard($pdo, static function () use ($pdo, $t, $row, $lineId): void {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM inventory_transfer_lines WHERE id = :id')->execute(['id' => $lineId]);
    stock_log($pdo, 'transfer.line_remove', 'inventory_transfer', (int) $t['transfer_id'], ['ingredient_id' => (int) $row['ingredient_id'], 'before' => ['line_id' => $lineId]]);
    $pdo->commit();
});
ri_done('Removed line ' . (int) $row['line_no'] . ' of ' . $t['number'], $lineId, ri_land('/transfers/' . $t['transfer_id'], 'line_removed'), 'transferChanged');
