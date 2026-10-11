<?php
declare(strict_types=1);
/** /counts/{count}?area= — the count sheet (screen `count-view`): one storage area at a time in walk order, designed at 375 px. stock.count (a Viewer reads the review and the history, not the sheet to fill). */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
require_login();
require_right('stock.count');
$pdo = db();
$site = (int) current_site_id();
$count = count_or_404($pdo, request_integer('id') ?? request_integer('count'));
$id = (int) $count['count_id'];
$areas = count_areas($pdo, $id);
$areaId = request_integer('area');
if ($areaId === null || !in_array($areaId, array_column($areas, 'storage_area_id'), true)) {
    $prefs = my_prefs($pdo, (int) current_member_id());
    $byId = array_column($areas, null, 'storage_area_id');
    $areaId = null;
    if ($prefs['default_area_id'] !== null && isset($byId[$prefs['default_area_id']]) && $byId[$prefs['default_area_id']]['pending'] > 0) { $areaId = $prefs['default_area_id']; }
    if ($areaId === null) { foreach ($areas as $a) { if ($a['pending'] > 0) { $areaId = $a['storage_area_id']; break; } } }
    $areaId ??= $areas[0]['storage_area_id'] ?? null;
}
$lines = $areaId === null ? [] : count_lines($pdo, $id, $areaId);
$progress = count_progress($pdo, $id);
log_screen_view($pdo, 'count-view', ['count_id' => $id]);
if (wants_json()) {
    respond_screen(['count' => present_count($count, sees_cost($site)), 'area_id' => $areaId, 'areas' => $areas, 'progress' => $progress, 'lines' => array_map('present_count_line', $lines)]);
}
$prefs ??= my_prefs($pdo, (int) current_member_id());
$changeable = in_array($count['status'], ['open', 'review', 'recount'], true);
$units = [];
foreach ($lines as $l) { $units[$l['count_line_id']] = count_line_units($pdo, $l, $prefs['display_units']); }
$containerOptions = [];
foreach ($lines as $l) { if ($l['container_count'] > 1) { $containerOptions[$l['count_line_id']] = containers_of($pdo, $l['ingredient_id']); } }
$notices = ['started' => ['success', 'The count is started. Walk the shelves one area at a time — an entry is saved as you type.'], 'line_added' => ['success', 'Line added to the count.'], 'reviewed' => ['success', 'Reviewed.']];
render_screen($count['number'], view('counts/view.php', ['count' => $count, 'areas' => $areas, 'areaId' => $areaId, 'lines' => $lines, 'progress' => $progress, 'units' => $units, 'containerOptions' => $containerOptions,
    'mayEdit' => $changeable, 'mayApprove' => has_right('counts.approve'), 'seesCost' => sees_cost($site), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'count-list', 'screen' => 'count-view', 'entity' => 'inventory_count', 'recordId' => (string) $id]);
