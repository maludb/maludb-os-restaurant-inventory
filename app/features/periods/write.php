<?php
declare(strict_types=1);

/**
 * The periods' writes (periods.md "Query functions" — Writes). Each is one schema verb (ri_periods_ensure, ri_period_close, ri_period_reopen) or one
 * statement on the calendars; the caller (a handler) opens the transaction inside ri_guard() and logs. Closing freezes figures the ledger already
 * holds and posts no movement; the verbs' own refusals (a period closes after its last day, in order, with every day depleted, with an approved
 * count) are the sentences a person reads.
 */

/** A calendar saved ($fields: name, kind, year_start_on, week_starts, is_default); one default — choosing it clears the previous in the same transaction. Returns its id. */
function save_calendar(PDO $pdo, ?int $id, array $fields): int
{
    if ($fields['is_default']) {
        $pdo->prepare('UPDATE costing_calendars SET is_default = false WHERE is_default AND (CAST(:id AS bigint) IS NULL OR id <> :id2)')->execute(['id' => $id, 'id2' => $id ?? 0]);
    }
    $args = ['n' => $fields['name'], 'k' => $fields['kind'], 'y' => $fields['year_start_on'], 'w' => $fields['week_starts'], 'd' => $fields['is_default'] ? 't' : 'f'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO costing_calendars (name, kind, year_start_on, week_starts, is_default) VALUES (:n, :k, :y, :w, :d) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE costing_calendars SET name = :n, kind = :k, year_start_on = :y, week_starts = :w, is_default = :d WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** Make (or find) the periods of a fiscal year at a site; the number of periods made (0 when they exist). */
function ensure_periods(PDO $pdo, int $scopeId, int $fiscalYear, ?int $calendarId): int
{
    $st = $pdo->prepare('SELECT ri_periods_ensure(:s, :y, CAST(:c AS bigint))');
    $st->execute(['s' => $scopeId, 'y' => $fiscalYear, 'c' => $calendarId]);
    return (int) $st->fetchColumn();
}

/** Close a period ($closingCountId: an approved count, or none). Returns the closed period as the function froze it. */
function close_period(PDO $pdo, int $periodId, int $by, ?int $closingCountId): array
{
    $st = $pdo->prepare('SELECT * FROM ri_period_close(:p, :by, CAST(:c AS bigint))');
    $st->execute(['p' => $periodId, 'by' => $by, 'c' => $closingCountId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/** Reopen the last closed period; its figures are live again. Returns the period. */
function reopen_period(PDO $pdo, int $periodId, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT * FROM ri_period_reopen(:p, :by)');
    $st->execute(['p' => $periodId, 'by' => $by]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/**
 * The worker's check (periods.md "Notifications"): an open period more than three days past its end — the site's Manager (else the business's, design D14)
 * is told once, `period_due`, naming the period. Returns the notifications made. Called by slice 9's morning_note_inputs pass.
 */
function period_due_check(PDO $pdo): int
{
    $made = 0;
    $rows = $pdo->query("SELECT p.id, p.scope_id, p.no, p.starts_on, p.ends_on, c.kind, COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) AS manager
          FROM periods p JOIN costing_calendars c ON c.id = p.calendar_id LEFT JOIN site_settings ss ON ss.scope_id = p.scope_id
         WHERE p.status = 'open' AND p.ends_on + 3 < current_date
           AND NOT EXISTS (SELECT 1 FROM periods e WHERE e.scope_id = p.scope_id AND e.calendar_id = p.calendar_id AND e.status = 'open' AND e.ends_on < p.ends_on)
         ORDER BY p.scope_id, p.starts_on")->fetchAll();
    foreach ($rows as $r) {
        if ($r['manager'] === null) { continue; }
        $label = period_label(['no' => $r['no'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'calendar_kind' => $r['kind']]);
        $exists = one_value($pdo, "SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'period_due' AND record_type = 'period' AND record_id = :p", ['m' => $r['manager'], 'p' => $r['id']]);
        if ($exists !== null) { continue; }
        $pdo->prepare("SELECT ri_notify(:m, 'period_due', 'period', :p, :t, :b, :d, false, :s)")
            ->execute(['m' => $r['manager'], 'p' => $r['id'], 't' => mb_substr(ucfirst($label) . ' has not been closed', 0, 200), 'b' => ucfirst($label) . ' ended ' . format_date($r['ends_on']) . ' and is still open. Close it from Periods.', 'd' => 'period:' . $r['id'] . ':due', 's' => $r['scope_id']]);
        $made++;
    }
    return $made;
}
