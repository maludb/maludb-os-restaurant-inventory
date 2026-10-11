<?php
declare(strict_types=1);

/**
 * The catalog's handler prelude (catalog.md): the request readers that turn a form (or an agent's JSON) into the fields the writes
 * take — "a field left out stays as it was" —, qty_with_unit() (the ONE way a quantity enters: a number + a unit → base through
 * ri_to_base(), its refusal the field error), and catalog_log(). Required by every html/ingredients, units, categories, suppliers,
 * areas, notes and attachments controller.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/units/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/me/queries.php';
require_once dirname(__DIR__) . '/me/present.php';

/** log_activity() with the catalog's keys. */
function catalog_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts);
}

/** A number field: '' → null (or $keep when absent); must be numeric and within bounds. */
function ri_num(string $name, ?string $keep, string $label, array &$errors, float $min = 0, ?float $max = null, bool $nullable = true, int $scale = 4): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = str_replace(',', '', (string) req_val($name));
    if ($v === '') { if ($nullable) { return null; } $errors[$name] = $label . ' is required.'; return $keep; }
    if (!is_numeric($v) || (float) $v < $min || ($max !== null && (float) $v > $max)) { $errors[$name] = $label . ' is a number' . ($min > 0 ? ' over ' . fmt_num($min) : ' of ' . fmt_num($min) . ' or more') . ($max !== null ? ' up to ' . fmt_num($max) : '') . '.'; return $keep; }
    return number_format((float) $v, $scale, '.', '');
}

/**
 * A quantity with its unit: `{name}` + `{name}_unit` → [qty, unit, qty_base]; the conversion's refusal is the field error on the unit.
 * null when the quantity is left out (or empty and $nullable).
 */
function qty_with_unit(PDO $pdo, int $ingredientId, string $name, string $label, array &$errors, bool $nullable = true, ?string $defaultUnit = null): ?array
{
    if (!req_has($name)) { return null; }
    $qty = str_replace(',', '', (string) req_val($name));
    $unit = (string) (req_val($name . '_unit') ?? '');
    if ($qty === '') { if ($nullable) { return null; } $errors[$name] = $label . ' is required.'; return null; }
    if (!is_numeric($qty) || (float) $qty < 0) { $errors[$name] = $label . ' is a number of 0 or more.'; return null; }
    if ($unit === '') { $unit = $defaultUnit ?? ''; }
    if ($unit === '') { $errors[$name . '_unit'] = 'Say the unit of ' . lcfirst($label) . '.'; return null; }
    try {
        $base = qty_to_base($pdo, $ingredientId, number_format((float) $qty, 4, '.', ''), $unit);
    } catch (PDOException $e) {
        $errors[$name . '_unit'] = db_message($e, 'That unit cannot be converted.');
        return null;
    }
    return ['qty' => number_format((float) $qty, 4, '.', ''), 'unit' => $unit, 'qty_base' => $base];
}

/** A code from a name: upper, [^A-Z0-9]+ → _, ≤ 20. */
function code_from_name(string $name, int $max = 20): string
{
    $c = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?? '', '_'));
    return mb_substr($c === '' ? 'ITEM' : $c, 0, $max);
}

/** A key from a name: lower, [^a-z0-9]+ → _. */
function key_from_name(string $name, int $max = 40): string
{
    $k = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?? '', '_'));
    $k = preg_replace('/^[^a-z]+/', '', $k) ?: 'x';
    return mb_substr($k, 0, $max);
}

