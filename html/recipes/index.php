<?php
declare(strict_types=1);
/** /recipes/ — the recipes as cards by kind and category (screen recipe-list; params q, kind, category, status, page). JSON: the cards with cost when seen. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['q' => request_string('q'), 'kind' => request_string('kind'), 'category' => request_string('category'), 'status' => request_string('status', 'active')];
$page = max(1, request_integer('page') ?? 1);
$r = find_recipes($pdo, $site, $filters, RECIPE_PAGE, ($page - 1) * RECIPE_PAGE);
log_screen_view($pdo, 'recipe-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'sees_cost' => sees_cost($site), 'recipes' => array_map('present_recipe_card', $r['rows'])]); }
$notices = ['deleted' => ['success', 'The recipe was deleted.']];
render_screen('Recipes', view('recipes/recipes.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'categories' => menu_categories($pdo), 'mayWrite' => has_right('recipes.write'), 'seesCost' => sees_cost($site),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'recipe-list', 'screen' => 'recipe-list', 'entity' => 'recipe']);
