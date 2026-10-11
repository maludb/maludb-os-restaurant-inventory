<?php
declare(strict_types=1);
/** /sales/sources/?status= — screen source-list: the POS sources of this site as cards (connector, intake, schedule, last ok, failures, paused, the days missing this week, the unmapped PLUs). JSON: the cards. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('settings.manage');                 // the menu's gate (app/features/shell/nav.php): the POS sources are the admin's to manage
$pdo = db();
$site = (int) current_site_id();
$status = request_string('status', 'all');
$rows = source_list($pdo, $site, ['status' => $status]);
log_screen_view($pdo, 'source-list');
if (wants_json()) { respond_screen(['status' => $status, 'sources' => array_map(static fn ($s) => present_source($s, false), $rows)]); }
$notices = ['deleted' => ['warning', 'The source was deleted.']];
render_screen('POS sources', view('pos/sources/list.php', ['rows' => $rows, 'status' => $status, 'mayCreate' => has_right('settings.manage'), 'mayUpload' => has_right('stock.receive'), 'fileSources' => file_sources_for_site($pdo, $site),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'source-list', 'screen' => 'source-list', 'entity' => 'source']);
