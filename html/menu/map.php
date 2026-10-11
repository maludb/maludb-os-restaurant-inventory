<?php
declare(strict_types=1);
/** /menu/{menu_item}/map — GET: the map form (screen menu-item-map); POST: action menu_item_map (recipes.write; recipe blank unmaps; no_depletion clears the recipe; a price given posts menu_item_price_set too). Log menu_item.map (+ menu_item.price_set). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
$pdo = db();
$site = (int) current_site_id();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_right('recipes.write');
    $m = menu_item_or_404($pdo, request_integer('id'));
    log_screen_view($pdo, 'menu-item-map');
    if (wants_json()) { respond_screen(['menu_item' => present_menu_item($m), 'categories' => menu_categories($pdo)]); }
    render_screen('Map ' . $m['name'], view('menu/map.php', ['m' => $m, 'categories' => menu_categories($pdo), 'price' => menu_price($pdo, (int) $m['menu_item_id'], $site), 'back' => back_link(), 'errors' => []]), ['activeNav' => 'menu-item-list', 'screen' => 'menu-item-map', 'entity' => 'menu_item', 'recordId' => (string) $m['menu_item_id']]);
}
recipes_write_begin();
$idRaw = (string) req_val('menu_item');
$id = ri_resolve_menu_item($pdo, $idRaw);
$m = menu_item_or_404($pdo, $id);
$errors = [];
$recipeId = $m['recipe_id'] === null ? null : (int) $m['recipe_id'];
if (req_has('recipe')) { $v = (string) req_val('recipe'); $recipeId = $v === '' ? null : ri_resolve_recipe($pdo, $v); if ($v !== '' && $recipeId === null) { $errors['recipe'] = 'Choose a recipe from the list.'; } }
$portions = ri_decimal('portions', (string) $m['portions'], 'The portions', $errors) ?? '1';
$noDep = ri_yes('no_depletion', (bool) $m['no_depletion']);
$cat = $m['category_id'] === null ? null : (int) $m['category_id'];
if (req_has('menu_category')) { $v = (string) req_val('menu_category'); $cat = $v === '' ? null : ri_resolve_category($pdo, $v); if ($v !== '' && $cat === null) { $errors['menu_category'] = 'Choose a menu category from the list.'; } }
$price = req_has('price') && (string) req_val('price') !== '' ? ri_decimal('price', null, 'The price', $errors, 0, false) : null;
if ($errors !== []) { ri_refuse_fields($errors); }
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $id, $recipeId, $portions, $noDep, $cat, $me, $price, $site): void {
    $pdo->beginTransaction();
    $d = map_menu_item($pdo, $id, $recipeId, (float) $portions, $noDep, $cat, $me);
    recipes_log($pdo, 'menu_item.map', 'menu_item', $id, $d, $recipeId);
    if ($price !== null) { set_menu_price($pdo, $id, $site, $price, date('Y-m-d')); recipes_log($pdo, 'menu_item.price_set', 'menu_item', $id, ['after' => ['price' => $price, 'scope_id' => $site, 'effective_from' => date('Y-m-d')]], $recipeId); }
    $pdo->commit();
});
$r = $recipeId === null ? null : find_recipe($pdo, $recipeId);
ri_done($noDep ? $m['name'] . ' sells nothing from stock' : ($r === null ? 'Unmapped ' . $m['name'] : 'Mapped ' . $m['name'] . ' to ' . $r['name']), $id, ri_land(return_path('/menu/' . $id), 'mapped'), 'menuItemChanged', ['recipe_id' => $recipeId]);
