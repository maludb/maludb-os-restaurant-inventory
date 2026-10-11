<?php
declare(strict_types=1);
/** /menu/categories — the menu categories (screen menu-category-list): the add form, an inline row form per category (Pattern C). recipes.write. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('recipes.write');
$pdo = db();
$rows = menu_categories($pdo, false);
$counts = menu_category_counts($pdo);
log_screen_view($pdo, 'menu-category-list');
if (wants_json()) { respond_screen(['categories' => array_map(static fn (array $c): array => ['category_id' => (int) $c['category_id'], 'key' => $c['key'], 'name' => $c['name'], 'kind' => $c['kind'], 'sort_order' => (int) $c['sort_order'], 'active' => (bool) $c['active']] + ($counts[(int) $c['category_id']] ?? ['recipes' => 0, 'menu_items' => 0]), $rows)]); }
$notices = ['saved' => ['success', 'Saved.']];
render_screen('Menu categories', view('menu/categories.php', ['rows' => $rows, 'counts' => $counts, 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'menu-category-list', 'screen' => 'menu-category-list', 'entity' => 'menu_category']);
