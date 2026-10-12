<?php
declare(strict_types=1);

/**
 * The projection's presenters: chips for the screens and WHITELISTED JSON for the agents' and the command bar's reads (never a raw row). A value the wall nulls is ABSENT
 * from the answer with `cost_withheld: true` beside it, so an agent never reads a null as zero. The quantities ride as `_base` (the database's) and `_count` in `count_unit`.
 */

function forecast_source_chip(string $source, string $id = ''): string
{
    return chip(FORECAST_SOURCE_WORDS[$source] ?? $source, FORECAST_SOURCES[$source] ?? 'secondary', $id);
}

function proposal_chip(string $status, string $id = ''): string
{
    return chip($status, PROPOSAL_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

function watch_kind_chip(string $kind, string $id = ''): string
{
    return chip(WATCH_KINDS[$kind] ?? $kind, WATCH_KIND_CHIPS[$kind] ?? 'secondary', $id);
}

function par_mode_chip(string $mode, string $id = ''): string
{
    return chip($mode, PAR_MODE_CHIPS[$mode] ?? 'secondary', $id);
}

/** A quantity of menu items for a cell: whole numbers print whole, else one decimal. */
function fc_qty(string|float|int|null $q): string
{
    if ($q === null || $q === '') { return '—'; }
    $f = (float) $q;
    return abs($f - round($f)) < 0.05 ? (string) (int) round($f) : number_format($f, 1, '.', '');
}

function present_forecast_day(array $d): array
{
    return ['on_date' => $d['on_date'], 'qty' => $d['qty'], 'source' => $d['source']] + (array_key_exists('lo', $d) ? ['lo' => $d['lo'], 'hi' => $d['hi'], 'sigma' => $d['sigma'] ?? null, 'covers_factor' => $d['covers_factor'] ?? null] : []);
}

function present_run(?array $r): ?array
{
    return $r === null ? null : ['forecast_run_id' => $r['forecast_run_id'], 'method' => $r['method'], 'made_at' => json_ts($r['made_at']), 'horizon_days' => $r['horizon_days'], 'history_days' => $r['history_days'], 'items' => $r['items'], 'made_by' => $r['made_by_name'] ?? null];
}

function present_forecast_row(array $r): array
{
    return ['menu_item_id' => $r['menu_item_id'], 'plu' => $r['plu'], 'name' => $r['name'], 'category' => $r['category'], 'days' => array_map('present_forecast_day', $r['days']), 'total' => $r['total'], 'lo' => $r['lo'], 'hi' => $r['hi'],
            'mae' => $r['mae'], 'wape' => $r['wape'], 'bias' => $r['bias'], 'thin_history' => $r['thin']];
}

function present_forecast_list(array $l): array
{
    return ['horizon' => $l['horizon'], 'sort' => $l['sort'], 'run' => present_run($l['run']), 'method' => $l['run']['method'] ?? 'baseline', 'items' => array_map('present_forecast_row', $l['rows'])];
}

function present_override(array $o): array
{
    return ['override_id' => $o['override_id'], 'menu_item_id' => $o['menu_item_id'], 'menu_item' => $o['menu_item'] ?? null, 'from_date' => $o['from_date'], 'to_date' => $o['to_date'], 'factor' => $o['factor'], 'qty' => $o['qty'], 'reason' => $o['reason'],
            'status' => $o['status'], 'set_by' => $o['set_by_name'] ?? null, 'created_at' => json_ts($o['created_at'] ?? null)];
}

function present_item_forecast(array $f): array
{
    $m = $f['menu_item'];
    return ['menu_item' => ['menu_item_id' => $m['menu_item_id'], 'plu' => $m['plu'], 'name' => $m['name'], 'category' => $m['category']], 'run' => present_run($f['run']), 'days' => array_map('present_forecast_day', $f['days']),
            'accuracy' => $f['accuracy'] === null ? null : ['mae' => $f['accuracy']['mae'], 'wape' => $f['accuracy']['wape'], 'bias' => $f['accuracy']['bias'], 'n' => (int) $f['accuracy']['n']],
            'overrides' => array_map('present_override', $f['overrides']), 'proposed_overrides' => array_map('present_override', $f['proposed']), 'thin_history' => intdiv((int) $m['days_sold'], 7) < thin_after_weeks(db())];
}

function present_calendar(array $c): array
{
    return ['month' => $c['month'], 'synced_at' => json_ts($c['synced_at']), 'last_sync' => $c['last_sync'] === null ? null : ['at' => json_ts($c['last_sync']['at']), 'connected' => $c['last_sync']['connected']],
            'days' => array_map(static fn (array $d): array => ['on_date' => $d['on_date'], 'closed' => $d['closed'], 'holiday' => $d['holiday'], 'event' => $d['event'], 'note' => $d['note'], 'covers' => $d['covers'] === null ? null : (int) $d['covers'],
                'total' => $d['total'], 'total_kind' => $d['total_kind']], $c['days'])];
}

function present_demand(array $d): array
{
    return ['from' => $d['from'], 'to' => $d['to'], 'dates' => $d['dates'], 'ingredients' => array_map(static fn (array $r): array => ['ingredient_id' => $r['ingredient_id'], 'name' => $r['name'], 'code' => $r['code'], 'category' => $r['category'],
        'count_unit' => $r['count_unit'], 'base_unit' => $r['base_unit'], 'total_count' => $r['total_count'], 'total_base' => $r['total_base'],
        'days' => array_map(static fn (string $dt, ?array $v): array => ['on_date' => $dt, 'count' => $v['count'] ?? '0', 'base' => $v['base'] ?? '0'], $d['dates'], array_map(static fn (string $dt): ?array => $r['days'][$dt] ?? null, $d['dates']))], $d['rows'])];
}

function present_projected(array $p): array
{
    return ['days' => $p['days'], 'dates' => $p['dates'], 'ingredients' => array_map(static fn (array $r): array => ['ingredient_id' => $r['ingredient_id'], 'name' => $r['name'], 'code' => $r['code'], 'category' => $r['category'], 'count_unit' => $r['count_unit'], 'base_unit' => $r['base_unit'],
        'on_hand_base' => $r['on_hand_base'], 'on_hand_count' => $r['on_hand_count'], 'par_base' => $r['par_base'], 'par_mode' => $r['par_mode'], 'stockout_on' => $r['stockout_on'], 'stockout_in_days' => $r['stockout_in_days'],
        'projection' => array_map(static fn (array $d): array => ['on_date' => $d['on_date'], 'demand_base' => $d['demand_base'], 'receipts_base' => $d['receipts_base'], 'projected_base' => $d['projected_base'], 'projected_count' => $d['projected_count']], $r['days'])], $p['rows'])];
}

function present_projected_ingredient(array $p): array
{
    $i = $p['ingredient'];
    return ['ingredient' => ['ingredient_id' => $i['ingredient_id'], 'name' => $i['name'], 'code' => $i['code'], 'count_unit' => $i['count_unit'], 'base_unit' => $i['base_unit_code']], 'on_hand_base' => $p['on_hand_base'], 'stockout_on' => $p['stockout_on'],
        'par' => $p['par'] === null ? null : ['par_base' => $p['par']['par_base'], 'par_mode' => $p['par']['par_mode'], 'service_level' => $p['par']['service_level']],
        'days' => array_map(static fn (array $d): array => ['on_date' => $d['on_date'], 'demand_base' => $d['demand_base'], 'receipts_base' => $d['receipts_base'], 'projected_base' => $d['projected_base'], 'projected_count' => $d['projected_count']], $p['days']),
        'po_lines' => array_map(static fn (array $l): array => ['purchase_order_id' => (int) $l['purchase_order_id'], 'number' => $l['number'], 'supplier' => $l['supplier'], 'due_on' => $l['due_on'], 'qty_ordered' => $l['qty_ordered'], 'unit' => $l['purchase_unit_code'], 'qty_ordered_base' => $l['qty_ordered_base'], 'qty_received_base' => $l['qty_received_base']], $p['po_lines']),
        'drivers' => array_map(static fn (array $d): array => ['menu_item_id' => $d['menu_item_id'], 'name' => $d['name'], 'forecast_qty' => $d['forecast_qty'], 'qty_base' => $d['qty_base'], 'share_pct' => $d['share_pct']], $p['drivers'])];
}

/** A suggested-order card for JSON: every step of the rule on every line; cost nulled by the wall and flagged. */
function present_suggested_card(array $c): array
{
    $withheld = $c['lines'] !== [] && $c['lines'][0]['unit_cost'] === null;
    $lines = array_map(static function (array $l) use ($withheld): array {
        $o = ['supplier_item_id' => $l['supplier_item_id'], 'ingredient_id' => $l['ingredient_id'], 'ingredient' => $l['name'], 'sku' => $l['sku'], 'purchase_unit' => $l['purchase_unit'], 'par_mode' => $l['par_mode'], 'thin_history' => $l['thin_history'],
              'count_unit' => $l['count_unit'], 'base_unit' => $l['base_unit'], 'rounding_note' => $l['rounding_note'], 'qty_units' => $l['qty_units']];
        foreach (['demand', 'safety', 'need', 'have', 'on_hand', 'on_order', 'cap', 'raw'] as $k) { $o[$k . '_base'] = $l[$k . '_base']; $o[$k . '_count'] = $l[$k . '_count']; }
        if (!$withheld) { $o['unit_cost'] = $l['unit_cost']; $o['line_value'] = $l['line_value']; }
        return $o;
    }, $c['lines']);
    $o = ['supplier_id' => $c['supplier_id'], 'supplier' => $c['name'], 'cutoff_at' => json_ts($c['cutoff_at']), 'delivery_on' => $c['delivery_on'], 'next_delivery_on' => $c['next_delivery_on'], 'protection_days' => $c['protection_days'], 'scheduled' => $c['scheduled'],
          'lines' => $lines, 'lines_to_order' => $c['to_order'], 'drafted_today' => $c['drafted_today'] === null ? null : ['purchase_order_id' => $c['drafted_today']['purchase_order_id'], 'number' => $c['drafted_today']['number'], 'created_at' => json_ts($c['drafted_today']['created_at'])]];
    if ($withheld) { $o['cost_withheld'] = true; } else { $o['total'] = $c['total']; $o['min_order_value'] = $c['min_order_value']; $o['reaches_min'] = $c['reaches_min']; }
    return $o;
}

function present_special(array $s): array
{
    return ['recipe' => $s['recipe'] === null ? null : ['recipe_id' => (int) $s['recipe']['recipe_id'], 'name' => $s['recipe']['name']], 'date' => $s['date'], 'portions' => $s['portions'], 'verdict' => $s['verdict'], 'problem' => $s['problem'],
        'needs' => array_map(static fn (array $n): array => ['ingredient_id' => $n['ingredient_id'], 'ingredient' => $n['name'], 'count_unit' => $n['count_unit'], 'base_unit' => $n['base_unit'], 'needed_base' => $n['needed_base'], 'needed_count' => $n['needed_count'],
            'projected_base' => $n['projected_base'], 'projected_count' => $n['projected_count'], 'short_base' => $n['short_base'], 'short_count' => $n['short_count'], 'short' => $n['short'],
            'supplier' => $n['supplier'] === null ? null : ['supplier_id' => $n['supplier']['supplier_id'], 'name' => $n['supplier']['supplier'], 'supplier_item_id' => $n['supplier']['supplier_item_id'], 'cutoff_at' => json_ts($n['supplier']['cutoff_at']), 'delivery_on' => $n['supplier']['delivery_on'], 'units' => $n['supplier']['units'], 'unit' => $n['supplier']['unit']]], $s['needs'])];
}

function present_proposal(array $p): array
{
    return ['proposal_id' => $p['proposal_id'], 'kind' => $p['kind'], 'heading' => $p['heading'], 'subject_type' => $p['subject_type'], 'subject_id' => $p['subject_id'], 'title' => $p['title'], 'detail' => array_diff_key($p['detail'], ['request_id' => 1]),
            'drafted_record_type' => $p['drafted_record_type'], 'drafted_record_id' => $p['drafted_record_id'], 'status' => $p['status'], 'proposed_by' => $p['proposed_by_name'], 'note_date' => $p['note_date'], 'decided_by' => $p['decided_by_name'], 'decided_at' => json_ts($p['decided_at'])];
}

function present_watch(array $w): array
{
    return ['watch_id' => $w['watch_id'], 'ingredient_id' => $w['ingredient_id'], 'ingredient' => $w['ingredient'], 'kind' => $w['kind'], 'threshold' => $w['threshold'], 'threshold_recipe_unit' => $w['threshold_recipe_unit'], 'recipe_unit' => $w['recipe_unit_code'],
            'agent' => $w['agent'], 'text_me' => $w['text_me'], 'active' => $w['active'], 'fired_at' => json_ts($w['fired_at']), 'fire_count' => $w['fire_count'], 'note' => $w['note'], 'member' => $w['member']];
}

function present_note(array $n): array
{
    return ['site' => $n['site'], 'date' => $n['date'], 'headings' => array_map(static fn (array $h): array => ['key' => $h['key'], 'title' => $h['title'], 'count' => $h['count'], 'source' => $h['source'], 'lines' => array_map(static fn (array $l): array => ['text' => $l['text'], 'link' => $l['link']], $h['lines'])], array_values($n['headings']))];
}
