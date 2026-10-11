<?php
declare(strict_types=1);
/** /counts/new — start a count (screen `count-add`; params sheet, kind). stock.count. The as-of defaults to the day's sales cut — "tonight at 04:00". */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
require_login();
require_right('stock.count');
$pdo = db();
$site = (int) current_site_id();
$sheets = count_sheets($pdo, $site);
$sheetId = request_integer('sheet');
$sheet = null;
foreach ($sheets as $s) { if ($s['count_sheet_id'] === $sheetId) { $sheet = $s; } }
$kind = request_string('kind');
if (!isset(COUNT_KINDS[$kind])) { $kind = $sheet['kind'] ?? 'full'; }
$asOf = default_count_as_of($site);
$setting = blind_counts_setting($pdo);
$mayBlind = has_right('counts.approve');
$openFull = (array) (counts($pdo, $site, ['status' => 'open,review,recount', 'kind' => 'full'], 1)['rows'][0] ?? []);
log_screen_view($pdo, 'count-add');
if (wants_json()) { respond_screen(['sheets' => array_map(static fn (array $s): array => present_sheet($s), $sheets), 'kinds' => array_keys(COUNT_KINDS), 'as_of_default' => $asOf, 'blind_default' => $setting, 'may_choose_blind' => $mayBlind,
    'open_full_count' => $openFull === [] ? null : ['count_id' => $openFull['count_id'], 'number' => $openFull['number']]]); }
render_screen('Start a count', view('counts/form.php', ['sheets' => $sheets, 'sheetId' => $sheet['count_sheet_id'] ?? null, 'kind' => $kind, 'asOf' => $asOf, 'blind' => $setting, 'mayBlind' => $mayBlind, 'openFull' => $openFull,
    'cutNote' => 'the sales cut']), ['activeNav' => 'count-list', 'screen' => 'count-add', 'entity' => 'inventory_count']);
