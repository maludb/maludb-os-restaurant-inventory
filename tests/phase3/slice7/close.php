<?php
/** Slice 7 — the close (periods.md "Proof": Close): the refusals in the schema's words, the periods closed in order and freezing their figures, the undepleted day and its banner, the closing count, a new receipt not moving a frozen period, the right, the log. */
require __DIR__ . '/lib.php';
$w = period_world();
$marco = person(27); $dana = person(30); $owner = person(1); $marcoA = person(27, AIRPORT);
$P = fn (int $n): int => period_id($n);
$fig = fn (int $n): array => jget(30, '/periods/' . $P($n))['json']['data']['period'] ?? [];

echo "1. What the schema refuses\n";
$r = post($marco, '/periods/close.php', ['period' => $P(6)]);
ok($r['code'] === 422 && says($r, 'closes after its last day'), 'the period holding today: "Period 6 ends … — it closes after its last day"');
$r = post($marco, '/periods/close.php', ['period' => $P(3)]);
ok($r['code'] === 422 && says($r, 'An earlier period is still open — close it first') && period_row($P(3))['status'] === 'open', 'week 3 while weeks 1 and 2 are open: "An earlier period is still open — close it first"');
$r = post($dana, '/periods/close.php', ['period' => $P(1)]);
ok($r['code'] === 403 && says($r, 'does not let you') && period_row($P(1))['status'] === 'open', 'Dana (Chef) cannot close — periods.close is the Manager\'s: 403 in words');
$r = post($marcoA, '/periods/close.php', ['period' => $P(1)]);
ok($r['code'] === 403 && says($r, 'does not let you'), 'Marco signed on at Airport is Kitchen there: no periods.close at that site, 403 in words');
$r = post(person(1, AIRPORT), '/periods/close.php', ['period' => $P(1)]);
ok($r['code'] === 404, 'the owner (admin at Airport too) closing a Downtown period from there: the same 404 as a missing one');
$r = post($marco, '/periods/close.php', ['period' => '999999']);
ok($r['code'] === 404, '…as a period that is not there');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['period_close']['approval'] ?? null) === 'other', 'an agent\'s period_close pauses at the kernel\'s hook (approval other)');

echo "2. Closing in order, freezing\n";
$before = $fig(1);
$bf = last_activity_id();
$r = post($marco, '/periods/close.php', ['period' => $P(1)]);
$p1 = period_row($P(1));
ok($r['code'] === 302 && str_contains($r['location'], '/periods/' . $P(1) . '?notice=closed') && $p1['status'] === 'closed' && (int) $p1['closed_by'] === 27 && $p1['closed_at'] !== null, 'Marco closes week 1: closed, by him, now');
$ref1 = usage_ref(wk(1), wk(1, 6));
$sum = fn (array $ref, string $k) => array_sum(array_map(fn ($r) => (float) $r[$k], $ref));
ok($before['live'] === true && near($before['cogs_value'], $sum($ref1, 'actual_value'), 0.005) && near($p1['cogs_value'], $sum($ref1, 'actual_value'), 0.005) && near($p1['ending_value'], $sum($ref1, 'closing_value'), 0.005) && near($p1['opening_value'], $sum($ref1, 'opening_value'), 0.005) && near($p1['purchases_value'], $sum($ref1, 'purchases_value'), 0.005) && near($p1['theoretical_value'], $sum($ref1, 'theoretical_value'), 0.005) && near($p1['waste_value'], $sum($ref1, 'waste_value'), 0.005), 'the frozen figures are what ri_usage() said over its dates (COGS $' . $p1['cogs_value'] . ', ending $' . $p1['ending_value'] . ') — and what the open period showed live a moment before');
ok(near($p1['food_sales'], 38 * 14 + 40 * 14, 0.001), 'its food sales are the week\'s $1,092');
$p2 = period_row($P(2));
ok(near($p2['opening_value'], $p1['ending_value'], 0.001), 'the next period\'s opening is the ending value just frozen (' . $p2['opening_value'] . ')');
$a = activity('period.close', $bf);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && (int) $a[0]['scope_id'] === DOWNTOWN && (int) $a[0]['entity_id'] === $P(1) && $af['period_no'] === 1 && near($af['cogs_value'], $p1['cogs_value'], 0.001) && near($af['ending_value'], $p1['ending_value'], 0.001) && near($af['food_sales'], $p1['food_sales'], 0.001) && $a[0]['count_id'] === null, 'period.close is logged with scope_id, the period, the figures and the food sales');
// a new receipt does not move a frozen period
$f1 = $fig(1); $liveBefore = $fig(6);
receive_on($dana, $w['walkin'], [['ingredient' => 'L-EGG', 'qty' => 300, 'unit' => 'each', 'unit_cost' => 0.30]], (new DateTimeImmutable('today', new DateTimeZone((string) one('SELECT timezone FROM sites WHERE scope_id = 101'))))->format('Y-m-d'), $w['sysco']);
$f1b = $fig(1); $liveAfter = $fig(6);
ok($f1b['cogs_value'] === $f1['cogs_value'] && $f1b['ending_value'] === $f1['ending_value'] && $f1b['live'] === false && (float) $liveAfter['purchases_value'] > (float) $liveBefore['purchases_value'], 'a receipt posted now does not move the closed week; the open week\'s purchases do');
ok(has(page($marco, '/periods/' . $P(1)), 'closed by') && !has(page($marco, '/periods/' . $P(1)), 'id="period-close-form"'), 'a closed period shows who closed it and no close form');
$h = page($marco, '/periods/');
ok(has($h, 'id="period-close-form"') && has($h, 'Close week 2') && preg_match('#id="period-row-' . $P(2) . '" class="table-active"#', $h['body']) === 1, 'the list now marks week 2 and offers its close');

