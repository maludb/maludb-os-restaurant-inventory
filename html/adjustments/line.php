<?php
declare(strict_types=1);
/** Action `adjustment_line_set`: counts.approve; a draft's line — ingredient, qty_delta (signed, never zero) + unit, unit_cost per that unit (a cost-seer's), note; `line` names one to change. Log adjustment.line_set (ingredient_id). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$site = (int) current_site_id();
$lineId = req_has('line') && (string) req_val('line') !== '' ? (int) req_val('line') : null;
$adj = $lineId !== null ? one_value($pdo, 'SELECT adjustment_id FROM inventory_adjustment_lines WHERE id = :id', ['id' => $lineId]) : req_val('adjustment');
$j = adjustment_or_404($pdo, $adj === null || $adj === false || $adj === '' ? null : (int) $adj);
$errors = [];
$l = adjustment_line_fields($pdo, ['ingredient' => req_val('ingredient'), 'qty_delta' => req_val('qty_delta'), 'unit' => req_val('unit'), 'unit_cost' => req_val('unit_cost'), 'note' => req_val('note')], $site, $errors);
if ($errors !== [] || $l === null) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $j, $lineId, $l): int {
    $pdo->beginTransaction();
    $id = save_adjustment_line($pdo, (int) $j['adjustment_id'], $lineId, $l);
    stock_log($pdo, 'adjustment.line_set', 'inventory_adjustment', (int) $j['adjustment_id'], ['ingredient_id' => $l['ingredient_id'], 'after' => ['line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty_delta' => $l['qty_delta'], 'unit' => $l['unit'], 'qty_delta_base' => $l['qty_delta_base']]]);
    $pdo->commit();
    return $id;
});
ri_done('Added ' . fmt_num($l['qty_delta'], 4) . ' ' . $l['unit'] . ' of ' . $l['ingredient'] . ' to ' . $j['number'], $id, ri_land('/adjustments/' . $j['adjustment_id'], 'line_saved', 'adjustment-line-row-' . $id), 'adjustmentChanged', ['adjustment_id' => $j['adjustment_id'], 'line_id' => $id, 'qty_delta_base' => $l['qty_delta_base']]);
