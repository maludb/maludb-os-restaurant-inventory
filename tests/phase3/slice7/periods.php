<?php
/** Slice 7 — the periods (periods.md "Proof": Periods): the year's periods made at a site and 0 the second time, the list with the period to close marked, the open periods' figures live and equal to ri_usage(), a scope's own periods. */
require __DIR__ . '/lib.php';
$w = period_world();
$owner = person(1); $dana = person(30); $marco = person(27); $ownerA = person(1, AIRPORT);
$y = fiscal_year();

echo "1. The year's periods\n";
$n = (int) one('SELECT count(*) FROM periods WHERE scope_id = 101 AND fiscal_year = :y', ['y' => $y]);
ok(in_array($n, [52, 53], true), "Downtown has $n periods for $y — weeks of seven days");
$r = jpost(27, '/periods/ensure.php', ['fiscal_year' => (string) $y]);
ok($r['code'] === 200 && ($r['json']['made'] ?? -1) === 0 && str_contains((string) $r['json']['did'], 'already made') && (int) one('SELECT count(*) FROM periods WHERE scope_id = 101 AND fiscal_year = :y', ['y' => $y]) === $n, 'periods_ensure again: 0 made, and it says so');
ok((int) one('SELECT count(*) FROM periods WHERE scope_id = 102') === 0, 'Airport has no periods until ensured there (a scope\'s own)');
$h = page($ownerA, '/periods/?year=' . $y);
ok($h['code'] === 200 && has($h, 'id="periods-ensure-form"') && has($h, 'id="periods-ensure-form-field-fiscal_year"') && !has($h, 'id="periods-table"'), 'Airport\'s list offers "Make the year\'s periods" and has no table');
$r = post_json($ownerA, '/periods/ensure.php', ['fiscal_year' => (string) $y]);
$na = (int) one('SELECT count(*) FROM periods WHERE scope_id = 102');
ok($r['code'] === 200 && ($r['json']['made'] ?? 0) === $na && $na === $n && str_contains((string) $r['json']['location'], '/periods/?year=' . $y), "the owner at Airport makes the year: $na periods, landing on the year");
$r = post_json($ownerA, '/periods/ensure.php', ['fiscal_year' => (string) $y]);
ok(($r['json']['made'] ?? -1) === 0 && (int) one('SELECT count(*) FROM periods WHERE scope_id = 102') === $na, 'and the second time makes none');
$a = activity('period.ensure');
$af = json_decode($a[count($a) - 1]['after'] ?? '{}', true);
ok((int) $a[count($a) - 1]['scope_id'] === AIRPORT && $af['fiscal_year'] === $y && $af['made'] === 0 && (int) $af['calendar_id'] === $w['cal'], 'period.ensure is logged with scope_id, fiscal_year, calendar_id and made');
$r = post_json($owner, '/periods/ensure.php', ['fiscal_year' => '1999']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['fiscal_year']), 'a fiscal year outside 2000..2100: 422 naming the field');
ok(post($dana, '/periods/ensure.php', ['fiscal_year' => (string) $y])['code'] === 403, 'the Chef holds no periods.close: 403');

