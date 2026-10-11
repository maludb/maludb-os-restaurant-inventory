<?php
/** Slice 7 — reopen and the worker's due notice (periods.md "Proof": Reopen, Notifications): only the last closed period reopens, with a reason, by the administrator; the figures are live again; the period_due notice once. */
require __DIR__ . '/lib.php';
$w = period_world();
$owner = person(1); $marco = person(27);
$P = fn (int $n): int => period_id($n);

echo "1. Who may, and which\n";
$r = post($marco, '/periods/reopen.php', ['period' => $P(5), 'reason' => 'a late invoice']);
ok($r['code'] === 403 && says($r, 'does not let you') && period_row($P(5))['status'] === 'closed', 'Marco (Manager) cannot reopen: settings.manage is the administrator\'s — 403 in words');
$r = post($owner, '/periods/reopen.php', ['period' => $P(4), 'reason' => 'a count to redo']);
ok($r['code'] === 422 && says($r, 'A later period is closed — reopen it first') && period_row($P(4))['status'] === 'closed', 'an earlier one: "A later period is closed — reopen it first"');
$r = post_json($owner, '/periods/reopen.php', ['period' => $P(5)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['reason']) && period_row($P(5))['status'] === 'closed', 'a reopen without a reason is refused naming the field');
$r = post_json($owner, '/periods/reopen.php', ['period' => $P(5), 'reason' => str_repeat('x', 201)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['reason']), '…and a reason over 200 characters');
$r = post($owner, '/periods/reopen.php', ['period' => $P(6), 'reason' => 'it is open']);
ok($r['code'] === 422 && says($r, 'is open'), 'a period that is open does not reopen');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['period_reopen']['approval'] ?? null) === 'other', 'an agent\'s period_reopen pauses at the kernel\'s hook (approval other)');

echo "2. The last closed period reopens\n";
$h = page($owner, '/periods/');
ok(has($h, 'id="period-reopen-form"') && has($h, 'id="period-reopen-form-field-reason"') && !has(page($marco, '/periods/'), 'id="period-reopen-form"'), 'the owner\'s list offers Reopen on the last closed week with a reason field; Marco\'s does not');
$frozen = period_row($P(5));
$bf = last_activity_id();
$r = post($owner, '/periods/reopen.php', ['period' => $P(5), 'reason' => 'a late invoice']);
$p5 = period_row($P(5));
ok($r['code'] === 302 && str_contains($r['location'], '/periods/' . $P(5) . '?notice=reopened') && $p5['status'] === 'open' && $p5['reopened_at'] !== null && (int) $p5['reopened_by'] === 1, 'the owner reopens week 5 with a reason: open, reopened by and when');
$a = activity('period.reopen', $bf);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && (int) $a[0]['scope_id'] === DOWNTOWN && (int) $a[0]['entity_id'] === $P(5) && $af['reason'] === 'a late invoice' && $af['period_no'] === 5, 'period.reopen is logged with scope_id, the period and the reason');
$j = jget(1, '/periods/' . $P(5))['json']['data'] ?? [];
ok(($j['period']['live'] ?? false) === true && ($j['closes_next'] ?? false) === true && ($j['period']['reopened_at'] ?? null) !== null, 'its figures are live again and it is the one to close next');
ok(has(page($owner, '/periods/' . $P(5)), 'reopened') && has(page($marco, '/periods/' . $P(5)), 'id="period-close-form"'), 'the page says it was reopened and Marco is offered the close again');

echo "3. The worker's notice\n";
$note0 = (int) one("SELECT count(*) FROM notifications WHERE kind = 'period_due'");
$root = dirname(__DIR__, 3);
$run = fn (): string => trim((string) shell_exec('php -r ' . escapeshellarg("require '$root/app/bootstrap.php'; require '$root/app/features/periods/handler.php'; \$GLOBALS['__public_door'] = 'cron'; echo period_due_check(db());") . ' 2>&1'));
$first = $run();
ok(ctype_digit($first) && (int) $first >= 1, "period_due_check() tells the Manager of the open period more than three days past its end (made $first)");
$n = q("SELECT * FROM notifications WHERE kind = 'period_due' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok((int) $n['member_id'] === 27 && (int) $n['scope_id'] === DOWNTOWN && $n['record_type'] === 'period' && str_contains($n['title'], 'has not been closed'), 'the notification goes to the site\'s Manager, names the period and points at it');
ok($run() === '0' && (int) one("SELECT count(*) FROM notifications WHERE kind = 'period_due'") === $note0 + (int) $first, 'a second run tells no one again (once per period)');
ok(!(int) one("SELECT count(*) FROM notifications WHERE kind = 'period_due' AND scope_id = 102"), 'Airport has made no periods that are past due');
finish();
