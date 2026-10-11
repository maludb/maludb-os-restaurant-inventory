<?php
/** Slice 5 — health and the queue (pos.md "Proof"): ri_pos_health() per source, the cards agreeing, the Unmapped tab, ri_unmapped_plus() by what sold; the pulls history and a kept file. */
require __DIR__ . '/lib.php';
pos_world();
$admin = at(1, DOWNTOWN);
$dana = at(30, DOWNTOWN);
$bea = at(28, DOWNTOWN);
$ann = at(29, DOWNTOWN);
$toast = source_id('SMOKE Toast export');
$square = source_id('SMOKE Square');
$sftp = source_id('SMOKE SFTP');
$mail = source_id('SMOKE Nightly mail');
$air = (int) one("SELECT id FROM sources WHERE scope_id = 102 AND name = 'POS uploads'");
$today = (string) one('SELECT current_date::text');
$window = []; for ($i = 7; $i >= 1; $i--) { $window[] = (string) one('SELECT (current_date - :i::int)::text', ['i' => $i]); }
$closed = $window[0];
as_member(1);
ri_sql("INSERT INTO calendar_days (scope_id, on_date, closed, note) VALUES (101, :d, true, 'SMOKE closed for a refit') ON CONFLICT (scope_id, on_date) DO UPDATE SET closed = true", ['d' => $closed]);
$expect = static function (int $src) use ($window, $closed): array {
    $sold = array_column(q('SELECT DISTINCT business_date::text AS d FROM pos_sales WHERE source_id = :s', ['s' => $src]), 'd');
    return array_values(array_filter($window, static fn ($d) => $d !== $closed && !in_array($d, $sold, true)));
};
$health = static function (): array { as_member(1); $out = []; foreach (q('SELECT * FROM ri_pos_health(101)') as $h) { $h['days_missing'] = $h['days_missing'] === null ? [] : explode(',', trim($h['days_missing'], '{}')); $out[(int) $h['source_id']] = $h; } return $out; };

echo "1. ri_pos_health()\n";
$h = $health();
ok(count($h) === 4 && isset($h[$toast], $h[$square], $h[$sftp], $h[$mail]) && !isset($h[$air]), "Downtown's four sources (Airport's not among them)");
ok($h[$toast]['days_missing'] === $expect($toast) && !in_array($closed, $h[$toast]['days_missing'], true), "the Toast export's days missing this week: " . implode(' ', $h[$toast]['days_missing']) . ' — the closed day (' . $closed . ') not counted');
ok($h[$sftp]['days_missing'] === $expect($sftp) && $h[$square]['days_missing'] === $expect($square), 'the SFTP and Square sources likewise');
ok((int) $h[$square]['unmapped_plus'] === 1 && (int) $h[$toast]['unmapped_plus'] === 0 && (int) $h[$sftp]['unmapped_plus'] === 0, "unmapped: Square's 2002 one; the Toast export's 2002 was mapped");
ok((int) $h[$square]['unmapped_modifiers'] === 0 && (int) $h[$toast]['pending_runs'] === (int) one('SELECT count(*) FROM ri_depletion_due() WHERE scope_id = 101'), 'no unmapped modifier; pending runs = the days due at the site');

echo "2. The cards and the JSON agree\n";
$r = req('GET', '/sales/sources/', ['jar' => $bea, 'headers' => ['Accept: application/json']]);
$js = array_column(json_decode($r['body'], true)['data']['sources'] ?? [], null, 'source_id');
$agree = true;
foreach ($h as $id => $x) { $agree = $agree && isset($js[$id]) && (int) $js[$id]['health']['unmapped_plus'] === (int) $x['unmapped_plus'] && count((array) $js[$id]['health']['days_missing']) === count($x['days_missing']); }
ok($r['code'] === 200 && $agree, "the list's JSON carries each source's health as ri_pos_health() says");
ok(array_key_exists('settings', $js[$square]) && $js[$square]['settings'] === null && array_key_exists('credential', $js[$square]) && $js[$square]['credential'] === null && $js[$square]['has_credential'] === true, 'the list never carries settings or the credential (has_credential only)');
$r = page($bea, '/sales/sources/');
ok(preg_match('#id="source-card-' . $square . '-unmapped"[^>]*>1 unmapped PLU<#', $r['body']) === 1, "Square's card: 1 unmapped PLU");
ok(preg_match('#id="source-card-' . $toast . '-missing"[^>]*>' . count($h[$toast]['days_missing']) . ' day#', $r['body']) === 1, "the Toast card's missing days count the same");

