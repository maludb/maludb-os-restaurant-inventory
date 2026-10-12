<?php
/** Slice 7 — JSON mode under signed action tokens, agents (run token + relay), an evaluation run, the HTMX stamps, the log vocabulary, the registry counts (periods.md "Proof": JSON mode). */
require __DIR__ . '/lib.php';
$w = period_world();
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['did'], $r['json']['location']) && array_key_exists('record_id', $r['json']) && array_key_exists('refresh', $r['json']);
$P = fn (int $n): int => period_id($n);
$f = wk(4); $t = wk(4, 6);

echo "1. Every handler under a signed token\n";
$r = jpost(1, '/periods/calendars/save.php', ['name' => 'JSON weeks', 'kind' => 'week', 'year_start_on' => wk(1)]);
$cal = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $cal > 0 && $r['json']['refresh'] === 'calendarChanged' && str_contains($r['json']['location'], '/periods/calendars') && $r['json']['did'] === 'Made the calendar JSON weeks', 'calendar_set: {ok, did, record_id, location, refresh}');
$r = jpost(27, '/periods/ensure.php', ['fiscal_year' => (string) (fiscal_year() + 1), 'calendar' => (string) $w['cal']]);
ok($shape($r) && $r['json']['refresh'] === 'periodChanged' && ($r['json']['made'] ?? 0) >= 52 && (int) $r['json']['record_id'] === DOWNTOWN, 'periods_ensure for next year: made ' . ($r['json']['made'] ?? '?') . ' periods, record_id the site');
ok(($r = jpost(27, '/periods/ensure.php', ['fiscal_year' => '2200']))['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['fiscal_year']), '422 {error: {code: invalid, fields}} for a year out of range');
$r = jpost(27, '/periods/close.php', ['period' => (string) $P(5)]);
ok($shape($r) && $r['json']['refresh'] === 'periodChanged' && (int) $r['json']['record_id'] === $P(5) && ($r['json']['period']['status'] ?? '') === 'closed' && isset($r['json']['period']['cogs_value']) && str_contains((string) $r['json']['did'], 'Closed week 5'), 'period_close (week 5, reopened earlier): the period with its frozen figures and a sentence');
$r = jpost(27, '/periods/close.php', ['period' => (string) $P(6)]);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && str_contains((string) ($r['json']['error']['message'] ?? ''), 'closes after its last day'), 'the close\'s refusal is the function\'s words in a 422');
$r = jpost(1, '/periods/reopen.php', ['period' => (string) $P(5), 'reason' => 'json check']);
ok($shape($r) && ($r['json']['period']['status'] ?? '') === 'open' && $r['json']['refresh'] === 'periodChanged', 'period_reopen: the period, open again');
ok(($r = jpost(1, '/periods/reopen.php', ['period' => (string) $P(5)]))['code'] === 422 && isset($r['json']['error']['fields']['reason']), '…and without a reason: 422 naming the field');
$r = jpost(27, '/usage/export.php', ['from' => $f, 'to' => $t, 'format' => 'json']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['count'] ?? 0) === count($r['json']['rows'] ?? []) && ($r['json']['count'] ?? 0) >= 5 && near(array_column($r['json']['rows'], null, 'ingredient_id')[$w['egg']]['unexplained_base'] ?? null, -41, 0.0001), 'usage_export format=json: {ok, rows, count} with the eggs\' −41');
$r = jpost(27, '/usage/export.php', ['from' => $f, 'to' => $t, 'format' => 'json', 'by' => 'category']);
ok(($r['json']['count'] ?? 0) >= 4 && isset($r['json']['rows'][0]['category']), 'by=category answers the categories');
$r = jpost(27, '/usage/export.php', ['from' => $f, 'to' => $t, 'format' => 'xml']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['format']), 'a format that is not csv or json: 422 naming the field');
$r = jpost(27, '/usage/export.php', ['from' => date('Y-m-d', strtotime($f . ' -500 days')), 'to' => $t, 'format' => 'json']);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), '400 days'), 'a window over 400 days: told to narrow');
$ex = activity('export.download');
$last = $ex[count($ex) - 1];
$af = json_decode($last['after'], true);
ok(count($ex) >= 2 && (int) $last['scope_id'] === DOWNTOWN && ($af['document'] ?? '') === 'usage' && isset($af['from'], $af['to'], $af['by'], $af['format'], $af['rows']), 'export.download is logged with document usage, from, to, by, format and rows');

