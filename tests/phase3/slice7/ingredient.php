<?php
/** Slice 7 — one ingredient's window (periods.md "Proof": The ingredient's week): the buckets, the recipes that consumed it with their shares, the waste logged, the count lines, the movements, the cost history. */
require __DIR__ . '/lib.php';
$w = period_world();
$dana = person(30); $bea = person(28); $ann = person(29);
$f = wk(4); $t = wk(6, 6);
if ((int) one("SELECT count(*) FROM production_batches WHERE scope_id = 101 AND status = 'posted'") === 0) { post($dana, '/production/save.php', ['recipe' => $w['r_stock'], 'batches' => '1', 'area' => $w['walkin']]); }
if ((int) one("SELECT count(*) FROM waste_logs WHERE scope_id = 101 AND ingredient_id = :i", ['i' => $w['egg']]) === 0) { post($dana, '/waste/save.php', ['stage' => 'ingredient', 'ingredient' => 'L-EGG', 'qty' => '2', 'unit' => 'each', 'reason' => 'spoilage', 'area' => $w['walkin']]); }
$batch = (int) one("SELECT max(id) FROM production_batches WHERE scope_id = 101");
$ck = $w['chicken'];

echo "1. The buckets\n";
$h = page($dana, "/usage/$ck?from=$f&to=$t");
ok($h['code'] === 200 && has($h, 'data-screen="usage-ingredient"') && has($h, 'data-record-id="' . $ck . '"') && has($h, 'id="usage-ingredient-buckets"') && has($h, 'id="bucket-actual"') && has($h, 'id="bucket-unexplained"') && has($h, 'id="usage-ingredient-note"'), 'chicken\'s page: its stamp, the bucket cards and the note that the variance is at the ingredient');
$j = jget(30, "/usage/$ck?from=$f&to=$t")['json']['data'] ?? [];
$ref = usage_ref($f, $t)[$ck];
$same = true;
foreach (['opening', 'purchases', 'transfers_in', 'transfers_out', 'production_in', 'production_out', 'adjustments', 'closing', 'actual', 'theoretical', 'variance', 'waste', 'comps', 'unexplained'] as $b) { $same = $same && near($j['row'][$b . '_base'] ?? null, (float) $ref[$b . '_base'], 0.0001); }
ok($same && ($j['ingredient']['code'] ?? '') === 'L-CHK', 'the buckets are ri_usage()\'s for the same window (every one of the fourteen)');
ok(has(page($dana, "/usage/$ck?from=" . wk(2) . '&to=' . wk(2, 6) . '&pick=custom'), 'id="usage-ingredient-buckets"'), 'another window shows the same ingredient\'s other week');
ok(has(page($dana, "/usage/$ck?from=" . date('Y-m-d', strtotime(wk(1) . ' -60 days')) . '&to=' . date('Y-m-d', strtotime(wk(1) . ' -54 days'))), 'id="usage-ingredient-empty"'), 'a window where nothing moved says so');

echo "2. The recipes that consumed it\n";
$cons = array_column($j['consumers'] ?? [], null, 'recipe_id');
$sold = (float) one('SELECT sum(qty) FROM sales_daily WHERE scope_id = 101 AND day_part_id IS NULL AND business_date BETWEEN :a::date AND :b::date', ['a' => $f, 'b' => $t]);
$perSandwich = 170 / 0.9;
ok(isset($cons[$w['r_sand']]) && isset($cons[$w['r_stock']]) && count($cons) === 2, 'the consumers table names the sandwich and the stock prep');
ok(near($cons[$w['r_sand']]['qty_base'] ?? null, $sold * $perSandwich, 0.05) && $sold == 141.0, 'the sandwich took 141 sold × 188.89 g (170 g at a 90 % line yield) = ' . number_format($sold * $perSandwich, 2) . ' g');
$prep = -1 * (float) one("SELECT sum(qty_base) FROM inventory_transactions WHERE reference_kind = 'production' AND reference_id = :b AND ingredient_id = :i AND txn_type = 'production_out'", ['b' => $batch, 'i' => $ck]);
ok(near($cons[$w['r_stock']]['qty_base'] ?? null, $prep, 0.001) && $prep > 0, 'the stock prep took what the batch consumed (' . number_format($prep) . ' g)');
$dep = -1 * (float) one("SELECT sum(qty_base) FROM inventory_transactions WHERE ingredient_id = :i AND scope_id = 101 AND txn_type = 'depletion' AND business_date BETWEEN :a::date AND :b::date", ['i' => $ck, 'a' => $f, 'b' => $t]);
ok(near($cons[$w['r_sand']]['qty_base'] ?? null, $dep, 0.05), '…and the sandwich\'s figure is the ledger\'s depletion of the same window');
ok(abs(array_sum(array_map(fn ($c) => (float) $c['share_pct'], $j['consumers'])) - 100.0) < 0.11, 'the shares add to 100 % (' . implode(' + ', array_map(fn ($c) => $c['share_pct'], $j['consumers'])) . ')');
ok(has($h, 'id="consumer-row-' . $w['r_sand'] . '"') && has($h, 'id="consumer-row-' . $w['r_stock'] . '"') && has($h, 'id="usage-consumers-table"'), 'the table is on the page, a row per recipe, each a link');

