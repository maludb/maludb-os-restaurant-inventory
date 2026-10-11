<?php
declare(strict_types=1);
/** /sales/mappings/ — screen mapping-list: key, name, built-in or ours, the columns it expects; New mapping copies generic (the admin's). */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('settings.manage');                 // the menu's gate: the file mappings are the admin's; a mapping's own page stays readable (read-only)
$pdo = db();
$rows = mapping_list($pdo);
log_screen_view($pdo, 'mapping-list');
if (wants_json()) { respond_screen(['mappings' => array_map(static fn ($m) => ['key' => $m['key'], 'name' => $m['name'], 'builtin' => $m['is_builtin'], 'columns' => $m['mapping']['columns'] ?? []], $rows)]); }
render_screen('File mappings', view('pos/mappings/list.php', ['rows' => $rows, 'mayEdit' => has_right('settings.manage')]), ['activeNav' => 'mapping-list', 'screen' => 'mapping-list', 'entity' => 'sales_file_mapping']);
