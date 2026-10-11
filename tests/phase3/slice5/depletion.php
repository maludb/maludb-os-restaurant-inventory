<?php
/** Slice 5 — the depletion run, re-run and void (pos.md "Proof"). Day 1 and day 2 as the Toast import posted them; then 2002 mapped, re-run, voided, re-run; a changed check. */
require __DIR__ . '/lib.php';
$w = pos_world();
$bea = at(28, DOWNTOWN);
$ann = at(29, DOWNTOWN);
$dana = at(30, DOWNTOWN);
$marco = at(27, DOWNTOWN);
$since = last_activity_id();
$toast = source_id('SMOKE Toast export');
$ING = ['chicken', 'tomato', 'bun', 'gin', 'lime'];
$COST = ['chicken' => 0.007, 'tomato' => 0.003, 'bun' => 0.5, 'gin' => 0.03, 'lime' => 0.25];   // the receipt of 2026-10-01, per base unit

echo "1. Day 1's run\n";
$r1 = live_run(D1);
ok($r1 !== null && $r1['status'] === 'partial' && (int) $r1['run_no'] === 1 && (int) $r1['started_by'] === 28, "day 1's run: partial (the unmapped PLU), run 1, Bea's");
ok((int) $r1['lines_total'] === 6 && (int) $r1['lines_depleted'] === 4 && (int) $r1['lines_unmapped'] === 1 && (int) $r1['lines_voided'] === 1 && (int) $r1['modifiers_unmapped'] === 0 && (int) $r1['ingredients'] === 5, 'lines 6: depleted 4, unmapped 1, voided 1; five ingredients');
$run1 = (int) $r1['id'];
$chk = 3 * SANDWICH_CHICKEN_G + 85;                      // two sandwiches and the comped one, the voided absent; one XCHK
ok(near(run_qty($run1, $w['chicken']), -$chk), 'chicken = 3 sandwiches × 188.89 g + XCHK 85 g = ' . round($chk, 2) . ' g (the void absent, the comp present)');
ok(near(run_qty($run1, $w['tomato']), -60.0), 'tomato = 3 × 30 g − NOTOM 30 g = 60 g (the remove)');
ok(near(run_qty($run1, $w['bun']), -3.0), 'buns 3');
ok(near(run_qty($run1, $w['gin']), -3 * GT_GIN_ML), 'gin = 3 G&Ts × 44.36 ml = ' . round(3 * GT_GIN_ML, 2) . ' ml (SMK-003 at 03:30 included)');
ok(near(run_qty($run1, $w['lime']), -0.375, 0.0001), 'limes = 3 × 0.125');
$rows = q("SELECT ingredient_id, storage_area_id, txn_type, to_char(occurred_at AT TIME ZONE 'America/New_York', 'YYYY-MM-DD HH24:MI:SS') AS at, business_date::text AS bd, value, unit_cost_per_base FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = :r ORDER BY ingredient_id", ['r' => $run1]);
ok(count($rows) === 5 && count(array_unique(array_map(static fn ($x) => $x['ingredient_id'] . '/' . $x['storage_area_id'], $rows))) === 5, 'one ledger row per ingredient per area');
$areas = array_column($rows, 'storage_area_id', 'ingredient_id');
ok((int) $areas[$w['gin']] === $w['bar'] && (int) $areas[$w['chicken']] === $w['walkin'] && (int) $areas[$w['lime']] === $w['walkin'], "at the depletion area (the Walk-in), gin at its own default area (the Bar)");
ok(array_unique(array_column($rows, 'at')) === ['2026-10-06 03:59:59'] && array_unique(array_column($rows, 'bd')) === [D1], "every row's occurred_at = the day's sales cut − 1 s (2026-10-06 03:59:59 local), business_date the day");
ok(count(array_unique(array_column($rows, 'txn_type'))) === 1, 'one movement kind: ' . ($rows[0]['txn_type'] ?? '?'));
$val = 0.0; foreach ($ING as $i) { $val += -run_qty($run1, $w[$i]) * $COST[$i]; }
ok(near((float) $r1['value'], $val) && near(-array_sum(array_map('floatval', array_column($rows, 'value'))), (float) $r1['value']), 'value on the run = Σ qty × the cost as of the cut = ' . number_format($val, 2));

