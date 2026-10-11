<?php
declare(strict_types=1);
/** Action menu_category_save: recipes.write; INSERT (no menu_category) or UPDATE (with). Pattern C answers the row partial. Log menu_category.save. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$id = request_integer('menu_category');
$cur = null;
if ($id !== null) { $st = $pdo->prepare('SELECT * FROM mcp_menu_categories WHERE category_id = :id'); $st->execute(['id' => $id]); $cur = $st->fetch() ?: null; if ($cur === null) { refuse(404, 'That menu category is not here.'); } }
$errors = [];
$f = [];
$f['name'] = ri_text('name', $cur['name'] ?? null, 60, 'The name', $errors, $cur === null);
if (req_has('key')) { $k = strtolower((string) req_val('key')); if ($k !== '' && !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k)) { $errors['key'] = 'The key is lowercase letters, digits and underscores.'; } elseif ($k !== '') { $f['key'] = $k; } }
if (req_has('kind')) { $f['kind'] = ri_choice('kind', $cur['kind'] ?? 'food', ['food', 'beverage', 'other'], 'The kind', $errors, false); }
if (req_has('sort_order')) { $f['sort_order'] = ri_int('sort_order', (int) ($cur['sort_order'] ?? 100), 0, 10000, 'The order', $errors); }
if (req_has('active')) { $f['active'] = ri_yes('active'); }
if ($errors !== []) { ri_refuse_fields($errors); }
$newId = ri_guard($pdo, static function () use ($pdo, $id, $f, $cur): int {
    try { $newId = save_menu_category($pdo, $id, $f); }
    catch (PDOException $e) { if ((string) $e->getCode() === '23505') { throw new DomainException('That key is already taken.'); } throw $e; }
    $st = $pdo->prepare('SELECT key, name, kind, sort_order, active FROM mcp_menu_categories WHERE category_id = :id'); $st->execute(['id' => $newId]); $after = $st->fetch();
    $after['active'] = (bool) $after['active']; $after['sort_order'] = (int) $after['sort_order'];
    if ($cur === null) { recipes_log($pdo, 'menu_category.save', 'menu_category', $newId, ['after' => $after]); }
    else { $before = ['key' => $cur['key'], 'name' => $cur['name'], 'kind' => $cur['kind'], 'sort_order' => (int) $cur['sort_order'], 'active' => (bool) $cur['active']]; $d = ri_diff($before, $after); if ($d['after'] !== []) { recipes_log($pdo, 'menu_category.save', 'menu_category', $newId, $d); } }
    return $newId;
});
if (is_htmx_request() && !wants_json() && $id !== null) {
    $st = $pdo->prepare('SELECT * FROM mcp_menu_categories WHERE category_id = :id'); $st->execute(['id' => $newId]);
    echo view('menu/partials/menu-category-row.php', ['c' => $st->fetch(), 'counts' => menu_category_counts($pdo)[$newId] ?? ['recipes' => 0, 'menu_items' => 0], 'saved' => true]);
    exit;
}
ri_done(($id === null ? 'Added ' : 'Saved ') . ($f['name'] ?? $cur['name']), $newId, ri_land('/menu/categories', 'saved'), 'menuCategoryChanged');
