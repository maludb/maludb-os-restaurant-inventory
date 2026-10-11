<?php
declare(strict_types=1);
/** /areas/new and /areas/{id}/edit (screens `area-add`, `area-edit`): name, kind, walk order, allows negative, active. ingredients.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('area');
$cur = $id === null ? null : catalog_area_or_404($pdo, $id);
$screen = $cur === null ? 'area-add' : 'area-edit';
log_screen_view($pdo, $screen);
if (wants_json()) { respond_screen(['area' => $cur === null ? null : present_area($cur + ['items_on_hand' => 0]), 'kinds' => array_keys(AREA_KINDS)]); }
$next = ((int) one_value($pdo, 'SELECT COALESCE(max(walk_order), 0) FROM mcp_storage_areas WHERE scope_id = :s', ['s' => (int) current_site_id()])) + 10;
render_screen($cur === null ? 'New storage area' : 'Change ' . $cur['name'], view('catalog/area-form.php', ['cur' => $cur, 'nextOrder' => $next, 'site' => current_site()]), ['activeNav' => 'area-list', 'screen' => $screen, 'entity' => 'storage_area', 'recordId' => $id === null ? '' : (string) $id]);
