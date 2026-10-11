<?php
declare(strict_types=1);
/** Action menu_item_price_set: recipes.write; site blank = every site (sale_price), else this site's row from a date. Log menu_item.price_set. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$m = menu_item_or_404($pdo, request_integer('menu_item'));
$errors = [];
$price = ri_decimal('price', null, 'The price', $errors, 0, false, null, true);
$siteRaw = req_has('site') ? (string) req_val('site') : (string) current_site_id();
$site = $siteRaw === '' ? null : (ctype_digit($siteRaw) ? (int) $siteRaw : null);
if ($siteRaw !== '' && $site === null) { $errors['site'] = 'Choose a site.'; }
if ($site !== null) { require_site($site); }
$from = req_has('effective_from') && (string) req_val('effective_from') !== '' ? (string) req_val('effective_from') : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $errors['effective_from'] = 'The date is YYYY-MM-DD.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$id = (int) $m['menu_item_id'];
ri_guard($pdo, static function () use ($pdo, $id, $site, $price, $from, $m): void {
    set_menu_price($pdo, $id, $site, $price, $from);
    recipes_log($pdo, 'menu_item.price_set', 'menu_item', $id, ['after' => ['price' => $price, 'scope_id' => $site, 'effective_from' => $from]], $m['recipe_id'] === null ? null : (int) $m['recipe_id']);
});
ri_done('Set ' . $m['name'] . ' at ' . ri_money($price) . ($site === null ? ' everywhere' : ''), $id, ri_land('/menu/' . $id, 'priced'), 'menuItemChanged');
