<?php
/** Slice 6 — the recount (counts.md "Proof": Recount): by another person only, the lines over tolerance only, the review that follows. Continues the count in recount that review.php left. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $ann = person(29); $marco = person(27);
$cid = (int) one("SELECT id FROM inventory_counts WHERE scope_id = :s AND status = 'recount' ORDER BY id DESC LIMIT 1", ['s' => DOWNTOWN]);
$num = ccount($cid)['number'];
$lime = line_of($cid, $w['lime']); $egg = line_of($cid, $w['egg']); $flour = line_of($cid, $w['flour']);

echo "1. Only another person, only a flagged line\n";
$r = post($bea, '/counts/recount.php', ['count_line' => $lime, 'whole' => '300', 'whole_unit' => 'each']);
ok($r['code'] === 422 && says($r, 'A recount is by another person') && cline($lime)['recount_qty_base'] === null, 'Bea recounting her own line: "A recount is by another person"; nothing is written');
$r = post($dana, '/counts/recount.php', ['count_line' => $flour, 'whole' => '6', 'whole_unit' => 'bag50']);
ok($r['code'] === 422 && says($r, 'is not waiting for a recount') && cline($flour)['recount_qty_base'] === null, 'a line within tolerance is not recounted: refused in words');
$r = post($dana, '/counts/recount.php', ['count_line' => $lime]);
ok($r['code'] === 422 && says($r, 'A counted line carries entries or a weight'), 'a recount with nothing entered: the database\'s sentence');
$r = post($dana, '/counts/line.php', ['count_line' => $lime, 'whole' => '298', 'whole_unit' => 'each']);
ok($r['code'] === 422 && says($r, 'waits for a recount by another person') && cline($lime)['recount_qty_base'] === null, 'count_line_set will not stand in for the recount of a flagged line (its sentence points to the recount)');
ok(post($ann, '/counts/recount.php', ['count_line' => $lime, 'whole' => '298', 'whole_unit' => 'each'])['code'] === 403, 'Ann cannot recount: 403');

echo "2. Dana recounts the limes\n";
$before = last_activity_id();
$r = post($dana, '/counts/recount.php', ['count_line' => $lime, 'whole' => '298', 'whole_unit' => 'each']);
$ln = cline($lime);
ok($r['code'] === 302 && str_contains($r['location'], "/counts/$cid/review") && near($ln['recount_qty_base'], 298, 0.0001) && (int) $ln['recount_by'] === 30, '298 limes: recount_qty_base 298 by Dana');
ok($ln['over_tolerance'] === false && near($ln['variance_base'], -2, 0.0001) && near($ln['variance_value'], -0.38, 0.005) && ccount($cid)['status'] === 'recount', 'the review ran again: limes −2 (−$0.38), the flag cleared; the eggs still owe a recount, so the count stays in recount');
$a = activity('count.recount', $before);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && (int) $a[0]['ingredient_id'] === $w['lime'] && (int) $a[0]['count_id'] === $cid && (int) $af['recount_by'] === 30 && near($af['recount_qty_base'], 298, 0.0001), 'count.recount logged with ingredient_id, count_id and recount_by');
$h = page($dana, "/counts/$cid/review");
ok(!has($h, 'id="review-row-' . $lime . '-recount-form"') && has($h, 'id="review-row-' . $egg . '-recount-form"') && has($h, '1 line over tolerance needs a second count'), 'the limes no longer offer a recount; the eggs still do');

echo "3. …and the eggs; the count returns to review\n";
$r = post($dana, '/counts/recount.php', ['count_line' => $egg, 'whole' => '10', 'whole_unit' => 'flat', 'loose' => '7', 'loose_unit' => 'each'], ['HX-Request: true']);
$c = ccount($cid);
ok($r['code'] === 200 && near(cline($egg)['recount_qty_base'], 307, 0.0001) && $c['status'] === 'review' && cline($egg)['over_tolerance'] === false, 'the eggs recounted at 307: no line awaits one — the count is in review, the flags cleared');
$h = page($dana, "/counts/$cid/review");
ok(has($h, 'id="count-approve"') && !has($h, 'id="count-approve-force"') && !has($h, 'id="count-recount-note"') && has($h, 'recount</span>'), 'the review now offers Approve (not "Approve anyway") and marks the two recounted lines');
$r = post($dana, '/counts/recount.php', ['count_line' => $egg, 'whole' => '1', 'whole_unit' => 'each']);
ok($r['code'] === 422 && says($r, 'is not waiting for a recount') && near(cline($egg)['recount_qty_base'], 307, 0.0001), 'a count in review takes no recount: refused in words');
ok(near($c['total_variance_value'], 12.30 + 2.50 - 9.00 - 0.38 + 1.32, 0.05), 'the count\'s total variance value is the lines\' sum with the recounted limes (' . $c['total_variance_value'] . ')');
ok(post($dana, '/counts/recount.php', ['count_line' => 999999, 'whole' => '1'])['code'] === 404, 'a line that is not here: 404');
finish();