echo "3. Waste, counts, movements, cost\n";
$eh = page($dana, '/usage/' . $w['egg'] . "?from=$f&to=$t");
$ej = jget(30, '/usage/' . $w['egg'] . "?from=$f&to=$t")['json']['data'] ?? [];
$wl = (int) one('SELECT id FROM waste_logs WHERE scope_id = 101 AND ingredient_id = :i ORDER BY id DESC LIMIT 1', ['i' => $w['egg']]);
ok(has($eh, 'id="waste-row-' . $wl . '"') && count($ej['waste'] ?? []) === 1 && near(abs((float) ($ej['waste'][0]['qty_base'] ?? 0)), 2, 0.0001), 'the waste logged for eggs in the window is listed (the door\'s: 2 eggs, spoilage)');
$cl = $ej['count_lines'][0] ?? [];
ok(count($ej['count_lines'] ?? []) === 1 && ($cl['number'] ?? '') === one('SELECT number FROM inventory_counts WHERE id = :i', ['i' => $w['count']]) && near($cl['variance_base'] ?? null, 41, 0.0001) && has($eh, 'id="count-line-row-' . ($cl['count_line_id'] ?? 0) . '"'), 'the approved count\'s line for eggs is listed with its +41');
ok(($ej['movements_total'] ?? 0) >= 8 && count($ej['movements'] ?? []) >= 8 && has($eh, 'id="usage-movements-table"'), 'the movements of the window are the ledger\'s (' . ($ej['movements_total'] ?? 0) . ' for eggs)');
$types = array_unique(array_column($ej['movements'] ?? [], 'type'));
ok(in_array('receipt', $types, true) && in_array('depletion', $types, true) && in_array('waste', $types, true) && in_array('count_correction', $types, true) && in_array('transfer_out', $types, true), 'receipts, depletions, waste, a count correction and a transfer all appear among them');
ok(count($ej['cost_history'] ?? []) >= 1 && has($eh, 'id="usage-cost-history"'), 'the cost history of the window (a row per receipt)');

echo "4. Cost is the wall, and the rest\n";
$bh = page($bea, '/usage/' . $w['egg'] . "?from=$f&to=$t");
$bj = jget(28, '/usage/' . $w['egg'] . "?from=$f&to=$t")['json']['data'] ?? [];
ok($bh['code'] === 200 && !has($bh, 'id="usage-cost-history"') && substr_count($bh['body'], 'cost withheld') >= 5 && ($bj['cost_withheld'] ?? false) === true && !isset($bj['row']['unexplained_value']) && near($bj['row']['unexplained_base'] ?? null, -41, 0.0001), 'Bea (Kitchen) reads the units and every value is "—"; JSON says cost_withheld and carries no value');
ok(page($ann, "/usage/$ck?from=$f&to=$t")['code'] === 200, 'a Viewer reads the ingredient\'s week too (ri.read)');
ok(page($dana, '/usage/999999?from=' . $f . '&to=' . $t)['code'] === 404, 'an ingredient that is not there is a 404');
$r = page($dana, "/usage/$ck?from=$t&to=$f");
ok($r['code'] === 422 && str_contains(says_text($r), 'ends before it starts'), 'a window that ends before it starts is refused in words');
ok(has(page($dana, "/usage/$ck?from=$f&to=$t&back=" . rawurlencode("/usage/?from=$f&to=$t")), 'Back to Actual vs theoretical'), 'the back link says where it goes ("Back to Actual vs theoretical")');
$hr = page($dana, "/usage/?from=$f&to=$t");
ok(preg_match('#id="usage-row-' . $ck . '".*?href="(/usage/' . $ck . '\?from=' . $f . '&amp;to=' . $t . '[^"]*back=[^"]+)"#s', $hr['body']) === 1, 'in the report an ingredient\'s name is a link to its week with the window and the way back');
finish();
