<?php
declare(strict_types=1);
/** Action `category_save` (log category.save): ingredients.write; the key from the name when absent. Location /categories/#category-row-{id}. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$raw = (string) (req_val('category') ?? '');
$cur = $raw === '' ? null : category_by_id_or_key($pdo, $raw);
if ($raw !== '' && $cur === null) { respond_not_found('That category is not here.'); }
$errors = [];
$f = [];
$f['name'] = ri_text('name', $cur['name'] ?? null, 80, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? '');
$key = ri_text('key', $cur['key'] ?? null, 40, 'The key', $errors);
$f['key'] = $key ?? ($cur['key'] ?? key_from_name($f['name']));
if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $f['key'])) { $errors['key'] = 'A key is lowercase letters, digits and _, starting with a letter.'; }
$f['kind'] = ri_choice('kind', $cur['kind'] ?? 'food', array_keys(CATEGORY_KINDS), 'The kind', $errors, false) ?? 'food';
$f['parent_id'] = isset($cur['parent_id']) ? (int) $cur['parent_id'] : null;
if (req_has('parent')) { $v = (string) req_val('parent'); $p = $v === '' ? null : category_by_id_or_key($pdo, $v); if ($v !== '' && $p === null) { $errors['parent'] = 'Choose the parent from the list.'; } $f['parent_id'] = $p === null ? null : (int) $p['category_id']; }
$f['safety_stock_pct'] = ri_num('safety_stock_pct', $cur['safety_stock_pct'] ?? '15.00', 'The safety stock', $errors, 0, 100, false, 2) ?? '15.00';
$f['price_alert_pct'] = ri_num('price_alert_pct', $cur['price_alert_pct'] ?? null, 'The price alert threshold', $errors, 0, 1000, true, 2);
$f['temperature_check'] = ri_yes('temperature_check', (bool) ($cur['temperature_check'] ?? false));
$f['sort_order'] = ri_int('sort_order', isset($cur['sort_order']) ? (int) $cur['sort_order'] : 100, 0, 10000, 'The order', $errors, false) ?? 100;
$f['active'] = ri_yes('active', (bool) ($cur['active'] ?? true));
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f): int {
    $pdo->beginTransaction();
    try { $id = save_category($pdo, isset($cur['category_id']) ? (int) $cur['category_id'] : null, $f); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['key' => 'That key is taken.']); } throw $e; }
    catalog_log($pdo, 'category.save', 'ingredient_category', $id, ['after' => $f]);
    $pdo->commit();
    return $id;
});
ri_done(($cur === null ? 'Added the category ' : 'Saved the category ') . $f['name'], $id, ri_land('/categories/', 'category_saved', 'category-row-' . $id), 'categoryChanged');
