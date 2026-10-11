<?php
declare(strict_types=1);

/**
 * Recipes, the menu map and costing — the writes (recipes.md "Writes"). Every rule is the database's: the CHECKs on a prep's output
 * ingredient, the cycle refusal, the draft-only lines, one draft, the activation window, the immutable active version, the menu item's
 * recipe kind — a PDOException with the schema's sentence reaches ri_guard() and becomes a 422. The code of a recipe is made here
 * (R-/M- from the name — the one rule the schema does not keep).
 */
require_once __DIR__ . '/queries.php';

function recipe_code_from_name(PDO $pdo, string $name, string $kind): string
{
    $base = strtoupper(trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name)), '_'));
    $base = ($kind === 'modifier' ? 'M-' : 'R-') . mb_substr($base === '' ? 'RECIPE' : $base, 0, 20);
    $code = $base;
    for ($n = 2; $n < 1000; $n++) {
        $st = $pdo->prepare('SELECT 1 FROM recipes WHERE lower(code) = lower(:c)');
        $st->execute(['c' => $code]);
        if ($st->fetchColumn() === false) { return $code; }
        $code = $base . '_' . $n;
    }
    return $base . '_' . bin2hex(random_bytes(2));
}

/** INSERT (with the first draft version) or UPDATE the recipe's own fields. Answers ['id', 'version_id' (the first draft on create, else null)]. */
function save_recipe(PDO $pdo, ?int $id, array $f, int $by): array
{
    if ($id === null) {
        $code = ($f['code'] ?? null) !== null && $f['code'] !== '' ? $f['code'] : recipe_code_from_name($pdo, (string) $f['name'], (string) $f['kind']);
        $st = $pdo->prepare('INSERT INTO recipes (code, name, kind, category_id, output_ingredient_id, portion_qty, portion_unit_code, method, created_by) VALUES (:code, :name, :kind, :cat, :out, :pq, :pu, :method, :by) RETURNING id');
        $st->execute(['code' => $code, 'name' => $f['name'], 'kind' => $f['kind'], 'cat' => $f['menu_category_id'] ?? null, 'out' => $f['output_ingredient_id'] ?? null, 'pq' => $f['portion_qty'] ?? null, 'pu' => $f['portion_unit_code'] ?? null, 'method' => $f['method'] ?? null, 'by' => $by]);
        $id = (int) $st->fetchColumn();
        $v = $pdo->prepare('INSERT INTO recipe_versions (recipe_id, batch_yield_qty, batch_yield_unit_code, expected_loss_pct, change_note, created_by) VALUES (:r, :y, :u, :l, :n, :by) RETURNING id');
        $v->execute(['r' => $id, 'y' => $f['batch_yield_qty'] ?? 1, 'u' => $f['batch_yield_unit_code'] ?? null, 'l' => $f['expected_loss_pct'] ?? 0, 'n' => 'the first draft', 'by' => $by]);
        $vid = (int) $v->fetchColumn();
        foreach ($f['lines'] ?? [] as $line) { save_recipe_line($pdo, $vid, null, $line); }
        return ['id' => $id, 'version_id' => $vid];
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['code' => 'code', 'name' => 'name', 'kind' => 'kind', 'menu_category_id' => 'category_id', 'output_ingredient_id' => 'output_ingredient_id', 'portion_qty' => 'portion_qty', 'portion_unit_code' => 'portion_unit_code', 'method' => 'method', 'photo_attachment_id' => 'photo_attachment_id', 'active' => 'active'] as $k => $col) {
        if (array_key_exists($k, $f)) { $sets[] = "{$col} = :{$k}"; $args[$k] = is_bool($f[$k]) ? ($f[$k] ? 't' : 'f') : $f[$k]; }
    }
    if ($sets !== []) { $pdo->prepare('UPDATE recipes SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args); }
    return ['id' => $id, 'version_id' => null];
}
function archive_recipe(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE recipes SET active = :a, archived_at = CASE WHEN :a2 THEN NULL ELSE now() END WHERE id = :id')->execute(['a' => $archived ? 'f' : 't', 'a2' => $archived ? 'f' : 't', 'id' => $id]);
}
function delete_recipe(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM recipes WHERE id = :id')->execute(['id' => $id]);
}
function new_draft(PDO $pdo, int $recipeId, ?string $note, int $by): int
{
    $st = $pdo->prepare('SELECT id FROM ri_recipe_version_draft(:r, :by, :n)');
    $st->execute(['r' => $recipeId, 'by' => $by, 'n' => $note]);
    return (int) $st->fetchColumn();
}
/** A draft's batch form: ['before', 'after'] of what changed (the trigger refuses an active version's change in words). */
function save_version(PDO $pdo, int $versionId, array $f): array
{
    $before = recipe_version($pdo, $versionId) ?? [];
    $sets = [];
    $args = ['id' => $versionId];
    foreach (['batch_yield_qty', 'batch_yield_unit_code', 'expected_loss_pct', 'change_note'] as $k) {
        if (array_key_exists($k, $f)) { $sets[] = "{$k} = :{$k}"; $args[$k] = $f[$k]; }
    }
    if ($sets !== []) { $pdo->prepare('UPDATE recipe_versions SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args); }
    $after = recipe_version($pdo, $versionId) ?? [];
    $keys = ['batch_yield_qty', 'batch_yield_unit_code', 'expected_loss_pct', 'change_note'];
    return ri_diff(array_intersect_key($before, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
}
/** A line: INSERT or UPDATE; qty_base is the trigger's (never computed here). Returns the line id. */
function save_recipe_line(PDO $pdo, int $versionId, ?int $lineId, array $f): int
{
    if ($lineId === null) {
        $seq = $f['seq'] ?? null;
        if ($seq === null) { $st = $pdo->prepare('SELECT COALESCE(max(seq), 0) + 1 FROM recipe_lines WHERE version_id = :v'); $st->execute(['v' => $versionId]); $seq = (int) $st->fetchColumn(); }
        $st = $pdo->prepare('INSERT INTO recipe_lines (version_id, seq, ingredient_id, sub_recipe_id, qty, unit_code, qty_base, line_yield_pct, group_name, optional, notes) VALUES (:v, :seq, :i, :s, :q, :u, 0, :y, :g, :o, :n) RETURNING id');
        $st->execute(['v' => $versionId, 'seq' => $seq, 'i' => $f['ingredient_id'] ?? null, 's' => $f['sub_recipe_id'] ?? null, 'q' => $f['qty'], 'u' => $f['unit_code'], 'y' => $f['line_yield_pct'] ?? 100, 'g' => $f['group_name'] ?? null, 'o' => !empty($f['optional']) ? 't' : 'f', 'n' => $f['notes'] ?? null]);
        return (int) $st->fetchColumn();
    }
    $sets = [];
    $args = ['id' => $lineId];
    foreach (['ingredient_id', 'sub_recipe_id', 'qty', 'unit_code', 'line_yield_pct', 'group_name', 'notes', 'seq'] as $k) {
        if (array_key_exists($k, $f)) { $sets[] = "{$k} = :{$k}"; $args[$k] = $f[$k]; }
    }
    if (array_key_exists('optional', $f)) { $sets[] = 'optional = :optional'; $args['optional'] = $f['optional'] ? 't' : 'f'; }
    if ($sets !== []) { $pdo->prepare('UPDATE recipe_lines SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args); }
    return $lineId;
}
function remove_recipe_line(PDO $pdo, int $lineId): array
{
    $st = $pdo->prepare('SELECT version_id, ingredient_id, sub_recipe_id, qty, unit_code, qty_base, seq FROM recipe_lines WHERE id = :id');
    $st->execute(['id' => $lineId]);
    $before = $st->fetch();
    if ($before === false) { throw new DomainException('Not found.'); }
    $pdo->prepare('DELETE FROM recipe_lines WHERE id = :id')->execute(['id' => $lineId]);
    return $before;
}
function reorder_lines(PDO $pdo, int $versionId, array $lineIds): void
{
    $st = $pdo->prepare('UPDATE recipe_lines SET seq = :s WHERE id = :id AND version_id = :v');
    foreach (array_values($lineIds) as $k => $id) { $st->execute(['s' => $k + 1, 'id' => (int) $id, 'v' => $versionId]); }
}
/** ri_recipe_version_activate(): ['version' (the row), 'ended' (the version_no ended, or null)]. */
function activate_version(PDO $pdo, int $versionId, string $from, int $by): array
{
    $v = recipe_version($pdo, $versionId);
    $open = null;
    if ($v !== null) {
        $st = $pdo->prepare('SELECT version_no FROM mcp_recipe_versions WHERE recipe_id = :r AND status = \'active\' AND effective_to IS NULL');
        $st->execute(['r' => $v['recipe_id']]);
        $open = $st->fetchColumn() ?: null;
    }
    $st = $pdo->prepare('SELECT id, version_no, effective_from, recipe_id FROM ri_recipe_version_activate(:v, :by, CAST(:d AS date))');
    $st->execute(['v' => $versionId, 'by' => $by, 'd' => $from]);
    return ['version' => $st->fetch(), 'ended' => $open === null ? null : (int) $open];
}
function discard_version(PDO $pdo, int $versionId): void
{
    $v = recipe_version($pdo, $versionId);
    if ($v === null) { throw new DomainException('Not found.'); }
    if ($v['status'] !== 'draft') { throw new DomainException('Only a draft is discarded; version ' . $v['version_no'] . ' is ' . $v['status'] . '.'); }
    $pdo->prepare('DELETE FROM recipe_versions WHERE id = :id')->execute(['id' => $versionId]);
}
function retire_version(PDO $pdo, int $versionId, string $to, int $by): void
{
    $v = recipe_version($pdo, $versionId);
    if ($v === null) { throw new DomainException('Not found.'); }
    if ($v['status'] !== 'active' || $v['effective_to'] !== null) { throw new DomainException('Only the version in force with no end is retired.'); }
    if ($to < $v['effective_from']) { throw new DomainException('The end is on or after the start, ' . format_date($v['effective_from']) . '.'); }
    $pdo->prepare('UPDATE recipe_versions SET status = \'retired\', effective_to = CAST(:to AS date) + 1 WHERE id = :id')->execute(['to' => $to, 'id' => $versionId]);
}

// ---- menu items and modifiers ----
function save_menu_item(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO menu_items (pos_source_id, plu, name, category_id, sale_price, recipe_id, portions, mapped_by) VALUES (:src, :plu, :name, :cat, :price, :recipe, :portions, :by) RETURNING id');
        $st->execute(['src' => $f['pos_source_id'] ?? null, 'plu' => $f['plu'], 'name' => $f['name'], 'cat' => $f['category_id'] ?? null, 'price' => $f['sale_price'] ?? null, 'recipe' => $f['recipe_id'] ?? null, 'portions' => $f['portions'] ?? 1, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['plu', 'name', 'category_id', 'sale_price', 'portions', 'pos_source_id'] as $k) {
        if (array_key_exists($k, $f)) { $sets[] = "{$k} = :{$k}"; $args[$k] = $f[$k]; }
    }
    if ($sets !== []) { $pdo->prepare('UPDATE menu_items SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args); }
    return $id;
}
/** The map: recipe (null unmaps), portions, no_depletion (clears the recipe), the category. ['before', 'after']. */
function map_menu_item(PDO $pdo, int $id, ?int $recipeId, float $portions, bool $noDepletion, ?int $categoryId, int $by): array
{
    $cur = find_menu_item($pdo, $id);
    if ($cur === null) { throw new DomainException('Not found.'); }
    $before = ['recipe_id' => $cur['recipe_id'] === null ? null : (int) $cur['recipe_id'], 'portions' => (float) $cur['portions'], 'no_depletion' => (bool) $cur['no_depletion'], 'category_id' => $cur['category_id'] === null ? null : (int) $cur['category_id']];
    if ($noDepletion) { $recipeId = null; }
    $pdo->prepare('UPDATE menu_items SET recipe_id = :r, portions = :p, no_depletion = :nd, category_id = :c, mapped_by = CASE WHEN CAST(:r2 AS bigint) IS NULL THEN mapped_by ELSE CAST(:by AS bigint) END WHERE id = :id')
        ->execute(['r' => $recipeId, 'p' => $portions, 'nd' => $noDepletion ? 't' : 'f', 'c' => $categoryId ?? $before['category_id'], 'r2' => $recipeId, 'by' => $by, 'id' => $id]);
    if ($recipeId !== null && $recipeId !== $before['recipe_id']) {
        // a newly mapped PLU: its days become due for a re-run (recipes.md; slice 5's ri_depletion_due() keys on imported_at)
        $pdo->prepare('UPDATE pos_sales s SET imported_at = now() WHERE s.id IN (SELECT l.sale_id FROM pos_sale_lines l WHERE l.menu_item_id = :m)')->execute(['m' => $id]);
    }
    return ['before' => $before, 'after' => ['recipe_id' => $recipeId, 'portions' => $portions, 'no_depletion' => $noDepletion, 'category_id' => $categoryId ?? $before['category_id']]];
}
/** A price: $scopeId null → the item's sale_price (every site); else a menu_item_prices row (the same day's row replaced). */
function set_menu_price(PDO $pdo, int $id, ?int $scopeId, string $price, string $from): void
{
    if ($scopeId === null) { $pdo->prepare('UPDATE menu_items SET sale_price = :p WHERE id = :id')->execute(['p' => $price, 'id' => $id]); return; }
    $pdo->prepare('INSERT INTO menu_item_prices (menu_item_id, scope_id, price, effective_from) VALUES (:m, :s, :p, CAST(:f AS date)) ON CONFLICT (menu_item_id, scope_id, effective_from) DO UPDATE SET price = EXCLUDED.price')
        ->execute(['m' => $id, 's' => $scopeId, 'p' => $price, 'f' => $from]);
}
function archive_menu_item(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE menu_items SET active = :a WHERE id = :id')->execute(['a' => $archived ? 'f' : 't', 'id' => $id]);
}
/** The modifier's own map, or a per-item override. ['before', 'after']. A non-modifier recipe refused in words (the schema has no CHECK). */
function map_modifier(PDO $pdo, int $modifierId, string $action, ?int $recipeId, float $factor, ?int $menuItemId, ?int $parentRecipeId, ?float $parentFactor): array
{
    foreach (array_filter([$recipeId, $parentRecipeId]) as $rid) {
        $r = find_recipe($pdo, (int) $rid);
        if ($r === null) { throw new DomainException('That recipe is not here.'); }
        if ($r['kind'] !== 'modifier') { throw new DomainException('A modifier maps to a modifier recipe; ' . $r['name'] . ' is a ' . str_replace('_', ' ', $r['kind']) . '.'); }
    }
    if ($menuItemId !== null) {
        $st = $pdo->prepare('SELECT qty_factor, recipe_id FROM menu_item_modifiers WHERE menu_item_id = :m AND modifier_id = :md');
        $st->execute(['m' => $menuItemId, 'md' => $modifierId]);
        $before = $st->fetch() ?: ['qty_factor' => null, 'recipe_id' => null];
        $pdo->prepare('INSERT INTO menu_item_modifiers (menu_item_id, modifier_id, qty_factor, recipe_id) VALUES (:m, :md, :f, :r) ON CONFLICT (menu_item_id, modifier_id) DO UPDATE SET qty_factor = EXCLUDED.qty_factor, recipe_id = EXCLUDED.recipe_id')
            ->execute(['m' => $menuItemId, 'md' => $modifierId, 'f' => $parentFactor, 'r' => $parentRecipeId]);
        return ['before' => ['menu_item_id' => $menuItemId, 'qty_factor' => $before['qty_factor'], 'recipe_id' => $before['recipe_id']], 'after' => ['menu_item_id' => $menuItemId, 'qty_factor' => $parentFactor, 'recipe_id' => $parentRecipeId]];
    }
    if ($action !== 'none' && $recipeId === null) { throw new DomainException('A modifier that adds, removes or swaps names its modifier recipe.'); }
    $cur = find_modifier($pdo, $modifierId);
    if ($cur === null) { throw new DomainException('Not found.'); }
    $pdo->prepare('UPDATE modifiers SET action = :a, recipe_id = :r, qty_factor = :f WHERE id = :id')->execute(['a' => $action, 'r' => $action === 'none' ? null : $recipeId, 'f' => $factor, 'id' => $modifierId]);
    return ['before' => ['action' => $cur['action'], 'recipe_id' => $cur['recipe_id'] === null ? null : (int) $cur['recipe_id'], 'qty_factor' => (float) $cur['qty_factor']], 'after' => ['action' => $action, 'recipe_id' => $action === 'none' ? null : $recipeId, 'qty_factor' => $factor]];
}
function save_menu_category(PDO $pdo, ?int $id, array $f): int
{
    if ($id === null) {
        $key = $f['key'] ?? strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $f['name'])), '_'));
        $st = $pdo->prepare('INSERT INTO menu_categories (key, name, kind, sort_order, active) VALUES (:k, :n, :kind, :o, :a) RETURNING id');
        $st->execute(['k' => $key, 'n' => $f['name'], 'kind' => $f['kind'] ?? 'food', 'o' => $f['sort_order'] ?? 100, 'a' => isset($f['active']) && !$f['active'] ? 'f' : 't']);
        return (int) $st->fetchColumn();
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['name', 'key', 'kind', 'sort_order'] as $k) { if (array_key_exists($k, $f)) { $sets[] = "{$k} = :{$k}"; $args[$k] = $f[$k]; } }
    if (array_key_exists('active', $f)) { $sets[] = 'active = :active'; $args['active'] = $f['active'] ? 't' : 'f'; }
    if ($sets !== []) { $pdo->prepare('UPDATE menu_categories SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args); }
    return $id;
}
