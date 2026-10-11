<?php
declare(strict_types=1);

/**
 * The periods' JSON presenters (periods.md "Files"): whitelists, never raw rows. A value the wall nulls is ABSENT from the answer with
 * `cost_withheld: true` beside it, so an agent never reads a null as zero.
 */

function period_status_chip(string $status, string $id = ''): string
{
    return chip($status, PERIOD_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

const PERIOD_FIGURE_KEYS = ['opening_value', 'purchases_value', 'ending_value', 'cogs_value', 'theoretical_value', 'waste_value', 'food_sales', 'food_cost_pct', 'theoretical_pct'];

function present_period(array $p, array $f, bool $sees): array
{
    $out = ['period_id' => $p['period_id'], 'scope_id' => $p['scope_id'], 'calendar_id' => $p['calendar_id'], 'calendar' => $p['calendar_name'] ?? null, 'no' => $p['no'], 'fiscal_year' => $p['fiscal_year'], 'label' => $p['label'],
        'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'], 'status' => $p['status'], 'opening_count_id' => $p['opening_count_id'], 'closing_count_id' => $p['closing_count_id'],
        'closed_at' => json_ts($p['closed_at'] ?? null), 'closed_by' => $p['closed_by'] ?? null, 'closed_by_name' => $p['closed_by_name'] ?? null, 'reopened_at' => json_ts($p['reopened_at'] ?? null), 'live' => $f['live'], 'begun' => $f['begun']];
    if ($sees) {
        foreach (PERIOD_FIGURE_KEYS as $k) { $out[$k] = $f[$k]; }
    } else {
        $out['cost_withheld'] = true;
    }
    return $out;
}

function present_calendar(array $c): array
{
    return ['calendar_id' => $c['calendar_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'year_start_on' => $c['year_start_on'], 'week_starts' => $c['week_starts'], 'week_starts_on' => WEEK_STARTS[$c['week_starts']] ?? null, 'is_default' => $c['is_default']];
}

function present_late_row(array $r, bool $sees): array
{
    $out = ['transaction_id' => $r['transaction_id'], 'ingredient_id' => $r['ingredient_id'], 'ingredient' => $r['ingredient'], 'type' => $r['txn_type'], 'qty_base' => $r['qty_base'], 'base_unit' => $r['base_unit_code'],
        'business_date' => $r['business_date'], 'dated' => $r['late']['dated'] ?? null, 'closed_period_no' => $r['late']['period_no'] ?? null, 'note' => $r['note']];
    if ($sees && $r['value'] !== null) { $out['value'] = $r['value']; } else { $out['cost_withheld'] = true; }
    return $out;
}

function present_period_count(array $c, bool $sees): array
{
    $out = ['count_id' => $c['count_id'], 'number' => $c['number'], 'kind' => $c['kind'], 'as_of' => json_ts($c['as_of']), 'status' => $c['status'], 'role' => $c['role']];
    if ($sees && $c['total_variance_value'] !== null) { $out['total_variance_value'] = $c['total_variance_value']; } else { $out['cost_withheld'] = true; }
    return $out;
}

function present_cogs_row(array $r, bool $sees): array
{
    $out = ['period_id' => $r['period_id'], 'no' => $r['no'], 'label' => $r['label'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'status' => $r['status'], 'live' => $r['live']];
    if ($sees) { $out += array_intersect_key($r, array_flip(['food_sales', 'cogs_value', 'food_cost_pct', 'theoretical_value', 'theoretical_pct', 'variance_value', 'variance_points', 'waste_value'])); } else { $out['cost_withheld'] = true; }
    return $out;
}
