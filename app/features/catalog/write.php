<?php
declare(strict_types=1);

/**
 * The catalog's writes (catalog.md "Saving, step by step"): every one a plain INSERT/UPDATE inside the caller's ri_guard() — the
 * triggers of db/007 decide the unit rules and refuse in their words; a cost override and a par go through here and nowhere else.
 */

function save_ingredient(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, density_g_per_ml, each_weight_g, each_volume_ml, yield_pct, catch_weight, shelf_life_days, costing_method, variance_tolerance_pct, variance_tolerance_value, allergens, notes, created_by)
            VALUES (:code, :name, :category_id, :measure_class, :base_unit_code, :recipe_unit_code, :density_g_per_ml, :each_weight_g, :each_volume_ml, :yield_pct, :catch_weight, :shelf_life_days, :costing_method, :variance_tolerance_pct, :variance_tolerance_value, :allergens::text[], :notes, :by) RETURNING id');
        $st->execute(['code' => $f['code'], 'name' => $f['name'], 'category_id' => $f['category_id'], 'measure_class' => $f['measure_class'], 'base_unit_code' => $f['base_unit_code'], 'recipe_unit_code' => $f['recipe_unit_code'],
            'density_g_per_ml' => $f['density_g_per_ml'], 'each_weight_g' => $f['each_weight_g'], 'each_volume_ml' => $f['each_volume_ml'], 'yield_pct' => $f['yield_pct'], 'catch_weight' => $f['catch_weight'] ? 'true' : 'false',
            'shelf_life_days' => $f['shelf_life_days'], 'costing_method' => $f['costing_method'], 'variance_tolerance_pct' => $f['variance_tolerance_pct'], 'variance_tolerance_value' => $f['variance_tolerance_value'],
            'allergens' => pg_array_literal($f['allergens']), 'notes' => $f['notes'], 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE ingredients SET code = :code, name = :name, category_id = :category_id, measure_class = :measure_class, base_unit_code = :base_unit_code, recipe_unit_code = :recipe_unit_code, density_g_per_ml = :density_g_per_ml,
        each_weight_g = :each_weight_g, each_volume_ml = :each_volume_ml, yield_pct = :yield_pct, catch_weight = :catch_weight, shelf_life_days = :shelf_life_days, costing_method = :costing_method, variance_tolerance_pct = :variance_tolerance_pct,
        variance_tolerance_value = :variance_tolerance_value, allergens = :allergens::text[], notes = :notes, active = :active, archived_at = CASE WHEN :active2::boolean THEN NULL ELSE COALESCE(archived_at, now()) END WHERE id = :id');
    $st->execute(['code' => $f['code'], 'name' => $f['name'], 'category_id' => $f['category_id'], 'measure_class' => $f['measure_class'], 'base_unit_code' => $f['base_unit_code'], 'recipe_unit_code' => $f['recipe_unit_code'],
        'density_g_per_ml' => $f['density_g_per_ml'], 'each_weight_g' => $f['each_weight_g'], 'each_volume_ml' => $f['each_volume_ml'], 'yield_pct' => $f['yield_pct'], 'catch_weight' => $f['catch_weight'] ? 'true' : 'false',
        'shelf_life_days' => $f['shelf_life_days'], 'costing_method' => $f['costing_method'], 'variance_tolerance_pct' => $f['variance_tolerance_pct'], 'variance_tolerance_value' => $f['variance_tolerance_value'],
        'allergens' => pg_array_literal($f['allergens']), 'notes' => $f['notes'], 'active' => $f['active'] ? 'true' : 'false', 'active2' => $f['active'] ? 'true' : 'false', 'id' => $id]);
    return $id;
}

function archive_ingredient(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE ingredients SET active = NOT :a::boolean, archived_at = CASE WHEN :a2::boolean THEN now() ELSE NULL END WHERE id = :id')->execute(['a' => $archived ? 'true' : 'false', 'a2' => $archived ? 'true' : 'false', 'id' => $id]);
}

function delete_ingredient(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM ingredients WHERE id = :id')->execute(['id' => $id]);
}

/** An own unit: $f = unit_code, name, to_base_factor, kind, is_default_count, active. */
function save_ingredient_unit(PDO $pdo, int $ingredientId, ?int $unitId, array $f): int
{
    if (!empty($f['is_default_count'])) {
        $pdo->prepare('UPDATE ingredient_units SET is_default_count = false WHERE ingredient_id = :i AND is_default_count' . ($unitId !== null ? ' AND id <> :u' : ''))->execute(['i' => $ingredientId] + ($unitId !== null ? ['u' => $unitId] : []));
    }
    if ($unitId === null) {
        $st = $pdo->prepare('INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count, active) VALUES (:i, :c, :n, :f, :k, :d, :a) RETURNING id');
        $st->execute(['i' => $ingredientId, 'c' => $f['unit_code'], 'n' => $f['name'], 'f' => $f['to_base_factor'], 'k' => $f['kind'], 'd' => !empty($f['is_default_count']) ? 'true' : 'false', 'a' => ($f['active'] ?? true) ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE ingredient_units SET unit_code = :c, name = :n, to_base_factor = :f, kind = :k, is_default_count = :d, active = :a WHERE id = :u AND ingredient_id = :i')
        ->execute(['i' => $ingredientId, 'u' => $unitId, 'c' => $f['unit_code'], 'n' => $f['name'], 'f' => $f['to_base_factor'], 'k' => $f['kind'], 'd' => !empty($f['is_default_count']) ? 'true' : 'false', 'a' => ($f['active'] ?? true) ? 'true' : 'false']);
    return $unitId;
}

/** The per-site settings: before/after (the loggable columns). */
function save_site_settings(PDO $pdo, int $ingredientId, int $scopeId, array $f): array
{
    $st = $pdo->prepare('SELECT par_base, par_mode, reorder_min_base, service_level, preferred_supplier_item_id, default_area_id, active FROM ingredient_site_settings WHERE ingredient_id = :i AND scope_id = :s');
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    $before = $st->fetch() ?: [];
    $pdo->prepare('INSERT INTO ingredient_site_settings (ingredient_id, scope_id, par_base, par_mode, reorder_min_base, service_level, preferred_supplier_item_id, default_area_id, active)
        VALUES (:i, :s, :par, :mode, :min, :sl, :pref, :area, :active)
        ON CONFLICT (ingredient_id, scope_id) DO UPDATE SET par_base = EXCLUDED.par_base, par_mode = EXCLUDED.par_mode, reorder_min_base = EXCLUDED.reorder_min_base, service_level = EXCLUDED.service_level,
            preferred_supplier_item_id = EXCLUDED.preferred_supplier_item_id, default_area_id = EXCLUDED.default_area_id, active = EXCLUDED.active')
        ->execute(['i' => $ingredientId, 's' => $scopeId, 'par' => $f['par_base'], 'mode' => $f['par_mode'], 'min' => $f['reorder_min_base'], 'sl' => $f['service_level'], 'pref' => $f['preferred_supplier_item_id'], 'area' => $f['default_area_id'], 'active' => ($f['active'] ?? true) ? 'true' : 'false']);
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    return ['before' => $before, 'after' => $st->fetch() ?: []];
}

/** The site's cost override and its price-history row in one go; blank clears. */
function set_cost_override(PDO $pdo, int $ingredientId, int $scopeId, ?string $costPerBase, ?string $unitCode, ?string $perUnitPrice, ?string $reason, int $by): array
{
    $st = $pdo->prepare('SELECT cost_override_per_base FROM ingredient_site_settings WHERE ingredient_id = :i AND scope_id = :s');
    $st->execute(['i' => $ingredientId, 's' => $scopeId]);
    $before = $st->fetchColumn();
    $before = $before === false ? null : $before;
    $pdo->prepare('INSERT INTO ingredient_site_settings (ingredient_id, scope_id, cost_override_per_base) VALUES (:i, :s, :c) ON CONFLICT (ingredient_id, scope_id) DO UPDATE SET cost_override_per_base = EXCLUDED.cost_override_per_base')
        ->execute(['i' => $ingredientId, 's' => $scopeId, 'c' => $costPerBase]);
    if ($costPerBase !== null) {
        $pdo->prepare('INSERT INTO price_history (ingredient_id, scope_id, old_per_base, new_per_base, per_unit_code, per_unit_price, source_kind, reason, changed_by) VALUES (:i, :s, :o, :n, :u, :p, \'manual\', :r, :by)')
            ->execute(['i' => $ingredientId, 's' => $scopeId, 'o' => $before, 'n' => $costPerBase, 'u' => $unitCode, 'p' => $perUnitPrice, 'r' => $reason ?? 'cost override', 'by' => $by]);
    }
    return ['before' => ['cost_override_per_base' => $before], 'after' => ['cost_override_per_base' => $costPerBase]];
}

function add_yield_test(PDO $pdo, int $ingredientId, array $f, bool $apply, int $by): int
{
    $st = $pdo->prepare('INSERT INTO yield_tests (ingredient_id, ap_qty_base, ep_qty_base, trim_loss_pct, cook_loss_pct, byproducts, applied, notes, tested_by) VALUES (:i, :ap, :ep, :trim, :cook, :bp::jsonb, :applied, :notes, :by) RETURNING id, yield_pct');
    $st->execute(['i' => $ingredientId, 'ap' => $f['ap_qty_base'], 'ep' => $f['ep_qty_base'], 'trim' => $f['trim_loss_pct'], 'cook' => $f['cook_loss_pct'], 'bp' => json_encode($f['byproducts'] ?? []), 'applied' => $apply ? 'true' : 'false', 'notes' => $f['notes'], 'by' => $by]);
    $row = $st->fetch();
    if ($apply) {
        $pdo->prepare('UPDATE ingredients SET yield_pct = :y WHERE id = :i')->execute(['y' => $row['yield_pct'], 'i' => $ingredientId]);
    }
    return (int) $row['id'];
}

function save_supplier(PDO $pdo, ?int $id, array $f, int $by): int
{
    $cols = ['name', 'kind', 'contact_name', 'email', 'phone', 'address', 'notes', 'terms_days', 'lead_time_days', 'order_method', 'order_email', 'portal_url', 'min_order_value', 'allow_split_case', 'match_tolerance_pct', 'match_tolerance_value', 'account_number', 'rep_name', 'rep_phone', 'commissary_scope_id', 'active'];
    $args = [];
    foreach ($cols as $c) { $v = $f[$c] ?? null; $args[$c] = is_bool($v) ? ($v ? 'true' : 'false') : $v; }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO suppliers (' . implode(', ', $cols) . ', created_by) VALUES (:' . implode(', :', $cols) . ', :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE suppliers SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $cols)) . ' WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_supplier(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE suppliers SET active = NOT :a::boolean WHERE id = :id')->execute(['a' => $archived ? 'true' : 'false', 'id' => $id]);
}

function delete_supplier(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $id]);
}

function save_schedule(PDO $pdo, int $supplierId, int $scopeId, ?int $id, array $f): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO supplier_schedules (supplier_id, scope_id, order_dow, cutoff_time, delivery_dow, active) VALUES (:sup, :s, :od, :ct, :dd, :a) RETURNING id');
        $st->execute(['sup' => $supplierId, 's' => $scopeId, 'od' => $f['order_dow'], 'ct' => $f['cutoff_time'], 'dd' => $f['delivery_dow'], 'a' => ($f['active'] ?? true) ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE supplier_schedules SET order_dow = :od, cutoff_time = :ct, delivery_dow = :dd, active = :a WHERE id = :id AND supplier_id = :sup AND scope_id = :s')
        ->execute(['sup' => $supplierId, 's' => $scopeId, 'od' => $f['order_dow'], 'ct' => $f['cutoff_time'], 'dd' => $f['delivery_dow'], 'a' => ($f['active'] ?? true) ? 'true' : 'false', 'id' => $id]);
    return $id;
}

function remove_schedule(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('DELETE FROM supplier_schedules WHERE id = :id RETURNING supplier_id, scope_id, order_dow, cutoff_time::text, delivery_dow');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: [];
}

/** $f: ingredient_id, purchase_unit_code, supplier_sku, description, pack, last_price, contract_price, contract_until, moq, catch_weight, lead_time_days, active. A typed last price writes price history (scope NULL). */
function save_supplier_item(PDO $pdo, int $supplierId, ?int $id, array $f, bool $seesCost, int $by): int
{
    $cols = ['ingredient_id', 'purchase_unit_code', 'supplier_sku', 'description', 'pack', 'moq', 'catch_weight', 'lead_time_days', 'active'];
    if ($seesCost) { $cols = array_merge($cols, ['last_price', 'contract_price', 'contract_until']); }
    $args = [];
    foreach ($cols as $c) { $v = $f[$c] ?? null; $args[$c] = is_bool($v) ? ($v ? 'true' : 'false') : $v; }
    $oldPrice = null;
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO supplier_items (supplier_id, ' . implode(', ', $cols) . ($seesCost ? ', last_price_at' : '') . ') VALUES (:sup, :' . implode(', :', $cols) . ($seesCost ? ', CASE WHEN :lp::numeric IS NULL THEN NULL ELSE now() END' : '') . ') RETURNING id');
        $st->execute($args + ['sup' => $supplierId] + ($seesCost ? ['lp' => $f['last_price'] ?? null] : []));
        $id = (int) $st->fetchColumn();
    } else {
        if ($seesCost) { $q = $pdo->prepare('SELECT last_price FROM supplier_items WHERE id = :id'); $q->execute(['id' => $id]); $oldPrice = $q->fetchColumn(); $oldPrice = $oldPrice === false ? null : $oldPrice; }
        $pdo->prepare('UPDATE supplier_items SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $cols)) . ($seesCost ? ', last_price_at = CASE WHEN :lp::numeric IS DISTINCT FROM last_price THEN now() ELSE last_price_at END' : '') . ' WHERE id = :id AND supplier_id = :sup')
            ->execute($args + ['id' => $id, 'sup' => $supplierId] + ($seesCost ? ['lp' => $f['last_price'] ?? null] : []));
    }
    if ($seesCost && ($f['last_price'] ?? null) !== null && (string) $f['last_price'] !== (string) $oldPrice) {
        $st = $pdo->prepare('SELECT ri_supplier_item_cost_per_base(:id)');
        $st->execute(['id' => $id]);
        $perBase = $st->fetchColumn();
        if ($perBase !== null && $perBase !== false) {
            $st = $pdo->prepare('SELECT new_per_base FROM price_history WHERE ingredient_id = :i AND scope_id IS NULL ORDER BY changed_at DESC LIMIT 1');
            $st->execute(['i' => $f['ingredient_id']]);
            $prev = $st->fetchColumn();
            $pdo->prepare('INSERT INTO price_history (ingredient_id, scope_id, old_per_base, new_per_base, per_unit_code, per_unit_price, source_kind, reference_kind, reference_id, reason, changed_by) VALUES (:i, NULL, :o, :n, :u, :p, \'manual\', \'supplier_item\', :ref, \'supplier item saved\', :by)')
                ->execute(['i' => $f['ingredient_id'], 'o' => $prev === false ? null : $prev, 'n' => $perBase, 'u' => $f['purchase_unit_code'], 'p' => $f['last_price'], 'ref' => $id, 'by' => $by]);
        }
    }
    return $id;
}

function retire_supplier_item(PDO $pdo, int $id, bool $active): void
{
    $pdo->prepare('UPDATE supplier_items SET active = :a WHERE id = :id')->execute(['a' => $active ? 'true' : 'false', 'id' => $id]);
}

/** The guide at a site: made when absent; the lines replaced whole. $lines: [[supplier_item_id, seq, preferred]]. */
function set_order_guide(PDO $pdo, int $supplierId, int $scopeId, string $name, array $lines): int
{
    $st = $pdo->prepare('SELECT id FROM order_guides WHERE supplier_id = :sup AND scope_id = :s AND active ORDER BY id LIMIT 1');
    $st->execute(['sup' => $supplierId, 's' => $scopeId]);
    $gid = $st->fetchColumn();
    if ($gid === false) {
        $st = $pdo->prepare('INSERT INTO order_guides (supplier_id, scope_id, name) VALUES (:sup, :s, :n) RETURNING id');
        $st->execute(['sup' => $supplierId, 's' => $scopeId, 'n' => $name]);
        $gid = $st->fetchColumn();
    }
    $gid = (int) $gid;
    $pdo->prepare('DELETE FROM order_guide_lines WHERE guide_id = :g')->execute(['g' => $gid]);
    $ins = $pdo->prepare('INSERT INTO order_guide_lines (guide_id, supplier_item_id, seq, preferred) VALUES (:g, :si, :seq, :p) ON CONFLICT (guide_id, supplier_item_id) DO UPDATE SET seq = EXCLUDED.seq, preferred = EXCLUDED.preferred');
    foreach ($lines as $l) { $ins->execute(['g' => $gid, 'si' => $l['supplier_item_id'], 'seq' => $l['seq'], 'p' => $l['preferred'] ? 'true' : 'false']); }
    return $gid;
}

function save_area(PDO $pdo, int $scopeId, ?int $id, array $f): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO storage_areas (scope_id, name, kind, walk_order, allow_negative) VALUES (:s, :n, :k, :w, :neg) RETURNING id');
        $st->execute(['s' => $scopeId, 'n' => $f['name'], 'k' => $f['kind'], 'w' => $f['walk_order'], 'neg' => $f['allow_negative'] ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE storage_areas SET name = :n, kind = :k, walk_order = :w, allow_negative = :neg, active = :a WHERE id = :id AND scope_id = :s')
        ->execute(['s' => $scopeId, 'n' => $f['name'], 'k' => $f['kind'], 'w' => $f['walk_order'], 'neg' => $f['allow_negative'] ? 'true' : 'false', 'a' => ($f['active'] ?? true) ? 'true' : 'false', 'id' => $id]);
    return $id;
}

function archive_area(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE storage_areas SET active = NOT :a::boolean WHERE id = :id')->execute(['a' => $archived ? 'true' : 'false', 'id' => $id]);
}

function reorder_areas(PDO $pdo, int $scopeId, array $ids): void
{
    $st = $pdo->prepare('UPDATE storage_areas SET walk_order = :w WHERE id = :id AND scope_id = :s');
    foreach (array_values($ids) as $k => $id) { $st->execute(['w' => ($k + 1) * 10, 'id' => (int) $id, 's' => $scopeId]); }
}

function save_category(PDO $pdo, ?int $id, array $f): int
{
    $cols = ['key', 'name', 'kind', 'parent_id', 'safety_stock_pct', 'price_alert_pct', 'temperature_check', 'sort_order', 'active'];
    $args = [];
    foreach ($cols as $c) { $v = $f[$c] ?? null; $args[$c] = is_bool($v) ? ($v ? 'true' : 'false') : $v; }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO ingredient_categories (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ') RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE ingredient_categories SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $cols)) . ' WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function save_global_unit(PDO $pdo, ?string $code, array $f): string
{
    if ($code === null) {
        $pdo->prepare('INSERT INTO units (code, name, measure_class, to_base_factor, display_order, active) VALUES (:c, :n, :m, :f, :d, :a)')
            ->execute(['c' => $f['code'], 'n' => $f['name'], 'm' => $f['measure_class'], 'f' => $f['to_base_factor'], 'd' => $f['display_order'], 'a' => ($f['active'] ?? true) ? 'true' : 'false']);
        return (string) $f['code'];
    }
    $pdo->prepare('UPDATE units SET name = :n, display_order = :d, active = :a' . (isset($f['to_base_factor']) ? ', to_base_factor = :f' : '') . ' WHERE code = :c')
        ->execute(['c' => $code, 'n' => $f['name'], 'd' => $f['display_order'], 'a' => ($f['active'] ?? true) ? 'true' : 'false'] + (isset($f['to_base_factor']) ? ['f' => $f['to_base_factor']] : []));
    return $code;
}

function add_note(PDO $pdo, string $type, int $id, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:t, :id, :m, :b) RETURNING id');
    $st->execute(['t' => $type, 'id' => $id, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

function remove_note(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('DELETE FROM notes WHERE id = :id RETURNING record_type, record_id, member_id, length(body) AS length');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: [];
}

function remove_attachment(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT record_type, record_id, filename, mime_type, byte_size, uploaded_by FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch() ?: [];
    attachment_delete($pdo, $id);
    return $row;
}
