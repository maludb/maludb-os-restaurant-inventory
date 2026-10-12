<?php
/** Slice 8 — projected stock (projection.md "Proof": Projected stock): fourteen cells falling from on hand, a delivery due marked and lifting the line, the first negative cell, stockouts_only, the planned waste, the ingredient page. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$marco = person(27);
$dana = person(30);
$egg = $w['egg'];
$ps = fn (int $i) => q('SELECT * FROM ri_projected_stock(101, :i, 14) ORDER BY on_date', ['i' => $i]);

echo "1. Fourteen cells falling from on hand\n";
$rows = $ps($egg);
$onHand = (float) one('SELECT ri_on_hand(:i, 101)', ['i' => $egg]);
ok(count($rows) === 14 && (float) $rows[0]['projected_base'] < $onHand && (float) $rows[0]['projected_base'] > $onHand - 200, 'ri_projected_stock: fourteen days, the first below today\'s on hand (' . $onHand . ' → ' . $rows[0]['projected_base'] . ')');
$j = jget(27, '/projection/stock')['json']['data'] ?? [];
$ing = array_column($j['ingredients'] ?? [], null, 'ingredient_id');
$e = $ing[$egg] ?? [];
$same = count($e['projection'] ?? []) === 14;
foreach ($rows as $k => $r) { $same = $same && near($e['projection'][$k]['projected_base'] ?? null, (float) $r['projected_base'], 0.0001); }
ok($same && ($j['days'] ?? 0) === 14, 'the screen\'s cells equal ri_projected_stock() day for day (ri_projected_stock_all answers every ingredient in one explosion)');
$allOk = true;
foreach ([$w['flour'], $w['chicken'], $w['lime']] as $i) { foreach ($ps($i) as $k => $r) { $allOk = $allOk && near($ing[$i]['projection'][$k]['projected_base'] ?? null, (float) $r['projected_base'], 0.0001); } }
ok($allOk, 'the same holds for the flour, the chicken and the limes');
ok(!isset($ing[$w['gin']]) || near($ing[$w['gin']]['on_hand_base'], 0, 0), 'an ingredient with no stock, no demand and no par does not clutter the table (gin has a par, so it is a row at 0)');
$h = page($marco, '/projection/stock');
ok($h['code'] === 200 && has($h, 'data-screen="projected-stock"') && has($h, 'id="projected-table"') && has($h, 'id="projected-row-' . $egg . '"') && has($h, 'id="projected-cell-' . $egg . '-' . day(0) . '"') && has($h, 'id="projected-filters"'), 'the screen: a row per ingredient and a cell per day');
ok(substr_count(substr($h['body'], strpos($h['body'], 'id="projected-row-' . $egg . '"'), 6000), 'id="projected-cell-' . $egg . '-') >= 14, 'fourteen cells in the eggs\' row');

echo "2. The stock-out day\n";
$first = null; foreach ($rows as $r) { if ($first === null && (float) $r['projected_base'] < 0) { $first = $r['on_date']; } }
ok($first !== null && (string) $e['stockout_on'] === $first && (string) one('SELECT ri_stockout_on(101, :i, 14)', ['i' => $egg]) === $first, 'the eggs run out on ' . $first . ' — ri_stockout_on() agrees');
ok(preg_match('#<td class="num fc-cell fc-neg[^"]*" id="projected-cell-' . $egg . '-' . $first . '"#', $h['body']) === 1 && has($h, 'id="projected-stockout-' . $egg . '"'), 'the first negative cell is marked, and the row names the date and the days until');
$so = jget(27, '/projection/stock?stockouts_only=1')['json']['data']['ingredients'] ?? [];
$soIds = array_column($so, 'ingredient_id');
ok(in_array($egg, $soIds, true) && in_array($w['lime'], $soIds, true) && !in_array($w['flour'], $soIds, true) && !in_array($w['chicken'], $soIds, true), 'stockouts_only lists the eggs and the limes, not the flour or the chicken');
$sql = array_column(q('SELECT * FROM ri_stockouts_projected(101, 14)'), 'ingredient_id');
ok(count(array_diff($sql, $soIds)) === 0 && count(array_diff(array_filter($soIds, fn ($i) => !in_array($i, [$w['stock']], true)), array_map('intval', $sql))) === 0, 'it is exactly ri_stockouts_projected() (prep ingredients excluded: they are made, not bought)');
$d7 = array_column(jget(27, '/projection/stock?days=7')['json']['data']['ingredients'] ?? [], null, 'ingredient_id');
ok(count($d7[$egg]['projection'] ?? []) === 7, 'days=7 gives seven cells');

echo "3. A delivery due lifts the line\n";
$po = draft_and_place($marco, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 2, 'unit' => 'case']], day(2));
$rows2 = $ps($egg);
ok(near($rows2[2]['receipts_base'], 360, 0.0001) && near($rows2[1]['receipts_base'], 0, 0) && (float) $rows2[2]['projected_base'] > (float) $rows[2]['projected_base'] + 359, 'a placed order of two cases (360 eggs) due in two days lands on that day and lifts the line from then on');
$j = jget(27, '/projection/stock')['json']['data'] ?? [];
$e2 = array_column($j['ingredients'], null, 'ingredient_id')[$egg];
ok(near($e2['projection'][2]['receipts_base'], 360, 0.0001), 'the screen marks the receipt (receipts_base 360 on day 3)');
$h2 = page($marco, '/projection/stock');
ok(preg_match('#class="num fc-cell [^"]*fc-recv[^"]*" id="projected-cell-' . $egg . '-' . day(2) . '"#', $h2['body']) === 1, 'the delivery day is marked with the + and the green underline');
$so2 = array_column(jget(27, '/projection/stock?stockouts_only=1')['json']['data']['ingredients'] ?? [], 'ingredient_id');
ok(!in_array($egg, $so2, true) || (string) array_column(jget(27, '/projection/stock?stockouts_only=1')['json']['data']['ingredients'], null, 'ingredient_id')[$egg]['stockout_on'] > $first, 'with the order due, the eggs no longer run out on ' . $first);

echo "4. One ingredient\n";
$ip = page($marco, '/projection/stock/' . $egg);
ok($ip['code'] === 200 && has($ip, 'data-screen="projected-ingredient"') && has($ip, 'id="projected-chart"') && has($ip, 'id="projected-days-table"') && has($ip, 'id="projected-po-lines"') && has($ip, 'id="projected-suggested"') && has($ip, 'id="watch-this-btn"') && has($ip, 'id="projected-par"'), 'the ingredient page: the chart, the days, the orders on the way, the suggested line, Watch this, the par');
preg_match('#<svg.*?</svg>#s', $ip['body'], $svg); $svg = $svg[0] ?? '';
ok(preg_match('#<polyline class="fc-line"[^>]*points="([^"]+)"#', $svg, $pm) && count(explode(' ', trim($pm[1]))) === 14 && str_contains($svg, 'class="fc-zero"') && !str_contains($svg, '<script') && substr_count($svg, 'class="fc-receipt"') === 1, 'the chart: fourteen points, the zero line, one delivery step, no script');
ok(has($ip, 'SMOKE Sysco') && has($ip, (string) one('SELECT number FROM purchase_orders WHERE id = :i', ['i' => $po])), 'the order on the way is listed with its number and supplier');
$pj = jget(27, '/projection/stock/' . $egg)['json']['data'] ?? [];
ok(count($pj['days'] ?? []) === 14 && count($pj['po_lines'] ?? []) === 1 && ($pj['suggested']['line']['par_mode'] ?? '') === 'dynamic' && count($pj['drivers'] ?? []) === 2, 'JSON: fourteen days, the PO line, the dynamic suggested line, two drivers');
$fp = jget(27, '/projection/stock/' . $w['flour'])['json']['data'] ?? [];
ok(near($fp['par']['par_base'] ?? null, 250000, 0.01) && ($fp['par']['par_mode'] ?? '') === 'fixed' && near($fp['suggested']['line']['raw_base'] ?? null, 88975, 0.01), 'the flour: the par 250 kg fixed and the suggested line short by 88 975 g');
ok(page($marco, '/projection/stock/999999')['code'] === 404, 'an ingredient that is not here is a 404');

echo "5. The planned waste\n";
post_waste($w['lime'], $w['walkin'], 6, 4);
$wasteShare = (float) one("SELECT sum(-qty_base) FILTER (WHERE txn_type = 'waste') / NULLIF(sum(-qty_base) FILTER (WHERE txn_type = 'depletion'), 0) FROM inventory_transactions WHERE scope_id = 101 AND ingredient_id = :i AND business_date >= current_date - 28", ['i' => $w['lime']]);
$raw = (float) one("SELECT COALESCE(sum(qty_base), 0) FROM ri_ingredient_demand(101, current_date + 1, current_date + 1) WHERE ingredient_id = :i", ['i' => $w['lime']]);
$lp = q('SELECT * FROM ri_projected_stock(101, :i, 14) ORDER BY on_date', ['i' => $w['lime']]);
ok($wasteShare > 0 && near($lp[1]['demand_base'], $raw * (1 + $wasteShare), 0.001), 'the projection adds the waste share of the last 28 days to the demand: ' . round($wasteShare * 100, 2) . ' % (6 limes wasted of the depletion) → ' . round((float) $lp[1]['demand_base'], 3) . ' = ' . round($raw, 3) . ' × (1 + share)');
ok(page($dana, '/projection/stock')['code'] === 200 && page($dana, '/projection/stock/' . $egg)['code'] === 200, 'Dana (reports.read) reads the projection');
$cx = post($marco, '/purchasing/cancel.php', ['purchase_order' => $po, 'reason' => 'SMOKE proof']);
ok(one('SELECT status FROM purchase_orders WHERE id = :i', ['i' => $po]) === 'cancelled', '(the proof\'s order is cancelled again so the next proofs start clean)');
finish();