/** The ingredient form → the fields of save_ingredient(). $cur: the current row or null. */
function ingredient_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = ri_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true) ?? '';
    if ($cur === null && $f['name'] === '') { $errors['name'] = 'The name is required.'; }
    $code = ri_text('code', $cur['code'] ?? null, 40, 'The code', $errors);
    if ($code !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $code)) { $errors['code'] = 'A code is letters, digits, _ . or -.'; }
    $f['code'] = $code ?? ($cur['code'] ?? '');
    $f['category_id'] = $cur['category_id'] ?? null;
    if (req_has('category')) {
        $c = (string) req_val('category');
        $cat = $c === '' ? null : category_by_id_or_key($pdo, $c);
        if ($cat === null) { $errors['category'] = 'Choose the category from the list.'; } else { $f['category_id'] = (int) $cat['category_id']; }
    }
    if ($f['category_id'] === null && !isset($errors['category'])) { $errors['category'] = 'Choose the category from the list.'; }
    $f['measure_class'] = ri_choice('measure_class', $cur['measure_class'] ?? null, array_keys(MEASURE_CLASSES), 'The measure class', $errors, false);
    if ($f['measure_class'] === null) { $errors['measure_class'] = 'Choose weight, volume or each.'; $f['measure_class'] = 'weight'; }
    if ($cur !== null && $f['measure_class'] !== $cur['measure_class'] && ingredient_locked($pdo, (int) $cur['ingredient_id'])) {
        $errors['measure_class'] = 'The class of an ingredient that has stock or recipes does not change — make a new ingredient.';
    }
    if (req_has('base_unit_code') && (string) req_val('base_unit_code') !== CLASS_BASE[$f['measure_class']]) { $errors['base_unit_code'] = 'The base unit is the class\'s base (' . CLASS_BASE[$f['measure_class']] . '); it is not a field.'; }
    $f['base_unit_code'] = CLASS_BASE[$f['measure_class']];
    $f['recipe_unit_code'] = ri_text('recipe_unit', $cur['recipe_unit_code'] ?? null, 16, 'The recipe unit', $errors) ?? CLASS_BASE[$f['measure_class']];
    $f['density_g_per_ml'] = ri_num('density_g_per_ml', $cur['density_g_per_ml'] ?? null, 'The density', $errors, 0.0001, 100, true, 6);
    $f['each_weight_g'] = ri_num('each_weight_g', $cur['each_weight_g'] ?? null, 'The each-weight', $errors, 0.0001, null, true, 4);
    $f['each_volume_ml'] = ri_num('each_volume_ml', $cur['each_volume_ml'] ?? null, 'The each-volume', $errors, 0.0001, null, true, 4);
    $f['yield_pct'] = ri_num('yield_pct', $cur['yield_pct'] ?? '100.00', 'The yield', $errors, 0.01, 100, false, 2) ?? '100.00';
    $f['catch_weight'] = ri_yes('catch_weight', $cur['catch_weight'] ?? false);
    $f['shelf_life_days'] = ri_int('shelf_life_days', isset($cur['shelf_life_days']) ? (int) $cur['shelf_life_days'] : null, 1, 3650, 'The shelf life', $errors, true);
    $f['costing_method'] = ri_choice('costing_method', $cur['costing_method'] ?? 'avg', ['avg', 'last'], 'The costing method', $errors, false) ?? 'avg';
    $f['variance_tolerance_pct'] = ri_num('variance_tolerance_pct', $cur['variance_tolerance_pct'] ?? null, 'The variance tolerance', $errors, 0, 100, true, 2);
    $f['variance_tolerance_value'] = ri_num('variance_tolerance_value', $cur['variance_tolerance_value'] ?? null, 'The variance tolerance value', $errors, 0, null, true, 2);
    $list = request_list('allergens');
    if ($list !== null) {
        $other = trim((string) (req_val('allergen_other') ?? ''));
        $known = array_values(array_intersect($list, array_keys(ALLERGENS)));
        $extra = array_values(array_filter(array_diff($list, array_keys(ALLERGENS)), static fn (string $x): bool => $x !== '' && !str_starts_with($x, '_')));
        if ($other !== '') { $extra[] = mb_substr($other, 0, 40); }
        $f['allergens'] = array_values(array_unique(array_merge($known, $extra)));
    } else { $f['allergens'] = $cur['allergens'] ?? []; }
    $f['notes'] = ri_text('notes', $cur['notes'] ?? null, 2000, 'The notes', $errors);
    $f['active'] = $cur === null ? true : (req_has('status') ? ((string) req_val('status') !== 'archived') : (bool) $cur['active']);
    foreach (['cost', 'cost_override', 'par', 'par_base'] as $k) {
        if (req_has($k)) { $errors[$k] = ($k === 'cost' || $k === 'cost_override' ? 'A cost is set with cost_override_set (per site).' : 'A par is set with par_set (per site).'); }
    }
    return $f;
}

