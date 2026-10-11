<?php
declare(strict_types=1);
/** /menu/ — the menu items with the unmapped queue first (screen menu-item-list; params q, source, category, unmapped). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['q' => request_string('q'), 'source' => request_integer('source'), 'category' => request_integer('category'), 'unmapped' => request_bool('unmapped'), 'archived' => request_bool('archived')];
$rows = find_menu_items($pdo, $site, $filters);
$queue = unmapped_plus($pdo, $site);
log_screen_view($pdo, 'menu-item-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'unmapped_sold' => $queue, 'menu_items' => array_map(static fn (array $m): array => present_menu_item($m), $rows)]); }
$sources = $pdo->prepare('SELECT source_id, name FROM mcp_sources WHERE scope_id = :s AND active ORDER BY name'); $sources->execute(['s' => $site]);
$notices = ['created' => ['success', 'The menu item was added.'], 'mapped' => ['success', 'Mapped.'], 'priced' => ['success', 'The price was set.'], 'archived' => ['warning', 'Archived.']];
render_screen('Menu items', view('menu/menu-items.php', ['rows' => $rows, 'queue' => $queue, 'filters' => $filters, 'sources' => $sources->fetchAll(), 'categories' => menu_categories($pdo), 'mayWrite' => has_right('recipes.write'), 'tz' => member_timezone(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'menu-item-list', 'screen' => 'menu-item-list', 'entity' => 'menu_item']);
