<?php
/** Slice 7 — the late rule (periods.md "Proof": Late): a receipt dated inside a closed period posts, lands in the first open period flagged, says so in its sentence, shows on the period's Late tab and the usage report's badge, and does not move the closed period. */
require __DIR__ . '/lib.php';
$w = period_world();
$dana = person(30); $marco = person(27);
$P = fn (int $n): int => period_id($n);
$day = wk(4, 2);
$eggsBefore = usage_ref(wk(4), wk(4, 6))[$w['egg']];
$closedBefore = jget(30, '/periods/' . $P(4))['json']['data']['period'] ?? [];
$nextBefore = usage_ref(wk(6), wk(6, 6));

echo "1. A receipt dated inside week 4\n";
post($dana, '/receipts/save.php', ['area' => $w['walkin'], 'received_on' => $day, 'supplier' => $w['sysco'], 'lines' => json_encode([['ingredient' => 'L-EGG', 'qty' => 120, 'unit' => 'each', 'unit_cost' => 0.30]])]);
$g = last_id('goods_receipts');
$r = jpost(30, '/receipts/post.php', ['receipt' => (string) $g]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true, 'the receipt posts (nothing refuses a late receipt)');
$did = (string) ($r['json']['did'] ?? '');
ok(str_contains($did, 'dated ' . (new DateTimeImmutable($day))->format('M j') . ' inside closed week 4; landed in week 6'), 'its sentence says so: "' . $did . '"');
$t = q("SELECT * FROM inventory_transactions WHERE reference_kind = 'goods_receipt' AND reference_id = :g", ['g' => $g])[0] ?? [];
ok(($t['business_date'] ?? '') === wk(6) && str_contains((string) ($t['note'] ?? ''), 'late: dated ' . $day . ' in closed period 4'), 'the ledger row carries business_date = the first open period\'s first day (' . wk(6) . ') and "late: dated ' . $day . ' in closed period 4" in its note');
ok(strtotime((string) $t['occurred_at']) > strtotime((string) one('SELECT ri_site_moment(101, :d::date, false)', ['d' => wk(6)])), 'and its moment is inside that period, after its start');

echo "2. Where it shows\n";
$j = jget(30, '/periods/' . $P(6) . '?tab=late')['json']['data'] ?? [];
ok(count($j['late'] ?? []) === 1 && ($j['late'][0]['dated'] ?? '') === $day && ($j['late'][0]['closed_period_no'] ?? 0) === 4 && near($j['late'][0]['qty_base'] ?? null, 120, 0.0001), 'the Late rows tab of week 6 lists it: dated, the closed period, the quantity');
$h = page($dana, '/periods/' . $P(6) . '?tab=late');
ok(has($h, 'id="period-late-table"') && has($h, 'id="late-row-' . $t['id'] . '"') && has($h, 'late: dated Sep') , 'and the screen shows it with its "late: dated …" badge');
ok(!has(page($dana, '/periods/' . $P(4) . '?tab=late'), 'id="late-row-' . $t['id'] . '"'), 'week 4\'s own Late tab does not list it');
$u6 = jget(30, '/usage/?from=' . wk(6) . '&to=' . wk(6, 6))['json']['data']['rows'] ?? [];
$e6 = array_column($u6, null, 'ingredient_id')[$w['egg']] ?? [];
ok(near($e6['purchases_base'] ?? null, (float) $nextBefore[$w['egg']]['purchases_base'] + 120, 0.0001), 'the next period\'s usage counts it (purchases +120)');
$h6 = page($dana, '/usage/?from=' . wk(6) . '&to=' . wk(6, 6));
ok(preg_match('#id="usage-row-' . $w['egg'] . '".*?late</span>#s', $h6['body']) === 1, '…and its eggs row carries the "late" badge');

echo "3. What it does not move\n";
$e4 = usage_ref(wk(4), wk(4, 6))[$w['egg']];
ok(near($e4['purchases_base'], $eggsBefore['purchases_base'], 0.0001) && near($e4['closing_base'], $eggsBefore['closing_base'], 0.0001), 'the closed period\'s usage over its dates is unchanged — the receipt is not in week 4');
$closedAfter = jget(30, '/periods/' . $P(4))['json']['data']['period'] ?? [];
ok($closedAfter['cogs_value'] === $closedBefore['cogs_value'] && $closedAfter['ending_value'] === $closedBefore['ending_value'], 'and its frozen figures are the same');
ok(!has(page($dana, '/usage/?from=' . wk(4) . '&to=' . wk(4, 6)), 'late</span>'), 'week 4\'s report has no "late" badge');
$rows = q("SELECT count(*) FROM inventory_transactions WHERE note LIKE '%late:%'");
ok((int) $rows[0]['count'] === 1, 'exactly one row in the ledger is late');
$mv = jget(30, '/stock/movements?ingredient=' . $w['egg'] . '&from=' . wk(6))['json']['data'] ?? [];
$late = array_values(array_filter($mv['movements'] ?? [], fn ($m) => ($m['late'] ?? false) === true));
ok(count($late) === 1, 'the ledger\'s movements screen flags it late too (slice 3)');
finish();
