<?php
declare(strict_types=1);
/** /recipes/new (screen recipe-add; params kind, output_ingredient) and /recipes/{recipe}/edit (recipe-edit): the recipe form. recipes.write. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('recipes.write');
$pdo = db();
$id = request_integer('id');
$cur = $id === null ? null : recipe_or_404($pdo, $id);
$kind = $cur['kind'] ?? (in_array(request_string('kind'), array_keys(RECIPE_KINDS), true) ? request_string('kind') : 'menu_item');
$outId = $cur['output_ingredient_id'] ?? request_integer('output_ingredient');
log_screen_view($pdo, $cur === null ? 'recipe-add' : 'recipe-edit', $cur === null ? [] : ['recipe_id' => $id]);
if (wants_json()) { respond_screen(['recipe' => $cur === null ? null : recipe_loggable($cur), 'kinds' => RECIPE_KINDS, 'categories' => menu_categories($pdo), 'locked' => $cur !== null && recipe_locked($pdo, $id)]); }
$units = $outId !== null ? ri_unit_options($pdo, (int) $outId) : [];
render_screen($cur === null ? 'New recipe' : 'Edit ' . $cur['name'], view('recipes/recipe-form.php', ['cur' => $cur, 'kind' => $kind, 'outputIngredientId' => $outId, 'categories' => menu_categories($pdo), 'units' => $units,
    'locked' => $cur !== null && recipe_locked($pdo, $id), 'globalUnits' => $pdo->query('SELECT unit_code, name FROM mcp_units WHERE active ORDER BY display_order')->fetchAll(), 'back' => back_link(), 'errors' => []]),
    ['activeNav' => 'recipe-list', 'screen' => $cur === null ? 'recipe-add' : 'recipe-edit', 'entity' => 'recipe', 'recordId' => (string) ($id ?? '')]);