echo "2. Agents\n";
agent_facts(40, 7401); agent_facts(42, 7421);
$r = agent_req('POST', '/periods/close.php', 40, 7401, ['period' => (string) $P(5)]);
ok($r['code'] === 403, 'the expert (a Chef) holds no periods.close: its period_close is refused');
ok(agent_req('POST', '/periods/calendars/save.php', 40, 7401, ['name' => 'x', 'kind' => 'week', 'year_start_on' => $f])['code'] === 403 && agent_req('GET', "/usage/export.php?from=$f&to=$t&format=json", 40, 7401)['code'] === 403, '…its calendar_set and usage_export are refused too');
$r = agent_req('POST', '/periods/ensure.php', 42, 7421, ['fiscal_year' => (string) (fiscal_year() + 2)]);
$act = activity('period.ensure');
$l = $act[count($act) - 1];
ok($shape($r) && $l['source'] === 'agent' && (string) $l['request_id'] === 'req-run-7421' && (int) $l['agent_run_id'] === 7421, 'the Purchasing agent (a Manager) makes a year\'s periods: its log row is source agent with the run\'s request id');
$r = agent_req('GET', "/usage/?from=$f&to=$t", 40, 7401);
ok($r['code'] === 200 && isset($r['json']['data']['rows']) && ($r['json']['data']['rows'][0]['unexplained_value'] ?? null) !== null, 'the expert (a Chef) reads the usage with cost');
kernel_state(function ($s) { $s['facts']['7499'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 7499, 'request_id' => 'req-run-7499', 'trigger' => 'eval', 'scope' => DOWNTOWN, 'endpoints' => [['name' => 'Records MCP']], 'is_eval' => true]; return $s; });
$n0 = (int) one('SELECT count(*) FROM periods');
foreach (['/periods/ensure.php' => ['fiscal_year' => (string) (fiscal_year() + 3)], '/periods/close.php' => ['period' => (string) $P(5)], '/periods/reopen.php' => ['period' => (string) $P(5), 'reason' => 'x']] as $path => $form) {
    $r = agent_req('POST', $path, 42, 7499, $form);
    ok($r['code'] === 403 && str_contains(json_encode($r['json']), 'evaluation run'), "an evaluation run: $path refused, nothing written");
}
ok((int) one('SELECT count(*) FROM periods') === $n0 && period_row($P(5))['status'] === 'open', '…and nothing changed');

echo "3. The shell's stamps, the log, the registry\n";
$jar = person(30);
$r = req('GET', '/usage/?from=' . $f . '&to=' . $t, ['jar' => $jar, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && preg_match('/^X-Screen: usage-report/mi', $r['headers']) && preg_match('/^X-Entity: ingredient/mi', $r['headers']) && !str_contains($r['body'], '<html'), 'an HTMX request gets the partial with X-Screen / X-Entity');
$r = req('GET', '/periods/' . $P(4), ['jar' => $jar, 'headers' => ['HX-Request: true']]);
ok(preg_match('/^X-Screen: period-view/mi', $r['headers']) && preg_match('/^X-Entity: period/mi', $r['headers']) && preg_match('/^X-Record-Id: ' . $P(4) . '\s*$/mi', $r['headers']), 'a period carries X-Record-Id');
$shell = page($jar, '/periods/' . $P(4))['body'];
ok(str_contains($shell, 'data-screen="period-view"') && str_contains($shell, 'data-entity="period"') && str_contains($shell, 'data-record-id="' . $P(4) . '"') && str_contains($shell, 'data-scope-id="101"'), 'the full page stamps #page-content: screen, entity, record id, scope');
$jo = ['Accept: application/json', 'X-Action-Token: ' . person_token(1), 'X-Action-Relay: ' . relay_of(person_token(1)), 'X-Site: 101', 'X-Screen-View: 1'];
foreach (['/periods/', '/periods/' . $P(4), "/usage/?from=$f&to=$t", '/usage/' . $w['egg'] . "?from=$f&to=$t", '/usage/cogs', '/periods/calendars'] as $p) { req('GET', $p, ['headers' => $jo]); }
$sv = array_column(q("SELECT DISTINCT screen FROM activity_log WHERE action = 'screen.view' AND screen IN ('period-list', 'period-view', 'usage-report', 'usage-ingredient', 'cogs-report', 'calendar-list')"), 'screen');
ok(count($sv) === 6, 'every screen of the slice writes a screen.view row (' . implode(', ', $sv) . ')');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'period-view' AND after->>'period_no' IS NOT NULL") > 0 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'usage-report' AND after->>'from' IS NOT NULL AND after->>'basis' IS NOT NULL") > 0, 'the period view logs after.period_no; the usage report logs after.from, to and basis');
$events = ['calendar.set', 'period.ensure', 'period.close', 'period.reopen', 'export.download', 'screen.view'];
$missing = array_filter($events, fn ($a) => (int) one('SELECT count(*) FROM activity_log WHERE action = :a', ['a' => $a]) === 0);
ok($missing === [], 'every event of the log vocabulary has been written by this suite' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
ok((int) one("SELECT count(*) FROM activity_log WHERE (action LIKE 'period.%' OR action LIKE 'calendar.%') AND scope_id IS NULL") === 0, 'every period.* and calendar.* row carries scope_id');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$slice = ['period-list', 'period-view', 'usage-report', 'usage-ingredient', 'cogs-report', 'calendar-list'];
$act = ['calendar_set', 'periods_ensure', 'period_close', 'period_reopen', 'usage_export'];
ok(count(array_filter($slice, fn ($s) => !empty($reg['screens'][$s]['built']))) === 6 && count(array_filter($act, fn ($a) => !empty($reg['actions'][$a]['built']))) === 5, 'the registry reads this slice\'s 6 screens and 5 actions as built');
ok(($reg['actions']['calendar_set']['approval'] ?? null) === 'other' && ($reg['actions']['period_close']['approval'] ?? null) === 'other' && ($reg['actions']['period_reopen']['approval'] ?? null) === 'other' && array_key_exists('approval', $reg['actions']['periods_ensure']) && $reg['actions']['periods_ensure']['approval'] === null && array_key_exists('approval', $reg['actions']['usage_export']) && $reg['actions']['usage_export']['approval'] === null, 'calendar_set, period_close and period_reopen pause an agent (other); ensuring a year and exporting do not');
ok(count(array_filter($reg['screens'], fn ($s) => !empty($s['built']))) === 107 && count(array_filter($reg['actions'], fn ($a) => !empty($a['built']))) === 136, 'the registry in all: 107 of 119 screens and 136 of 146 actions built');
finish();
