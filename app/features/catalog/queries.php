<?php
declare(strict_types=1);

/**
 * The catalog's reads (catalog.md "Query functions"; the tools of Phase 4 call these and the resolvers): ingredients and their units,
 * pars and costs per site, suppliers, schedules, items, order guides, storage areas, categories, notes and attachments. Every row
 * comes from the mcp_* views (db/017) — the views are the wall for cost and for the site; a function never selects a base table.
 */
require_once dirname(__DIR__) . '/units/queries.php';

const INGREDIENT_PAGE = 48;
const SUPPLIER_PAGE = 48;
const SUPPLIER_KINDS = ['distributor' => 'Distributor', 'producer' => 'Producer', 'wholesaler' => 'Wholesaler', 'retailer' => 'Retailer', 'commissary' => 'Commissary', 'other' => 'Other'];
const ORDER_METHODS = ['email' => 'Email', 'portal' => 'Portal', 'phone' => 'Phone', 'api' => 'API', 'edi' => 'EDI'];
const AREA_KINDS = ['walk_in' => 'Walk-in', 'freezer' => 'Freezer', 'dry' => 'Dry store', 'bar' => 'Bar', 'line' => 'Line', 'office' => 'Office', 'in_transit' => 'In transit', 'other' => 'Other'];
const CATEGORY_KINDS = ['food' => 'Food', 'beverage' => 'Beverage', 'supplies' => 'Supplies'];
const ALLERGENS = ['gluten' => 'Cereals containing gluten', 'crustaceans' => 'Crustaceans', 'egg' => 'Eggs', 'fish' => 'Fish', 'peanut' => 'Peanuts', 'soy' => 'Soybeans', 'milk' => 'Milk', 'nuts' => 'Tree nuts',
    'celery' => 'Celery', 'mustard' => 'Mustard', 'sesame' => 'Sesame', 'sulphites' => 'Sulphites', 'lupin' => 'Lupin', 'molluscs' => 'Molluscs'];
const DOW = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
const SERVICE_LEVELS = ['0.800' => '80 %', '0.850' => '85 %', '0.900' => '90 %', '0.950' => '95 %', '0.980' => '98 %', '0.990' => '99 %'];

/** The categories: [{category_id, key, name, kind, parent_id, safety_stock_pct, price_alert_pct, temperature_check, sort_order, active, ingredients}] in order. */
function categories(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT c.*, (SELECT count(*) FROM mcp_ingredients i WHERE i.category_id = c.category_id AND i.active) AS ingredients FROM mcp_ingredient_categories c' . ($activeOnly ? ' WHERE c.active' : '') . ' ORDER BY c.sort_order, c.name')->fetchAll();
}

