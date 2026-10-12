<?php
/** Slice 8 — the trading calendar and booked covers (projection.md "Proof": Calendar and covers): a closure, a holiday, covers_sync against the fake kernel (mapped by service, an unknown service into the day), the covers factor, no_connection, two calls for a long window. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$a0 = last_activity_id();
$marco = person(27);
$dana = person(30);
$sand = $w['mi_sand'];
if (!one("SELECT 1 FROM day_parts WHERE scope_id = 101 AND service_name = 'Lunch'")) {
    ri_sql("INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, service_name) VALUES (101, 'lunch', 'Lunch', '11:00', '15:00', 'Lunch'), (101, 'dinner', 'Dinner', '17:00', '22:00', 'Dinner')");
}
$lunch = id_of("SELECT id FROM day_parts WHERE scope_id = 101 AND key = 'lunch'"); $dinner = id_of("SELECT id FROM day_parts WHERE scope_id = 101 AND key = 'dinner'");
$cell = fn (string $d, int $item = 0) => q('SELECT * FROM ri_item_forecast(101, :i, :d::date)', ['i' => $item ?: $sand, 'd' => $d])[0];
$D = day(9); $month = substr($D, 0, 7);

echo "1. A closure and a holiday\n";
$r = jpost(27, '/projection/calendar/save.php', ['on_date' => $D, 'closed' => 'yes', 'holiday' => 'SMOKE Founders Day', 'event' => '', 'note' => 'closed for the parade']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? '') === $D && $r['json']['location'] === '/projection/calendar?month=' . $month . '&notice=saved#calendar-day-' . $D && str_contains($r['json']['did'], 'Closed'), 'calendar_day_set: closed, the day is the record, landing on the month');
ok($cell($D)['source'] === 'closed' && (float) $cell($D)['qty'] === 0.0, 'the day forecasts zero');
$back = day(9 + 7);
ok(near(one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $sand, 'd' => $back]), (float) one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $sand, 'd' => $back]), 0) && (int) one('SELECT count(*) FROM calendar_days WHERE scope_id = 101 AND on_date = :d AND closed', ['d' => $D]) === 1, 'the closure is a row (a closed back-week is left out of the baseline — proven with the weights in forecast.php)');
$h = page($marco, '/projection/calendar?month=' . $month);
ok(has($h, 'data-screen="calendar-days"') && has($h, 'id="calendar-grid"') && has($h, 'id="calendar-day-' . $D . '"') && has($h, 'SMOKE Founders Day') && preg_match('#id="calendar-day-' . $D . '"[^>]*data-closed="1"#', $h['body']) === 1 && has($h, 'id="covers-sync-btn"'), 'the month grid: a cell per day, the holiday named, the closed day marked, Sync covers');
$h = page($marco, '/projection/calendar?month=' . $month . '&day=' . $D);
ok(has($h, 'id="calendar-day-form"') && has($h, 'id="calendar-day-form-field-closed"') && has($h, 'value="SMOKE Founders Day"') && has($h, 'checked'), 'a tap on a day opens its form prefilled (closed ticked, the holiday)');
$r = jpost(27, '/projection/calendar/save.php', ['on_date' => $D, 'closed' => 'no', 'holiday' => '', 'event' => '', 'note' => '']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM calendar_days WHERE scope_id = 101 AND on_date = :d', ['d' => $D]) === 0 && $cell($D)['source'] !== 'closed', 'a day with nothing left to say is no row, and forecasts again');
$r = jpost(27, '/projection/calendar/save.php', ['on_date' => 'not a date', 'closed' => 'yes']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['on_date']), 'a day that is not a date is refused');
$r = jpost(27, '/projection/calendar/save.php', ['on_date' => $D, 'holiday' => str_repeat('x', 81)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['holiday']), 'a holiday over 80 characters is refused');
$r = jpost(30, '/projection/calendar/save.php', ['on_date' => $D, 'closed' => 'yes']);
ok($r['code'] === 403, 'Dana (reports.read, no forecast.adjust) may not name a day');
ok(!has(page($dana, '/projection/calendar?month=' . $month . '&day=' . $D), 'id="calendar-day-form"') && has(page($dana, '/projection/calendar?month=' . $month), 'id="calendar-grid"') && !has(page($dana, '/projection/calendar?month=' . $month), 'id="covers-sync-btn"'), 'Dana reads the calendar: no form, no Sync covers');
$lg = activity('calendar.day_set', $a0); $la = json_decode($lg[0]['after'], true);
ok(count($lg) === 2 && $la['closed'] === true && $la['holiday'] === 'SMOKE Founders Day' && (int) $lg[0]['scope_id'] === 101, 'calendar.day_set is logged (the day, closed, the holiday) with the site');

echo "2. Booked covers from Reservations (K7)\n";
@unlink(need('FAKE_KERNEL_STATE') . '.reads');
kernel_state(function ($s) use ($D) { $s['read'] = ['mode' => 'ok', 'rows' => [['date' => $D, 'service' => 'Lunch', 'reservations' => 10, 'covers' => 60], ['date' => $D, 'service' => 'Dinner', 'reservations' => 30, 'covers' => 140], ['date' => $D, 'service' => 'Brunch', 'reservations' => 6, 'covers' => 25]]]; return $s; });
$r = jpost(27, '/projection/calendar/covers.php', ['from_date' => day(1), 'to_date' => day(20)]);
ok($r['code'] === 200 && ($r['json']['connected'] ?? false) === true && ($r['json']['covers'] ?? 0) === 225 && ($r['json']['days'] ?? 0) === 1 && str_contains($r['json']['did'], '225 covers'), 'covers_sync against the fake kernel: connected, 225 covers over one day');
$ce = q('SELECT day_part_id, covers, source FROM covers_expected WHERE scope_id = 101 AND on_date = :d ORDER BY day_part_id NULLS LAST', ['d' => $D]);
$byPart = []; foreach ($ce as $c) { $byPart[(string) ($c['day_part_id'] ?? 'day')] = (int) $c['covers']; }
ok($byPart[(string) $lunch] === 60 && $byPart[(string) $dinner] === 140 && $byPart['day'] === 25 && count($ce) === 3 && $ce[0]['source'] === 'reservations', 'mapped to Lunch and Dinner by service_name; the unknown service (Brunch) summed into the day\'s whole-day row');
$reads = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents(need('FAKE_KERNEL_STATE') . '.reads'))));
ok(count($reads) === 1 && $reads[0]['body']['provider'] === 'reservations' && $reads[0]['body']['tool'] === 'covers_by_service' && $reads[0]['body']['arguments']['location_id'] === 10 && $reads[0]['body']['arguments']['from'] === day(1) && $reads[0]['body']['arguments']['to'] === day(20), 'one K7 call: reservations.covers_by_service for the site\'s kernel location and the window');
$lg = activity('forecast.covers_sync', $a0); $la = json_decode($lg[count($lg) - 1]['after'], true);
ok($la['connected'] === true && $la['covers'] === 225 && $la['days'] === 1, 'forecast.covers_sync is logged: connected, days, covers');
$h = page($marco, '/projection/calendar?month=' . $month);
ok(preg_match('#id="calendar-day-' . $D . '".*?(\d+)</div>#s', $h['body']) === 1 && has($h, 'title="booked covers"') && has($h, 'Booked covers last synced'), 'the calendar shows the day\'s booked covers and when they were synced');
$jc = jget(27, '/projection/calendar?month=' . $month)['json']['data'] ?? [];
$jd = array_column($jc['days'] ?? [], null, 'on_date')[$D] ?? [];
ok(($jd['covers'] ?? 0) === 225 && $jd['total_kind'] === 'forecast', 'JSON: the day carries 225 covers and a forecast total');

echo "3. The covers factor\n";
ri_sql('DELETE FROM covers_expected');
$norm = (float) one("SELECT avg(c) FROM (SELECT sum(covers) AS c FROM pos_sales WHERE scope_id = 101 AND business_date IN (CAST(:a AS date), CAST(:b AS date), CAST(:c AS date), CAST(:d AS date)) GROUP BY business_date) x", ['a' => day(5 - 7), 'b' => day(5 - 14), 'c' => day(5 - 21), 'd' => day(5 - 28)]);
ok($norm === 200.0, 'the weekday\'s norm is 200 covers (four weeks of POS checks)');
ri_sql("INSERT INTO covers_expected (scope_id, on_date, day_part_id, covers, source) VALUES (101, :d::date, NULL, 300, 'reservations')", ['d' => day(5)]);
ok(near(one('SELECT ri_covers_factor(101, :d::date)', ['d' => day(5)]), 1.5, 0.0001), '300 booked against a norm of 200 is a factor of 1.5');
$base = (float) one('SELECT ri_forecast_baseline(101, :i, :d::date)', ['i' => $sand, 'd' => day(5)]);
ok(near($cell(day(5))['qty'], $base * 1.5, 0.0001) && $cell(day(5))['source'] === 'baseline', 'the forecast carries the factor (baseline × 1.5) and still says baseline — covers are a factor, never the forecast');
ri_sql("INSERT INTO covers_expected (scope_id, on_date, day_part_id, covers, source) VALUES (101, :d::date, NULL, 210, 'reservations'), (101, :e::date, NULL, 229, 'reservations'), (101, :f::date, NULL, 231, 'reservations')", ['d' => day(6), 'e' => day(7), 'f' => day(8)]);
ok(near(one('SELECT ri_covers_factor(101, :d::date)', ['d' => day(6)]), 1, 0) && near(one('SELECT ri_covers_factor(101, :d::date)', ['d' => day(7)]), 1, 0) && near(one('SELECT ri_covers_factor(101, :d::date)', ['d' => day(8)]), 1.155, 0.0001), '210 and 229 booked (within 15 %) leave the factor 1; 231 (15.5 % over) is 1.155');
ok(near(one('SELECT ri_covers_factor(101, :d::date)', ['d' => day(10)]), 1, 0), 'no booked covers: the factor is 1');
$j = jget(27, '/projection/items/' . $sand)['json']['data'] ?? [];
ok(near(array_column($j['days'], null, 'on_date')[day(5)]['covers_factor'] ?? 0, 1.5, 0.0001), 'the item page carries the covers factor per day');
ri_sql('DELETE FROM covers_expected');

echo "4. Reservations not connected, and a long window\n";
$before = row_count('covers_expected');
ri_sql("INSERT INTO covers_expected (scope_id, on_date, day_part_id, covers, source) VALUES (101, :d::date, NULL, 180, 'reservations')", ['d' => day(12)]);
kernel_state(function ($s) { $s['read'] = ['mode' => 'no_connection']; return $s; });
$r = jpost(27, '/projection/calendar/covers.php', []);
ok($r['code'] === 200 && ($r['json']['connected'] ?? 1) === false && str_contains($r['json']['did'] ?? '', 'Reservations is not connected — the forecast runs without covers'), 'no_connection is a 200 that says so: "Reservations is not connected — the forecast runs without covers"');
ok(row_count('covers_expected') === $before + 1, 'no rows changed (the earlier sync\'s stay)');
$h = page($marco, '/projection/calendar?month=' . substr(day(12), 0, 7));
ok(has($h, 'Reservations is not connected') || has($h, 'Booked covers last synced'), 'the calendar tells a Manager about the missing connection');
$lg = activity('forecast.covers_sync', $a0); $la = json_decode($lg[count($lg) - 1]['after'], true);
ok($la['connected'] === false && $la['code'] === 'no_connection', 'the log says connected false with the kernel\'s code');
@unlink(need('FAKE_KERNEL_STATE') . '.reads');
kernel_state(function ($s) { $s['read'] = ['mode' => 'ok', 'rows' => []]; return $s; });
$r = jpost(27, '/projection/calendar/covers.php', ['from_date' => day(0), 'to_date' => day(89)]);
$reads = array_filter(explode("\n", (string) @file_get_contents(need('FAKE_KERNEL_STATE') . '.reads')));
ok($r['code'] === 200 && count($reads) === 2, 'a 90-day window is two calls of at most 62 days');
ok((int) one("SELECT count(*) FROM covers_expected WHERE source = 'reservations'") === 0, 'a sync replaces the window\'s Reservations rows (the answer was empty)');
$r = jpost(27, '/projection/calendar/covers.php', ['from_date' => day(0), 'to_date' => day(300)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['to_date']), 'a window over 185 days is refused');
$r = jpost(30, '/projection/calendar/covers.php', []);
ok($r['code'] === 403, 'Dana may not sync the covers (forecast.adjust)');
kernel_state(function ($s) { unset($s['read']); return $s; });
finish();
