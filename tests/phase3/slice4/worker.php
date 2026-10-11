<?php
/** Proof: the worker's two purchasing passes — price_alerts (the Manager is told once, with the count) and link_expiry (a link dies with its order's closing). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27);
function run_pass(string $passes, ?string $now = null): array
{
    $env = ($now ? 'RI_WORKER_NOW=' . escapeshellarg($now) . ' ' : '');
    $out = trim((string) shell_exec($env . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . $passes . ' 2>&1'));
    $lines = explode("\n", $out);
    return json_decode(end($lines), true) ?: ['raw' => $out];
}
$eggs = static fn (string $cost): int => stock_in($dana, $w['dry'], [['supplier_item' => $w['si_egg'], 'qty' => 1, 'unit' => 'case', 'unit_cost' => $cost]], $w['sysco']);

echo "1. price_alerts — the Manager is told\n";
post($marco, '/purchasing/alerts/threshold.php', ['pct' => '10', 'category' => 'dairy', 'category_pct' => '']);
post($marco, '/purchasing/alerts/seen.php', []);
ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
$r = run_pass('price_alerts');
ok(isset($r['ran']['price_alerts']) && !isset($r['ran']['price_alerts']['stub']) && $r['ran']['price_alerts']['alerts'] === 0 && $r['ran']['price_alerts']['told'] === 0, 'the pass is real now (no stub) and, with every alert seen, says nothing: ' . json_encode($r['ran']['price_alerts'] ?? $r));
$base = (float) one('SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = :i AND scope_id = 101', ['i' => $w['egg']]);
$eggs('300'); $eggs('700');
$open = (int) one('SELECT count(*) FROM price_alerts WHERE seen_at IS NULL AND scope_id = 101');
$n0 = last_note_id();
$r = run_pass('price_alerts')['ran']['price_alerts'] ?? [];
$nn = notes_for(27, 'price_alert', $n0);
ok($open >= 2 && $r['alerts'] === $open && $r['told'] === 1 && count($nn) === 1, "two moves raised $open open alerts: ONE notification to the site's Manager, not one per alert");
ok(str_contains($nn[0]['title'] ?? '', $open . ' price alerts at SMOKE Downtown') && (int) $nn[0]['scope_id'] === 101 && $nn[0]['record_type'] === 'price_alert', 'it carries the count and the site: "' . ($nn[0]['title'] ?? '') . '"');
$n1 = last_note_id();
$r = run_pass('price_alerts')['ran']['price_alerts'] ?? [];
ok($r['alerts'] === 0 && notes_for(27, 'price_alert', $n1) === [], 'a second pass changes nothing: the Manager is not told twice');
$eggs('1500');
$r = run_pass('price_alerts')['ran']['price_alerts'] ?? [];
$n2 = notes_for(27, 'price_alert', $n1);
ok($r['alerts'] === 1 && count($n2) === 1 && str_contains($n2[0]['title'], '1 price alert at'), 'one more alert later: one more notification, counting only the new one');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 27 AND kind = 'price_alert' AND channel = 'email'") >= 1, 'the Manager\'s email copy is queued with the bell');
ri_sql('UPDATE site_settings SET manager_member_id = NULL WHERE scope_id = 101');
ri_sql('UPDATE ri_settings SET manager_member_id = NULL WHERE id = 1');
$eggs('4000');
$n3 = last_note_id();
$r = run_pass('price_alerts')['ran']['price_alerts'] ?? [];
ok($r['alerts'] === 1 && $r['told'] === 0 && notes_for(27, 'price_alert', $n3) === [], 'a site with no Manager named tells nobody (and does not fail)');
ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
$l = run_pass('price_alerts');
ok(($l['errors'] ?? []) === [] && (int) one("SELECT count(*) FROM activity_log WHERE action = 'worker.pass' AND source = 'cron' AND actor_member_id IS NULL") >= 1, 'a pass logs worker.pass as cron with no actor; no pass errored');

echo "2. link_expiry — a link dies with its order\n";
$mk = static function (string $status, string $col, string $ago) use ($dana, $marco, $w): array {
    $po = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 1]]);
    post($marco, '/purchasing/send.php', ['purchase_order' => $po]);
    $tok = link_of(page($marco, "/purchasing/$po")['body']);
    ri_sql("UPDATE purchase_orders SET status = :s, $col = now() - :ago::interval WHERE id = :p", ['s' => $status, 'ago' => $ago, 'p' => $po]);
    return [$po, $tok];
};
[$p1, $t1] = $mk('closed', 'closed_at', '31 days');
[$p2, $t2] = $mk('closed', 'closed_at', '10 days');
[$p3, $t3] = $mk('cancelled', 'cancelled_at', '45 days');
[$p4, $t4] = $mk('closed_short', 'closed_at', '29 days');
[$p5, $t5] = $mk('sent', 'sent_at', '1 day');
[$p6, $t6] = $mk('sent', 'sent_at', '1 day');
ri_sql("UPDATE supplier_links_secure SET expires_at = now() - interval '1 minute' WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $p6]);
ok(req('GET', "/s/$t1")['code'] === 200 && req('GET', "/s/$t2")['code'] === 200, 'before the pass, the closed order\'s door still answers (read-only)');
$r = run_pass('link_expiry')['ran']['link_expiry'] ?? [];
ok(!isset($r['stub']) && $r['expired'] === 3, 'the pass is real and rotates out three links: ' . json_encode($r));
$live = static fn (int $p): int => (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $p]);
ok($live($p1) === 0 && $live($p3) === 0 && $live($p6) === 0, 'closed 31 days ago, cancelled 45 days ago and past its expiry: the live link is gone');
ok($live($p2) === 1 && $live($p4) === 1 && $live($p5) === 1, 'closed 10 and 29 days ago and a sent order: the link stays');
ok(req('GET', "/s/$t1")['code'] === 404 && req('GET', "/s/$t6")['code'] === 404 && req('GET', "/s/$t2")['code'] === 200, 'the door answers 404 for the dead links and 200 for the live one');
$r = run_pass('link_expiry')['ran']['link_expiry'] ?? [];
ok($r['expired'] === 0, 'idempotent: a second pass expires nothing');
$r = run_pass('link_expiry', gmdate(DATE_ATOM, time() + 3 * 86400))['ran']['link_expiry'] ?? [];
ok($r['expired'] === 1 && $live($p4) === 0 && $live($p2) === 1 && $live($p5) === 1, 'three days later (RI_WORKER_NOW) the 29-days-closed order\'s link goes too; the 10-days one and the sent one stay');
$x = run_pass('link_expiry', gmdate(DATE_ATOM, time() + 90 * 86400));
ok(($x['ran']['link_expiry']['expired'] ?? 0) >= 2, 'ninety days on, every remaining link is past its 60 days');
$lk = page($marco, "/purchasing/$p1?tab=link");
ok(has($lk, 'id="po-link-none"') && has($lk, 'Make a new link'), 'the Link tab says there is no live link and offers a new one');
finish();
