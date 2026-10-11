<?php
declare(strict_types=1);
/** Action `count_sheet_reorder` (log count_sheet.reorder): ingredients.write; sheet, area, lines[] (sheet line ids in shelf order) → shelf_order 1..n in one statement. HTMX (the drag) answers 204; a browser (▲/▼) lands back on the area; JSON says ok. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
counts_begin('ingredients.write');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$sheet = sheet_or_404($pdo, request_integer('sheet'));
$errors = [];
$areaId = (int) stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area', $errors);
$ids = array_map('intval', request_list('lines') ?? []);
if ($ids === []) { $errors['lines'] = 'Say the sheet lines in shelf order.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$own = array_map('intval', $pdo->query('SELECT id FROM count_sheet_lines WHERE sheet_id = ' . (int) $sheet['count_sheet_id'] . ' AND storage_area_id = ' . $areaId)->fetchAll(PDO::FETCH_COLUMN));
foreach ($ids as $i) { if (!in_array($i, $own, true)) { ri_refuse_fields(['lines' => 'Line #' . $i . ' is not on this sheet in that area.']); } }
ri_guard($pdo, static function () use ($pdo, $sheet, $areaId, $ids): void {
    $pdo->beginTransaction();
    reorder_sheet_lines($pdo, (int) $sheet['count_sheet_id'], $areaId, $ids);
    count_log($pdo, 'count_sheet.reorder', 'count_sheet', (int) $sheet['count_sheet_id'], null, ['after' => ['area_id' => $areaId, 'lines' => $ids]]);
    $pdo->commit();
});
emit_action_status(true, ['did' => 'Saved the shelf order', 'record_id' => (int) $sheet['count_sheet_id'], 'refresh' => 'countSheetChanged']);
if (wants_json()) { respond_saved(['did' => 'Saved the shelf order', 'record_id' => (int) $sheet['count_sheet_id'], 'location' => '/counts/sheets/' . $sheet['count_sheet_id'], 'refresh' => 'countSheetChanged']); }
if (is_htmx_request()) { http_response_code(204); exit; }
saved_go(ri_land('/counts/sheets/' . $sheet['count_sheet_id'], 'reordered', 'sheet-area-' . $areaId), 'countSheetChanged');
