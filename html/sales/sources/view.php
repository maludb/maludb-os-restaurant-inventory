<?php
declare(strict_types=1);
/** /sales/sources/{source}?tab= — screen source-view: the header (connector, intake, schedule, state), the settings (an admin's JSON; others a summary), the credential's label and last four, Pull now / Upload / Pause / Probe; the tabs Pulls (the last 20), Days, Unmapped (Map → menu-item-map). */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$src = source_or_404($pdo, (string) (request_integer('id') ?? ''));
$s = source_for($pdo, (int) $src['id']);
$scope = (int) $src['scope_id'];
$admin = is_ri_admin($scope);
$pulls = source_pulls($pdo, (int) $src['id'], 1);
$days = source_days($pdo, (int) $src['id']);
$unmapped = source_unmapped($pdo, (int) $src['id']);
log_screen_view($pdo, 'source-view', ['scope_id' => $scope]);
if (wants_json()) { respond_screen(['source' => present_source($s, $admin), 'pulls' => array_map('present_pull', $pulls), 'days' => $days, 'unmapped' => $unmapped]); }
$notices = ['created' => ['success', 'The source was added.'], 'saved' => ['success', 'Saved.'], 'credential' => ['success', 'The credential is sealed.'], 'paused' => ['warning', 'Paused.'], 'resumed' => ['success', 'Resumed.'], 'pulled' => ['success', 'Pulled.']];
render_screen($src['name'], view('pos/sources/view.php', ['s' => $s, 'admin' => $admin, 'pulls' => $pulls, 'days' => $days, 'unmapped' => $unmapped, 'tab' => request_string('tab', 'pulls'),
    'mayEdit' => has_right('settings.manage', $scope), 'mayCred' => has_right('sources.credentials', $scope), 'mayPull' => has_right('purchasing.draft', $scope), 'mayUpload' => has_right('stock.receive', $scope),
    'mayDelete' => has_right('records.delete', $scope) && !$s['has_sales'], 'mayMap' => has_right('recipes.write', $scope), 'trail' => pos_activity($pdo, 'source', (int) $src['id']), 'tz' => site_timezone($scope),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]), ['activeNav' => 'source-list', 'screen' => 'source-view', 'entity' => 'source', 'recordId' => (string) $src['id']]);
