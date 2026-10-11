<?php
declare(strict_types=1);
/** /modifiers/{modifier}/map — GET: the form (screen modifier-map); POST: action modifier_map (recipes.write; the modifier's own action/recipe/factor, or a per-item override with menu_item + parent_recipe + parent_qty_factor). Log modifier.map. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_right('recipes.write');
    $m = modifier_or_404($pdo, request_integer('id'));
    log_screen_view($pdo, 'modifier-map');
    $parents = modifier_parents($pdo, (int) $m['modifier_id']);
    if (wants_json()) { respond_screen(['modifier' => present_modifier($m), 'parents' => $parents]); }
    render_screen('Map ' . $m['name'], view('modifiers/map.php', ['m' => $m, 'parents' => $parents, 'back' => back_link(), 'notice' => ri_notice($_GET['notice'] ?? null, ['mapped' => ['success', 'Mapped.']])]), ['activeNav' => 'modifier-list', 'screen' => 'modifier-map', 'entity' => 'modifier', 'recordId' => (string) $m['modifier_id']]);
}
recipes_write_begin();
$raw = (string) req_val('modifier');
$mid = ctype_digit($raw) ? (int) $raw : (int) (one_value($pdo, 'SELECT modifier_id FROM mcp_modifiers WHERE lower(code) = lower(:c) ORDER BY active DESC LIMIT 1', ['c' => $raw]) ?: 0);
$m = modifier_or_404($pdo, $mid ?: null);
$errors = [];
$action = ri_choice('action', $m['action'], array_keys(MODIFIER_ACTIONS), 'The action', $errors, false) ?? $m['action'];
$recipeId = $m['recipe_id'] === null ? null : (int) $m['recipe_id'];
if (req_has('recipe')) { $v = (string) req_val('recipe'); $recipeId = $v === '' ? null : ri_resolve_recipe($pdo, $v); if ($v !== '' && $recipeId === null) { $errors['recipe'] = 'Choose a recipe from the list.'; } }
$factor = ri_decimal('qty_factor', (string) $m['qty_factor'], 'The factor', $errors) ?? '1';
$menuItemId = null; $parentRecipe = null; $parentFactor = null;
if (req_has('menu_item') && (string) req_val('menu_item') !== '') {
    $v = (string) req_val('menu_item'); $menuItemId = ri_resolve_menu_item($pdo, $v);
    if ($menuItemId === null) { $errors['menu_item'] = 'Choose a menu item from the list.'; }
    if (req_has('parent_recipe') && (string) req_val('parent_recipe') !== '') { $parentRecipe = ri_resolve_recipe($pdo, (string) req_val('parent_recipe')); if ($parentRecipe === null) { $errors['parent_recipe'] = 'Choose a modifier recipe from the list.'; } }
    $pf = ri_decimal('parent_qty_factor', null, 'The factor', $errors); $parentFactor = $pf === null ? null : (float) $pf;
}
if ($errors !== []) { ri_refuse_fields($errors); }
$mid = (int) $m['modifier_id'];
$d = ri_guard($pdo, static function () use ($pdo, $mid, $action, $recipeId, $factor, $menuItemId, $parentRecipe, $parentFactor): array {
    $d = map_modifier($pdo, $mid, $action, $recipeId, (float) $factor, $menuItemId, $parentRecipe, $parentFactor);
    recipes_log($pdo, 'modifier.map', 'modifier', $mid, $d + ($menuItemId !== null ? ['after' => $d['after'] + ['menu_item_id' => $menuItemId]] : []), $menuItemId !== null ? $parentRecipe : ($action === 'none' ? null : $recipeId));
    return $d;
});
ri_done($menuItemId !== null ? 'Set ' . $m['name'] . ' for the menu item' : 'Mapped ' . $m['name'] . ' as ' . $action, $mid, ri_land('/modifiers/' . $mid . '/map', 'mapped'), 'modifierChanged', ['recipe_id' => $recipeId]);