echo "3. Week 2, then the undepleted day\n";
$r = post($marco, '/periods/close.php', ['period' => $P(2)]);
ok($r['code'] === 302 && period_row($P(2))['status'] === 'closed', 'week 2 closes in order');
$h = page($marco, '/periods/');
ok(has($h, 'id="period-undepleted"') && str_contains(says_text($h), format_date_t(wk(3, 3)) . ' is not depleted yet — run it first'), 'week 3 has a day with sales and no depletion: the banner names it (' . format_date_t(wk(3, 3)) . ') and links to the sales days');
$r = post($marco, '/periods/close.php', ['period' => $P(3)]);
ok($r['code'] === 422 && says($r, 'Sales in the period are not yet depleted — run the depletion first') && period_row($P(3))['status'] === 'open', 'closing week 3: "Sales in the period are not yet depleted — run the depletion first"');
ri_sql('SELECT ri_depletion_run(101, :d::date, 1)', ['d' => wk(3, 3)]);
ok(!has(page($marco, '/periods/'), 'id="period-undepleted"'), 'run the day: the banner is gone');
$r = post($marco, '/periods/close.php', ['period' => $P(3)]);
ok($r['code'] === 302 && period_row($P(3))['status'] === 'closed', 'week 3 closes');

echo "4. The closing count\n";
$count = $w['count'];
$h = page($marco, '/periods/');
ok(has($h, 'Close week 4') && has($h, 'value="' . $count . '"') && has($h, 'id="period-close-form-field-closing_count"'), 'week 4\'s form offers the approved count taken on its last day (CNT) as the closing count');
ri_sql("UPDATE inventory_counts SET status = 'discarded' WHERE scope_id = 101 AND status IN ('open', 'review', 'recount')");
[$open] = start_count_as($dana, ['kind' => 'spot']);
$r = post($marco, '/periods/close.php', ['period' => $P(4), 'closing_count' => $open]);
ok($r['code'] === 422 && says($r, 'The closing count is not approved') && period_row($P(4))['status'] === 'open', 'a count that is not approved: "The closing count is not approved"');
ri_sql("UPDATE inventory_counts SET status = 'discarded' WHERE id = :i", ['i' => $open]);
$r = post($marco, '/periods/close.php', ['period' => $P(4), 'closing_count' => '999999']);
ok($r['code'] === 422, 'a count that is not at this site is refused');
$bf = last_activity_id();
$r = post($marco, '/periods/close.php', ['period' => $P(4), 'closing_count' => $count]);
$p4 = period_row($P(4));
ok($r['code'] === 302 && $p4['status'] === 'closed' && (int) $p4['closing_count_id'] === $count, 'week 4 closes with the count: closing_count_id set');
$ref4 = usage_ref(wk(4), wk(4, 6));
ok(near($p4['cogs_value'], $sum($ref4, 'actual_value'), 0.005) && near($p4['theoretical_value'], $sum($ref4, 'theoretical_value'), 0.005) && near($p4['waste_value'], $sum($ref4, 'waste_value'), 0.005), 'its COGS, theoretical and waste are the week\'s ri_usage() at cost, the count surplus inside the COGS (COGS $' . $p4['cogs_value'] . ')');
$a = activity('period.close', $bf);
ok(count($a) === 1 && (int) $a[0]['count_id'] === $count && (int) $a[0]['scope_id'] === DOWNTOWN && json_decode($a[0]['after'], true)['closing_count_id'] === $count, 'period.close carries count_id = the closing count');
ok((int) period_row($P(5))['opening_count_id'] === $count && near(period_row($P(5))['opening_value'], $p4['ending_value'], 0.001), 'week 5 opens on that count and its ending value');

echo "5. The last past week; the one holding today\n";
$r = post($marco, '/periods/close.php', ['period' => $P(5)]);
ok($r['code'] === 302 && period_row($P(5))['status'] === 'closed', 'week 5 closes');
$h = page($marco, '/periods/');
ok(!has($h, 'id="period-close-form"') && !preg_match('#id="period-row-' . $P(6) . '" class="table-active"#', $h['body']), 'week 6 holds today: nothing is offered to close and none is marked "closes next"');
$j = jget(27, '/periods/')['json']['data'] ?? [];
ok(array_key_exists('to_close', $j) && $j['to_close'] === null && count(array_filter($j['periods'], fn ($p) => $p['status'] === 'closed')) === 5, 'JSON: nothing to close, five closed');
$r = post($marco, '/periods/close.php', ['period' => $P(1)]);
ok($r['code'] === 422 && says($r, 'is closed'), 'a closed period does not close again');
finish();
function format_date_t(string $d): string { return (new DateTimeImmutable($d))->format('M j, Y'); }
