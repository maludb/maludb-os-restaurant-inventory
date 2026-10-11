<?php
declare(strict_types=1);
/** /units/ — the global units by measure class (screen `unit-list`): the add form and inline row forms. settings.manage. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
$rows = global_units($pdo, false);
log_screen_view($pdo, 'unit-list');
if (wants_json()) { respond_screen(['units' => $rows]); }
$notices = ['unit_saved' => ['success', 'The unit is saved.']];
render_screen('Units', view('catalog/units.php', ['rows' => $rows, 'edit' => request_string('unit'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'unit-list', 'screen' => 'unit-list', 'entity' => 'unit']);
