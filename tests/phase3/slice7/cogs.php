<?php
/** Slice 7 — COGS and food-cost % by period (periods.md "Proof": COGS): the year's table with the open periods live, food-cost % = COGS ÷ food sales, "—" for a period with no sales, the monthly roll-up, the chart as inline SVG with no script, the wall. */
require __DIR__ . '/lib.php';
$w = period_world();
$dana = person(30); $bea = person(28);
$y = fiscal_year();
$p = static fn (int $n): int => period_id($n);

echo "1. The table\n";
$h = page($dana, '/usage/cogs');
ok($h['code'] === 200 && has($h, 'data-screen="cogs-report"') && has($h, 'id="cogs-table"') && has($h, 'id="cogs-row-' . $p(1) . '"') && has($h, 'id="cogs-row-' . $p(6) . '"') && has($h, 'id="cogs-totals"') && has($h, 'id="cogs-form-field-year"'), 'the screen: the table, a row per period, the totals and the year select');
ok(!has($h, 'id="cogs-row-' . $p(7) . '"') && has($h, 'id="cogs-not-begun"'), 'a period that has not begun is not listed (and the page says how many are not)');
$j = jget(30, '/usage/cogs')['json']['data'] ?? [];
$rows = array_column($j['periods'] ?? [], null, 'no');
ok(($j['fiscal_year'] ?? 0) === $y && count($rows) === 6 && $rows[6]['status'] === 'open' && $rows[6]['live'] === true, 'JSON: the year, the six periods that have begun, the open one live');
$ref4 = usage_ref(wk(4), wk(4, 6));
$cogs4 = array_sum(array_map(fn ($r) => (float) $r['actual_value'], $ref4)); $theo4 = array_sum(array_map(fn ($r) => (float) $r['theoretical_value'], $ref4)); $waste4 = array_sum(array_map(fn ($r) => (float) $r['waste_value'], $ref4));
ok(near($rows[4]['cogs_value'], $cogs4, 0.005) && near($rows[4]['theoretical_value'], $theo4, 0.005) && near($rows[4]['waste_value'], $waste4, 0.005) && near($rows[4]['food_sales'], 1050.0, 0.001), 'week 4 (open, live): COGS, theoretical COGS, waste and food sales equal the functions\' (COGS $' . number_format($cogs4, 2) . ' on $1,050.00)');
ok(near($rows[4]['food_cost_pct'], 100 * $cogs4 / 1050.0, 0.006) && near($rows[4]['theoretical_pct'], 100 * $theo4 / 1050.0, 0.006), 'food-cost % = COGS ÷ food sales, actual ' . $rows[4]['food_cost_pct'] . ' %, theoretical ' . $rows[4]['theoretical_pct'] . ' %');
ok(near($rows[4]['variance_value'], $cogs4 - $theo4, 0.005) && near($rows[4]['variance_points'], (float) $rows[4]['food_cost_pct'] - (float) $rows[4]['theoretical_pct'], 0.006), 'the variance is shown in dollars and in points (actual − theoretical)');
ok($rows[6]['food_cost_pct'] === null && $rows[6]['food_sales'] === '0.00', 'week 6 has no sales: the food-cost % is null in JSON');
preg_match('#id="cogs-row-' . $p(6) . '"(.*?)</tr>#s', $h['body'], $m6);
ok(str_contains($m6[1] ?? '', '—'), '…and "—" on the screen');
$sumSales = array_sum(array_map(fn ($r) => (float) $r['food_sales'], $rows)); $sumCogs = array_sum(array_map(fn ($r) => (float) $r['cogs_value'], $rows));
ok(near($j['totals']['food_sales'] ?? null, $sumSales, 0.011) && near($j['totals']['cogs_value'] ?? null, $sumCogs, 0.011) && near($j['totals']['food_cost_pct'] ?? null, 100 * $sumCogs / $sumSales, 0.006), 'the year\'s totals add the periods, and its % is the total COGS ÷ the total sales');

echo "2. The monthly roll-up\n";
$months = $j['months'] ?? [];
ok($months !== [] && has($h, 'id="cogs-month-' . $months[0]['month'] . '"'), 'a weekly calendar gets a subtotal row per calendar month (' . count($months) . ' months so far)');
$byMonth = [];
foreach ($rows as $r) { $m = substr($r['starts_on'], 0, 7); $byMonth[$m] = ($byMonth[$m] ?? 0) + (float) $r['food_sales']; }
$okM = true;
foreach ($months as $m) { $okM = $okM && near($m['food_sales'], $byMonth[$m['month']] ?? -1, 0.011); }
ok($okM && abs(array_sum(array_column($months, 'food_sales')) - $sumSales) < 0.02, 'a month\'s subtotal is the weeks that began in it, and the months add to the year');

echo "3. The chart\n";
$svg = preg_match('#<svg class="cogs-chart-svg".*?</svg>#s', $h['body'], $m) ? $m[0] : '';
ok($svg !== '' && substr_count($svg, '<rect') === 12 && !str_contains($svg, '<script') && !preg_match('#<script[^>]+src=[^>]*(cdn|http)#i', $h['body']), 'the chart is an inline SVG with one pair of bars per period that has begun (12 bars for 6 periods) and no script in it');
ok(has($h, 'id="cogs-chart"') && str_contains($svg, 'viewBox="0 0 600 220"') && str_contains($svg, 'role="img"') && substr_count($svg, '<title>') >= 12, 'it is labelled, scales through its viewBox, and every bar carries its figure in a title');
$none = page($dana, '/usage/cogs?year=2019');
ok($none['code'] === 200 && has($none, 'id="cogs-empty"') && !has($none, 'id="cogs-table"'), 'a year with no periods says so and links to make them');

echo "4. Cost is the wall\n";
$bh = page($bea, '/usage/cogs');
$bj = jget(28, '/usage/cogs')['json']['data'] ?? [];
ok($bh['code'] === 200 && has($bh, 'id="cogs-withheld"') && !has($bh, 'id="cogs-chart"') && ($bj['cost_withheld'] ?? false) === true && !isset($bj['periods'][3]['cogs_value']) && ($bj['periods'][3]['cost_withheld'] ?? false) === true, 'Bea (Kitchen): the notice, no chart, and JSON says cost_withheld with no value in any period');
$sv = activity('screen.view');
$last = array_values(array_filter($sv, fn ($r) => $r['screen'] === 'cogs-report'));
ok($last !== [] && json_decode($last[count($last) - 1]['after'], true)['year'] !== null, 'the screen logs screen.view with after.year');
finish();