echo "2. The list\n";
$h = page($dana, '/periods/');
$p1 = period_id(1); $p4 = period_id(4); $p5 = period_id(5); $p6 = period_id(6);
ok($h['code'] === 200 && has($h, 'data-screen="period-list"') && has($h, 'id="periods-table"') && has($h, 'id="period-row-' . $p1 . '"') && has($h, 'id="period-row-' . $p6 . '"') && has($h, 'id="period-card-' . $p4 . '"'), 'the table, a row per period and a card per period for the phone');
ok(has($h, 'closes next') && preg_match('/id="period-row-' . $p1 . '" class="table-active"/', $h['body']) === 1 && !preg_match('/id="period-row-' . $p4 . '" class="table-active"/', $h['body']), 'the first open period past its end is marked "closes next" (week 1), no other is');
$j = jget(30, '/periods/')['json']['data'] ?? [];
ok(($j['year'] ?? 0) === $y && ($j['to_close'] ?? 0) === $p1 && count($j['periods'] ?? []) === $n && $j['periods'][0]['closes_next'] === true && $j['periods'][1]['closes_next'] === false, 'JSON: the year, the period to close and the list in order');
$byNo = array_column($j['periods'] ?? [], null, 'no');
foreach ([4, 5] as $no) {
    $ref = usage_ref($byNo[$no]['starts_on'], $byNo[$no]['ends_on']);
    $cogs = array_sum(array_map(fn ($r) => (float) $r['actual_value'], $ref)); $op = array_sum(array_map(fn ($r) => (float) $r['opening_value'], $ref)); $cl = array_sum(array_map(fn ($r) => (float) $r['closing_value'], $ref)); $pu = array_sum(array_map(fn ($r) => (float) $r['purchases_value'], $ref));
    ok($byNo[$no]['live'] === true && near($byNo[$no]['cogs_value'], $cogs, 0.005) && near($byNo[$no]['opening_value'], $op, 0.005) && near($byNo[$no]['ending_value'], $cl, 0.005) && near($byNo[$no]['purchases_value'], $pu, 0.005), "week $no (open): COGS, opening, purchases and ending are live and equal ri_usage() over its dates (COGS \$" . number_format($cogs, 2) . ')');
}
$sales4 = (float) one('SELECT ri_food_sales(101, :a::date, :b::date, NULL)', ['a' => wk(4), 'b' => wk(4, 6)]);
ok(near($sales4, 1050.00, 0.001) && near($byNo[4]['food_sales'], 1050.00, 0.001) && near($byNo[4]['food_cost_pct'], 100 * (float) $byNo[4]['cogs_value'] / 1050.0, 0.006), 'food sales $1,050 (25 + 30 + 20 sandwiches at $14, the comped one at nothing) and the food-cost % is COGS ÷ sales');
ok($byNo[6]['food_sales'] === '0.00' && $byNo[6]['food_cost_pct'] === null && has($h, 'id="period-row-' . $p6 . '"') && preg_match('/period-row-' . $p6 . '.*?title="no sales">—/s', $h['body']) === 1, 'a period with no sales shows "—" for the %');
$j2 = jget(30, '/periods/?status=closed')['json']['data'] ?? [];
ok(($j2['periods'] ?? ['x']) === [], 'status=closed: nothing is closed yet');
$h2 = page($dana, '/periods/?status=closed');
ok(has($h2, 'id="period-list-empty"') && !has($h2, 'id="periods-ensure-form"'), '…and the empty state does not offer to make periods the year already has… for a Chef');
ok(has(page($marco, '/periods/'), 'id="period-close-form"') && !has($h, 'id="period-close-form"'), 'Marco (periods.close) is offered the close of week 1; Dana is not');
$jy = jget(30, '/periods/?year=' . ($y + 1))['json']['data'] ?? [];
ok(($jy['periods'] ?? ['x']) === [] && ($jy['year'] ?? 0) === $y + 1, 'another year with no periods is empty, not an error');
$v = page($dana, '/periods/' . $p4);
ok($v['code'] === 200 && has($v, 'data-screen="period-view"') && has($v, 'data-record-id="' . $p4 . '"') && has($v, 'id="period-figure-cogs"') && has($v, 'id="period-figure-food_cost_pct"') && has($v, 'id="period-tabs"') && has($v, 'id="period-usage"'), 'the period view: its stamp, the figure cards, the tabs and the usage by category');
$jv = jget(30, '/periods/' . $p4)['json']['data'] ?? [];
ok(($jv['period']['no'] ?? 0) === 4 && ($jv['period']['label'] ?? '') !== '' && ($jv['tab'] ?? '') === 'usage' && count($jv['usage']['rows'] ?? []) >= 5 && ($jv['closes_next'] ?? true) === false, 'JSON: the period, its tab and the usage rows of its window');
ok(page($dana, '/periods/' . $p4 . '?tab=counts')['code'] === 200 && has(page($dana, '/periods/' . $p4 . '?tab=counts'), 'id="period-counts-table"'), 'the Counts tab lists the approved count inside it');
ok(has(page($dana, '/periods/' . $p4 . '?tab=late'), 'id="period-late-rows"') && has(page($dana, '/periods/' . $p4 . '?tab=trail'), 'id="period-trail"'), 'the Late rows and Trail tabs');
ok(page($dana, '/periods/999999')['code'] === 404, 'a period that is not there is a 404');
finish();
