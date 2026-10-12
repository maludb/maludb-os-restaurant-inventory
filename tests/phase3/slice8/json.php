<?php
/** Slice 8 — JSON mode under signed action tokens, agents (run token + relay), an evaluation run, the HTMX stamps, the log vocabulary, the registry counts (projection.md "Proof": JSON mode). */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['did'], $r['json']['location']) && array_key_exists('record_id', $r['json']) && array_key_exists('refresh', $r['json']);
$sand = $w['mi_sand'];
$a0 = last_activity_id() - 0;
$D = day(11);

echo "1. Every handler under a signed token\n";
$r = jpost(27, '/projection/run.php', ['method' => 'baseline', 'horizon_days' => '14']);
ok($shape($r) && $r['json']['refresh'] === 'forecastChanged' && $r['json']['method'] === 'baseline' && ($r['json']['items'] ?? 0) === 4 && str_contains($r['json']['did'], 'Forecast run'), 'forecast_run: {ok, did, record_id, location, refresh} plus the run');
ok((int) one('SELECT count(*) FROM item_forecasts WHERE run_id = :r', ['r' => $r['json']['record_id']]) === 56, 'horizon_days = 14 writes 14 days an item');
$r = jpost(27, '/projection/run.php', ['method' => 'nonsense']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['method']), 'forecast_run: an unknown method is a 422 naming the field');
$r = jpost(27, '/projection/run.php', ['horizon_days' => '400']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['horizon_days']), '…and a horizon over 92 days');
$r = jpost(27, '/projection/override.php', ['menu_item' => $sand, 'from_date' => $D, 'to_date' => $D, 'factor' => '1.25', 'reason' => 'json']);
$ov = (int) $r['json']['record_id'];
ok($shape($r) && $r['json']['refresh'] === 'forecastChanged' && $ov > 0, 'forecast_override_set');
$r = jpost(27, '/projection/override-remove.php', ['override' => $ov]);
ok($shape($r) && (int) $r['json']['record_id'] === $ov, 'forecast_override_remove');
$r = jpost(27, '/projection/calendar/save.php', ['on_date' => $D, 'closed' => 'yes', 'holiday' => 'json day']);
ok($shape($r) && $r['json']['refresh'] === 'calendarChanged' && $r['json']['record_id'] === $D && $r['json']['closed'] === true, 'calendar_day_set: the record is the day');
jpost(27, '/projection/calendar/save.php', ['on_date' => $D, 'closed' => 'no', 'holiday' => '']);
kernel_state(function ($s) { $s['read'] = ['mode' => 'ok', 'rows' => []]; return $s; });
$r = jpost(27, '/projection/calendar/covers.php', []);
ok($shape($r) && $r['json']['connected'] === true && $r['json']['refresh'] === 'calendarChanged' && isset($r['json']['days'], $r['json']['covers'], $r['json']['from'], $r['json']['to']), 'covers_sync answers connected');
kernel_state(function ($s) { unset($s['read']); return $s; });
$r = jpost(27, '/projection/calendar/covers.php', []);
ok($shape($r) && $r['json']['connected'] === false, '…and connected: false when Reservations is not (still a 200)');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'yes']);
$po = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $po > 0 && $r['json']['refresh'] === 'orderChanged' && $r['json']['location'] === '/purchasing/' . $po . '?notice=drafted' && ($r['json']['drafted'] ?? 0) === 1 && $r['json']['suppliers'][0]['number'] === po_row($po)['number'], 'suggested_order_run (supplier + draft): record_id = the PO');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'maybe']);
ok($r['code'] === 422, 'draft must be yes or no');
$r = jpost(27, '/projection/watches/save.php', ['ingredient' => $w['flour'], 'kind' => 'below_par']);
$wid = (int) $r['json']['record_id'];
ok($shape($r) && $r['json']['refresh'] === 'watchChanged' && $wid > 0, 'watch_set');
$r = jpost(27, '/projection/watches/clear.php', ['watch' => $wid]);
ok($shape($r) && (int) $r['json']['record_id'] === $wid, 'watch_clear');
$r = jpost(30, '/projection/proposals/save.php', ['kind' => 'pos', 'subject_type' => 'site', 'subject_id' => 101, 'title' => 'The POS is quiet', 'detail' => json_encode(['summary' => 'no sales since yesterday'])]);
$pid = (int) $r['json']['record_id'];
ok($shape($r) && $r['json']['refresh'] === 'proposalChanged' && $pid > 0, 'buyer_propose (a person with purchasing.draft may write one too)');
$r = jpost(27, '/projection/proposals/decide.php', ['proposal' => $pid, 'decision' => 'accept']);
ok($shape($r) && $r['json']['status'] === 'accepted', 'buyer_proposal_decide');
$r = jpost(27, '/projection/proposals/note.php', ['date' => day(5)]);
ok($shape($r) && $r['json']['sent'] === true && count($r['json']['headings']) === 7 && $r['json']['refresh'] === 'proposalChanged', 'morning_note_send: sent, the counts of the seven headings');
$r = jpost(27, '/projection/override-propose.php', ['from_date' => $D, 'to_date' => $D, 'factor' => '2', 'reason' => 'json']);
ok($shape($r), 'forecast_override_propose (a Manager holds purchasing.draft)');
$r2 = jpost(27, '/projection/override-decide.php', ['override' => $r['json']['record_id'], 'decision' => 'set']);
ok($shape($r2), 'forecast_override_decide');

