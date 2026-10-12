<?php
declare(strict_types=1);

/**
 * The Purchasing agent's proposals and the morning note's send (projection.md "Posting" — Proposals, The morning note). The agent PROPOSES and DRAFTS; a person decides a
 * proposal and a person sends the purchase order it drafted (slice 4's `purchase_order_send`, money_out). send_morning_note() logs its own `buyer.note` row (the handler, the
 * agent's duty and slice 9's morning_note_inputs pass all call it, and only the function knows whether a note went).
 */
require_once __DIR__ . '/note.php';
require_once dirname(__DIR__) . '/projection/write.php';
require_once dirname(__DIR__) . '/ordering/write.php';

/** The site's own date at a moment (default now) — the day a proposal and a note belong to. */
function site_date(PDO $pdo, int $scopeId, ?DateTimeInterface $at = null): string
{
    return (string) one_value($pdo, 'SELECT (CAST(:t AS timestamptz) AT TIME ZONE s.timezone)::date FROM sites s WHERE s.scope_id = :s', ['t' => ($at ?? new DateTimeImmutable())->format(DATE_ATOM), 's' => $scopeId]);
}

/**
 * A proposal. $fields: kind, subject_type, subject_id, title, detail (array), drafted_record_type, drafted_record_id, note_date (the site's today by default). $by is the
 * member proposing (the agent; null for the worker). The kernel's request id rides in detail.request_id (not shown). Returns the id.
 */
function propose(PDO $pdo, int $scopeId, array $fields, ?int $by): int
{
    $detail = is_array($fields['detail'] ?? null) ? $fields['detail'] : [];
    $rid = agent_run_request_id();
    if ($rid !== null) { $detail['request_id'] = $rid; }
    $st = $pdo->prepare('INSERT INTO buyer_proposals (scope_id, kind, subject_type, subject_id, title, detail, drafted_record_type, drafted_record_id, proposed_by, note_date)
                         VALUES (:s, :k, :st, :si, :t, CAST(:d AS jsonb), :dt, :di, :by, CAST(:nd AS date)) RETURNING id');
    $st->execute(['s' => $scopeId, 'k' => $fields['kind'], 'st' => $fields['subject_type'], 'si' => $fields['subject_id'], 't' => $fields['title'], 'd' => json_encode($detail === [] ? new stdClass() : $detail, JSON_UNESCAPED_SLASHES),
                  'dt' => $fields['drafted_record_type'] ?? null, 'di' => $fields['drafted_record_id'] ?? null, 'by' => $by, 'nd' => $fields['note_date'] ?? site_date($pdo, $scopeId)]);
    return (int) $st->fetchColumn();
}

/**
 * A person's decision on a proposal ('accept' or 'dismiss'). Accepting a forecast proposal that drafted an override sets it; dismissing it dismisses the override. Returns
 * ['proposal' => the row as it stands, 'location' => where a person should go next (the drafted order, for an accepted order proposal), 'override' => the override or null].
 */
function decide_proposal(PDO $pdo, int $id, string $decision, int $by): array
{
    $new = $decision === 'accept' ? 'accepted' : 'dismissed';
    $st = $pdo->prepare("UPDATE buyer_proposals SET status = :n, decided_by = :by, decided_at = now() WHERE id = :id AND status = 'proposed' RETURNING id");
    $st->execute(['n' => $new, 'by' => $by, 'id' => $id]);
    if ($st->fetchColumn() === false) { throw new DomainException('That proposal was already decided.'); }
    $p = find_proposal($pdo, $id);
    $override = null;
    if ($p['kind'] === 'forecast' && $p['drafted_record_type'] === 'forecast_override' && $p['drafted_record_id'] !== null) {
        $cur = proj_row($pdo, 'SELECT status FROM forecast_overrides WHERE id = :id', ['id' => $p['drafted_record_id']]);
        if ($cur !== null && $cur['status'] === 'proposed') { $override = decide_override($pdo, $p['drafted_record_id'], $new === 'accepted' ? 'set' : 'dismiss', $by); }
    }
    $location = $new === 'accepted' && $p['drafted_record_type'] === 'purchase_order' && $p['drafted_record_id'] !== null ? record_url('purchase_order', $p['drafted_record_id']) : null;
    return ['proposal' => $p, 'location' => $location, 'override' => $override];
}

/** Who gets the note: the site's Manager, else the business's, else every member holding purchasing.send at the site, else the first super-admin. */
function note_recipients(PDO $pdo, int $scopeId): array
{
    $m = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $scopeId]);
    if ($m !== null) { return [(int) $m]; }
    $st = $pdo->prepare("SELECT m.id FROM members m WHERE m.status = 'active' AND m.member_kind = 'human' AND ri_member_has_right(m.id, 'purchasing.send', :s) ORDER BY m.id");
    $st->execute(['s' => $scopeId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) { return $ids; }
    $a = one_value($pdo, "SELECT id FROM members WHERE status = 'active' AND member_kind = 'human' AND business_role = 'super_admin' ORDER BY id LIMIT 1");
    return $a === null ? [] : [(int) $a];
}

/**
 * Send the day's morning note once: assemble it, render it per recipient (a value reads "—" for one who sees no cost) and notify them (`morning_note`, the bell and the mail
 * copy). A second send the same day for the site answers sent=false with the earlier time and notifies no one (DECISION 8). Logs `buyer.note` (date, headings counts,
 * recipients, proposals). Returns ['sent', 'already' => ts|null, 'recipients' => ids, 'headings' => key => count, 'proposals', 'markdown' (the first recipient's)].
 */
function send_morning_note(PDO $pdo, int $scopeId, string $date, ?int $by): array
{
    $prior = proj_row($pdo, "SELECT occurred_at FROM activity_log WHERE action = 'buyer.note' AND scope_id = :s AND after->>'date' = :d AND (after->>'sent')::boolean ORDER BY id DESC LIMIT 1", ['s' => $scopeId, 'd' => $date]);
    if ($prior !== null) { return ['sent' => false, 'already' => $prior['occurred_at'], 'recipients' => [], 'headings' => [], 'proposals' => 0, 'markdown' => null]; }
    $note = morning_note_assemble($pdo, $scopeId, $date);
    $recipients = note_recipients($pdo, $scopeId);
    $counts = array_map(static fn (array $h): int => $h['count'], $note['headings']);
    $md = null;
    foreach ($recipients as $r) {
        $text = morning_note_markdown($note, member_sees_cost($pdo, $r, $scopeId));
        $md ??= $text;
        $pdo->prepare("SELECT ri_notify(:m, 'morning_note', 'buyer_note', :s, :t, :b, NULL, false, :s2)")->execute([
            'm' => $r, 's' => $scopeId, 't' => mb_substr('Morning note — ' . $note['site'] . ', ' . date('D M j', (int) strtotime($date)), 0, 200), 'b' => $text, 's2' => $scopeId]);
    }
    log_activity($pdo, 'buyer.note', 'site', $scopeId, ['scope_id' => $scopeId, 'after' => ['date' => $date, 'sent' => $recipients !== [], 'headings' => $counts, 'recipients' => count($recipients), 'proposals' => $note['proposals']]]);
    return ['sent' => $recipients !== [], 'already' => null, 'recipients' => $recipients, 'headings' => $counts, 'proposals' => $note['proposals'], 'markdown' => $md];
}
