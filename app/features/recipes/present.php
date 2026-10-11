<?php
declare(strict_types=1);
/** JSON presenters (whitelists) for slice 2's screens — never a raw row; cost only when the caller sees it. */
function present_recipe_card(array $r): array
{
    return ['recipe_id' => (int) $r['recipe_id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'], 'category' => $r['category'],
            'output_ingredient_id' => $r['output_ingredient_id'] === null ? null : (int) $r['output_ingredient_id'], 'allergens' => $r['allergens'], 'active' => (bool) $r['active'],
            'version_in_force' => $r['current_version_id'] === null ? null : ['version_id' => (int) $r['current_version_id'], 'version_no' => (int) $r['version_no'], 'effective_from' => $r['effective_from']],
            'drafts' => (int) $r['drafts'], 'lines' => (int) $r['lines'], 'sub_recipes' => (int) $r['sub_recipes'], 'portion_cost' => $r['portion_cost'], 'menu_price' => $r['menu_price'], 'food_cost_pct' => $r['food_cost_pct']];
}
function present_version(array $v): array
{
    return ['version_id' => (int) $v['recipe_version_id'], 'recipe_id' => (int) $v['recipe_id'], 'version_no' => (int) $v['version_no'], 'status' => $v['status'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'],
            'batch_yield_qty' => $v['batch_yield_qty'], 'batch_yield_unit_code' => $v['batch_yield_unit_code'], 'expected_loss_pct' => $v['expected_loss_pct'], 'change_note' => $v['change_note'], 'activated_at' => $v['activated_at']];
}
function present_line(array $l, bool $withCost = false): array
{
    $out = ['line_id' => (int) $l['recipe_line_id'], 'seq' => (int) $l['seq'], 'ingredient_id' => $l['ingredient_id'] === null ? null : (int) $l['ingredient_id'], 'ingredient' => $l['ingredient_name'], 'sub_recipe_id' => $l['sub_recipe_id'] === null ? null : (int) $l['sub_recipe_id'], 'sub_recipe' => $l['sub_recipe_name'],
            'qty' => $l['qty'], 'unit_code' => $l['unit_code'], 'qty_base' => $l['qty_base'], 'base_display' => $l['base_display'], 'line_yield_pct' => $l['line_yield_pct'], 'group_name' => $l['group_name'], 'optional' => (bool) $l['optional'], 'notes' => $l['notes']];
    if ($withCost) { $out['line_cost'] = $l['line_cost'] ?? null; $out['cost_per_base'] = $l['cost_per_base'] ?? null; $out['share_pct'] = $l['share_pct'] ?? null; }
    return $out;
}
function present_recipe_full(array $full, bool $seesCost): array
{
    $r = $full['recipe'];
    return ['recipe' => ['recipe_id' => (int) $r['recipe_id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'], 'category' => $r['category'], 'output_ingredient_id' => $r['output_ingredient_id'] === null ? null : (int) $r['output_ingredient_id'], 'output_ingredient' => $r['output_ingredient'],
            'portion_qty' => $r['portion_qty'], 'portion_unit_code' => $r['portion_unit_code'], 'method' => $r['method'], 'allergens' => $r['allergens'], 'active' => (bool) $r['active'], 'photo_attachment_id' => $r['photo_attachment_id'] === null ? null : (int) $r['photo_attachment_id']],
            'versions' => array_map('present_version', $full['versions']), 'version' => $full['version'] ? present_version($full['version']) : null, 'as_of' => $full['as_of'],
            'lines' => array_map(static fn (array $l): array => present_line($l, $seesCost), $full['lines']),
            'cost' => $seesCost && $full['cost'] ? ['batch_cost' => $full['cost']['batch_cost'], 'portion_cost' => $full['cost']['portion_cost'], 'batch_yield_qty' => $full['cost']['batch_yield_qty'], 'uncosted' => (int) $full['cost']['uncosted']] : null,
            'leaves' => array_map(static fn (array $l): array => ['ingredient_id' => (int) $l['ingredient_id'], 'name' => $l['name'], 'qty_base' => $l['qty_base'], 'base_unit_code' => $l['base_unit_code']], $full['leaves']),
            'menu_items' => array_map(static fn (array $m): array => ['menu_item_id' => (int) $m['menu_item_id'], 'plu' => $m['plu'], 'name' => $m['name'], 'portions' => $m['portions'], 'price' => $m['price'], 'plate_cost' => $seesCost ? $m['plate_cost'] : null, 'food_cost_pct' => $seesCost ? $m['food_cost_pct'] : null], $full['menu_items']),
            'used_in' => array_map(static fn (array $u): array => ['recipe_id' => (int) $u['recipe_id'], 'name' => $u['name'], 'kind' => $u['kind'], 'version_no' => (int) $u['version_no'], 'status' => $u['status']], $full['used_in'])];
}
function present_menu_item(array $m, bool $seesCost = false, ?array $cost = null): array
{
    return ['menu_item_id' => (int) $m['menu_item_id'], 'plu' => $m['plu'], 'name' => $m['name'], 'source_id' => $m['pos_source_id'] === null ? null : (int) $m['pos_source_id'], 'source' => $m['source_name'] ?? null, 'category_id' => $m['category_id'] === null ? null : (int) $m['category_id'], 'category' => $m['category'] ?? null,
            'sale_price' => $m['sale_price'], 'price' => $m['price'] ?? null, 'recipe_id' => $m['recipe_id'] === null ? null : (int) $m['recipe_id'], 'recipe' => $m['recipe_name'] ?? null, 'portions' => $m['portions'], 'no_depletion' => (bool) $m['no_depletion'], 'active' => (bool) $m['active'], 'last_sold_at' => $m['last_sold_at'],
            'mapped_by' => $m['mapped_by'] === null ? null : (int) $m['mapped_by'], 'mapped_at' => $m['mapped_at'], 'cost' => $seesCost && $cost ? ['plate_cost' => $cost['plate_cost'], 'food_cost_pct' => $cost['food_cost_pct']] : null];
}
function present_modifier(array $m): array
{
    return ['modifier_id' => (int) $m['modifier_id'], 'code' => $m['code'], 'name' => $m['name'], 'source_id' => $m['pos_source_id'] === null ? null : (int) $m['pos_source_id'], 'source' => $m['source_name'] ?? null, 'action' => $m['action'], 'recipe_id' => $m['recipe_id'] === null ? null : (int) $m['recipe_id'], 'recipe' => $m['recipe_name'] ?? null, 'qty_factor' => $m['qty_factor'], 'per_item' => (int) ($m['per_item'] ?? 0)];
}
