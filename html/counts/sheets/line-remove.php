<?php
declare(strict_types=1);
/** Action `count_sheet_line_remove` (log count_sheet.line_remove, ingredient_id): ingredients.write; sheet_line. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
counts_begin('ingredients.write');
$pdo = db();
$lineId = request_integer('sheet_line');
$st = $pdo->prepare('SELECT l.id, l.sheet_id, l.storage_area_id, l.ingredient_id, i.name FROM count_sheet_lines l JOIN mcp_count_sheets s ON s.count_sheet_id = l.sheet_id JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id WHERE l.id = :id');
$st->execute(['id' => $lineId ?? 0]);
$cur = $st->fetch();
if ($cur === false) { respond_not_found('That sheet line is not here.'); }
$sheet = sheet_or_404($pdo, (int) $cur['sheet_id']);
ri_guard($pdo, static function () use ($pdo, $cur, $sheet): void {
    $pdo->beginTransaction();
    remove_sheet_line($pdo, (int) $cur['id']);
    count_log($pdo, 'count_sheet.line_remove', 'count_sheet', (int) $sheet['count_sheet_id'], null, ['ingredient_id' => (int) $cur['ingredient_id'], 'after' => ['sheet_line_id' => (int) $cur['id'], 'area_id' => (int) $cur['storage_area_id'], 'ingredient_id' => (int) $cur['ingredient_id']]]);
    $pdo->commit();
});
ri_done('Removed ' . $cur['name'] . ' from the sheet', (int) $cur['id'], ri_land('/counts/sheets/' . $sheet['count_sheet_id'], 'line_removed', 'sheet-area-' . $cur['storage_area_id']), 'countSheetChanged', ['sheet_id' => (int) $sheet['count_sheet_id']]);
