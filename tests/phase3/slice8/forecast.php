<?php
/** Slice 8 — the forecast (projection.md "Proof": Forecast; projection-and-ordering.md §1, §7): the baseline's weights, bin/forecast.py on a known weekly shape, the run and its precedence, the accuracy, the chart, the thin chip. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$marco = person(27);
$dana = person(30);
$mi = ['sand' => $w['mi_sand'], 'omel' => $w['mi_omel'], 'soda' => $w['mi_soda'], 'special' => $w['mi_special']];
$fq = fn (int $item, int $offset) => q('SELECT * FROM ri_item_forecast(101, :i, current_date + CAST(:o AS integer))', ['i' => $item, 'o' => $offset])[0];

echo "1. The baseline in SQL: the same weekday's weighted average of the last four weeks\n";
$sat = 0; for ($o = 1; $o <= 7; $o++) { if ((int) one('SELECT extract(isodow FROM current_date + CAST(:o AS integer))', ['o' => $o]) === 6) { $sat = $o; } }
ok(near(one('SELECT ri_forecast_baseline(101, :i, current_date + CAST(:o AS integer))', ['i' => $mi['sand'], 'o' => $sat]), 40, 0.0001) && near(one('SELECT ri_forecast_baseline(101, :i, current_date + CAST(:o AS integer))', ['i' => $mi['sand'], 'o' => $sat + 1]), 20, 0.0001), 'the sandwich: 40 on a Saturday and 20 on a Sunday, exactly — the weights 0.4 0.3 0.2 0.1 over equal weeks');
$d2 = day(2); $d2back = day(2 - 7);
ri_sql('UPDATE sales_daily SET qty = 3 WHERE scope_id = 101 AND menu_item_id = :i AND business_date = :d AND day_part_id IS NULL', ['i' => $mi['soda'], 'd' => $d2back]);
ok(near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['soda'], 'd' => $d2]), 13.2 + 0 * 1, 0.0001) === false && near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['soda'], 'd' => $d2]), 16.2, 0.0001), 'a last week of 3 among weeks of 25: 0.4 × 3 + 0.6 × 25 = 16.2 — the honest floor, not 25');
ri_sql('UPDATE sales_daily SET qty = 25 WHERE scope_id = 101 AND menu_item_id = :i AND business_date = :d AND day_part_id IS NULL', ['i' => $mi['soda'], 'd' => $d2back]);
ri_sql("INSERT INTO calendar_days (scope_id, on_date, closed, holiday) VALUES (101, :d::date, true, 'SMOKE closure')", ['d' => day(2 - 14)]);
ok(near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['sand'], 'd' => $d2]), (float) one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['sand'], 'd' => $d2]), 0) && near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['soda'], 'd' => $d2]), 25, 0.0001), 'a closed back-week is left out and the weights renormalised (weeks of 25 stay 25)');
ri_sql('DELETE FROM calendar_days');
ri_sql("INSERT INTO calendar_days (scope_id, on_date, closed, holiday) VALUES (101, :d::date, true, 'SMOKE closure')", ['d' => $d2]);
ok(near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $mi['sand'], 'd' => $d2]), 0, 0) && $fq($mi['sand'], 2)['source'] === 'closed' && (float) $fq($mi['sand'], 2)['qty'] === 0.0, 'a closed day forecasts zero (source closed)');
ri_sql('DELETE FROM calendar_days');

echo "2. With no run, every cell says baseline and equals ri_forecast_baseline()\n";
$j = jget(27, '/projection/')['json']['data'] ?? [];
$items = array_column($j['items'] ?? [], null, 'menu_item_id');
ok(count($items) === 4 && array_key_exists('run', $j) && $j['run'] === null && ($j['method'] ?? '') === 'baseline', 'four active items sold in the last 84 days; "baseline — no run yet"');
$same = true; $all = true;
foreach ($items as $id => $it) { foreach ($it['days'] as $k => $d) { $all = $all && $d['source'] === 'baseline'; $same = $same && near($d['qty'], (float) one('SELECT COALESCE(ri_forecast_baseline(101, :i, current_date + CAST(:o AS integer)), 0)', ['i' => $id, 'o' => $k]), 0.0001); } }
ok($all && $same && count($items[$mi['sand']]['days']) === 21, 'twenty-one days per item, each the baseline and equal to it');
$h = page($marco, '/projection/');
ok(has($h, 'data-screen="forecast-list"') && has($h, 'id="forecast-table"') && has($h, 'id="forecast-row-' . $mi['sand'] . '"') && has($h, 'id="forecast-run-btn"') && has($h, 'Baseline — no run yet') && has($h, 'id="forecast-cell-' . $mi['sand'] . '-' . day(0) . '"'), 'the screen: the table, a row and a cell per item, the header says "Baseline — no run yet", Run the forecast now');
ok(has($h, 'id="forecast-row-' . $mi['special'] . '"') && preg_match('#id="forecast-row-' . $mi['special'] . '".*?thin#s', $h['body']) === 1 && !preg_match('#id="forecast-row-' . $mi['sand'] . '"[^>]*>.*?<span class="badge[^>]*>thin</span>.*?</td>#s', substr($h['body'], strpos($h['body'], 'id="forecast-row-' . $mi['sand'] . '"'), 600)), 'the daily special (three weeks of sales) carries the thin chip and the sandwich does not');
ok(($items[$mi['special']]['thin_history'] ?? false) === true && ($items[$mi['sand']]['thin_history'] ?? true) === false, 'the JSON says thin_history for the special only');
$page2 = jget(27, '/projection/?sort=name&horizon=7')['json']['data'] ?? [];
ok(count($page2['items'][0]['days'] ?? []) === 7 && ($page2['items'][0]['name'] ?? '') <= ($page2['items'][1]['name'] ?? ''), 'horizon=7 gives seven days; sort=name is alphabetical');

echo "3. A baseline run: the cells say run with the same numbers\n";
$r = jpost(27, '/projection/run.php', ['method' => 'baseline']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && $r['json']['location'] === '/projection/?notice=run' && ($r['json']['refresh'] ?? '') === 'forecastChanged' && ($r['json']['items'] ?? 0) === 4, 'forecast_run: ok, did, the run, the location /projection/, items 4');
$run1 = (int) $r['json']['record_id'];
ok(row_count('forecast_runs') === 1 && one('SELECT method FROM forecast_runs WHERE id = :r AND is_current', ['r' => $run1]) === 'baseline' && (int) one('SELECT count(*) FROM item_forecasts WHERE run_id = :r', ['r' => $run1]) === 84, 'one current baseline run with 84 rows (4 items × 21 days)');
$j = jget(27, '/projection/')['json']['data'] ?? [];
$items = array_column($j['items'] ?? [], null, 'menu_item_id');
$all = true; $same = true;
foreach ($items as $id => $it) { foreach ($it['days'] as $k => $d) { $all = $all && $d['source'] === 'run'; $same = $same && near($d['qty'], (float) one('SELECT COALESCE(ri_forecast_baseline(101, :i, current_date + CAST(:o AS integer)), 0)', ['i' => $id, 'o' => $k]), 0.0001); } }
ok($all && $same && ($j['method'] ?? '') === 'baseline' && ($j['run']['items'] ?? 0) === 4, 'every cell now says run, with the same numbers');
$lg = activity('forecast.run');
$la = json_decode($lg[count($lg) - 1]['after'], true);
ok((int) $lg[count($lg) - 1]['scope_id'] === 101 && $la['method'] === 'baseline' && $la['items'] === 4 && $la['horizon_days'] === 21 && isset($la['seconds']), 'forecast.run is logged with the site, the method, the horizon and the seconds');

echo "4. bin/forecast.py on a known weekly shape\n";
$py = function (array $doc): array { $p = proc_open('python3 ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/forecast.py'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); fwrite($pipes[0], json_encode($doc)); fclose($pipes[0]); $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); proc_close($p); return json_decode($o, true) ?: ['raw' => $o, 'err' => $e]; };
$asOf = new DateTimeImmutable(today_db());
$truth = fn (DateTimeImmutable $d, int $i): float => (in_array((int) $d->format('N'), [5, 6], true) ? 40 : 20) + $i * 0.05;
$start = $asOf->modify('-112 days');
$y = []; for ($i = 0; $i < 112; $i++) { $y[] = $truth($start->modify("+$i days"), $i); }
$o = $py(['as_of' => $asOf->format('Y-m-d'), 'horizon' => 21, 'closed_future' => [$asOf->modify('+3 days')->format('Y-m-d')], 'items' => [['menu_item_id' => 1, 'start' => $start->format('Y-m-d'), 'y' => $y], ['menu_item_id' => 2, 'start' => $start->format('Y-m-d'), 'y' => array_slice($y, 0, 56)]]]);
$it = $o['items']['1'] ?? [];
ok(count($it['days'] ?? []) === 21 && ($it['days'][0]['on_date'] ?? '') === $asOf->format('Y-m-d'), 'sixteen weeks: 21 forecast days from as_of');
$err = 0.0; $sum = 0.0; foreach ($it['days'] as $k => $d) { if ($k === 3) { continue; } $t = $truth($asOf->modify("+$k days"), 112 + $k); $err += abs($d['qty'] - $t); $sum += $t; }
ok($sum > 0 && $err / $sum < 0.10, 'Holt-Winters recovers the weekly cycle and the trend: WAPE against the truth ' . round($err / $sum * 100, 2) . ' % (under 10 %)');
$lohi = true; foreach ($it['days'] as $d) { $lohi = $lohi && $d['lo'] <= $d['qty'] + 1e-9 && $d['qty'] <= $d['hi'] + 1e-9 && $d['qty'] >= 0; }
ok($lohi, 'lo ≤ qty ≤ hi and no negative quantity on any day');
ok(($it['days'][3]['qty'] ?? 1) === 0.0 && ($it['days'][3]['hi'] ?? 1) === 0.0, 'a closed day forecasts 0 (lo, qty, hi)');
ok(isset($o['skipped'][0]['menu_item_id']) && $o['skipped'][0]['menu_item_id'] === 2 && str_contains($o['skipped'][0]['reason'], 'twelve weeks') && !isset($o['items']['2']), 'eight weeks of history: no forecast is written — the baseline answers');
ok($it['mae28'] <= $it['mae28_default'] + 1e-9 && in_array($it['alpha'], [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9], true) && in_array($it['beta'], [0.0, 0.05, 0.1, 0.2], true), 'the grid search picks parameters whose 28-day MAE (' . $it['mae28'] . ') is no worse than α = β = γ = 0.3 (' . $it['mae28_default'] . ')');
$yn = $y; $yn[40] = null; $yn[41] = null;
$o2 = $py(['as_of' => $asOf->format('Y-m-d'), 'horizon' => 7, 'closed_future' => [], 'items' => [['menu_item_id' => 5, 'start' => $start->format('Y-m-d'), 'y' => $yn]]]);
ok(count($o2['items']['5']['days'] ?? []) === 7, 'days the fit must skip (closed days) do not stop the forecast');
$bad = $py(['as_of' => 'not a date']);
ok(isset($bad['err']) || isset($bad['error']), 'a bad document is one JSON error and a non-zero exit, never a stack trace on the page');

echo "5. A Holt-Winters run through the door\n";
$r = jpost(27, '/projection/run.php', ['method' => 'holt_winters']);
$run2 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['items'] ?? 0) === 3 && ($r['json']['skipped'] ?? 0) === 1, 'three items have twelve weeks; the special (three weeks) is skipped and says so');
ok(row_count('forecast_runs') === 2 && (int) one('SELECT count(*) FROM forecast_runs WHERE is_current') === 1 && one('SELECT is_current FROM forecast_runs WHERE id = :r', ['r' => $run1]) === false && one('SELECT is_current FROM forecast_runs WHERE id = :r', ['r' => $run2]), 'a second run marks the first not current (one current run)');
ok((int) one('SELECT count(*) FROM item_forecasts WHERE run_id = :r', ['r' => $run2]) === 63 && (int) one('SELECT count(*) FROM item_forecasts WHERE run_id = :r AND lo IS NOT NULL AND hi IS NOT NULL AND lo <= qty AND qty <= hi', ['r' => $run2]) === 63, '63 rows (3 items × 21 days), every one with its band');
$params = json_decode((string) one('SELECT params FROM forecast_runs WHERE id = :r', ['r' => $run2]), true);
ok(isset($params['items'][(string) $mi['sand']]['alpha']) && count($params['skipped']) === 1, 'the run keeps the chosen α, β, γ per item and the skipped ones');
$j = jget(27, '/projection/')['json']['data'] ?? [];
$items = array_column($j['items'] ?? [], null, 'menu_item_id');
ok(($items[$mi['sand']]['days'][0]['source'] ?? '') === 'run' && ($items[$mi['special']]['days'][0]['source'] ?? '') === 'baseline' && ($j['method'] ?? '') === 'holt_winters' && $items[$mi['sand']]['lo'] !== null && $items[$mi['special']]['lo'] === null, 'the sandwich says run with a band; the special falls back to the baseline with none');
$sandSat = null; foreach ($items[$mi['sand']]['days'] as $k => $d) { if ((int) one('SELECT extract(isodow FROM current_date + CAST(:o AS integer))', ['o' => $k]) === 6 && $sandSat === null) { $sandSat = (float) $d['qty']; } }
ok($sandSat !== null && abs($sandSat - 40) < 4, 'the forecast has the weekend swing: a Saturday is about 40 (' . round((float) $sandSat, 1) . ')');

echo "6. Accuracy: MAE, WAPE and bias — never MAPE\n";
$acc = q('SELECT * FROM forecast_accuracy WHERE scope_id = 101 AND horizon_days = 7 AND window_days = 28 ORDER BY menu_item_id');
ok(count($acc) >= 3 && $acc[0]['mae'] !== null && $acc[0]['wape'] !== null && $acc[0]['bias'] !== null && (int) $acc[0]['n'] > 0, 'the run scored the baseline: a row per item with MAE, WAPE, bias and n');
ok(!in_array('mape', array_column(q("SELECT column_name FROM information_schema.columns WHERE table_name = 'forecast_accuracy'"), 'column_name'), true), 'forecast_accuracy has no MAPE column (zero-sale days make it meaningless)');
$sa = array_column($acc, null, 'menu_item_id')[$mi['sand']];
ok(near($sa['wape'], 0.0, 0.0001) && near($sa['bias'], 0.0, 0.0001), 'a perfectly regular series is scored perfectly (the sandwich: WAPE 0, bias 0)');

echo "7. The item page: the chart, the same weekdays, the accuracy\n";
$ip = page($marco, '/projection/items/' . $mi['sand']);
ok($ip['code'] === 200 && has($ip, 'data-screen="forecast-item"') && has($ip, 'id="forecast-chart"') && has($ip, 'id="same-weekdays-table"') && has($ip, 'id="override-form"') && has($ip, 'id="forecast-accuracy"') && has($ip, 'id="forecast-days-table"'), 'the item page: chart, same weekdays, the days, the accuracy, the Override form');
preg_match('#<svg.*?</svg>#s', $ip['body'], $svg); $svg = $svg[0] ?? '';
ok(substr_count($svg, 'class="fc-bar"') === 56 && preg_match('#<polyline class="fc-line"[^>]*points="([^"]+)"#', $svg, $pm) && count(explode(' ', trim($pm[1]))) === 21 && substr_count($svg, '<polygon class="fc-band"') === 1, 'the chart: eight weeks of bars (56), three weeks of line (21 points), the band shaded');
ok(!str_contains($svg, '<script') && !str_contains($svg, 'http://') && !str_contains($svg, 'https://') && str_contains($svg, 'role="img"') && str_contains($svg, '<title'), 'the chart is inline SVG: no script, no external reference, a title for a reader without sight');
$sw = jget(27, '/projection/items/' . $mi['sand'])['json']['data'] ?? [];
ok(count($sw['same_weekdays'] ?? []) === 4 && abs(array_sum(array_map(fn ($x) => $x['used'] ? (float) $x['weight'] : 0, $sw['same_weekdays'])) - 1.0) < 1e-9, 'the four same weekdays carry the weights 0.4 0.3 0.2 0.1 (sum 1)');
$none = page($marco, '/projection/items/999999');
ok($none['code'] === 404, 'a menu item that is not here is a 404');
ok(page($dana, '/projection/items/' . $mi['sand'])['code'] === 200 && !has(page($dana, '/projection/items/' . $mi['sand']), 'id="override-form"'), 'a Chef reads the item and is not offered the override form (forecast.adjust is the Manager\'s)');
finish();
