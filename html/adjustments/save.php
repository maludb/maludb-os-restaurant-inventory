<?php
declare(strict_types=1);
/** Action `adjustment_draft`: counts.approve; area (this site), reason (an adjustment reason — a waste-only one refused in the schema's words), notes; first lines from `lines` JSON or one ingredient + qty_delta + unit (+ unit_cost). Log adjustment.draft. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$area = stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area', $errors);
$reason = adjustment_reason($pdo, (string) (req_val('reason') ?? ''), $errors);
$notes = ri_text('notes', null, 2000, 'Notes', $errors);
$lines = [];
foreach (stock_json_lines($errors) as $k => $l) { $x = adjustment_line_fields($pdo, $l, $site, $errors, 'lines.' . $k . '.'); if ($x !== null) { $lines[] = $x; } }
if (req_has('ingredient') && (string) req_val('ingredient') !== '' && req_has('qty_delta')) { $x = adjustment_line_fields($pdo, ['ingredient' => req_val('ingredient'), 'qty_delta' => req_val('qty_delta'), 'unit' => req_val('unit'), 'unit_cost' => req_val('unit_cost'), 'note' => req_val('note')], $site, $errors); if ($x !== null) { $lines[] = $x; } }
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $site, $area, $reason, $notes, $lines, $me): int {
    $pdo->beginTransaction();
    $st = $pdo->prepare('INSERT INTO inventory_adjustments (scope_id, storage_area_id, reason_code_id, notes, created_by) VALUES (:s, :a, :r, :n, :by) RETURNING id');
    $st->execute(['s' => $site, 'a' => $area, 'r' => $reason['reason_code_id'], 'n' => $notes, 'by' => $me]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $l) { save_adjustment_line($pdo, $id, null, $l); }
    stock_log($pdo, 'adjustment.draft', 'inventory_adjustment', $id, ['after' => ['storage_area_id' => $area, 'reason' => $reason['code'], 'lines' => array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty_delta' => $l['qty_delta'], 'unit' => $l['unit'], 'qty_delta_base' => $l['qty_delta_base']], $lines)]]);
    $pdo->commit();
    return $id;
});
$j = adjustment_for($pdo, $id);
ri_done('Started ' . $j['number'] . ' (' . $j['reason'] . ')' . ($lines !== [] ? ' with ' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') : ''), $id, ri_land('/adjustments/' . $id, 'created'), 'adjustmentChanged', ['adjustment_id' => $id, 'number' => $j['number']]);
