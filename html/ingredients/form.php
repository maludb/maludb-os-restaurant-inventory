<?php
declare(strict_types=1);
/** /ingredients/new?category= and /ingredients/{id}/edit (screens `ingredient-add`, `ingredient-edit`). ingredients.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('ingredient');
$cur = $id === null ? null : catalog_ingredient_or_404($pdo, $id);
$screen = $cur === null ? 'ingredient-add' : 'ingredient-edit';
log_screen_view($pdo, $screen, $cur === null ? [] : ['ingredient_id' => $cur['ingredient_id']]);
if (wants_json()) {
    respond_screen(['ingredient' => $cur === null ? null : present_ingredient($cur), 'categories' => categories($pdo), 'classes' => array_keys(MEASURE_CLASSES), 'allergens' => ALLERGENS, 'locked' => $cur === null ? false : ingredient_locked($pdo, $cur['ingredient_id'])]);
}
$prefillCat = request_string('category') !== '' ? (category_by_id_or_key($pdo, request_string('category'))['category_id'] ?? null) : null;
render_screen($cur === null ? 'New ingredient' : 'Change ' . $cur['name'], view('catalog/ingredient-form.php', ['cur' => $cur, 'categories' => categories($pdo), 'globalUnits' => global_units($pdo), 'prefillCategory' => $prefillCat === null ? null : (int) $prefillCat,
    'locked' => $cur === null ? false : ingredient_locked($pdo, $cur['ingredient_id']), 'here' => here_url()]), ['activeNav' => 'ingredient-list', 'screen' => $screen, 'entity' => 'ingredient', 'recordId' => $id === null ? '' : (string) $id]);
