<?php
declare(strict_types=1);
/** /areas/ — this site's storage areas in walk order (screen `area-list`): drag to reorder, add, edit, archive. JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$rows = find_storage_areas($pdo, $site, false);
log_screen_view($pdo, 'area-list');
if (wants_json()) { respond_screen(['areas' => array_map('present_area', $rows), 'site' => current_site()]); }
$notices = ['created' => ['success', 'The storage area is made.'], 'saved' => ['success', 'Saved.'], 'archived' => ['success', 'The area is archived.'], 'restored' => ['success', 'The area is active again.'], 'reordered' => ['success', 'The walk order is saved.']];
render_screen('Storage areas', view('catalog/areas.php', ['rows' => $rows, 'site' => current_site(), 'mayWrite' => has_right('ingredients.write'), 'here' => here_url(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'area-list', 'screen' => 'area-list', 'entity' => 'storage_area']);