echo "2. Day 2\n";
$r2 = live_run(D2);
$run2 = (int) $r2['id'];
ok($r2['status'] === 'done' && (int) $r2['lines_total'] === 2 && (int) $r2['lines_depleted'] === 2 && (int) $r2['ingredients'] === 4, "day 2's run done: two lines, four ingredients (no tomato — NOTOM took the sandwich's)");
ok(near(run_qty($run2, $w['chicken']), -(SANDWICH_CHICKEN_G + 170)) && near(run_qty($run2, $w['gin']), -3 * GT_GIN_ML) && near(run_qty($run2, $w['tomato']), 0.0), 'chicken 188.89 + XCHK ×2 = 358.89 g; gin 3 × 44.36 ml; tomato none');
ok((string) one("SELECT to_char(min(occurred_at) AT TIME ZONE 'America/New_York', 'YYYY-MM-DD HH24:MI:SS') FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = :r", ['r' => $run2]) === '2026-10-07 03:59:59', "day 2's rows at 2026-10-07 03:59:59 local");
ok(near(on_hand($w['chicken']), 20000 - $chk - SANDWICH_CHICKEN_G - 170) && near(on_hand($w['bun']), 48 - 4) && near(on_hand($w['lime']), 100 - 0.75, 0.0001), 'on hand: chicken 20 kg less both days, buns 44, limes 99.25');

