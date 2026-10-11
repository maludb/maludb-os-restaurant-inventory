<?php
/** Proof: price alerts — raised by receipts and settled invoices, the list in units and percent, Seen, the thresholds (purchasing.md "Proof: Alerts"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27); $bea = person(28); $ann = person(29);
$eggs = static function (string $cost, int $qty = 1) use ($dana, $w): int { return stock_in($dana, $w['dry'], [['supplier_item' => $w['si_egg'], 'qty' => $qty, 'unit' => 'case', 'unit_cost' => $cost]], $w['sysco']); };
$alerts = static fn (): int => (int) one('SELECT count(*) FROM price_alerts WHERE ingredient_id = :i', ['i' => $w['egg']]);

echo "1. The threshold at 10 %, and a receipt that moves the cost\n";
$r = post($marco, '/purchasing/alerts/threshold.php', ['pct' => '10']);
ok($r['code'] === 302 && near(one('SELECT price_alert_pct FROM ri_settings WHERE id = 1'), 10), 'Marco sets the default threshold to 10 %');
$eggs('50');
$a0 = $alerts();
$g = $eggs('54');                       // +8 % on 50 → under the threshold
ok($alerts() === $a0, 'a receipt 8 % over the last raises no alert at 10 %');
$g = $eggs('66');                       // well over
$a1 = $alerts();
$al = q('SELECT * FROM price_alerts WHERE ingredient_id = :i ORDER BY id DESC LIMIT 1', ['i' => $w['egg']])[0] ?? [];
ok($a1 === $a0 + 1 && (float) $al['pct'] > 10 && $al['seen_at'] === null && (int) $al['scope_id'] === 101, 'a receipt well over the last raises an alert: ' . ($al['pct'] ?? '?') . ' %, unseen, at Downtown');
$aid = (int) $al['id'];

echo "2. The list\n";
$l = page($dana, '/purchasing/alerts');
ok($l['code'] === 200 && has($l, 'id="alerts-table"') && has($l, "id=\"alert-row-$aid\"") && has($l, 'data-screen="price-alert-list"'), 'the alert list: a table with a row per alert');
ok(preg_match('#alert-row-' . $aid . '".*?SMOKE Eggs.*?/ case.*?/ each.*?\+[0-9.]+ %#s', $l['body']) === 1, 'the row names the ingredient and shows old → new per purchase unit (case) and per recipe unit (each), and the percentage');
$gr = q("SELECT number FROM goods_receipts WHERE id = :g", ['g' => $g])[0]['number'];
ok(has($l, '/receipts/' . $g) && has($l, $gr), 'the document that raised it is a link (the receipt)');
$flourAlert = q("SELECT a.id FROM price_alerts a JOIN price_history h ON h.id = a.price_history_id WHERE h.source_kind = 'invoice' ORDER BY a.id DESC LIMIT 1")[0] ?? null;
if ($flourAlert) { ok(has($l, "id=\"alert-row-{$flourAlert['id']}\"") && has($l, '/invoices/'), 'an alert raised by a settled invoice links the invoice'); }
ok(has($l, 'id="alert-thresholds"') === false, 'a Chef sees the list but not the threshold form (prices.write)');
$lm = page($marco, '/purchasing/alerts');
ok(has($lm, 'id="alert-thresholds"') && has($lm, 'id="alert-threshold-pct"') && has($lm, 'id="alert-category-form"') && has($lm, 'id="alert-all-seen-btn"'), 'a Manager also sees the thresholds and Mark all seen');
$open = page($dana, '/purchasing/alerts?seen=0');
ok(has($open, "alert-row-$aid") && has($open, 'id="alert-seen-' . $aid . '"') && !has(page($dana, '/purchasing/alerts?seen=1'), "alert-row-$aid"), 'the filters: open shows it with its Seen button; the seen list does not');

echo "3. Seen\n";
$r = post($dana, '/purchasing/alerts/seen.php', ['alert' => $aid]);
$al = q('SELECT * FROM price_alerts WHERE id = :i', ['i' => $aid])[0];
ok($r['code'] === 302 && $al['seen_at'] !== null && (int) $al['seen_by'] === 30, 'Seen on one row: seen now, by Dana');
ok(has(page($dana, '/purchasing/alerts?seen=1'), "alert-row-$aid") && !has(page($dana, '/purchasing/alerts?seen=0'), 'id="alert-seen-' . $aid . '"'), '…it moves to the seen list');
$openBefore = (int) one('SELECT count(*) FROM price_alerts WHERE seen_at IS NULL AND (scope_id = 101 OR scope_id IS NULL)');
ok($openBefore >= 1, "there are still open alerts ($openBefore)");
$r = post($marco, '/purchasing/alerts/seen.php', []);
ok($r['code'] === 302 && (int) one('SELECT count(*) FROM price_alerts WHERE seen_at IS NULL AND (scope_id = 101 OR scope_id IS NULL)') === 0, 'Mark all seen closes every open alert at the site');
$lg = activity('price_alert.seen', 0);
ok(count($lg) === 2 && (int) json_decode((string) $lg[1]['after'], true)['marked'] === $openBefore && json_decode((string) $lg[1]['after'], true)['all'] === true && (int) $lg[1]['scope_id'] === 101, 'price_alert.seen logs one row per action with the count');
ok(post($dana, '/purchasing/alerts/seen.php', ['alert' => 999999])['code'] === 404, 'an alert that does not exist → 404');
ok(post($bea, '/purchasing/alerts/seen.php', [])['code'] === 404 || post($bea, '/purchasing/alerts/seen.php', [])['code'] === 403, 'Bea may not mark alerts seen');

echo "4. Thresholds — the default and a category's own\n";
$r = post($marco, '/purchasing/alerts/threshold.php', ['pct' => '25']);
$a2 = $alerts();
$eggs('79.20');                         // +20 % on 66
ok($r['code'] === 302 && $alerts() === $a2, 'with the threshold at 25 %, a 20 % move raises none');
$r = post($marco, '/purchasing/alerts/threshold.php', ['category' => 'dairy', 'category_pct' => '5']);
ok($r['code'] === 302 && near(one("SELECT price_alert_pct FROM ingredient_categories WHERE key = 'dairy'"), 5), 'a category threshold of 5 % on dairy is set');
$a3 = $alerts();
$eggs('88');                            // +11 % on 79.20: under 25 but over dairy's 5
ok($alerts() === $a3 + 1, 'an 11 % move on eggs (dairy) now raises one: the category\'s own threshold decides');
$r = post($marco, '/purchasing/alerts/threshold.php', ['category' => 'dairy', 'category_pct' => '']);
ok($r['code'] === 302 && one("SELECT price_alert_pct FROM ingredient_categories WHERE key = 'dairy'") === null, 'an empty category threshold puts it back to the default');
$a4 = $alerts();
$eggs('96');                            // +9 %: under 25
ok($alerts() === $a4, '…and a 9 % move under the 25 % default raises none');
$lg = activity('price_alert.threshold_set', 0);
ok(count($lg) >= 4 && count(array_filter($lg, fn ($r) => str_contains((string) $r['after'], '"category": "dairy"') && str_contains((string) $r['before'], 'price_alert_pct'))) === 2 && (int) $lg[0]['scope_id'] === 101, 'price_alert.threshold_set is logged for the default and for a category, with before and after');
foreach (['-1', '1001', 'abc', ''] as $bad) { ok(post($marco, '/purchasing/alerts/threshold.php', ['pct' => $bad])['code'] === 422, "a default threshold of '$bad' → 422"); }
ok(post($marco, '/purchasing/alerts/threshold.php', [])['code'] === 422 && post($marco, '/purchasing/alerts/threshold.php', ['category' => 'nonesuch', 'category_pct' => '5'])['code'] === 422, 'nothing named → 422; a category that does not exist → 422');
ok(post($dana, '/purchasing/alerts/threshold.php', ['pct' => '5'])['code'] === 403 && near(one('SELECT price_alert_pct FROM ri_settings WHERE id = 1'), 25), 'Dana (no prices.write) → 403, the threshold unchanged');
post($marco, '/purchasing/alerts/threshold.php', ['pct' => '10']);

echo "5. Cost is the wall\n";
foreach (['Ann (Viewer)' => $ann, 'Bea (Kitchen)' => $bea] as $who => $j) {
    $p = page($j, '/purchasing/alerts');
    ok($p['code'] === 200 && has($p, 'id="alerts-withheld"') && !has($p, 'id="alerts-table"') && !has($p, 'id="alert-thresholds"'), "$who opens the screen and is told it is cost: no table, no form");
}
$j = get_json($ann, '/purchasing/alerts');
ok(($j['json']['data']['cost_withheld'] ?? false) === true && ($j['json']['data']['alerts'] ?? null) === [], 'JSON for a Viewer: cost_withheld, no alerts');
finish();
