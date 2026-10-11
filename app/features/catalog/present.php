<?php
declare(strict_types=1);
/** The catalog's presenters (whitelists for JSON) and chips. */

function present_ingredient(array $i): array
{
    return ['ingredient_id' => $i['ingredient_id'], 'code' => $i['code'], 'name' => $i['name'], 'category_id' => (int) $i['category_id'], 'category' => $i['category'] ?? null, 'measure_class' => $i['measure_class'], 'base_unit_code' => $i['base_unit_code'],
        'recipe_unit_code' => $i['recipe_unit_code'], 'density_g_per_ml' => $i['density_g_per_ml'], 'each_weight_g' => $i['each_weight_g'], 'each_volume_ml' => $i['each_volume_ml'], 'yield_pct' => $i['yield_pct'], 'catch_weight' => $i['catch_weight'],
        'shelf_life_days' => $i['shelf_life_days'], 'costing_method' => $i['costing_method'], 'variance_tolerance_pct' => $i['variance_tolerance_pct'], 'variance_tolerance_value' => $i['variance_tolerance_value'], 'allergens' => $i['allergens'],
        'is_prep' => $i['is_prep'], 'notes' => $i['notes'], 'active' => $i['active'], 'archived_at' => $i['archived_at'] ?? null, 'on_hand_base' => $i['on_hand_base'] ?? null, 'count_unit' => $i['count_unit'] ?? null, 'days_on_hand' => $i['days_on_hand'] ?? null,
        'avg_cost_per_base' => $i['avg_cost_per_base'] ?? null, 'cost_withheld' => array_key_exists('avg_cost_per_base', $i) && $i['avg_cost_per_base'] === null];
}

function present_supplier(array $s): array
{
    return ['supplier_id' => $s['supplier_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'contact_name' => $s['contact_name'], 'email' => $s['email'], 'phone' => $s['phone'], 'address' => $s['address'], 'order_method' => $s['order_method'], 'order_email' => $s['order_email'],
        'portal_url' => $s['portal_url'], 'terms_days' => $s['terms_days'] ?? null, 'lead_time_days' => $s['lead_time_days'], 'min_order_value' => $s['min_order_value'], 'allow_split_case' => $s['allow_split_case'], 'match_tolerance_pct' => $s['match_tolerance_pct'],
        'match_tolerance_value' => $s['match_tolerance_value'], 'account_number' => has_right('suppliers.write') ? ($s['account_number'] ?? null) : null, 'rep_name' => $s['rep_name'], 'rep_phone' => $s['rep_phone'], 'commissary_scope_id' => $s['commissary_scope_id'], 'notes' => $s['notes'], 'active' => $s['active'],
        'items' => isset($s['items']) ? (int) $s['items'] : null, 'open_pos' => isset($s['open_pos']) ? (int) $s['open_pos'] : null, 'next_window' => $s['next_window'] ?? null, 'has_schedule' => $s['has_schedule'] ?? null];
}

function present_supplier_item(array $si): array
{
    return ['supplier_item_id' => $si['supplier_item_id'], 'supplier_id' => $si['supplier_id'], 'supplier' => $si['supplier'] ?? null, 'ingredient_id' => $si['ingredient_id'], 'ingredient' => $si['ingredient'] ?? null, 'supplier_sku' => $si['supplier_sku'], 'description' => $si['description'],
        'purchase_unit_code' => $si['purchase_unit_code'], 'pack' => $si['pack'], 'last_price' => $si['last_price'], 'last_price_at' => $si['last_price_at'], 'contract_price' => $si['contract_price'], 'contract_until' => $si['contract_until'], 'moq' => $si['moq'],
        'catch_weight' => $si['catch_weight'], 'lead_time_days' => $si['lead_time_days'], 'active' => $si['active'], 'cost_per_base' => $si['cost_per_base'] ?? null, 'per_recipe_unit' => $si['per_recipe_unit'] ?? null, 'cheapest' => $si['cheapest'] ?? null, 'cost_withheld' => !sees_cost()];
}

function present_area(array $a): array
{
    return ['storage_area_id' => $a['storage_area_id'], 'scope_id' => $a['scope_id'], 'name' => $a['name'], 'kind' => $a['kind'], 'walk_order' => (int) $a['walk_order'], 'allow_negative' => $a['allow_negative'], 'active' => $a['active'], 'items_on_hand' => (int) ($a['items_on_hand'] ?? 0)];
}

function class_icon(string $class): string { return CLASS_ICON[$class] ?? 'feather-box'; }

function chip(string $words, string $kind = 'secondary', string $id = ''): string
{
    return '<span class="badge bg-soft-' . e($kind) . ' text-' . ($kind === 'light' ? 'dark' : e($kind)) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($words) . '</span>';
}

function supplier_kind_chip(string $kind): string
{
    return chip(SUPPLIER_KINDS[$kind] ?? $kind, ['distributor' => 'primary', 'producer' => 'success', 'wholesaler' => 'info', 'retailer' => 'secondary', 'commissary' => 'warning', 'other' => 'light'][$kind] ?? 'secondary');
}

function order_method_chip(string $m): string
{
    return chip(ORDER_METHODS[$m] ?? $m, ['email' => 'info', 'portal' => 'primary', 'phone' => 'secondary', 'api' => 'dark', 'edi' => 'dark'][$m] ?? 'secondary');
}

function unit_kind_chip(string $k): string
{
    return chip($k, ['purchase' => 'primary', 'count' => 'secondary', 'recipe' => 'info', 'global' => 'light'][$k] ?? 'light');
}

/** "Sat 13:00 → Tue" from a window row. */
function window_words(?array $w): string
{
    if ($w === null || empty($w['cutoff_at'])) { return 'no schedule here'; }
    $tz = site_timezone();
    return format_ts($w['cutoff_at'], $tz, 'D H:i') . ' → ' . date('D', strtotime((string) $w['delivery_on']));
}
