<?php
declare(strict_types=1);
/** Action `transfer_line_set`: stock.transfer at the sending site; a draft's line (the trigger converts; a line of a sent transfer is refused by it). Log transfer.line_set (ingredient_id). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.transfer');
$pdo = db();
$lineId = req_has('line') && (string) req_val('line') !== '' ? (int) req_val('line') : null;
$tidRaw = $lineId !== null ? one_value($pdo, 'SELECT transfer_id FROM inventory_transfer_lines WHERE id = :id', ['id' => $lineId]) : req_val('transfer');
$t = transfer_or_404($pdo, $tidRaw === null || $tidRaw === false || $tidRaw === '' ? null : (int) $tidRaw);
if ($t['scope_id'] !== (int) current_site_id()) { respond_not_found('That transfer is not here.'); }
$errors = [];
$ingRef = req_val('ingredient');
if (($ingRef === null || (string) $ingRef === '') && $lineId !== null) { $ingRef = (string) one_value($pdo, 'SELECT ingredient_id FROM inventory_transfer_lines WHERE id = :id', ['id' => $lineId]); }   // an edit keeps the line's ingredient
$l = transfer_line_fields($pdo, ['ingredient' => $ingRef, 'qty' => req_val('qty'), 'unit' => req_val('unit')], $errors);
if ($errors !== [] || $l === null) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $t, $lineId, $l): int {
    $pdo->beginTransaction();
    $id = save_transfer_line($pdo, (int) $t['transfer_id'], $lineId, $l);
    stock_log($pdo, 'transfer.line_set', 'inventory_transfer', (int) $t['transfer_id'], ['ingredient_id' => $l['ingredient_id'], 'after' => ['line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'qty_base' => $l['qty_base']]]);
    $pdo->commit();
    return $id;
});
ri_done('Added ' . fmt_num($l['qty'], 4) . ' ' . $l['unit'] . ' of ' . $l['ingredient'] . ' to ' . $t['number'], $id, ri_land('/transfers/' . $t['transfer_id'], 'line_saved', 'transfer-line-row-' . $id), 'transferChanged', ['transfer_id' => $t['transfer_id'], 'line_id' => $id, 'qty_base' => $l['qty_base']]);
