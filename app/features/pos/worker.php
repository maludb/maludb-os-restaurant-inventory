<?php
declare(strict_types=1);

/**
 * The worker's POS passes (pos.md "The worker glue"; bin/worker.php names them pos_pulls, email_intake, depletion_runs). Each idempotent:
 *  - pos_pulls: every active, unpaused source with schedule_minutes > 0 whose last ok pull + schedule has passed and whose backoff is
 *    over → a `scheduled` pull through its connector (failures back off 15 min × failures, ≤ 6 h; the third in a row notifies the Manager).
 *  - email_intake: RI_INTAKE_MAILBOX's unread mail through MaluMail's mailbox MCP: a message from a source's `settings.email_from` with a
 *    CSV/XLSX attachment → an `email` pull for that source, the message marked \Seen ONLY after the pull row is written (D13: \Seen =
 *    handled, never set on a message it did not take); a message no source claims is left unread and one notice a day names the sender.
 *  - depletion_runs: ri_depletion_due() → ri_depletion_run() per day (the worker as actor — started_by NULL); a failure leaves the day due
 *    and notifies the site's Manager once a day; ri_watches_fire() after (slice 8 owns the watches; the call is one line here).
 */
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/mailbox.php';

/** False while the schema lacks the POS tables (the kit's proof runs the worker on db/001–006 alone). */
function pos_schema_ready(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT to_regclass('public.sources') IS NOT NULL AND to_regprocedure('ri_depletion_due()') IS NOT NULL")->fetchColumn();
}

function pos_pass_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!pos_schema_ready($pdo)) {
        return ['pulled' => 0, 'skipped' => 'no POS schema'];
    }
    $st = $pdo->prepare("SELECT * FROM sources WHERE active AND paused_at IS NULL AND schedule_minutes > 0 AND connector IN ('file', 'square')
                          AND (backoff_until IS NULL OR backoff_until <= :now)
                          AND (last_ok_at IS NULL OR last_ok_at + make_interval(mins => schedule_minutes) <= :now2)
                          AND NOT (connector = 'file' AND COALESCE(settings->>'intake', 'upload') <> 'sftp')
                        ORDER BY last_ok_at NULLS FIRST, id LIMIT :l");
    $st->execute(['now' => $now->format(DATE_ATOM), 'now2' => $now->format(DATE_ATOM), 'l' => $limit]);
    $out = ['pulled' => 0, 'failed' => 0, 'sales_new' => 0, 'runs' => 0];
    foreach ($st->fetchAll() as $s) {
        try {
            $r = pos_run_pull($pdo, $s, 'scheduled', null, true);
            $out['pulled']++;
            $out['sales_new'] += (int) $r['sales_new'];
            $out['runs'] += count($r['runs']);
        } catch (DomainException) {
            $out['failed']++;
        }
    }
    return $out;
}

