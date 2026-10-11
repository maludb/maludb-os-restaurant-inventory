<?php
declare(strict_types=1);
/** /ingredients/ — the ingredients as cards by category at this site (screen `ingredient-list`; params q, category, kind, status, is_prep, page). JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['q' => request_string('q'), 'category' => request_string('category'), 'kind' => request_string('kind'), 'status' => request_string('status') ?: 'active', 'is_prep' => request_string('is_prep')];
$page = max(1, request_integer('page') ?? 1);
$r = find_ingredients($pdo, $site, $filters, INGREDIENT_PAGE, ($page - 1) * INGREDIENT_PAGE);
log_screen_view($pdo, 'ingredient-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'ingredients' => array_map('present_ingredient', $r['rows']), 'sees_cost' => sees_cost($site)]);
}
$notices = ['deleted' => ['success', 'The ingredient was deleted.']];
render_screen('Ingredients', view('catalog/ingredients.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'categories' => categories($pdo), 'mayWrite' => has_right('ingredients.write'),
    'seesCost' => sees_cost($site), 'units' => my_prefs($pdo, (int) current_member_id())['display_units'] ?? 'metric', 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'ingredient-list', 'screen' => 'ingredient-list', 'entity' => 'ingredient']);
