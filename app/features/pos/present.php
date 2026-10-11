<?php
declare(strict_types=1);

/**
 * The JSON shapes of the POS screens (whitelists — never a raw row): a source (its settings for an admin only, never the credential's
 * secret — its label and last four for an admin), a pull, a day, a run (its value only where the caller sees cost — the views null it),
 * a check with its lines.
 */

function present_source(array $s, bool $admin): array
{
    $h = $s['health'] ?? null;
    return ['source_id' => (int) $s['source_id'], 'scope_id' => (int) $s['scope_id'], 'name' => (string) $s['name'], 'connector' => (string) $s['connector'],
            'intake' => (string) ($s['intake'] ?? ''), 'schedule_minutes' => (int) $s['schedule_minutes'], 'state' => source_state($s),
            'last_ok_at' => json_ts($s['last_ok_at'] ?? null), 'consecutive_failures' => (int) ($s['consecutive_failures'] ?? 0), 'backoff_until' => json_ts($s['backoff_until'] ?? null),
            'paused_at' => json_ts($s['paused_at'] ?? null), 'paused_reason' => $s['paused_reason'] ?? null,
            'settings' => $admin && isset($s['settings']) && is_array($s['settings']) ? $s['settings'] : null,
            'has_credential' => ($s['credential_id'] ?? null) !== null,
            'credential' => $admin && !empty($s['credentials']) ? ['kind' => $s['credentials'][0]['kind'], 'label' => $s['credentials'][0]['label'], 'last4' => $s['credentials'][0]['last4'], 'rotated_at' => json_ts($s['credentials'][0]['rotated_at'])] : null,
            'health' => $h === null ? null : ['days_missing' => $h['days_missing'], 'unmapped_plus' => (int) $h['unmapped_plus'], 'unmapped_modifiers' => (int) $h['unmapped_modifiers'], 'pending_runs' => (int) $h['pending_runs']]];
}

/** active / paused / backing off. */
function source_state(array $s): string
{
    if (($s['paused_at'] ?? null) !== null) {
        return 'paused';
    }
    if (($s['backoff_until'] ?? null) !== null && strtotime((string) $s['backoff_until']) > time()) {
        return 'backing off';
    }
    return 'active';
}

function present_pull(array $p): array
{
    return ['pull_id' => (int) $p['pull_id'], 'kind' => (string) $p['kind'], 'status' => (string) $p['status'], 'started_at' => json_ts($p['started_at']), 'finished_at' => json_ts($p['finished_at']),
            'file_name' => $p['file_name'], 'attachment_id' => $p['attachment_id'] === null ? null : (int) $p['attachment_id'], 'dates_seen' => $p['dates_seen'],
            'sales_seen' => (int) $p['sales_seen'], 'sales_new' => (int) $p['sales_new'], 'lines_seen' => (int) $p['lines_seen'], 'plus_new' => (int) $p['plus_new'], 'error' => $p['error'],
            'by' => $p['started_by_name'] ?? ($p['started_by'] === null ? 'worker' : null)];
}

function present_day(array $d): array
{
    return ['business_date' => (string) $d['business_date'], 'checks' => (int) $d['checks'], 'covers' => (int) $d['covers'], 'net' => round((float) $d['net'], 2),
            'comps' => round((float) $d['comps'], 2), 'voids' => round((float) $d['voids'], 2), 'due' => (bool) ($d['due'] ?? false),
            'run' => ($d['run_id'] ?? null) === null ? null : ['run_id' => (int) $d['run_id'], 'status' => (string) $d['run_status'], 'run_no' => (int) $d['run_no'],
                     'lines_unmapped' => (int) $d['lines_unmapped'], 'modifiers_unmapped' => (int) $d['modifiers_unmapped'], 'value' => $d['run_value'] === null ? null : (float) $d['run_value']]];
}

function present_run(array $r): array
{
    return ['run_id' => (int) $r['depletion_run_id'], 'scope_id' => (int) $r['scope_id'], 'business_date' => (string) $r['business_date'], 'status' => (string) $r['status'], 'run_no' => (int) $r['run_no'],
            'lines_total' => (int) $r['lines_total'], 'lines_depleted' => (int) $r['lines_depleted'], 'lines_unmapped' => (int) $r['lines_unmapped'], 'lines_voided' => (int) $r['lines_voided'],
            'modifiers_unmapped' => (int) $r['modifiers_unmapped'], 'ingredients' => (int) $r['ingredients'], 'value' => $r['value'] === null ? null : (float) $r['value'],
            'started_at' => json_ts($r['started_at']), 'finished_at' => json_ts($r['finished_at']), 'by' => $r['started_by'] === null ? 'worker' : ($r['started_by_name'] ?? null), 'note' => $r['note']];
}

function present_check(array $c, array $lines = []): array
{
    return ['sale_id' => (int) $c['sale_id'], 'external_id' => (string) $c['external_id'], 'business_date' => (string) $c['business_date'], 'closed_at' => json_ts($c['closed_at']),
            'day_part' => $c['day_part'] ?? null, 'covers' => $c['covers'] === null ? null : (int) $c['covers'], 'subtotal' => $c['subtotal'] === null ? null : (float) $c['subtotal'],
            'comps' => (float) $c['comps'], 'voids' => (float) $c['voids'], 'source' => $c['source_name'] ?? null,
            'lines' => array_map(static fn (array $l): array => ['plu' => (string) $l['plu'], 'name' => $l['name'], 'qty' => (float) $l['qty'], 'unit_price' => $l['unit_price'] === null ? null : (float) $l['unit_price'],
                'line_total' => $l['line_total'] === null ? null : (float) $l['line_total'], 'voided' => (bool) $l['voided'], 'comp' => (bool) $l['comp'], 'menu_item_id' => $l['menu_item_id'] === null ? null : (int) $l['menu_item_id'],
                'mapped' => $l['recipe_id'] !== null, 'modifiers' => $l['modifiers']], $lines)];
}

/** The chip class of a status. */
function pos_chip(?string $status): string
{
    return match ($status) {
        'ok', 'done', 'active' => 'bg-soft-success text-success',
        'partial', 'backing off' => 'bg-soft-warning text-warning',
        'failed', 'void' => 'bg-soft-danger text-danger',
        'paused' => 'bg-dark text-white',
        'running', 'pending' => 'bg-soft-info text-info',
        default => 'bg-soft-secondary text-secondary',
    };
}

function schedule_label(int $minutes): string
{
    if ($minutes <= 0) {
        return 'on arrival';
    }
    return $minutes % 60 === 0 ? 'every ' . ($minutes === 60 ? 'hour' : ($minutes / 60) . ' hours') : 'every ' . $minutes . ' min';
}