echo "3. sales_daily\n";
$sd = static function (string $plu, string $date, ?string $part) use ($toast): ?array {
    return q("SELECT d.qty, d.net, d.comps_qty FROM sales_daily d JOIN menu_items m ON m.id = d.menu_item_id LEFT JOIN day_parts p ON p.id = d.day_part_id
              WHERE m.pos_source_id = :s AND m.plu = :p AND d.business_date = :d AND " . ($part === null ? 'd.day_part_id IS NULL' : 'p.name = :n'), ['s' => $toast, 'p' => $plu, 'd' => $date] + ($part === null ? [] : ['n' => $part]))[0] ?? null;
};
$sDay = $sd('1001', D1, null); $sL = $sd('1001', D1, 'Lunch'); $sD = $sd('1001', D1, 'Dinner');
ok($sDay !== null && (float) $sDay['qty'] === 3.0 && (float) $sDay['comps_qty'] === 1.0, 'the sandwich on day 1: the day row 3 sold (the void out), one comped');
ok($sL !== null && $sD !== null && (float) $sL['qty'] === 2.0 && (float) $sD['qty'] === 1.0 && (float) $sL['net'] === 28.0, 'per day part: lunch 2 (net 28.00), dinner 1');
$gDay = $sd('3001', D1, null); $gL = $sd('3001', D1, 'Lunch');
ok($gDay !== null && (float) $gDay['qty'] === 3.0 && $gL !== null && (float) $gL['qty'] === 1.0 && (int) one("SELECT count(*) FROM sales_daily d JOIN menu_items m ON m.id = d.menu_item_id WHERE m.pos_source_id = :s AND m.plu = '3001' AND d.business_date = :d", ['s' => $toast, 'd' => D1]) === 2, 'the G&T: the day row 3 (the 03:30 pair in it), lunch 1, no row for the part it lacks');
ok($sd('2002', D1, 'Dinner') !== null && (float) $sd('2002', D1, null)['net'] === 16.0, 'the unmapped 2002 is in sales_daily too (sold, net 16.00)');

echo "4. The screens and the walls\n";
$r = page($dana, '/sales/?from=' . D1 . '&to=' . D2);
ok($r['code'] === 200 && preg_match('#id="day-run-' . D1 . '"[^>]*>.*?partial#s', $r['body']) === 1 && preg_match('#id="day-value-' . D1 . '"[^>]*>[^<]*\$#', $r['body']) === 1, "the day list (Dana): day 1's chip partial, its value shown");
$r = page($bea, '/sales/?from=' . D1 . '&to=' . D2);
ok($r['code'] === 200 && preg_match('#id="day-value-' . D1 . '"[^>]*>[^<]*\$#', $r['body']) === 0, 'Bea (Kitchen, the site keeps cost from the kitchen): no value');
$r = req('GET', '/sales/?from=' . D1 . '&to=' . D2, ['jar' => $ann, 'headers' => ['Accept: application/json']]);
ok($r['code'] === 200 && !str_contains($r['body'], number_format($val, 2)) && str_contains($r['body'], D1), 'Ann reads the days as JSON without a value');
$r = req('GET', '/sales/' . D1, ['jar' => $dana, 'headers' => ['Accept: application/json']]);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && ($d['checks'] ?? 0) === 3 && near((float) ($d['run']['value'] ?? 0), $val) && count($d['depleted'] ?? []) === 5, "the day's JSON (Dana): 3 checks, the run's value, five ingredients depleted");
$parts = array_map(static fn ($x) => [$x['day_part'], (int) $x['checks']], $d['day_parts'] ?? []);
sort($parts);
ok($parts === [[null, 1], ['Dinner', 1], ['Lunch', 1]], 'its day parts: Lunch 1 check, Dinner 1, and the 03:30 check under none');
$r = req('GET', '/sales/' . D1, ['jar' => $bea, 'headers' => ['Accept: application/json']]);
$d = json_decode($r['body'], true)['data'] ?? [];
ok(array_key_exists('value', $d['run'] ?? []) && $d['run']['value'] === null && array_key_exists('value', $d['depleted'][0] ?? []) && $d['depleted'][0]['value'] === null, "Bea's: the run's value and the depletion's values null");
$r = page($dana, '/sales/' . D1);
ok($r['code'] === 200 && has($r, 'id="day-tiles"') && has($r, 'id="run-card"') && has($r, 'id="run-card-value"') && has($r, 'id="day-depleted-table"') && has($r, 'id="run-card-void"'), "the day's page (Dana): tiles, the run card with its value, what it depleted, Void");
$mi2002 = (int) one("SELECT id FROM menu_items WHERE pos_source_id = :s AND plu = '2002'", ['s' => $toast]);
ok(has($r, 'href="/menu/' . $mi2002 . '/map'), 'the unmapped 2002 offers Map → /menu/{id}/map');
$r = page($bea, '/sales/' . D1);
ok($r['code'] === 200 && has($r, 'id="run-card"') && !has($r, 'id="run-card-value"') && !has($r, 'id="run-card-void"'), "Bea's page: the card without value, no Void (counts.approve)");
$r = page($ann, '/sales/' . D1);
ok($r['code'] === 200 && !has($r, 'id="run-card-rerun"') && !has($r, 'id="run-card-void"') && !has($r, 'id="run-card-run-form"'), 'Ann: no Re-run, no Void');
$lunch = (int) one("SELECT id FROM day_parts WHERE scope_id = 101 AND name = 'Lunch'");
$r = req('GET', '/sales/' . D1 . '?day_part=' . $lunch, ['jar' => $dana, 'headers' => ['Accept: application/json']]);
$items = json_decode($r['body'], true)['data']['items'] ?? [];
ok($r['code'] === 200 && count($items) === 2 && !in_array('2002', array_column($items, 'plu'), true), 'day_part=Lunch: the sandwich and the G&T only');
$sale1 = (int) one("SELECT id FROM pos_sales WHERE source_id = :s AND external_id = 'SMK-001'", ['s' => $toast]);
$r = page($bea, '/sales/' . D1 . '?check=' . $sale1, ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && has($r, 'id="check-lines-table-' . $sale1 . '"') && substr_count($r['body'], '<tr') === 2 && has($r, 'XCHK'), "a check's lines on expand (SMK-001: two, XCHK on the sandwich)");
$r = req('GET', '/sales/runs/' . $run1, ['jar' => $dana, 'headers' => ['Accept: application/json']]);
$mv = json_decode($r['body'], true)['data']['movements'] ?? [];
ok($r['code'] === 200 && count($mv) === 5 && (json_decode($r['body'], true)['data']['run']['by'] ?? '') !== 'worker', "the run's page: five movements, by Bea");
$r = req('GET', '/sales/runs/?status=partial', ['jar' => $bea, 'headers' => ['Accept: application/json']]);
$runs = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && ($runs['total'] ?? 0) === 1 && array_key_exists('value', $runs['runs'][0] ?? []) && $runs['runs'][0]['value'] === null, 'the runs list filtered partial: one, its value walled for Bea');
ok(page($bea, '/sales/runs/')['code'] === 200 && has(page($bea, '/sales/runs/'), 'id="run-row-' . $run2 . '"'), 'the runs list page');
ok(page($ann, '/sales/runs/')['code'] === 403, "the runs list is for who runs a day (the menu's gate, stock.receive): Ann 403");
$r = page($ann, '/sales/runs/' . $run1);
ok($r['code'] === 200 && preg_match('#<a[^>]+href="/sales/runs/"#', $r['body']) === 0 && !has($r, 'id="run-card-void"'), "a run's own page stays readable from its day for Ann: the crumb unlinked, no Void");

