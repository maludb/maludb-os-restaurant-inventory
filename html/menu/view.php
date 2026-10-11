<?php
declare(strict_types=1);
/** /menu/{menu_item} — one menu item (screen menu-item-view): prices per site, modifiers, sales by week, the cost (walled). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$id = request_integer('id');
$full = menu_item_full($pdo, $id, $site);
if ($full === null) { refuse(404, 'That menu item is not here.'); }
log_screen_view($pdo, 'menu-item-view');
if (wants_json()) { respond_screen(['menu_item' => present_menu_item($full['menu_item'], sees_cost($site), $full['cost']), 'prices' => $full['prices'], 'modifiers' => $full['modifiers'], 'sales_by_week' => $full['sales_by_week']]); }
$notices = ['mapped' => ['success', 'Mapped.'], 'priced' => ['success', 'The price was set.'], 'archived' => ['warning', 'Archived.'], 'restored' => ['success', 'Restored.'], 'created' => ['success', 'The menu item was added.']];
render_screen($full['menu_item']['name'], view('menu/menu-item.php', ['full' => $full, 'siteId' => $site, 'sites' => held_sites(), 'seesCost' => sees_cost($site), 'mayWrite' => has_right('recipes.write'), 'back' => back_link(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'menu-item-list', 'screen' => 'menu-item-view', 'entity' => 'menu_item', 'recordId' => (string) $id]);