function category_by_id_or_key(PDO $pdo, string $v): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_ingredient_categories WHERE ' . (ctype_digit($v) ? 'category_id = :v' : 'lower(key) = lower(:v) OR lower(name) = lower(:v)') . ' LIMIT 1');
    $st->execute(['v' => $v]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function ingredient_row_decode(array $i): array
{
    $i['ingredient_id'] = (int) $i['ingredient_id'];
    $i['allergens'] = pg_text_array((string) ($i['allergens'] ?? '{}'));
    foreach (['catch_weight', 'is_prep', 'active'] as $b) { $i[$b] = (bool) $i[$b]; }
    return $i;
}

/**
 * The ingredient cards at a site: filters q (ri_find kind ingredient first, else name/code), category (id or key), kind (the category's),
 * status (active | archived | all — active by default), is_prep. Each row: the mcp_ingredients columns + category, on_hand_base,
 * avg_cost_per_base (walled by the view), days_on_hand, count_unit. ['rows', 'total'].
 */
function find_ingredients(PDO $pdo, int $scopeId, array $filters, int $limit = INGREDIENT_PAGE, int $offset = 0): array
{
    $where = [];
    $args = ['s' => $scopeId];
    $status = (string) ($filters['status'] ?? 'active');
    if ($status === 'active') { $where[] = 'i.active'; } elseif ($status === 'archived') { $where[] = 'NOT i.active'; }
    if (!empty($filters['category'])) {
        $c = category_by_id_or_key($pdo, (string) $filters['category']);
        $where[] = 'i.category_id = :c'; $args['c'] = $c['category_id'] ?? 0;
    }
    if (!empty($filters['kind']) && isset(CATEGORY_KINDS[$filters['kind']])) { $where[] = 'c.kind = :k'; $args['k'] = $filters['kind']; }
    if (isset($filters['is_prep']) && $filters['is_prep'] !== '' && $filters['is_prep'] !== null) { $where[] = 'i.is_prep = :p'; $args['p'] = in_array((string) $filters['is_prep'], ['1', 'yes', 'true'], true) ? 'true' : 'false'; }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $st = $pdo->prepare("SELECT id FROM ri_find(:q, ARRAY['ingredient'], 200)");
        $st->execute(['q' => $q]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $where[] = $ids === [] ? 'false' : 'i.ingredient_id = ANY(' . "'{" . implode(',', $ids) . "}'::bigint[])";
    }
    $sql = 'FROM mcp_ingredients i JOIN mcp_ingredient_categories c ON c.category_id = i.category_id LEFT JOIN mcp_ingredient_site_costs sc ON sc.ingredient_id = i.ingredient_id AND sc.scope_id = :s'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $st = $pdo->prepare('SELECT count(*) ' . $sql);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT i.*, c.name AS category, c.key AS category_key, c.kind AS category_kind, c.sort_order AS category_order, sc.on_hand_base, sc.avg_cost_per_base, sc.on_hand_value,
        (SELECT unit_code FROM mcp_ingredient_units u WHERE u.ingredient_id = i.ingredient_id AND u.is_default_count AND u.active) AS count_unit ' . $sql . ' ORDER BY c.sort_order, c.name, i.name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    $rows = array_map('ingredient_row_decode', $st->fetchAll());
    if ($rows !== []) {
        $st = $pdo->prepare('SELECT ingredient_id, daily_usage_base, days_on_hand FROM ri_days_on_hand(:s)');
        $st->execute(['s' => $scopeId]);
        $doh = [];
        foreach ($st->fetchAll() as $d) { $doh[(int) $d['ingredient_id']] = $d; }
        foreach ($rows as &$r) { $r['days_on_hand'] = $doh[$r['ingredient_id']]['days_on_hand'] ?? null; $r['daily_usage_base'] = $doh[$r['ingredient_id']]['daily_usage_base'] ?? null; }
        unset($r);
    }
    return ['rows' => $rows, 'total' => $total];
}

function find_ingredient(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, c.name AS category, c.key AS category_key, c.kind AS category_kind FROM mcp_ingredients i JOIN mcp_ingredient_categories c ON c.category_id = i.category_id WHERE i.ingredient_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : ingredient_row_decode($r);
}

/** By id, code or name (an agent's param). */
function ingredient_by_ref(PDO $pdo, string $v): ?array
{
    if (ctype_digit($v)) { return find_ingredient($pdo, (int) $v); }
    $st = $pdo->prepare('SELECT ingredient_id FROM mcp_ingredients WHERE lower(code) = lower(:v) OR lower(name) = lower(:v) ORDER BY active DESC LIMIT 1');
    $st->execute(['v' => $v]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_ingredient($pdo, (int) $id);
}

/** On hand by area at a site, in the base and the default count unit; the total. */
function ingredient_on_hand(PDO $pdo, int $ingredientId, int $scopeId): array
{
    $st = $pdo->prepare('SELECT b.storage_area_id, a.name, a.kind, b.qty_base FROM mcp_inventory_balances b JOIN mcp_storage_areas a ON a.storage_area_id = b.storage_area_id WHERE b.ingredient_id = :i AND b.scope_id = :s AND b.qty_base <> 0 ORDER BY a.walk_order, a.name');
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    $rows = [];
    $total = 0.0;
    $unit = default_count_unit($pdo, $ingredientId);
    foreach ($st->fetchAll() as $r) {
        $r['storage_area_id'] = (int) $r['storage_area_id'];
        $r['count_unit_code'] = $unit;
        $r['qty_in_count_unit'] = $unit === null ? null : qty_from_base($pdo, $ingredientId, (string) $r['qty_base'], $unit);
        $total += (float) $r['qty_base'];
        $rows[] = $r;
    }
    return ['rows' => $rows, 'total_base' => number_format($total, 4, '.', ''), 'count_unit_code' => $unit, 'total_in_count_unit' => $unit === null ? null : qty_from_base($pdo, $ingredientId, number_format($total, 4, '.', ''), $unit)];
}

/** The cost of an ingredient at a site: per base, per recipe unit, per default count unit; null for a caller without cost there. */
function ingredient_cost(PDO $pdo, int $ingredientId, int $scopeId, ?string $asOf = null, ?string $basis = null): ?array
{
    if (!sees_cost($scopeId)) { return null; }
    $i = find_ingredient($pdo, $ingredientId);
    if ($i === null) { return null; }
    $st = $pdo->prepare('SELECT ri_cost_as_of(:i, :s, COALESCE(:t::timestamptz, now()), :b)');
    $st->execute(['i' => $ingredientId, 's' => $scopeId, 't' => $asOf, 'b' => $basis]);
    $perBase = (string) $st->fetchColumn();
    $count = default_count_unit($pdo, $ingredientId);
    $ep = (float) $i['yield_pct'] > 0 ? number_format((float) $perBase / ((float) $i['yield_pct'] / 100), 6, '.', '') : $perBase;
    return ['per_base' => $perBase, 'base_unit' => $i['base_unit_code'], 'per_recipe_unit' => cost_per_unit($pdo, $ingredientId, $perBase, $i['recipe_unit_code']), 'recipe_unit' => $i['recipe_unit_code'],
        'per_count_unit' => $count === null ? null : cost_per_unit($pdo, $ingredientId, $perBase, $count), 'count_unit' => $count, 'ep_per_base' => $ep, 'ep_per_recipe_unit' => cost_per_unit($pdo, $ingredientId, $ep, $i['recipe_unit_code']),
        'basis' => $basis ?? 'avg', 'sentence' => ri_cost_sentence($pdo, $ingredientId, $perBase, array_values(array_filter([$i['recipe_unit_code'], $count])))];
}

/** The price history at a site (the view is the wall: nothing for a caller without cost). Newest first. */
function cost_history(PDO $pdo, int $ingredientId, int $scopeId, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT h.*, m.display_name AS changed_by_name FROM mcp_price_history h LEFT JOIN mcp_members m ON m.member_id = h.changed_by WHERE h.ingredient_id = :i AND (h.scope_id = :s OR h.scope_id IS NULL) ORDER BY h.changed_at DESC LIMIT ' . (int) $limit);
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    return $st->fetchAll();
}

/** The chart's series from history rows (oldest first): one step series of the cost per base unit. */
function cost_series(array $rows): array
{
    $pts = [];
    foreach (array_reverse($rows) as $h) { $pts[] = [$h['changed_at'], (float) $h['new_per_base'], $h['source_kind'] . ($h['reason'] ? ' · ' . $h['reason'] : '')]; }
    return [['key' => 'cost', 'label' => 'Cost per base unit', 'points' => $pts]];
}

/** The suppliers' items for an ingredient ranked by cost per recipe unit (walled: the order without prices for a caller without cost). */
function cheapest_supplier(PDO $pdo, int $ingredientId, int $scopeId): array
{
    $st = $pdo->prepare('SELECT si.*, s.name AS supplier, ri_supplier_item_cost_per_base(si.supplier_item_id) AS cost_per_base FROM mcp_supplier_items si JOIN mcp_suppliers s ON s.supplier_id = si.supplier_id WHERE si.ingredient_id = :i AND si.active AND s.active ORDER BY ri_supplier_item_cost_per_base(si.supplier_item_id) NULLS LAST, s.name');
    $st->execute(['i' => $ingredientId]);
    $rows = $st->fetchAll();
    $sees = sees_cost($scopeId);
    $i = find_ingredient($pdo, $ingredientId);
    foreach ($rows as $k => &$r) {
        $r['supplier_item_id'] = (int) $r['supplier_item_id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['cheapest'] = $k === 0 && $r['cost_per_base'] !== null;
        if (!$sees) { $r['cost_per_base'] = null; $r['last_price'] = null; $r['contract_price'] = null; $r['per_recipe_unit'] = null; }
        else { $r['per_recipe_unit'] = $i === null ? null : cost_per_unit($pdo, $ingredientId, $r['cost_per_base'], $i['recipe_unit_code']); }
    }
    unset($r);
    return $rows;
}

/** The recipes whose version in force names the ingredient (slice 2's tool reads this; guarded — slice 2's queries define one too). */
if (!function_exists('recipes_using')) {
function recipes_using(PDO $pdo, int $ingredientId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT r.recipe_id, r.name, r.kind, l.qty, l.unit_code, l.qty_base, v.batch_yield_qty, v.batch_yield_unit_code FROM mcp_recipe_lines l JOIN mcp_recipe_versions v ON v.recipe_version_id = l.version_id JOIN mcp_recipes r ON r.recipe_id = v.recipe_id
        WHERE l.ingredient_id = :i AND v.status = \'active\' AND v.effective_from <= current_date AND (v.effective_to IS NULL OR v.effective_to > current_date) AND r.active ORDER BY r.kind, r.name LIMIT ' . (int) $limit);
    $st->execute(['i' => $ingredientId]);
    return $st->fetchAll();
}

}

/** A row per held site: the settings, the defaults when absent. */
function site_settings_rows(PDO $pdo, int $ingredientId, array $heldSiteIds): array
{
    if ($heldSiteIds === []) { return []; }
    $st = $pdo->prepare('SELECT s.*, ss.par_base, ss.par_mode, ss.reorder_min_base, ss.service_level, ss.preferred_supplier_item_id, ss.cost_override_per_base, ss.default_area_id, ss.active AS settings_active
        FROM mcp_sites s LEFT JOIN mcp_ingredient_site_settings ss ON ss.scope_id = s.scope_id AND ss.ingredient_id = :i WHERE s.scope_id = ANY(:h::bigint[]) AND s.removed_at IS NULL ORDER BY s.name');
    $st->execute(['i' => $ingredientId, 'h' => '{' . implode(',', array_map('intval', $heldSiteIds)) . '}']);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['scope_id'] = (int) $r['scope_id'];
        $r['par_mode'] ??= 'fixed';
        $r['service_level'] ??= '0.950';
        $r['settings_active'] = $r['settings_active'] === null ? true : (bool) $r['settings_active'];
        $r['on_hand_base'] = (string) one_value($pdo, 'SELECT ri_on_hand(:i, :s)', ['i' => $ingredientId, 's' => $r['scope_id']]);
        $r['on_order_base'] = (string) one_value($pdo, 'SELECT ri_on_order(:i, :s)', ['i' => $ingredientId, 's' => $r['scope_id']]);
        $rows[] = $r;
    }
    return $rows;
}

function yield_tests(PDO $pdo, int $ingredientId): array
{
    $st = $pdo->prepare('SELECT y.*, m.display_name AS tested_by_name FROM mcp_yield_tests y LEFT JOIN mcp_members m ON m.member_id = y.tested_by WHERE y.ingredient_id = :i ORDER BY y.tested_at DESC');
    $st->execute(['i' => $ingredientId]);
    return $st->fetchAll();
}

function ingredient_containers(PDO $pdo, int $ingredientId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_containers WHERE ingredient_id = :i ORDER BY is_default DESC, name');
    $st->execute(['i' => $ingredientId]);
    return $st->fetchAll();
}

function ingredient_ledger(PDO $pdo, int $ingredientId, int $scopeId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT t.*, a.name AS area FROM mcp_inventory_transactions t JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id WHERE t.ingredient_id = :i AND t.scope_id = :s ORDER BY t.occurred_at DESC, t.transaction_id DESC LIMIT ' . (int) $limit);
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    return $st->fetchAll();
}

/** null when deletable, else the sentence. */
function ingredient_deletable(PDO $pdo, int $id): ?string
{
    foreach (['mcp_inventory_transactions' => ['ingredient_id', 'It has stock movements.'], 'mcp_recipe_lines' => ['ingredient_id', 'A recipe names it.'], 'mcp_supplier_items' => ['ingredient_id', 'A supplier sells it.'], 'mcp_inventory_count_lines' => ['ingredient_id', 'A count lists it.']] as $view => [$col, $words]) {
        $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$col} = :id LIMIT 1");
        $st->execute(['id' => $id]);
        if ($st->fetchColumn() !== false) { return 'This ingredient cannot be deleted: ' . $words . ' Archive it instead.'; }
    }
    return null;
}

/** The class is locked once a ledger row or a recipe line exists. */
function ingredient_locked(PDO $pdo, int $id): bool
{
    foreach (['mcp_inventory_transactions', 'mcp_recipe_lines'] as $view) {
        $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE ingredient_id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        if ($st->fetchColumn() !== false) { return true; }
    }
    return false;
}

/** Everything the ingredient's page shows (tool get_ingredient). */
function ingredient_full(PDO $pdo, int $id, int $scopeId): ?array
{
    $i = find_ingredient($pdo, $id);
    if ($i === null) { return null; }
    return ['ingredient' => $i, 'units' => ingredient_units($pdo, $id, false), 'on_hand' => ingredient_on_hand($pdo, $id, $scopeId), 'site_settings' => site_settings_rows($pdo, $id, array_column(held_sites(), 'scope_id')),
        'cost' => ingredient_cost($pdo, $id, $scopeId), 'supplier_items' => cheapest_supplier($pdo, $id, $scopeId), 'recipes_using' => recipes_using($pdo, $id), 'yield_tests' => yield_tests($pdo, $id),
        'containers' => ingredient_containers($pdo, $id), 'notes' => record_notes($pdo, 'ingredient', $id), 'attachments' => record_attachments($pdo, 'ingredient', $id)];
}

// ---------------------------------------------------------------------------------------------------------------------- suppliers

function supplier_row_decode(array $s): array
{
    $s['supplier_id'] = (int) $s['supplier_id'];
    foreach (['allow_split_case', 'active'] as $b) { $s[$b] = (bool) $s[$b]; }
    return $s;
}

/** The next order window at a site (null when the function answers nothing). */
function next_window(PDO $pdo, int $supplierId, int $scopeId): ?array
{
    $st = $pdo->prepare('SELECT * FROM ri_next_order_windows(:sup, :s)');
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    $w = $st->fetch();
    return $w === false ? null : $w;
}

/** The next $n windows from the schedule, each from the previous cut-off. */
function next_windows(PDO $pdo, int $supplierId, int $scopeId, int $n = 3): array
{
    $out = [];
    $from = 'now()';
    $args = [];
    for ($k = 0; $k < $n; $k++) {
        $st = $pdo->prepare('SELECT * FROM ri_next_order_windows(:sup, :s, ' . ($k === 0 ? 'now()' : ':f::timestamptz') . ')');
        $st->execute(['sup' => $supplierId, 's' => $scopeId] + ($k === 0 ? [] : ['f' => $args['f']]));
        $w = $st->fetch();
        if ($w === false) { break; }
        $out[] = $w;
        $args['f'] = $w['cutoff_at'];
    }
    return $out;
}

function has_schedule(PDO $pdo, int $supplierId, int $scopeId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM mcp_supplier_schedules WHERE supplier_id = :sup AND scope_id = :s AND active LIMIT 1');
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    return $st->fetchColumn() !== false;
}

/** The supplier cards at a site: q, kind, status. */
function find_suppliers(PDO $pdo, int $scopeId, array $filters, int $limit = SUPPLIER_PAGE, int $offset = 0): array
{
    $where = [];
    $args = [];
    $status = (string) ($filters['status'] ?? 'active');
    if ($status === 'active') { $where[] = 's.active'; } elseif ($status === 'archived') { $where[] = 'NOT s.active'; }
    if (!empty($filters['kind']) && isset(SUPPLIER_KINDS[$filters['kind']])) { $where[] = 's.kind = :k'; $args['k'] = $filters['kind']; }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') { $where[] = 'lower(s.name) LIKE :q'; $args['q'] = '%' . mb_strtolower($q) . '%'; }
    $sql = 'FROM mcp_suppliers s' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $st = $pdo->prepare('SELECT count(*) ' . $sql);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT s.*, (SELECT count(*) FROM mcp_supplier_items si WHERE si.supplier_id = s.supplier_id AND si.active) AS items,
        (SELECT count(*) FROM mcp_purchase_orders p WHERE p.supplier_id = s.supplier_id AND p.scope_id = :s AND p.status IN (\'sent\', \'acknowledged\', \'partial\')) AS open_pos,
        (SELECT name FROM mcp_sites x WHERE x.scope_id = s.commissary_scope_id) AS commissary_site ' . $sql . ' ORDER BY s.name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args + ['s' => $scopeId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = supplier_row_decode($r);
        $r['has_schedule'] = has_schedule($pdo, $r['supplier_id'], $scopeId);
        $r['next_window'] = $r['has_schedule'] ? next_window($pdo, $r['supplier_id'], $scopeId) : null;
        $rows[] = $r;
    }
    return ['rows' => $rows, 'total' => $total];
}

function find_supplier(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT s.*, (SELECT name FROM mcp_sites x WHERE x.scope_id = s.commissary_scope_id) AS commissary_site FROM mcp_suppliers s WHERE s.supplier_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : supplier_row_decode($r);
}

function supplier_by_ref(PDO $pdo, string $v): ?array
{
    if (ctype_digit($v)) { return find_supplier($pdo, (int) $v); }
    $st = $pdo->prepare('SELECT supplier_id FROM mcp_suppliers WHERE lower(name) = lower(:v) ORDER BY active DESC LIMIT 1');
    $st->execute(['v' => $v]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_supplier($pdo, (int) $id);
}

/** The schedule rows at a site, in words. */
function supplier_schedules(PDO $pdo, int $supplierId, int $scopeId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_supplier_schedules WHERE supplier_id = :sup AND scope_id = :s ORDER BY order_dow, cutoff_time');
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['supplier_schedule_id'] = (int) $r['supplier_schedule_id'];
        $r['words'] = 'Order ' . DOW[(int) $r['order_dow']] . ' by ' . substr((string) $r['cutoff_time'], 0, 5) . ' → delivered ' . DOW[(int) $r['delivery_dow']] . ' (' . (int) $r['lead_days'] . ' day' . ((int) $r['lead_days'] === 1 ? '' : 's') . ')';
        $rows[] = $r;
    }
    return $rows;
}

/** The items a supplier sells (or every supplier's of an ingredient); the view walls the prices. */
function supplier_items(PDO $pdo, ?int $supplierId = null, ?int $ingredientId = null, bool $activeOnly = true): array
{
    $where = [];
    $args = [];
    if ($supplierId !== null) { $where[] = 'si.supplier_id = :sup'; $args['sup'] = $supplierId; }
    if ($ingredientId !== null) { $where[] = 'si.ingredient_id = :i'; $args['i'] = $ingredientId; }
    if ($activeOnly) { $where[] = 'si.active'; }
    $st = $pdo->prepare('SELECT si.*, i.name AS ingredient, i.code AS ingredient_code, i.recipe_unit_code, i.base_unit_code, s.name AS supplier, ri_supplier_item_cost_per_base(si.supplier_item_id) AS cost_per_base
        FROM mcp_supplier_items si JOIN mcp_ingredients i ON i.ingredient_id = si.ingredient_id JOIN mcp_suppliers s ON s.supplier_id = si.supplier_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY si.active DESC, i.name, si.purchase_unit_code');
    $st->execute($args);
    $rows = [];
    $sees = sees_cost();
    foreach ($st->fetchAll() as $r) {
        $r['supplier_item_id'] = (int) $r['supplier_item_id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['ingredient_id'] = (int) $r['ingredient_id'];
        $r['catch_weight'] = (bool) $r['catch_weight'];
        $r['active'] = (bool) $r['active'];
        if (!$sees || $r['last_price'] === null && $r['contract_price'] === null) { $r['cost_per_base'] = $sees ? $r['cost_per_base'] : null; }
        $r['per_recipe_unit'] = $sees && $r['cost_per_base'] !== null ? cost_per_unit($pdo, $r['ingredient_id'], $r['cost_per_base'], $r['recipe_unit_code']) : null;
        $rows[] = $r;
    }
    return $rows;
}

function find_supplier_item(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT si.*, i.name AS ingredient, i.recipe_unit_code, s.name AS supplier FROM mcp_supplier_items si JOIN mcp_ingredients i ON i.ingredient_id = si.ingredient_id JOIN mcp_suppliers s ON s.supplier_id = si.supplier_id WHERE si.supplier_item_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['supplier_item_id'] = (int) $r['supplier_item_id']; $r['supplier_id'] = (int) $r['supplier_id']; $r['ingredient_id'] = (int) $r['ingredient_id'];
    $r['catch_weight'] = (bool) $r['catch_weight']; $r['active'] = (bool) $r['active'];
    return $r;
}

/** The picker's rows: by SKU, ingredient name or description; narrowed by supplier and/or ingredient. */
function supplier_item_pick(PDO $pdo, string $q, ?int $supplierId, ?int $ingredientId, int $page): array
{
    $rows = [];
    $sees = sees_cost();
    foreach (supplier_items($pdo, $supplierId, $ingredientId) as $si) {
        $rows[] = ['id' => $si['supplier_item_id'], 'label' => ($si['supplier_sku'] ? $si['supplier_sku'] . ' · ' : '') . $si['ingredient'] . ($supplierId === null ? ' — ' . $si['supplier'] : ''),
            'detail' => trim(($si['pack'] ?? $si['purchase_unit_code']) . ($sees && $si['last_price'] !== null ? ' · $' . number_format((float) $si['last_price'], 2) . ' / ' . $si['purchase_unit_code'] : '')), 'badge' => $si['catch_weight'] ? 'catch weight' : null, 'color' => 'warning'];
    }
    return picker_result_from_list($rows, $q, $page);
}

/** The order guide at a site, with its lines joined to the items. */
function order_guide(PDO $pdo, int $supplierId, int $scopeId): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_order_guides WHERE supplier_id = :sup AND scope_id = :s AND active ORDER BY order_guide_id LIMIT 1');
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    $g = $st->fetch();
    if ($g === false) { return null; }
    $g['order_guide_id'] = (int) $g['order_guide_id'];
    $lines = json_decode((string) ($g['lines'] ?? '[]'), true) ?: [];
    usort($lines, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
    $items = [];
    foreach (supplier_items($pdo, $supplierId, null, false) as $si) { $items[$si['supplier_item_id']] = $si; }
    $g['lines'] = [];
    foreach ($lines as $l) {
        $si = $items[(int) $l['supplier_item_id']] ?? null;
        if ($si === null) { continue; }
        $g['lines'][] = ['supplier_item_id' => (int) $l['supplier_item_id'], 'seq' => (int) $l['seq'], 'preferred' => (bool) $l['preferred']] + $si;
    }
    return $g;
}

function supplier_deletable(PDO $pdo, int $id): ?string
{
    foreach (['mcp_purchase_orders' => 'A purchase order names it.', 'mcp_goods_receipts' => 'A receipt names it.', 'mcp_supplier_invoices' => 'An invoice names it.', 'mcp_supplier_items' => 'It sells us items.'] as $view => $words) {
        $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE supplier_id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        if ($st->fetchColumn() !== false) { return 'This supplier cannot be deleted: ' . $words . ' Archive it instead.'; }
    }
    return null;
}

function supplier_purchase_orders(PDO $pdo, int $supplierId, int $scopeId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_purchase_orders WHERE supplier_id = :sup AND scope_id = :s ORDER BY ordered_on DESC, purchase_order_id DESC LIMIT ' . (int) $limit);
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    return $st->fetchAll();
}

function supplier_invoices(PDO $pdo, int $supplierId, int $scopeId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_supplier_invoices WHERE supplier_id = :sup AND scope_id = :s ORDER BY invoice_date DESC LIMIT ' . (int) $limit);
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    return $st->fetchAll();
}

function supplier_price_alerts(PDO $pdo, int $supplierId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT a.*, i.name AS ingredient FROM mcp_price_alerts a JOIN mcp_supplier_items si ON si.supplier_item_id = a.supplier_item_id JOIN mcp_ingredients i ON i.ingredient_id = a.ingredient_id WHERE si.supplier_id = :sup ORDER BY a.raised_at DESC LIMIT ' . (int) $limit);
    $st->execute(['sup' => $supplierId]);
    return $st->fetchAll();
}

function supplier_full(PDO $pdo, int $id, int $scopeId): ?array
{
    $s = find_supplier($pdo, $id);
    if ($s === null) { return null; }
    $sched = supplier_schedules($pdo, $id, $scopeId);
    return ['supplier' => $s, 'schedules' => $sched, 'next_windows' => $sched === [] ? [] : next_windows($pdo, $id, $scopeId), 'items' => supplier_items($pdo, $id, null, false), 'guide' => order_guide($pdo, $id, $scopeId),
        'open_purchase_orders' => array_values(array_filter(supplier_purchase_orders($pdo, $id, $scopeId), static fn (array $p): bool => in_array($p['status'], ['sent', 'acknowledged', 'partial'], true))),
        'notes' => record_notes($pdo, 'supplier', $id), 'attachments' => record_attachments($pdo, 'supplier', $id)];
}

// ---------------------------------------------------------------------------------------------------------------------- areas, notes

/** This site's areas in walk order with items on hand. */
function find_storage_areas(PDO $pdo, int $scopeId, bool $activeOnly = true): array
{
    $st = $pdo->prepare('SELECT a.*, (SELECT count(*) FROM mcp_inventory_balances b WHERE b.storage_area_id = a.storage_area_id AND b.qty_base <> 0) AS items_on_hand FROM mcp_storage_areas a WHERE a.scope_id = :s' . ($activeOnly ? ' AND a.active' : '') . ' ORDER BY a.walk_order, a.name');
    $st->execute(['s' => $scopeId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) { $r['storage_area_id'] = (int) $r['storage_area_id']; $r['scope_id'] = (int) $r['scope_id']; $r['allow_negative'] = (bool) $r['allow_negative']; $r['active'] = (bool) $r['active']; $rows[] = $r; }
    return $rows;
}

function find_storage_area(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_storage_areas WHERE storage_area_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['storage_area_id'] = (int) $r['storage_area_id']; $r['scope_id'] = (int) $r['scope_id']; $r['allow_negative'] = (bool) $r['allow_negative']; $r['active'] = (bool) $r['active'];
    return $r;
}

function area_archivable(PDO $pdo, int $areaId): ?string
{
    $st = $pdo->prepare('SELECT count(*) FROM mcp_inventory_balances WHERE storage_area_id = :id AND qty_base <> 0');
    $st->execute(['id' => $areaId]);
    $n = (int) $st->fetchColumn();
    return $n === 0 ? null : 'The area still holds ' . $n . ' ingredient' . ($n === 1 ? '' : 's') . ' — move or count them out first.';
}

function record_notes(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT n.*, m.display_name AS author FROM mcp_notes n LEFT JOIN mcp_members m ON m.member_id = n.member_id WHERE n.record_type = :t AND n.record_id = :id ORDER BY n.created_at DESC');
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

function record_attachments(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT a.*, m.display_name AS uploader FROM mcp_attachments a LEFT JOIN mcp_members m ON m.member_id = a.uploaded_by WHERE a.record_type = :t AND a.record_id = :id ORDER BY a.created_at DESC');
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

/** The mcp_* view and id column of a record type a note or a file attaches to (catalog.md: record_url()'s map). */
function record_view(string $type): ?array
{
    return match ($type) {
        'ingredient' => ['mcp_ingredients', 'ingredient_id'], 'supplier' => ['mcp_suppliers', 'supplier_id'], 'supplier_item' => ['mcp_supplier_items', 'supplier_item_id'], 'storage_area', 'area' => ['mcp_storage_areas', 'storage_area_id'],
        'recipe' => ['mcp_recipes', 'recipe_id'], 'menu_item' => ['mcp_menu_items', 'menu_item_id'], 'goods_receipt', 'receipt' => ['mcp_goods_receipts', 'goods_receipt_id'], 'transfer' => ['mcp_inventory_transfers', 'transfer_id'],
        'adjustment' => ['mcp_inventory_adjustments', 'adjustment_id'], 'production_batch', 'batch' => ['mcp_production_batches', 'production_batch_id'], 'waste_log', 'waste' => ['mcp_waste_logs', 'waste_log_id'],
        'purchase_order' => ['mcp_purchase_orders', 'purchase_order_id'], 'invoice', 'supplier_invoice' => ['mcp_supplier_invoices', 'invoice_id'], 'inventory_count', 'count' => ['mcp_inventory_counts', 'count_id'], 'source' => ['mcp_sources', 'source_id'],
        default => null,
    };
}
