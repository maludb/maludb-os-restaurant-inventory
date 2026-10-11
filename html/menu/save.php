<?php
declare(strict_types=1);
/** Action menu_item_create: recipes.write; a typed menu item (no POS) or one on a source; a PLU unique per source. Log menu_item.create. Location /menu/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$site = (int) current_site_id();
$errors = [];
$f = [];
$f['plu'] = ri_text('plu', null, 40, 'The PLU', $errors, true);
$f['name'] = ri_text('name', null, 120, 'The name', $errors, true);
if (req_has('source') && (string) req_val('source') !== '') { $f['pos_source_id'] = ri_ref($pdo, 'source', null, 'SELECT 1 FROM mcp_sources WHERE source_id = :id AND scope_id = ' . $site, 'POS source', $errors); }
if (req_has('menu_category')) { $v = (string) req_val('menu_category'); $f['category_id'] = $v === '' ? null : ri_resolve_category($pdo, $v); if ($v !== '' && $f['category_id'] === null) { $errors['menu_category'] = 'Choose a menu category from the list.'; } }
if (req_has('sale_price')) { $f['sale_price'] = ri_decimal('sale_price', null, 'The price', $errors, 0, false); }
if (req_has('recipe') && (string) req_val('recipe') !== '') { $f['recipe_id'] = ri_resolve_recipe($pdo, (string) req_val('recipe')); if ($f['recipe_id'] === null) { $errors['recipe'] = 'Choose a recipe from the list.'; } }
$f['portions'] = ri_decimal('portions', '1', 'The portions', $errors) ?? '1';
if ($errors !== []) { ri_refuse_fields($errors); }
$me = (int) current_member_id();
$id = ri_guard($pdo, static function () use ($pdo, $f, $me): int {
    try { $id = save_menu_item($pdo, null, $f, $me); }
    catch (PDOException $e) { if ((string) $e->getCode() === '23505') { throw new DomainException('That PLU is already on the list for this source.'); } throw $e; }
    recipes_log($pdo, 'menu_item.create', 'menu_item', $id, ['after' => ['plu' => $f['plu'], 'name' => $f['name'], 'source_id' => $f['pos_source_id'] ?? null, 'recipe_id' => $f['recipe_id'] ?? null, 'sale_price' => $f['sale_price'] ?? null]], $f['recipe_id'] ?? null);
    return $id;
});
ri_done('Added ' . $f['plu'] . ' ' . $f['name'], $id, ri_land(return_path('/menu/' . $id), 'created'), 'menuItemChanged');
