<?php
/** Slice 6 — the review (counts.md "Proof": Review): pending lines refuse it in words, the variances and tolerance flags, the status recount, the skipped areas, the totals, the notifications. Continues the open count lines.php left. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $marco = person(27);
$cid = (int) one("SELECT id FROM inventory_counts WHERE scope_id = :s AND status = 'open' AND kind = 'full' ORDER BY id DESC LIMIT 1", ['s' => DOWNTOWN]);
$cnt = ccount($cid);
$L = ['flour' => line_of($cid, $w['flour']), 'egg' => line_of($cid, $w['egg']), 'gin' => line_of($cid, $w['gin']), 'lime' => line_of($cid, $w['lime']), 'oil' => line_of($cid, $w['oil'], $w['walkin']), 'chicken' => line_of($cid, $w['chicken']), 'found' => line_of($cid, $w['oil'], $w['dry'])];

echo "1. Before the review, the counter sees no variance\n";
$r = page($bea, "/counts/$cid/review");
ok($r['code'] === 200 && has($r, 'data-screen="count-review"') && has($r, 'id="count-review-open-note"') && has($r, 'id="review-table"') && has($r, 'id="review-row-' . $L['lime'] . '"'), 'the review of an open count opens (ri.read) and says it is not reviewed yet');
preg_match('/id="review-row-' . $L['lime'] . '-variance"[^>]*>(.*?)<\/td>/s', $r['body'], $m);
ok(str_contains($m[1] ?? '', '—') && !has($r, '136077') && !has($r, '>300'), 'Bea\'s review of the blind open count: the variance and the book figure are "—" (the view hides them)');
$j = get_json($bea, "/counts/$cid/review");
ok(($j['json']['data']['lines'][0]['book_hidden'] ?? false) === true, 'JSON for the counter: book_hidden on every line');

echo "2. A line still pending refuses the review\n";
$st = post($bea, '/counts/line-add.php', ['count' => $cid, 'area' => $w['dry'], 'ingredient' => $w['stock']]);
$pend = line_of($cid, $w['stock'], $w['dry']);
ok($st['code'] === 302 && cline($pend)['state'] === 'pending', 'Bea adds a line she has not counted yet (the stock): pending');
$h = page($bea, '/counts/' . $cid . '?area=' . $w['dry']);
ok(preg_match('/id="count-review-btn"[^>]*disabled/', $h['body']) === 1 && has($h, '1 line is still pending'), 'the sheet disables Review and says "1 line is still pending"');
$before = last_note_id();
$r = post($bea, '/counts/review.php', ['count' => $cid]);
ok($r['code'] === 422 && says($r, '1 line is still pending') && ccount($cid)['status'] === 'open' && last_note_id() === $before, 'Review with a pending line: "1 line is still pending — count it, zero it or skip it"; the count stays open; nobody is told');
post($bea, '/counts/line.php', ['count_line' => $pend, 'state' => 'zero']);

echo "3. The review\n";
$before = last_activity_id();
$r = post($bea, '/counts/review.php', ['count' => $cid]);
$cnt = ccount($cid);
ok($r['code'] === 302 && str_contains($r['location'], "/counts/$cid/review") && $cnt['status'] === 'recount', 'Bea presses Review: the count goes to recount (two lines are over tolerance)');
$ln = fn (string $k): array => cline($L[$k]);
ok($ln('lime')['over_tolerance'] === true && $ln('egg')['over_tolerance'] === true && $ln('flour')['over_tolerance'] === false && $ln('gin')['over_tolerance'] === false && $ln('found')['over_tolerance'] === false, 'limes (zero against 300) and eggs (307 against 266) are flagged; five pounds on six bags, 375 ml short on the gin and 500 ml of oil found are within tolerance');
ok(near($ln('lime')['variance_base'], -300, 0.0001) && near($ln('egg')['variance_base'], 41, 0.0001) && near($ln('flour')['variance_base'], 2267.9619, 0.001) && near($ln('gin')['variance_base'], -375, 0.001), 'the variances the database computed: −300, +41, +2 267.9619 g, −375 ml');
ok(near($ln('lime')['variance_value'], -57.00, 0.005) && near($ln('egg')['variance_value'], 12.30, 0.005), 'and at cost: limes −$57.00, eggs +$12.30');
ok($ln('chicken')['variance_base'] === null && $ln('oil')['variance_base'] === null && $ln('chicken')['state'] === 'skipped', 'a skipped line has no variance');
$a = activity('count.review', $before);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && $af['status'] === 'recount' && $af['lines'] === 8 && $af['over_tolerance'] === 2 && (int) $a[0]['count_id'] === $cid && $af['number'] === $cnt['number'], 'count.review logged: number, status, lines (8), over tolerance (2)');
ok(near($cnt['total_variance_value'], -57.00 + 12.30 + 2.50 - 9.00 + 1.32, 0.05), 'the count\'s total variance value is the sum of the lines (' . $cnt['total_variance_value'] . ')');

echo "4. The screen\n";
$h = page($dana, "/counts/$cid/review");
ok($h['code'] === 200 && has($h, 'id="count-recount-note"') && has($h, '2 lines over tolerance need a second count') && has($h, 'id="review-table"') && has($h, 'id="review-totals"') && has($h, 'id="review-skipped"'), 'Dana\'s review: the recount note, the table, the totals row, the skipped list');
preg_match('/id="review-row-' . $L['lime'] . '-variance" class="([^"]*)"/', $h['body'], $m);
preg_match('/id="review-row-' . $L['lime'] . '-variance"[^>]*>(.*?)<\/td>/s', $h['body'], $m2);
ok(str_contains($h['body'], 'id="review-row-' . $L['lime'] . '-variance"') && preg_match('/class="[^"]*text-danger[^"]*" id="review-row-' . $L['lime'] . '-variance"/', $h['body']) === 1 && str_contains(strip_tags($m2[1] ?? ''), '-1.5'), 'an over-tolerance variance is red and says −1.5 case (300 limes, in the count unit)');
ok(!preg_match('/class="[^"]*text-danger[^"]*" id="review-row-' . $L['flour'] . '-variance"/', $h['body']), 'a variance within tolerance is not red');
$sk = preg_match('#id="review-skipped".*?</div></div>#s', $h['body'], $mm) ? strip_tags($mm[0]) : '';
ok(str_contains($sk, 'SMOKE Cold room') && !str_contains($sk, 'SMOKE Dry') && !str_contains($sk, 'SMOKE Bar') && !str_contains($sk, 'SMOKE Walk-in'), 'the areas skipped lists the Cold room (only the chicken, skipped) and no other');
ok(has($h, 'id="review-totals-over">2 over tolerance<') && has($h, 'id="review-totals-value"') && str_contains(strip_tags(preg_match('/id="review-totals-value">(.*?)<\/td>/s', $h['body'], $t) ? $t[1] : ''), '$'), 'the totals row: 8 lines, 2 over tolerance, the total at cost');
ok(has($h, 'id="review-row-' . $L['lime'] . '-recount-form"') && has($h, 'id="review-row-' . $L['egg'] . '-recount-form"') && !has($h, 'id="review-row-' . $L['flour'] . '-recount') , 'Dana is offered a recount on the two flagged lines only');
$hb = page($bea, "/counts/$cid/review");
ok(has($hb, 'id="review-row-' . $L['lime'] . '-recount-note"') && !has($hb, 'id="review-row-' . $L['lime'] . '-recount-form"') && has($hb, '(you)'), 'Bea, who counted them, sees her name greyed and no recount form');
ok(!has($h, 'id="count-approve"') && has($h, 'id="count-approve-force"') && has($h, 'id="count-discard"') && has($h, 'hx-confirm="Approve ' . $cnt['number'] . ': 5 corrections at '), 'a count in recount offers "Approve anyway" (not Approve) and Discard; the confirmation counts the corrections');
ok(!has($hb, 'id="count-approve-force"') && !has($hb, 'id="count-discard"'), 'Bea (no counts.approve) is offered neither');
$o = page($dana, "/counts/$cid/review?over_only=1");
ok(has($o, 'id="review-row-' . $L['lime'] . '"') && has($o, 'id="review-row-' . $L['egg'] . '"') && !has($o, 'id="review-row-' . $L['flour'] . '"') && !has($o, 'id="review-row-' . $L['gin'] . '"'), '?over_only=1 lists only the lines over tolerance');
$j = get_json($dana, "/counts/$cid/review");
$jl = array_column($j['json']['data']['lines'] ?? [], null, 'count_line_id');
ok(($jl[$L['lime']]['over_tolerance'] ?? false) === true && near($jl[$L['lime']]['variance_value'] ?? null, -57.0, 0.005) && near($jl[$L['egg']]['variance_qty_count'] ?? null, 1.3667, 0.0001) && ($j['json']['data']['count']['status'] ?? '') === 'recount' && count($j['json']['data']['areas_skipped'] ?? []) === 1, 'JSON for Dana: the flags, the values, the status, the one area skipped');

echo "5. Who was told\n";
$dn = notifications_of(30, 'count_review');
ok(count($dn) === 1 && str_contains($dn[0]['title'], 'is ready to review') && str_contains((string) $dn[0]['body'], '8 lines, 2 over tolerance') && (int) $dn[0]['record_id'] === $cid && $dn[0]['record_type'] === 'inventory_count', 'Dana (counts.approve) is told "…is ready to review: 8 lines, 2 over tolerance"');
ok(count(notifications_of(27, 'count_review')) === 1 && count(notifications_of(1, 'count_review')) === 1 && count(notifications_of(28, 'count_review')) === 0, 'so are the Manager and the owner; Bea (who cannot approve) is not');
ok(count(notifications_of(30, 'count_recount')) === 1 && str_contains(notifications_of(30, 'count_recount')[0]['title'], '2 lines of ' . $cnt['number'] . ' need a second count') && count(notifications_of(28, 'count_recount')) === 0, 'the others who may count are told 2 lines need a second count; the counter is not');
$r = post($dana, '/counts/review.php', ['count' => $cid]);
ok($r['code'] === 302 && ccount($cid)['status'] === 'recount', 'Review again changes nothing while the recounts are owed');
finish();
