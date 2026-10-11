<?php
declare(strict_types=1);
/** /counts/sheets/ — the count sheets of this site as cards (screen `count-sheet-list`). ri.read to see; ingredients.write makes and changes them. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$mayWrite = has_right('ingredients.write');
$sheets = count_sheets($pdo, $site, $mayWrite);
log_screen_view($pdo, 'count-sheet-list');
if (wants_json()) { respond_screen(['sheets' => array_map(static fn (array $s): array => present_sheet($s), $sheets)]); }
$notices = ['created' => ['success', 'The sheet is made. Add the ingredients area by area.'], 'saved' => ['success', 'Saved.']];
render_screen('Count sheets', view('counts/sheets/index.php', ['sheets' => $sheets, 'mayWrite' => $mayWrite, 'mayCount' => has_right('stock.count'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'count-sheet-list', 'screen' => 'count-sheet-list', 'entity' => 'count_sheet']);