echo "2. Every screen answers JSON\n";
foreach (['/projection/' => 'items', '/projection/items/' . $sand => 'days', '/projection/calendar' => 'days', '/projection/demand' => 'ingredients', '/projection/stock' => 'ingredients', '/projection/stock/' . $w['egg'] => 'days', '/projection/special' => 'needs',
          '/projection/proposals' => 'proposals', '/projection/watches' => 'watches', '/purchasing/suggested' => 'suppliers'] as $p => $key) {
    $r = jget(27, $p);
    ok($r['code'] === 200 && isset($r['json']['data']) && array_key_exists($key, $r['json']['data']), "GET $p answers {data: {…{$key}…}}");
}

echo "3. Agents\n";
agent_facts(40, 7801); agent_facts(42, 7821);
ok(agent_req('POST', '/projection/run.php', 40, 7801, ['method' => 'baseline'])['code'] === 403 && agent_req('POST', '/projection/override.php', 40, 7801, ['from_date' => $D, 'to_date' => $D, 'factor' => '2', 'reason' => 'x'])['code'] === 403 && agent_req('POST', '/projection/calendar/save.php', 40, 7801, ['on_date' => $D, 'closed' => 'yes'])['code'] === 403, 'the expert (a Chef) holds no forecast.adjust: forecast_run, forecast_override_set and calendar_day_set are refused');
$r = agent_req('POST', '/purchasing/suggested/run.php', 40, 7801, ['supplier' => $w['splitter'], 'draft' => 'yes']);
ok($shape($r) && po_row((int) $r['json']['record_id'])['origin'] === 'agent', 'the expert\'s suggested_order_run drafts (origin agent) — nothing pauses');
$r = agent_req('POST', '/projection/proposals/save.php', 42, 7821, ['kind' => 'delivery', 'subject_type' => 'purchase_order', 'subject_id' => $po, 'title' => 'Sysco\'s delivery has no receipt']);
$l = activity('buyer.propose', $a0); $l = $l[count($l) - 1];
ok($shape($r) && $l['source'] === 'agent' && (string) $l['request_id'] === 'req-run-7821' && (int) $l['agent_run_id'] === 7821, 'the Purchasing agent\'s buyer_propose: source agent, the run\'s request id');
$r = agent_req('POST', '/projection/proposals/note.php', 42, 7821, ['date' => day(6)]);
ok($shape($r) && $r['json']['sent'] === true, 'and its morning_note_send');
$r = agent_req('POST', '/projection/run.php', 42, 7821, ['method' => 'baseline']);
ok($shape($r), 'the Purchasing agent (a Manager) may run the forecast');
$r = agent_req('GET', '/purchasing/suggested?supplier=' . $w['sysco'], 40, 7801);
ok($r['code'] === 200 && ($r['json']['data']['suppliers'][0]['lines'][0]['unit_cost'] ?? null) !== null, 'the expert (a Chef) reads the suggested orders with cost');
kernel_state(function ($s) { $s['facts']['7899'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 7899, 'request_id' => 'req-run-7899', 'trigger' => 'eval', 'scope' => DOWNTOWN, 'endpoints' => [['name' => 'Records MCP']], 'is_eval' => true]; return $s; });
$n0 = [row_count('forecast_runs'), row_count('buyer_proposals'), row_count('watches'), row_count('forecast_overrides'), row_count('purchase_orders')];
foreach (['/projection/run.php' => ['method' => 'baseline'], '/projection/override.php' => ['from_date' => $D, 'to_date' => $D, 'factor' => '2', 'reason' => 'x'], '/projection/calendar/covers.php' => [], '/purchasing/suggested/run.php' => ['supplier' => $w['produce'], 'draft' => 'yes'],
          '/projection/watches/save.php' => ['ingredient' => $w['egg'], 'kind' => 'below_par'], '/projection/proposals/save.php' => ['kind' => 'pos', 'subject_type' => 'site', 'subject_id' => 101, 'title' => 'x'], '/projection/proposals/note.php' => ['date' => day(9)]] as $path => $form) {
    $r = agent_req('POST', $path, 42, 7899, $form);
    ok($r['code'] === 403 && str_contains(json_encode($r['json']), 'evaluation run'), "an evaluation run: $path refused");
}
ok($n0 === [row_count('forecast_runs'), row_count('buyer_proposals'), row_count('watches'), row_count('forecast_overrides'), row_count('purchase_orders')], '…and nothing was written');

