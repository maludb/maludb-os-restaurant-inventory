<?php
/** Slice 6 — approval (counts.md "Proof": Approve): the corrections posted at the count's moment, skipped lines untouched, an approved count frozen, force on a count owing a recount, the variance notification. Continues the count in review that recount.php left. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $ann = person(29); $marco = person(27);
$cid = (int) one("SELECT id FROM inventory_counts WHERE scope_id = :s AND status = 'review' ORDER BY id DESC LIMIT 1", ['s' => DOWNTOWN]);
$cnt = ccount($cid); $num = $cnt['number'];
$L = ['flour' => line_of($cid, $w['flour']), 'egg' => line_of($cid, $w['egg']), 'gin' => line_of($cid, $w['gin']), 'lime' => line_of($cid, $w['lime']), 'oil' => line_of($cid, $w['oil'], $w['walkin']), 'chicken' => line_of($cid, $w['chicken']), 'found' => line_of($cid, $w['oil'], $w['dry'])];
$walkinEggBefore = on_hand($w['egg'], DOWNTOWN, $w['walkin']);

echo "1. Who may approve\n";
$r = post($bea, '/counts/approve.php', ['count' => $cid]);
ok($r['code'] === 403 && says($r, 'does not let you') && ccount($cid)['status'] === 'review', 'Bea (counts, cannot approve): 403 in words');
ok(post($ann, '/counts/approve.php', ['count' => $cid])['code'] === 403, 'Ann: 403');

echo "2. Dana approves\n";
$before = last_activity_id(); $notes = last_note_id();
$r = post($dana, '/counts/approve.php', ['count' => $cid]);
$c = ccount($cid);
ok($r['code'] === 302 && preg_match("#^/counts/$cid/history\?notice=approved#", $r['location']) === 1 && $c['status'] === 'approved' && (int) $c['approved_by'] === 30 && $c['approved_at'] !== null && $c['group_id'] !== null, 'count_approve lands on the history; approved by Dana at a moment, with a group');
$tx = q("SELECT t.*, rc.code AS reason FROM inventory_transactions t JOIN reason_codes rc ON rc.id = t.reason_code_id WHERE t.reference_kind = 'count' AND t.reference_id = :c ORDER BY t.id", ['c' => $cid]);
$byIng = array_column($tx, null, 'ingredient_id');
ok(count($tx) === 5 && array_unique(array_column($tx, 'txn_type')) === ['count_correction'] && array_unique(array_column($tx, 'reason')) === ['count_correction'], 'five count_correction rows (flour, eggs, gin, limes, the oil found), reason count_correction');
ok(count(array_unique(array_column($tx, 'group_id'))) === 1 && $tx[0]['group_id'] === $c['group_id'], '…in one group');
ok(count(array_unique(array_map(fn ($t) => strtotime($t['occurred_at']), $tx))) === 1 && strtotime($tx[0]['occurred_at']) === strtotime($c['as_of']), '…all dated at the count\'s moment (as of), not at the approval');
ok(near($byIng[$w['egg']]['qty_base'], 41, 0.0001) && near($byIng[$w['lime']]['qty_base'], -2, 0.0001) && near($byIng[$w['gin']]['qty_base'], -375, 0.001) && near($byIng[$w['flour']]['qty_base'], 2267.9619, 0.001) && near($byIng[$w['oil']]['qty_base'], 500, 0.001), 'the corrections: eggs +41, limes −2 (the recount), gin −375, flour +2 267.9619 g, oil +500 ml');
ok(!isset($byIng[$w['chicken']]) && (int) one("SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = :c AND storage_area_id = :a", ['c' => $cid, 'a' => $w['walkin']]) === 1, 'the skipped chicken and the skipped oil in the Walk-in post nothing; the stock zeroed against a book of 0 posts nothing');
ok(near(on_hand($w['egg'], DOWNTOWN, $w['walkin']), 307, 0.0001) && near(on_hand($w['lime'], DOWNTOWN, $w['bar']), 298, 0.0001) && near(on_hand($w['gin'], DOWNTOWN, $w['bar']), 8625, 0.001) && near(on_hand($w['flour'], DOWNTOWN, $w['dry']), 138345.6729, 0.001) && near(on_hand($w['oil'], DOWNTOWN, $w['dry']), 500, 0.001), 'the balances now equal the counted figures: eggs 307, limes 298, gin 8 625 ml, flour 138 345.6729 g, oil 500 ml');
$a = activity('count.approve', $before);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && (int) $a[0]['count_id'] === $cid && (int) $a[0]['scope_id'] === DOWNTOWN && $af['corrections'] === 5 && $af['forced'] === false && count($af['units_delta']) === 5 && $af['areas_skipped'] === [$w['cold']] && $af['number'] === $num && isset($af['total_variance_value']), 'count.approve logged: number, corrections (5), units_delta by ingredient, the total variance, forced no, the area skipped');
ok(near($c['total_variance_value'], 12.30 + 2.50 - 9.00 - 0.38 + 1.32, 0.05) && count(notifications_of(27, 'variance', $notes)) === 0, 'the total (about $6.7) is under the $100 threshold (no period is closed yet): the Manager is not told');

echo "3. An approved count is frozen\n";
$r = post($bea, '/counts/line.php', ['count_line' => $L['egg'], 'whole' => '1', 'whole_unit' => 'flat']);
ok($r['code'] === 422 && says($r, 'is approved') && says($r, 'no longer change'), 'a line of an approved count: "…is approved — its lines no longer change"');
ok(post($bea, '/counts/line-add.php', ['count' => $cid, 'area' => $w['dry'], 'ingredient' => $w['egg'], 'qty' => '1'])['code'] === 422, 'no line may be added');
$r = post($dana, '/counts/review.php', ['count' => $cid]);
ok($r['code'] === 422 && says($r, 'is approved'), 'it cannot be reviewed again');
$r = post($dana, '/counts/approve.php', ['count' => $cid]);
ok($r['code'] === 422 && says($r, 'review it first') && (int) one("SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = :c", ['c' => $cid]) === 5, 'approving twice: refused, nothing posted twice');
$r = post($dana, '/counts/discard.php', ['count' => $cid]);
ok($r['code'] === 422 && says($r, 'An approved count is never discarded') && ccount($cid)['status'] === 'approved', 'discarding an approved count: "An approved count is never discarded"');

echo "4. Force on a count that owes a recount (a copy of the world: the oil in the Walk-in alone)\n";
post($dana, '/counts/sheets/save.php', ['name' => 'Oil check', 'kind' => 'spot']);
$oc = last_id('count_sheets');
post($dana, '/counts/sheets/line.php', ['sheet' => $oc, 'area' => $w['walkin'], 'ingredient' => $w['oil']]);
[$s1, ] = start_count_as($bea, ['sheet' => $oc]);
$ol = line_of($s1, $w['oil']);
ok($s1 > $cid && ccount($s1)['kind'] === 'spot' && near(cline($ol)['system_qty_base'], 13641.65, 0.01), 'a spot count of the oil: the book figure 13 641.65 ml (the depletions the start proof posted are in it)');
post($bea, '/counts/line.php', ['count_line' => $ol, 'whole' => '5000', 'whole_unit' => 'ml']);
post($bea, '/counts/review.php', ['count' => $s1]);
ok(ccount($s1)['status'] === 'recount' && cline($ol)['over_tolerance'] === true, 'counted 5 000 ml: over tolerance, in recount');
$r = post($dana, '/counts/approve.php', ['count' => $s1]);
ok($r['code'] === 422 && says($r, '1 line over tolerance awaits a recount') && ccount($s1)['status'] === 'recount', 'approve before the recount: "1 line over tolerance awaits a recount (approve with force to accept it)"');
$r = post($dana, '/counts/approve.php', ['count' => $s1, 'force' => 'yes']);
ok($r['code'] === 302 && ccount($s1)['status'] === 'approved' && near(on_hand($w['oil'], DOWNTOWN, $w['walkin']), 5000, 0.01), 'with force Dana approves anyway: the first count stands and the balance is 5 000 ml');
$a = activity('count.approve', $before);
ok(count($a) === 2 && json_decode($a[1]['after'], true)['forced'] === true && (int) json_decode($a[1]['after'], true)['corrections'] === 1, 'count.approve logs forced yes for it');
ok(count(notifications_of(27, 'variance', $notes)) === 0, 'the variance (about −$22.8) is still under $100: no notice');

echo "5. The variance notice\n";
ri_sql("INSERT INTO costing_calendars (name, kind, year_start_on) SELECT 'SMOKE weekly', 'week', date '2026-01-05' WHERE NOT EXISTS (SELECT 1 FROM costing_calendars)");
ri_sql("INSERT INTO periods (scope_id, calendar_id, no, fiscal_year, starts_on, ends_on, status, cogs_value, closed_at) VALUES (:s, (SELECT id FROM costing_calendars ORDER BY id LIMIT 1), 99, 2026, current_date - 30, current_date - 24, 'closed', 1000, now())", ['s' => DOWNTOWN]);
[$s2, ] = start_count_as($bea, ['sheet' => $oc]);
$ol2 = line_of($s2, $w['oil']);
post($bea, '/counts/line.php', ['count_line' => $ol2, 'state' => 'zero']);
post($bea, '/counts/review.php', ['count' => $s2]);
$notes2 = last_note_id();
$r = post($dana, '/counts/approve.php', ['count' => $s2, 'force' => 'yes']);
$v = notifications_of(27, 'variance', $notes2);
ok($r['code'] === 302 && count($v) === 1 && str_contains($v[0]['title'], ccount($s2)['number'] . ' was approved short by $13.') && (int) $v[0]['record_id'] === $s2, 'with a closed period (cost of goods sold $1 000, so 1 % is $10) a −$13.21 count tells the Manager (Marco): "…approved short by $13.21"');
ok(count(notifications_of(30, 'variance', $notes2)) === 0 && count(notifications_of(1, 'variance', $notes2)) === 0, 'and only the Manager');
$r = post($dana, '/counts/discard.php', ['count' => 999999]);
ok($r['code'] === 404, 'a count that is not here: 404');
finish();