/** The supplier form → the fields of save_supplier(). */
function supplier_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = ri_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true) ?? '';
    if ($cur === null && $f['name'] === '') { $errors['name'] = 'The name is required.'; }
    $f['kind'] = ri_choice('kind', $cur['kind'] ?? 'distributor', array_keys(SUPPLIER_KINDS), 'The kind', $errors, false) ?? 'distributor';
    foreach (['contact_name' => 120, 'phone' => 60, 'address' => 500, 'notes' => 2000, 'portal_url' => 300, 'account_number' => 80, 'rep_name' => 120, 'rep_phone' => 60] as $k => $max) { $f[$k] = ri_text($k, $cur[$k] ?? null, $max, 'The ' . str_replace('_', ' ', $k), $errors); }
    foreach (['email', 'order_email'] as $k) {
        $v = ri_text($k, $cur[$k] ?? null, 200, 'The ' . str_replace('_', ' ', $k), $errors);
        if ($v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false) { $errors[$k] = 'That is not an email address.'; }
        $f[$k] = $v;
    }
    $f['order_method'] = ri_choice('order_method', $cur['order_method'] ?? 'email', array_keys(ORDER_METHODS), 'The order method', $errors, false) ?? 'email';
    if ($f['order_method'] === 'email' && ($f['order_email'] ?? null) === null) { $errors['order_email'] = 'An order email is needed when the method is email.'; }
    if ($f['order_method'] === 'portal' && ($f['portal_url'] ?? null) === null) { $errors['portal_url'] = 'A portal address is needed when the method is portal.'; }
    $f['terms_days'] = ri_int('terms_days', isset($cur['terms_days']) ? (int) $cur['terms_days'] : null, 0, 365, 'The terms', $errors, true);
    $f['lead_time_days'] = ri_int('lead_time_days', isset($cur['lead_time_days']) ? (int) $cur['lead_time_days'] : 1, 0, 90, 'The lead time', $errors, false) ?? 1;
    $f['min_order_value'] = ri_num('min_order_value', $cur['min_order_value'] ?? null, 'The minimum order value', $errors, 0, null, true, 2);
    $f['allow_split_case'] = ri_yes('allow_split_case', $cur['allow_split_case'] ?? false);
    $f['match_tolerance_pct'] = ri_num('match_tolerance_pct', $cur['match_tolerance_pct'] ?? '2.00', 'The match tolerance', $errors, 0, 100, false, 2) ?? '2.00';
    $f['match_tolerance_value'] = ri_num('match_tolerance_value', $cur['match_tolerance_value'] ?? '5.00', 'The match tolerance value', $errors, 0, null, false, 2) ?? '5.00';
    $f['commissary_scope_id'] = isset($cur['commissary_scope_id']) ? (int) $cur['commissary_scope_id'] : null;
    if (req_has('commissary_site')) {
        $v = (string) req_val('commissary_site');
        $f['commissary_scope_id'] = $v === '' ? null : (ctype_digit($v) && in_array((int) $v, array_column(held_sites(), 'scope_id'), true) ? (int) $v : null);
        if ($v !== '' && $f['commissary_scope_id'] === null) { $errors['commissary_site'] = 'Choose one of your sites.'; }
    }
    if ($f['kind'] === 'commissary' && $f['commissary_scope_id'] === null && !isset($errors['commissary_site'])) { $errors['commissary_site'] = 'A commissary is one of your sites — choose which.'; }
    if ($f['kind'] !== 'commissary') { $f['commissary_scope_id'] = null; }
    $f['active'] = $cur === null ? true : ri_yes('active', (bool) $cur['active']);
    return $f;
}