echo "4. The shell's stamps, the log, the registry\n";
$jar = person(30);
$r = req('GET', '/projection/items/' . $sand, ['jar' => $jar, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && preg_match('/^X-Screen: forecast-item/mi', $r['headers']) && preg_match('/^X-Entity: menu_item/mi', $r['headers']) && preg_match('/^X-Record-Id: ' . $sand . '\s*$/mi', $r['headers']) && !str_contains($r['body'], '<html'), 'an HTMX request for the item gets the partial with X-Screen, X-Entity and X-Record-Id');
$shell = page($jar, '/projection/stock/' . $w['egg'])['body'];
ok(str_contains($shell, 'data-screen="projected-ingredient"') && str_contains($shell, 'data-entity="ingredient"') && str_contains($shell, 'data-record-id="' . $w['egg'] . '"') && str_contains($shell, 'data-scope-id="101"'), 'the full page stamps #page-content: screen, entity, record id, scope');
$jo = ['Accept: application/json', 'X-Action-Token: ' . person_token(1), 'X-Action-Relay: ' . relay_of(person_token(1)), 'X-Site: 101', 'X-Screen-View: 1'];
foreach (['/projection/', '/projection/items/' . $sand, '/projection/calendar', '/projection/demand', '/projection/stock', '/projection/stock/' . $w['egg'], '/projection/special?recipe=' . $w['r_braise'] . '&date=' . day(3) . '&portions=10', '/projection/proposals', '/projection/watches', '/purchasing/suggested'] as $p) { req('GET', $p, ['headers' => $jo]); }
$ids = ['forecast-list', 'forecast-item', 'calendar-days', 'demand-report', 'projected-stock', 'projected-ingredient', 'special-check', 'proposal-list', 'watch-list', 'suggested-order-list'];
$sv = array_column(q("SELECT DISTINCT screen FROM activity_log WHERE action = 'screen.view' AND screen = ANY (CAST(:s AS text[]))", ['s' => '{' . implode(',', $ids) . '}']), 'screen');
ok(count($sv) === 10, 'every screen of the slice writes a screen.view row (' . count($sv) . ' of 10)');
$events = ['forecast.run', 'forecast.override', 'forecast.override_propose', 'forecast.override_decide', 'forecast.override_remove', 'forecast.covers_sync', 'calendar.day_set', 'purchase_order.suggest', 'watch.set', 'watch.clear', 'buyer.propose', 'buyer.decide', 'buyer.note'];
$missing = array_filter($events, fn ($a) => (int) one('SELECT count(*) FROM activity_log WHERE action = :a', ['a' => $a]) === 0);
ok($missing === [], 'every event of the log vocabulary has been written by this suite' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
ok((int) one("SELECT count(*) FROM activity_log WHERE (action LIKE 'forecast.%' OR action LIKE 'calendar.%' OR action LIKE 'watch.%' OR action LIKE 'buyer.%' OR action = 'purchase_order.suggest') AND scope_id IS NULL") === 0, 'every one of them carries scope_id');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'purchase_order.suggest' AND purchase_order_id IS NOT NULL") > 0 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'watch.set' AND ingredient_id IS NOT NULL") > 0, 'the audit keys ride: purchase_order_id on a drafted order, ingredient_id on a watch');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['forecast-list', 'forecast-item', 'calendar-days', 'demand-report', 'projected-stock', 'projected-ingredient', 'suggested-order-list', 'special-check', 'proposal-list', 'watch-list'];
$acts = ['forecast_run', 'forecast_override_set', 'forecast_override_propose', 'forecast_override_decide', 'forecast_override_remove', 'calendar_day_set', 'covers_sync', 'suggested_order_run', 'watch_set', 'watch_clear', 'buyer_propose', 'buyer_proposal_decide', 'morning_note_send'];
ok(count(array_filter($screens, fn ($s) => !empty($reg['screens'][$s]['built']))) === 10 && count(array_filter($acts, fn ($a) => !empty($reg['actions'][$a]['built']))) === 13, 'the registry reads this slice\'s 10 screens and 13 actions as built');
ok(count(array_filter($acts, fn ($a) => array_key_exists('approval', $reg['actions'][$a]) && $reg['actions'][$a]['approval'] === null)) === 13, 'none of the 13 pauses for an approval — the application drafts and proposes, a person sends (money_out is slice 4\'s purchase_order_send)');
ok(count(array_filter($reg['screens'], fn ($s) => !empty($s['built']))) === 107 && count(array_filter($reg['actions'], fn ($a) => !empty($a['built']))) === 136, 'the registry in all: 107 of 119 screens and 136 of 146 actions built');
finish();
