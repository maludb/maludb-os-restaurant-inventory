<?php
declare(strict_types=1);

/**
 * The usage report's JSON presenters: whitelists. Every row answers each bucket twice — `<bucket>_base` (the function's figure, in the ingredient's base
 * unit) and `<bucket>` (the same in its count unit, `count_unit`) — and the value columns only where the caller sees cost (`cost_withheld` otherwise).
 */

function present_usage_row(array $r, bool $sees): array
{
    $out = ['ingredient_id' => $r['ingredient_id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'], 'category_key' => $r['category_key'], 'base_unit' => $r['base_unit_code'], 'count_unit' => $r['count_unit']];
    foreach (USAGE_BUCKETS as $b) { $out[$b . '_base'] = $r[$b . '_base']; $out[$b] = $r[$b . '_count']; }
    if ($sees) {
        $out['unit_cost_per_base'] = $r['unit_cost'];
        $out['unit_cost_per_count_unit'] = $r['unit_cost_count'] === null ? null : number_format((float) $r['unit_cost_count'], 4, '.', '');
        foreach (USAGE_VALUE_COLS as $v) { $out[$v] = $r[$v]; }
    } else {
        $out['cost_withheld'] = true;
    }
    return $out;
}

function present_usage(array $u): array
{
    $sees = !$u['withheld'];
    $cat = static fn (array $c): array => ['category' => $c['category'], 'category_key' => $c['category_key'], 'ingredients' => $c['ingredients']] + ($sees ? array_intersect_key($c, array_flip(USAGE_VALUE_COLS)) : ['cost_withheld' => true]);
    return ['from' => $u['from'], 'to' => $u['to'], 'basis' => $u['basis'], 'sort' => $u['sort'], 'rows' => array_map(static fn (array $r): array => present_usage_row($r, $sees), $u['rows']),
        'categories' => array_map($cat, $u['categories']), 'totals' => ['ingredients' => $u['totals']['ingredients']] + ($sees ? array_intersect_key($u['totals'], array_flip(USAGE_VALUE_COLS)) : ['cost_withheld' => true])];
}

function present_consumer(array $c): array
{
    return ['recipe_id' => $c['recipe_id'], 'recipe' => $c['recipe'], 'code' => $c['code'], 'kind' => $c['kind'], 'qty_base' => $c['qty_base'], 'share_pct' => $c['share_pct']];
}

function present_usage_ingredient(array $d): array
{
    $sees = !$d['withheld'];
    $i = $d['ingredient'];
    return ['ingredient' => ['ingredient_id' => $i['ingredient_id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit_code']],
        'row' => $d['row'] === null ? null : present_usage_row($d['row'], $sees), 'consumers' => array_map('present_consumer', $d['consumers']),
        'waste' => array_map(static fn (array $w): array => ['waste_log_id' => $w['waste_log_id'], 'number' => $w['number'], 'business_date' => $w['business_date'], 'qty_base' => $w['qty_base'], 'reason' => $w['reason'], 'stage' => $w['stage']] + ($sees && $w['cost'] !== null ? ['cost' => $w['cost']] : ['cost_withheld' => true]), $d['waste']),
        'count_lines' => array_map(static fn (array $l): array => ['count_line_id' => $l['count_line_id'], 'count_id' => $l['count_id'], 'number' => $l['number'], 'as_of' => json_ts($l['as_of']), 'state' => $l['state'], 'system_qty_base' => $l['system_qty_base'], 'counted_qty_base' => $l['counted_qty_base'], 'variance_base' => $l['variance_base']]
            + ($sees && $l['variance_value'] !== null ? ['variance_value' => $l['variance_value']] : ['cost_withheld' => true]), $d['count_lines']),
        'movements' => array_map(static fn (array $m): array => present_movement($m, $sees), $d['movements']['rows']), 'movements_total' => $d['movements']['total']]
        + ($sees ? ['cost_history' => array_map(static fn (array $h): array => ['changed_at' => json_ts($h['changed_at']), 'new_per_base' => $h['new_per_base'], 'source' => $h['source_kind']], $d['cost_history'])] : ['cost_withheld' => true]);
}