/** 404 unless the ingredient exists (visible through the view). */
function catalog_ingredient_or_404(PDO $pdo, ?int $id): array
{
    $i = $id === null ? null : find_ingredient($pdo, $id);
    if ($i === null) { respond_not_found('That ingredient is not here.'); }
    return $i;
}

function catalog_supplier_or_404(PDO $pdo, ?int $id): array
{
    $s = $id === null ? null : find_supplier($pdo, $id);
    if ($s === null) { respond_not_found('That supplier is not here.'); }
    return $s;
}

/** A storage area of the current site, or 404. */
function catalog_area_or_404(PDO $pdo, ?int $id): array
{
    $a = $id === null ? null : find_storage_area($pdo, $id);
    if ($a === null || $a['scope_id'] !== (int) current_site_id()) { respond_not_found('That storage area is not here.'); }
    return $a;
}

/** The ingredient named by `ingredient` (id, code or name), or 404. */
function ingredient_param_or_404(PDO $pdo, string $name = 'ingredient'): array
{
    $v = (string) (req_val($name) ?? request_string($name));
    $i = $v === '' ? null : ingredient_by_ref($pdo, $v);
    if ($i === null) { respond_not_found('That ingredient is not here.'); }
    return $i;
}

function supplier_param_or_404(PDO $pdo, string $name = 'supplier'): array
{
    $v = (string) (req_val($name) ?? request_string($name));
    $s = $v === '' ? null : supplier_by_ref($pdo, $v);
    if ($s === null) { respond_not_found('That supplier is not here.'); }
    return $s;
}

/** The site a per-site write names: `site` (held), else the current site. */
function site_param_or_current(): int
{
    $v = (string) (req_val('site') ?? '');
    if ($v === '') { return (int) current_site_id(); }
    if (!ctype_digit($v) || !in_array((int) $v, array_column(held_sites(), 'scope_id'), true)) { respond_not_found('That site is not one you hold.'); }
    return (int) $v;
}

/** The loggable shape of an ingredient. */
function ingredient_loggable(array $i): array
{
    return ['name' => $i['name'], 'code' => $i['code'], 'category_id' => (int) $i['category_id'], 'measure_class' => $i['measure_class'], 'recipe_unit_code' => $i['recipe_unit_code'], 'yield_pct' => $i['yield_pct'], 'catch_weight' => $i['catch_weight'],
        'shelf_life_days' => $i['shelf_life_days'], 'density_g_per_ml' => $i['density_g_per_ml'], 'each_weight_g' => $i['each_weight_g'], 'each_volume_ml' => $i['each_volume_ml'], 'costing_method' => $i['costing_method'], 'allergens' => $i['allergens'], 'active' => $i['active']];
}

function supplier_loggable(array $s): array
{
    return ['name' => $s['name'], 'kind' => $s['kind'], 'order_method' => $s['order_method'], 'order_email' => $s['order_email'], 'lead_time_days' => (int) $s['lead_time_days'], 'min_order_value' => $s['min_order_value'], 'allow_split_case' => $s['allow_split_case'],
        'match_tolerance_pct' => $s['match_tolerance_pct'], 'match_tolerance_value' => $s['match_tolerance_value'], 'commissary_scope_id' => $s['commissary_scope_id'], 'account_number' => $s['account_number'] ?? null, 'rep_name' => $s['rep_name'], 'rep_phone' => $s['rep_phone'], 'contact_name' => $s['contact_name'], 'email' => $s['email'], 'phone' => $s['phone'], 'terms_days' => $s['terms_days'] ?? null, 'portal_url' => $s['portal_url'], 'active' => $s['active']];
}