echo "5. Refusals\n";
$r = post_json($bea, '/sales/runs/run.php', ['business_date' => '2026-10-04']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'No sales on 2026-10-04'), 'a day with no sales → 422 "No sales on 2026-10-04."');
ok(post_json($bea, '/sales/runs/run.php', ['business_date' => 'yesterday'])['code'] === 422, 'a date that is not one → 422');
ok(post_json($ann, '/sales/runs/run.php', ['business_date' => D1])['code'] === 403, 'Ann cannot run → 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['depletion_run_void']['approval'] ?? '') === 'deletion', "the agent's depletion_run_void pauses as deletion (the registry)");

echo "6. Re-run and void\n";
as_member(30);
ri_sql('UPDATE menu_items SET recipe_id = :r, mapped_by = 30, mapped_at = now() WHERE id = :m', ['r' => $w['r_special'], 'm' => $mi2002]);
ri_sql("UPDATE pos_sales SET imported_at = now() WHERE id IN (SELECT sale_id FROM pos_sale_lines WHERE menu_item_id = :m)", ['m' => $mi2002]);
ok(is_due(D1) && !is_due(D2), "Dana maps 2002 to the special (slice 2's handler, by SQL: imported_at touched) → day 1 due, day 2 not");
ok(has(page($bea, '/sales/' . D1), 'id="run-card-rerun"'), "the day's page offers Re-run");
$chk0 = on_hand($w['chicken']); $gin0 = on_hand($w['gin']);
$r = post_json($bea, '/sales/runs/run.php', ['business_date' => D1]);
$card = $r['json']['card'] ?? [];
ok($r['code'] === 200 && ($card['status'] ?? '') === 'done' && ($card['run_no'] ?? 0) === 2 && ($card['lines_depleted'] ?? 0) === 5 && ($card['lines_unmapped'] ?? 1) === 0 && array_key_exists('value', $card) && $card['value'] === null, 'Re-run (Bea): run 2 done, five lines, none unmapped; the card walled for her');
ok((string) one('SELECT status FROM depletion_runs WHERE id = :r', ['r' => $run1]) === 'void', 'run 1 void');
$rev = (int) one("SELECT count(*) FROM inventory_transactions t WHERE t.reverses_id IN (SELECT id FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = :r)", ['r' => $run1]);
ok($rev === 5, 'its five rows reversed');
$run1b = (int) live_run(D1)['id'];
ok(near(run_qty($run1b, $w['chicken']), -($chk + 100)), 'run 2: chicken = run 1 + the special\'s 100 g');
ok(near(on_hand($w['chicken']), $chk0 - 100) && near(on_hand($w['gin']), $gin0), 'the balances unchanged but for the newly mapped item (chicken −100 g, gin the same)');
$r = post_json($bea, '/sales/runs/void.php', ['run' => (string) $run1b]);
ok($r['code'] === 403, "Bea's void → 403 (counts.approve)");
$r = post_json($dana, '/sales/runs/void.php', ['run' => (string) $run1b, 'note' => 'SMOKE recount the special']);
ok($r['code'] === 200 && (string) one('SELECT status FROM depletion_runs WHERE id = :r', ['r' => $run1b]) === 'void' && is_due(D1), 'Void by Dana: void, the day due again');
ok(near(on_hand($w['chicken']), $chk0 + $chk), 'its rows reversed: the chicken back');
$v = activity('depletion.void', $since);
ok(count($v) === 1 && json_decode($v[0]['after'], true)['note'] === 'SMOKE recount the special', 'depletion.void logged with the note');
ok(preg_match('#id="day-run-' . D1 . '"[^>]*>.*?void#s', page($dana, '/sales/?from=' . D1 . '&to=' . D2)['body']) === 1, "the day's chip says void");
$r = post_json($dana, '/sales/runs/void.php', ['run' => (string) $run1b]);
ok($r['code'] === 422 && str_contains(strtolower($r['json']['error']['message'] ?? ''), 'void'), 'a void run voided again → 422');
$r = post_json($marco, '/sales/runs/run.php', ['business_date' => D1]);
ok($r['code'] === 200 && ($r['json']['card']['run_no'] ?? 0) === 3 && near((float) ($r['json']['card']['value'] ?? 0), $val + 0.7), 'Marco runs it again: run 3, its value shown to a Manager (the special adds $0.70)');
ok(!is_due(D1) && near(on_hand($w['chicken']), $chk0 - 100), 'day 1 no longer due; the balances as after run 2');
ok(count(activity('depletion.run', $since)) === 2, 'depletion.run logged for each run');

echo "7. A changed check\n";
$rows = file(FIX . '/toast_items.csv');
foreach ($rows as $i => $line) { if (str_starts_with($line, 'SMK-005,')) { $rows[$i] = str_replace(',3001,SMOKE Gin and Tonic,3,11.00,33.00,', ',3001,SMOKE Gin and Tonic,4,11.00,44.00,', $line); } }
$changed = state_dir() . '/samples/toast_changed.csv';
file_put_contents($changed, implode('', $rows));
$r = post_file_json($bea, '/sales/upload', ['source' => (string) $toast], 'file', $changed);
ok($r['code'] === 200 && ($r['json']['sales_new'] ?? -1) === 0 && ($r['json']['sales_changed'] ?? 0) === 1 && count($r['json']['runs'] ?? []) === 1 && ($r['json']['runs'][0]['business_date'] ?? '') === D2, 'SMK-005 now four G&Ts: one check changed, only day 2 re-run');
$n2 = live_run(D2);
ok((int) $n2['run_no'] === 2 && near(run_qty((int) $n2['id'], $w['gin']), -4 * GT_GIN_ML) && (string) one('SELECT status FROM depletion_runs WHERE id = :r', ['r' => $run2]) === 'void', 'day 2: run 2 with four G&Ts of gin, run 1 void');
ok((int) one("SELECT count(*) FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.source_id = :s AND s.external_id = 'SMK-005'", ['s' => $toast]) === 1, "the changed check's lines replaced, not added");
finish();
