<?php
declare(strict_types=1);
/** Action `count_sheet_line_set` (log count_sheet.line_set, ingredient_id): ingredients.write; sheet, area, ingredient, shelf_order, count_unit (a unit the ingredient converts); `sheet_line` changes one. A change answers the line's row (Pattern C); an addition lands on the area. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
counts_begin('ingredients.write');
$pdo = db();
$site = (int) current_site_id();
$lineId = request_integer('sheet_line');
$cur = null;
if ($lineId !== null) {
    $st = $pdo->prepare('SELECT l.id AS sheet_line_id, l.sheet_id, l.storage_area_id, l.ingredient_id, l.shelf_order, l.count_unit_code FROM count_sheet_lines l JOIN mcp_count_sheets s ON s.count_sheet_id = l.sheet_id WHERE l.id = :id');
    $st->execute(['id' => $lineId]);
    $cur = $st->fetch() ?: null;
    if ($cur === null) { respond_not_found('That sheet line is not here.'); }
}
$sheet = sheet_or_404($pdo, $cur !== null ? (int) $cur['sheet_id'] : request_integer('sheet'));
$errors = [];
$areaId = $cur !== null && !req_has('area') ? (int) $cur['storage_area_id'] : stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area', $errors);
$ing = $cur !== null && !req_has('ingredient') ? find_ingredient($pdo, (int) $cur['ingredient_id']) : ((string) (req_val('ingredient') ?? '') === '' ? null : ingredient_by_ref($pdo, (string) req_val('ingredient')));
if ($ing === null) { $errors['ingredient'] = (string) (req_val('ingredient') ?? '') === '' ? 'Choose the ingredient.' : 'That ingredient is not here.'; }
$order = ri_int('shelf_order', $cur !== null ? (int) $cur['shelf_order'] : null, 0, 100000, 'The shelf order', $errors, true);
$unit = req_has('count_unit') ? (string) req_val('count_unit') : ($cur['count_unit_code'] ?? '');
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $sheet, $lineId, $areaId, $ing, $order, $unit): int {
    $pdo->beginTransaction();
    $id = set_sheet_line($pdo, (int) $sheet['count_sheet_id'], $lineId, $areaId, (int) $ing['ingredient_id'], $order, $unit);
    count_log($pdo, 'count_sheet.line_set', 'count_sheet', (int) $sheet['count_sheet_id'], null, ['ingredient_id' => (int) $ing['ingredient_id'], 'after' => ['sheet_line_id' => $id, 'area_id' => $areaId, 'ingredient_id' => (int) $ing['ingredient_id'], 'count_unit' => $unit === '' ? null : $unit]]);
    $pdo->commit();
    return $id;
});
if (is_htmx_request() && !wants_json() && $lineId !== null) {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $id]);
    foreach (count_sheet_areas($pdo, (int) $sheet['count_sheet_id']) as $a) {
        foreach ($a['lines'] as $i => $l) { if ($l['sheet_line_id'] === $id) { echo view('counts/sheets/partials/sheet-line.php', ['l' => $l, 'sheetId' => (int) $sheet['count_sheet_id'], 'areaLines' => $a['lines'], 'index' => $i, 'units' => sheet_unit_options($pdo, $l['ingredient_id']), 'mayWrite' => true, 'saved' => true]); exit; } }
    }
}
ri_done(($lineId === null ? 'Added ' : 'Saved ') . $ing['name'] . ' on the sheet', $id, ri_land('/counts/sheets/' . $sheet['count_sheet_id'], 'line_saved', 'sheet-area-' . $areaId), 'countSheetChanged', ['sheet_id' => (int) $sheet['count_sheet_id'], 'sheet_line_id' => $id]);
