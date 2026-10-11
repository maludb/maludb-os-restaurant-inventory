<?php
declare(strict_types=1);
/** /counts/sheets/{sheet} — the sheet by storage area in walk order (screen `count-sheet-edit`): drag lines to shelf order (▲/▼ without JavaScript), add an ingredient to an area through the picker, set its count unit, remove. ri.read to see; ingredients.write to change. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$sheet = sheet_or_404($pdo, request_integer('id') ?? request_integer('sheet'));
$sid = (int) $sheet['count_sheet_id'];
$areas = count_sheet_areas($pdo, $sid);
log_screen_view($pdo, 'count-sheet-edit', ['after' => ['sheet_id' => $sid]]);
if (wants_json()) { respond_screen(['sheet' => present_sheet($sheet, $areas)]); }
$units = [];
foreach ($areas as $a) { foreach ($a['lines'] as $l) { $units[$l['ingredient_id']] ??= sheet_unit_options($pdo, $l['ingredient_id']); } }
$notices = ['created' => ['success', 'The sheet is made. Add the ingredients area by area, or fill it from the shelves.'], 'saved' => ['success', 'Saved.'], 'filled' => ['success', 'Filled from the shelves.'], 'line_saved' => ['success', 'Line added.'], 'line_removed' => ['success', 'Line removed.'], 'reordered' => ['success', 'Shelf order saved.']];
render_screen($sheet['name'], view('counts/sheets/view.php', ['sheet' => $sheet, 'areas' => $areas, 'units' => $units, 'mayWrite' => has_right('ingredients.write'), 'mayCount' => has_right('stock.count'),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'count-sheet-list', 'screen' => 'count-sheet-edit', 'entity' => 'count_sheet', 'recordId' => (string) $sid]);