function pos_pass_email_intake(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $box = trim((string) env('RI_INTAKE_MAILBOX', ''));
    if ($box === '' || !pos_schema_ready($pdo)) {
        return ['read' => 0, 'skipped' => 'RI_INTAKE_MAILBOX is not set'];
    }
    $list = malumail_mcp_call('list_messages', ['address' => $box, 'unread_only' => true, 'limit' => $limit]);
    $out = ['read' => 0, 'imported' => 0, 'unclaimed' => 0, 'failed' => 0];
    $sources = $pdo->query("SELECT * FROM sources WHERE active AND paused_at IS NULL AND connector = 'file' AND settings->>'intake' = 'email' ORDER BY id")->fetchAll();
    foreach ((array) ($list['messages'] ?? []) as $m) {
        $uid = (int) ($m['uid'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $out['read']++;
        $from = pos_mail_address((string) ($m['from'] ?? ''));
        $source = null;
        foreach ($sources as $s) {
            $want = mb_strtolower(trim((string) ((json_decode((string) $s['settings'], true) ?: [])['email_from'] ?? '')));
            if ($want !== '' && ($want === $from || (str_starts_with($want, '@') ? str_ends_with($from, $want) : (!str_contains($want, '@') && str_ends_with($from, '@' . $want))))) {
                $source = $s;
                break;
            }
        }
        if ($source === null) {
            $out['unclaimed']++;
            pos_intake_unclaimed($pdo, $from, $uid);                    // left unread: \Seen means handled
            continue;
        }
        $msg = malumail_mcp_call('read_message', ['address' => $box, 'uid' => $uid, 'mark_seen' => false]);
        $file = null;
        foreach ((array) ($msg['attachments'] ?? []) as $a) {
            $name = (string) ($a['filename'] ?? ($a['name'] ?? 'export.csv'));
            if (preg_match('/\.(csv|xlsx|txt)$/i', $name) && isset($a['content_base64'])) {
                $file = ['name' => $name, 'bytes' => base64_decode((string) $a['content_base64'], true)];
                break;
            }
        }
        if ($file === null || $file['bytes'] === false) {
            $pullId = pos_pull_open($pdo, (int) $source['id'], 'email', null, null);
            pos_pull_close($pdo, $pullId, (int) $source['id'], 'failed', [], 'The mail from ' . $from . ' carried no CSV or XLSX attachment.');
            log_activity($pdo, 'source.pull_fail', 'source', (int) $source['id'], ['scope_id' => (int) $source['scope_id'], 'actor_member_id' => null, 'after' => ['pull_id' => $pullId, 'kind' => 'email', 'error' => 'no attachment']]);
            malumail_mcp_call('mark_message', ['address' => $box, 'uid' => $uid, 'seen' => true]);    // handled: a pull row says why
            $out['failed']++;
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rimail');
        file_put_contents($tmp, $file['bytes']);
        $r = pos_import_file($pdo, $source, $tmp, $file['name'], null, 'email', null, true);
        @unlink($tmp);
        log_activity($pdo, $r['status'] === 'failed' ? 'source.pull_fail' : 'source.pull_done', 'source', (int) $source['id'], ['scope_id' => (int) $source['scope_id'], 'actor_member_id' => null,
            'after' => ['pull_id' => $r['pull_id'], 'kind' => 'email', 'status' => $r['status'], 'sales_new' => $r['sales_new'], 'dates_seen' => $r['dates_seen'], 'from' => $from]]);
        malumail_mcp_call('mark_message', ['address' => $box, 'uid' => $uid, 'seen' => true]);        // only after the pull row is written
        $r['status'] === 'failed' ? $out['failed']++ : $out['imported']++;
    }
    return $out;
}

/** "SMOKE POS <pos@smoke.invalid>" → "pos@smoke.invalid". */
function pos_mail_address(string $from): string
{
    return mb_strtolower(trim(preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from));
}

/** A mail no source claims: left unread, logged, and one notice a day to the admins naming the sender. */
function pos_intake_unclaimed(PDO $pdo, string $from, int $uid): void
{
    log_activity($pdo, 'source.pull_fail', 'source', null, ['scope_id' => null, 'actor_member_id' => null, 'after' => ['kind' => 'email', 'error' => 'unknown sender', 'from' => $from, 'uid' => $uid]]);
    $admins = $pdo->query("SELECT DISTINCT r.member_id, r.scope_id FROM member_site_roles r JOIN members m ON m.id = r.member_id WHERE 'admin' = ANY (r.roles) AND m.status = 'active' AND m.member_kind = 'human' ORDER BY r.member_id, r.scope_id")->fetchAll();
    $done = [];
    foreach ($admins as $a) {
        if (isset($done[$a['member_id']])) {
            continue;
        }
        $done[$a['member_id']] = true;
        $title = 'A POS file from ' . $from . ' matched no source';
        if ((int) one_value($pdo, "SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'pull_failed' AND title = :t AND created_at::date = current_date", ['m' => $a['member_id'], 't' => $title]) > 0) {
            continue;
        }
        $pdo->prepare("SELECT ri_notify(:m, 'pull_failed', 'source', NULL, :t, :b, :d, false, :s)")->execute(['m' => $a['member_id'], 't' => $title,
            'b' => 'The intake mailbox holds a file from ' . $from . '. Name that sender on a file source (intake: email) and it will be imported; the mail is left unread.', 'd' => 'intake:' . $from . ':' . date('Y-m-d') . ':' . $a['member_id'], 's' => $a['scope_id']]);
    }
}

function pos_pass_depletion(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!pos_schema_ready($pdo)) {
        return ['ran' => 0, 'skipped' => 'no POS schema'];
    }
    $st = $pdo->prepare('SELECT scope_id, business_date::text AS business_date FROM ri_depletion_due() LIMIT :l');
    $st->execute(['l' => $limit]);
    $out = ['ran' => 0, 'failed' => 0];
    foreach ($st->fetchAll() as $d) {
        $r = pos_run_day($pdo, (int) $d['scope_id'], (string) $d['business_date'], null);
        isset($r['error']) ? $out['failed']++ : $out['ran']++;
    }
    if ($out['ran'] > 0) {
        $out['watches_fired'] = (int) one_value($pdo, 'SELECT ri_watches_fire()');
    }
    return $out;
}
