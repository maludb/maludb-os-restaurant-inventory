<?php
/** Slice 8 — ingredient demand (projection.md "Proof": Demand): the forecast exploded through the recipes, eggs from two recipes, the chicken with its yield, the drivers summing to 100 %, the window's limit, the count unit with the base in a title. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$marco = person(27);
$dana = person(30);
$from = day(1); $to = day(7);
$dem = fn (int $ing, string $d) => (float) one('SELECT COALESCE(sum(qty_base), 0) FROM ri_ingredient_demand(101, :d::date, :d2::date) WHERE ingredient_id = :i', ['i' => $ing, 'd' => $d, 'd2' => $d]);
$fc = fn (int $mi, string $d) => (float) one('SELECT qty FROM ri_item_forecast(101, :i, :d::date)', ['i' => $mi, 'd' => $d]);

echo "1. The explosion\n";
ok(near($dem($w['egg'], day(2)), $fc($w['mi_sand'], day(2)) * 1 + $fc($w['mi_omel'], day(2)) * 2, 0.001), 'the egg demand on a day is the sandwich forecast (one egg each) plus twice the omelette\'s: ' . $dem($w['egg'], day(2)));
ok(near($dem($w['chicken'], day(2)), $fc($w['mi_sand'], day(2)) * 170 / 0.9, 0.01), 'the chicken: 170 g through the line yield of 90 % = 188.9 g a sandwich (the stock prep is a leaf, taken from stock)');
ok(near($dem($w['stock'], day(2)), $fc($w['mi_sand'], day(2)) * 50, 0.01), 'the prep (chicken stock) is demanded as itself, 50 ml a sandwich');
ok(near($dem($w['lime'], day(2)), $fc($w['mi_soda'], day(2)) + $fc($w['mi_special'], day(2)), 0.001), 'limes: one per soda and one per daily special (two menu items on one recipe)');
ok($dem($w['rib'], day(2)) == 0.0, 'short rib is on no menu item: no demand');
ri_sql("INSERT INTO forecast_overrides (scope_id, menu_item_id, from_date, to_date, factor, reason, status, set_by) VALUES (101, :i, :d::date, :d2::date, 2, 'SMOKE', 'set', 27)", ['i' => $w['mi_omel'], 'd' => day(2), 'd2' => day(2)]);
ok(near($dem($w['egg'], day(2)), $fc($w['mi_sand'], day(2)) + $fc($w['mi_omel'], day(2)) * 2, 0.001) && $fc($w['mi_omel'], day(2)) > 20, 'an override flows through to the demand (the omelette doubled)');
ri_sql('DELETE FROM forecast_overrides');

echo "2. The report\n";
$j = jget(27, "/projection/demand?from=$from&to=$to")['json']['data'] ?? [];
$rows = array_column($j['ingredients'] ?? [], null, 'ingredient_id');
$egg = $rows[$w['egg']] ?? [];
ok(count($j['dates'] ?? []) === 7 && count($egg['days'] ?? []) === 7 && $egg['count_unit'] === 'flat' && $egg['base_unit'] === 'each', 'seven days; eggs are shown in their count unit (the flat of 30) with the base each in the JSON');
$sumBase = 0.0; foreach ($egg['days'] as $d) { $sumBase += (float) $d['base']; }
ok(near($egg['total_base'], $sumBase, 0.001) && near($egg['total_count'], $sumBase / 30, 0.001), 'the total is the sum of the days, in flats: ' . round((float) $egg['total_count'], 2));
foreach ($egg['days'] as $k => $d) { if (!near($d['base'], $dem($w['egg'], $d['on_date']), 0.001)) { $bad = true; } }
ok(!isset($bad), 'every day equals ri_ingredient_demand() for that day');
$h = page($marco, "/projection/demand?from=$from&to=$to");
ok($h['code'] === 200 && has($h, 'data-screen="demand-report"') && has($h, 'id="demand-table"') && has($h, 'id="demand-row-' . $w['egg'] . '"') && has($h, 'id="demand-form"') && has($h, 'id="demand-form-field-from"') && preg_match('#id="demand-row-' . $w['egg'] . '".*?title="[0-9.]+ each"#s', $h['body']) === 1, 'the screen: the table, a row per ingredient, the base figure in each cell\'s title');
$cat = jget(27, "/projection/demand?from=$from&to=$to&category=dairy")['json']['data']['ingredients'] ?? [];
ok(count($cat) === 1 && $cat[0]['ingredient_id'] === $w['egg'], 'a category filter keeps its ingredients (dairy: the eggs)');
$def = jget(27, '/projection/demand')['json']['data'] ?? [];
ok(count($def['dates'] ?? []) === 7 && ($def['dates'][0] ?? '') === today_db(), 'with no window the report opens on this week: today and six days');

echo "3. What drives it\n";
$dr = jget(27, "/projection/demand?from=$from&to=$to&drivers=" . $w['egg'])['json']['data']['drivers'] ?? [];
$names = array_column($dr, 'name');
ok(count($dr) === 2 && in_array('SMOKE8 Chicken sandwich', $names, true) && in_array('SMOKE8 Omelette', $names, true), 'two menu items drive the eggs: the sandwich and the omelette');
$sumShare = array_sum(array_map(fn ($d) => (float) $d['share_pct'], $dr));
$sumQty = array_sum(array_map(fn ($d) => (float) $d['qty_base'], $dr));
ok(abs($sumShare - 100) < 0.2 && near($sumQty, (float) $egg['total_base'], 0.01), 'the drivers sum to 100 % (' . $sumShare . ') and to the ingredient\'s total (' . $sumQty . ')');
$h = page($marco, "/projection/demand?from=$from&to=$to&drivers=" . $w['egg']);
ok(has($h, 'id="demand-drivers"') && has($h, 'id="demand-drivers-table"') && has($h, 'id="demand-drivers-link-' . $w['egg'] . '"'), 'the drivers table sits under the report');

echo "4. The window\n";
$r = page($marco, '/projection/demand?from=' . day(0) . '&to=' . day(28));
ok($r['code'] === 422 && says($r, 'at most 28 days'), 'a 29-day window is refused in words');
$r = page($marco, '/projection/demand?from=' . day(5) . '&to=' . day(2));
ok($r['code'] === 422 && says($r, 'ends before it starts'), 'a window that ends before it starts is refused');
$ok28 = page($marco, '/projection/demand?from=' . day(0) . '&to=' . day(27));
ok($ok28['code'] === 200, 'a 28-day window is allowed');
ok(page($dana, "/projection/demand?from=$from&to=$to")['code'] === 200, 'Dana (reports.read) reads it');
finish();
