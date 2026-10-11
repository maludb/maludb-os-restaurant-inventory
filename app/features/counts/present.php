<?php
declare(strict_types=1);

/**
 * The counts' JSON presenters (counts.md "Files"): whitelists, never raw rows. A figure the lines view nulls — the book figure and the
 * variance on a blind open count for a counter, a value for one who sees no cost — is ABSENT from the answer (with `book_hidden` /
 * `cost_withheld` saying so), so an agent's answer never carries a null that reads as zero. Chips and words are the screens'.
 */

function count_status_chip(string $status, string $id = ''): string
{
    return chip(count_status_words($status), COUNT_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

function count_line_chip(string $state, string $id = ''): string
{
    return chip(COUNT_LINE_WORDS[$state] ?? $state, COUNT_LINE_CHIPS[$state] ?? 'secondary', $id);
}

function sheet_kind_chip(string $kind): string
{
    return chip(COUNT_KINDS[$kind] ?? $kind, SHEET_KIND_CHIPS[$kind] ?? 'secondary');
}

function present_count(array $c, bool $sees): array
{
    $out = ['count_id' => $c['count_id'], 'number' => $c['number'], 'kind' => $c['kind'], 'status' => $c['status'], 'as_of' => json_ts($c['as_of']), 'blind' => $c['blind'], 'sheet_id' => $c['sheet_id'], 'sheet' => $c['sheet_name'] ?? null,
        'notes' => $c['notes'], 'started_by' => $c['started_by'], 'started_by_name' => $c['started_by_name'] ?? null, 'started_at' => json_ts($c['started_at']),
        'approved_by' => $c['approved_by'], 'approved_by_name' => $c['approved_by_name'] ?? null, 'approved_at' => json_ts($c['approved_at']),
        'lines' => $c['lines'] ?? null, 'lines_done' => $c['lines_done'] ?? null, 'lines_pending' => $c['lines_pending'] ?? null, 'lines_skipped' => $c['lines_skipped'] ?? null, 'over_tolerance' => $c['lines_over'] ?? null];
    if ($sees && $c['total_variance_value'] !== null) { $out['total_variance_value'] = $c['total_variance_value']; } else { $out['cost_withheld'] = true; }
    return $out;
}

/** A count line: the counted figure always, the book figure and variance only where the view let them through. */
function present_count_line(array $l): array
{
    $out = ['count_line_id' => $l['count_line_id'], 'count_id' => $l['count_id'], 'storage_area_id' => $l['storage_area_id'], 'area' => $l['area'], 'ingredient_id' => $l['ingredient_id'], 'code' => $l['code'], 'name' => $l['name'],
        'shelf_order' => $l['shelf_order'], 'count_unit' => $l['count_unit'], 'base_unit' => $l['base_unit_code'], 'state' => $l['state'], 'entries' => $l['entries'], 'gross_weight_g' => $l['gross_weight_g'], 'container_id' => $l['container_id'],
        'counted_qty_base' => $l['counted_qty_base'], 'counted_qty_count' => $l['counted_qty_count'], 'recount_qty_base' => $l['recount_qty_base'], 'recount_by' => $l['recount_by'], 'counted_by' => $l['counted_by'],
        'counted_at' => json_ts($l['counted_at']), 'over_tolerance' => $l['over_tolerance'], 'note' => $l['note']];
    if ($l['system_qty_base'] !== null) {
        $out += ['system_qty_base' => $l['system_qty_base'], 'system_qty_count' => $l['system_qty_count'], 'variance_base' => $l['variance_base'], 'variance_qty_count' => $l['variance_qty_count']];
        if ($l['variance_value'] !== null) { $out['variance_value'] = $l['variance_value']; } elseif ($l['variance_base'] !== null) { $out['cost_withheld'] = true; }
    } else {
        $out['book_hidden'] = true;
    }
    return $out;
}

function present_correction(array $t, bool $sees): array
{
    $out = ['transaction_id' => $t['transaction_id'], 'ingredient_id' => $t['ingredient_id'], 'ingredient' => $t['ingredient'], 'storage_area_id' => $t['storage_area_id'], 'area' => $t['area'], 'qty_base' => $t['qty_base'],
        'base_unit' => $t['base_unit_code'], 'qty_count' => $t['qty_count'], 'count_unit' => $t['count_unit'], 'occurred_at' => json_ts($t['occurred_at'])];
    if ($sees && $t['value'] !== null) { $out['value'] = $t['value']; $out['unit_cost_per_base'] = $t['unit_cost_per_base']; } else { $out['cost_withheld'] = true; }
    return $out;
}

function present_sheet(array $s, ?array $areas = null): array
{
    $out = ['count_sheet_id' => $s['count_sheet_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'active' => $s['active'], 'lines' => $s['lines'] ?? null, 'areas' => $s['areas'] ?? null,
        'last_count_id' => $s['last_count_id'] ?? null, 'last_count_number' => $s['last_count_number'] ?? null];
    if ($areas !== null) {
        $out['areas'] = array_map(static fn (array $a): array => ['storage_area_id' => $a['storage_area_id'], 'name' => $a['name'], 'lines' => array_map(static fn (array $l): array => [
            'sheet_line_id' => $l['sheet_line_id'], 'ingredient_id' => $l['ingredient_id'], 'name' => $l['name'], 'shelf_order' => $l['shelf_order'], 'count_unit' => $l['count_unit_code'] ?? $l['default_count_unit'] ?? $l['base_unit_code']], $a['lines'])], $areas);
    }
    return $out;
}

function present_container(array $c): array
{
    return ['container_id' => $c['container_id'], 'ingredient_id' => $c['ingredient_id'], 'ingredient' => $c['ingredient'] ?? null, 'name' => $c['name'], 'full_weight_g' => $c['full_weight_g'], 'empty_weight_g' => $c['empty_weight_g'],
        'pourer_tare_g' => $c['pourer_tare_g'], 'volume_ml' => $c['volume_ml'], 'is_default' => $c['is_default'], 'active' => $c['active']];
}