echo "3. The Unmapped tab and the queue\n";
$mi = (int) one("SELECT id FROM menu_items WHERE pos_source_id = :s AND plu = '2002'", ['s' => $square]);
$r = page($dana, '/sales/sources/' . $square . '?tab=unmapped');
ok($r['code'] === 200 && has($r, 'id="source-unmapped-table"') && has($r, 'id="unmapped-row-' . $mi . '"') && preg_match('#id="unmapped-row-' . $mi . '"><td>2002</td><td>SMOKE Mystery Special</td><td class="text-end">1</td><td class="text-end">\$16\.00</td>#', $r['body']) === 1, 'the Unmapped tab: 2002, sold 1, net $16.00');
ok(has($r, 'id="unmapped-map-' . $mi . '"') && has($r, '/menu/' . $mi . '/map'), 'its Map → /menu/{id}/map (Dana: recipes.write)');
ok(!has(page($bea, '/sales/sources/' . $square . '?tab=unmapped'), 'id="unmapped-map-' . $mi . '"'), 'Bea sees it without Map');
ok(has(page($dana, '/sales/sources/' . $toast . '?tab=unmapped'), 'id="source-unmapped-none"'), "the Toast export's tab: every PLU mapped");
$extra = state_dir() . '/samples/extra.csv';
$y = $window[6];
file_put_contents($extra, "check_id,date,closed_at,plu,item,qty,price,total,voided,comp,modifiers,covers\nXTR-1,$y,12:00,4004,SMOKE Soup,5,6.00,30.00,no,no,,5\nXTR-2,$y,12:10,5005,SMOKE Salad,2,9.00,18.00,no,no,,2\n");
ri_sql("UPDATE sources SET settings = settings || '{\"mapping_key\": \"generic\"}'::jsonb WHERE id = :s", ['s' => $mail]);
$r = post_file_json($bea, '/sales/upload', ['source' => (string) $mail, 'deplete' => 'no'], 'file', $extra);
ok($r['code'] === 200 && ($r['json']['plus_new'] ?? 0) === 2 && ($r['json']['runs'] ?? [1]) === [], 'a file by hand to the mail source (deplete off): two new PLUs, no run');
as_member(1);
$u = q('SELECT plu, qty_sold FROM ri_unmapped_plus(101)');
ok(array_column($u, 'plu') === ['4004', '5005', '2002'] && (float) $u[0]['qty_sold'] === 5.0, 'ri_unmapped_plus() sorted by what sold: 4004 (5), 5005 (2), 2002 (1)');
ok(is_due($y) && (int) $health()[$mail]['pending_runs'] === (int) one('SELECT count(*) FROM ri_depletion_due() WHERE scope_id = 101'), 'the undepleted day is pending in every source\'s health');
ri_sql("INSERT INTO pos_sales (scope_id, source_id, external_id, business_date, closed_at) VALUES (102, :s, 'SMOKE-OLD', current_date - 40, now() - interval '40 days')", ['s' => $air]);
$old = (int) one("SELECT id FROM pos_sales WHERE external_id = 'SMOKE-OLD'");
ri_sql("INSERT INTO pos_sale_lines (sale_id, plu, name, qty, line_total) VALUES (:o, '2002', 'SMOKE Mystery Special', 10, 160)", ['o' => $old]);
$a = array_column(q('SELECT plu, qty_sold, net FROM ri_unmapped_plus(102)'), null, 'plu');
ok((float) ($a['2002']['qty_sold'] ?? 0) === 1.0 && (float) $a['2002']['net'] === 16.0, "a sale 40 days old is outside the 28-day window: Airport's 2002 sold 1, not 11 (db/023)");

echo "4. The pulls and a kept file\n";
$r = req('GET', '/sales/sources/' . $square . '/pulls', ['jar' => $bea, 'headers' => ['Accept: application/json']]);
$pj = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && ($pj['total'] ?? 0) === (int) one('SELECT count(*) FROM source_pulls WHERE source_id = :s', ['s' => $square]) && in_array('failed', array_column($pj['pulls'], 'status'), true) && in_array('probe', array_column($pj['pulls'], 'kind'), true), "Square's pulls: every one, the failed and the probe among them");
$up = q("SELECT id, attachment_id FROM source_pulls WHERE source_id = :s AND kind = 'upload' AND attachment_id IS NOT NULL ORDER BY id LIMIT 1", ['s' => $toast])[0];
$r = page($admin, '/sales/sources/' . $toast . '/pulls');
ok($r['code'] === 200 && has($r, 'id="pull-file-' . $up['id'] . '"') && has($r, 'href="/files/' . $up['attachment_id'] . '"'), "the Toast export's pulls page links the kept file");
$f = page($bea, '/files/' . $up['attachment_id']);
ok($f['code'] === 200 && $f['body'] === file_get_contents(FIX . '/toast_items.csv'), 'the file comes back byte for byte');

echo "5. A source with history\n";
$r = post_json($admin, '/sales/sources/save.php', ['source' => (string) $toast, '_partial' => 'yes', 'connector' => 'square', 'settings_location_id' => 'L-X']);
ok($r['code'] === 422 && str_contains($r['json']['error']['fields']['connector'] ?? '', 'fixed once a pull exists') && (string) one('SELECT connector FROM sources WHERE id = :s', ['s' => $toast]) === 'file', 'the connector is fixed once a pull exists → 422');
$r = post_json($admin, '/sales/sources/delete.php', ['source' => (string) $toast]);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'Pause it') && (int) one('SELECT count(*) FROM sources WHERE id = :s', ['s' => $toast]) === 1, 'a source with sales cannot be deleted → 422 "Pause it — its sales are history."');
ok(!has(page($admin, '/sales/sources/' . $toast), 'id="source-view-delete"'), 'its page offers no Delete');
finish();
