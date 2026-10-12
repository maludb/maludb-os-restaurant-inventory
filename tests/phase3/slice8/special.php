<?php
/** Slice 8 — the special check (projection.md "Proof": Special check): 60 portions of the braise, what it needs against the projected stock, the shortfall, the supplier that can bring it in time and its cut-off, the verdict, Draft what is short, a recipe with no version, the limits. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$a0 = last_activity_id();
$marco = person(27);
$dana = person(30);
$win = q('SELECT * FROM ri_next_order_windows(:s, 101, now())', ['s' => $w['sysco']])[0];
$D = $win['delivery_on'];
$cut = (new DateTimeImmutable($win['cutoff_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
$path = fn (int $recipe, string $date, string $portions) => "/projection/special?recipe=$recipe&date=$date&portions=$portions";

echo "1. The braise for sixty\n";
$j = jget(27, $path($w['r_braise'], $D, '60'))['json']['data'] ?? [];
$needs = array_column($j['needs'] ?? [], null, 'ingredient_id');
ok(count($needs) === 2 && near($needs[$w['rib']]['needed_base'] ?? null, 15000, 0.001) && near($needs[$w['flour']]['needed_base'] ?? null, 1200, 0.001), 'the explosion × 60: 15 000 g of short rib (250 g a portion) and 1 200 g of flour');
$proj = fn (int $i) => (float) one('SELECT projected_base FROM ri_projected_stock(101, :i, CAST(:n AS integer)) WHERE on_date = :d::date', ['i' => $i, 'n' => (int) ((strtotime($D) - strtotime(today_db())) / 86400) + 1, 'd' => $D]);
ok(near($needs[$w['rib']]['projected_base'], $proj($w['rib']), 0.001) && near($needs[$w['flour']]['projected_base'], $proj($w['flour']), 0.001), 'each ingredient against ri_projected_stock() on that day (rib ' . round($proj($w['rib']), 2) . ', flour ' . round($proj($w['flour']), 2) . ')');
ok($needs[$w['rib']]['short'] === true && near($needs[$w['rib']]['short_base'], 15000 - $proj($w['rib']), 0.001) && $needs[$w['flour']]['short'] === false && near($needs[$w['flour']]['short_base'], 0, 0), 'a shortfall on the rib; the flour is covered');
$sup = $needs[$w['rib']]['supplier'] ?? [];
ok(($sup['supplier_id'] ?? 0) === $w['sysco'] && ($sup['delivery_on'] ?? '') === $D && strtotime($sup['cutoff_at'] ?? '0') === strtotime($win['cutoff_at']) && near($sup['units'] ?? null, ceil((15000 - $proj($w['rib'])) / 4535.92), 0), 'the supplier that can bring it by then: Sysco, delivery ' . $D . ', cut-off the window\'s, ' . ($sup['units'] ?? '?') . ' cases');
$wd = date('l', strtotime($D));
$expect = 'Short ' . '15,000 g';
ok(str_starts_with($j['verdict'], 'Short ') && str_contains($j['verdict'], 'SMOKE Short rib') && str_contains($j['verdict'], 'SMOKE Sysco by ' . $wd . ' if ordered by ' . $cut->format('l H:i')), 'the verdict: "' . $j['verdict'] . '"');
$h = page($marco, $path($w['r_braise'], $D, '60'));
ok($h['code'] === 200 && has($h, 'data-screen="special-check"') && has($h, 'id="special-form"') && has($h, 'id="special-form-field-date"') && has($h, 'id="special-form-field-portions"') && has($h, 'id="special-form-field-recipe"') && has($h, 'id="special-table"') && has($h, 'id="special-row-' . $w['rib'] . '"') && has($h, 'id="special-verdict"') && has($h, 'id="special-draft-btn"'), 'the screen: the form (the recipe through the picker), the table, a row per ingredient, the verdict, Draft what is short');
ok(has($h, 'data-source="recipe"') || has($h, 'id="special-form-field-recipe"'), 'the recipe is chosen through the record picker, not a select');

echo "2. The other verdicts\n";
$j0 = jget(27, $path($w['r_braise'], today_db(), '60'))['json']['data'] ?? [];
ok(str_contains($j0['verdict'], 'no supplier delivers') && str_contains($j0['verdict'], 'SMOKE Short rib'), 'for today no supplier can deliver the rib in time: "no supplier delivers … by …"');
$jc = jget(27, $path($w['r_soda'], today_db(), '10'))['json']['data'] ?? [];
ok($jc['verdict'] === 'Yes — everything covered' && count(array_filter($jc['needs'], fn ($n) => $n['short'])) === 0, '10 limes sodas today: "Yes — everything covered"');
$h = page($marco, $path($w['r_soda'], today_db(), '10'));
ok(has($h, 'id="special-verdict"') && !has($h, 'id="special-draft-btn"') && str_contains(says_text($h), 'Yes — everything covered'), 'nothing short: the verdict is green and there is nothing to draft');
ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES ('L-R-EMPTY', 'SMOKE Empty recipe', 'menu_item', (SELECT id FROM menu_categories WHERE key = 'mains'), 30) ON CONFLICT DO NOTHING");
$re = id_of("SELECT id FROM recipes WHERE code = 'L-R-EMPTY'");
$hn = page($marco, $path($re, $D, '5'));
ok($hn['code'] === 200 && has($hn, 'id="special-problem"') && str_contains(says_text($hn), 'has no version in force'), 'a recipe with no version in force: the sentence, no table');
$jn = jget(27, $path($re, $D, '5'));
ok(($jn['json']['data']['problem'] ?? '') !== '' && $jn['json']['data']['needs'] === [], 'and the same in JSON');

echo "3. The limits\n";
$r = jget(27, $path($w['r_braise'], day(-1), '60'));
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'Choose a day from today'), 'a day in the past is refused in words');
$r = jget(27, $path($w['r_braise'], day(29), '60'));
ok($r['code'] === 422, 'a day beyond 28 days is refused');
$r = jget(27, $path($w['r_braise'], $D, '0'));
ok($r['code'] === 422 && isset($r['json']['error']['fields']['portions']), 'zero portions is refused');
$r = jget(27, $path($w['r_braise'], $D, '10001'));
ok($r['code'] === 422, 'more than 10,000 portions is refused');
$r = jget(27, '/projection/special?date=' . $D . '&portions=5');
ok($r['code'] === 422 && isset($r['json']['error']['fields']['recipe']), 'no recipe is refused');
$e = jget(27, '/projection/special')['json']['data'] ?? [];
ok(array_key_exists('verdict', $e) && $e['verdict'] === null, 'with nothing asked the screen is just the form');

echo "4. Draft what is short\n";
preg_match('#name="supplier" value="(\d+)"><input type="hidden" name="lines" value="([^"]+)"#', $h2 = page($marco, $path($w['r_braise'], $D, '60'))['body'], $m);
$lines = json_decode(html_entity_decode($m[2] ?? '[]'), true);
ok(((int) ($m[1] ?? 0)) === $w['sysco'] && count($lines) === 1 && $lines[0]['supplier_item'] === $w['si_rib'] && $lines[0]['unit'] === 'case' && !isset($lines[0]['name']), 'the form carries the supplier and the short lines (supplier item, whole units, the purchase unit) for slice 4\'s purchase_order_draft');
$n0 = row_count('purchase_orders');
$r = post($marco, '/purchasing/save.php', ['supplier' => $m[1], 'lines' => html_entity_decode($m[2])]);
$po = last_id('purchase_orders');
$pl = po_lines_of($po);
ok(row_count('purchase_orders') === $n0 + 1 && po_row($po)['status'] === 'draft' && po_row($po)['origin'] === 'manual' && count($pl) === 1 && (int) $pl[0]['ingredient_id'] === $w['rib'] && near($pl[0]['qty_ordered'], $sup['units'], 0) && $r['code'] === 302, 'Draft what is short lands on a draft PO with the short line (rib, ' . $sup['units'] . ' cases)');
ok(!has(page($dana, $path($w['r_braise'], $D, '60')), 'id="special-draft-btn"') === false, 'Dana (purchasing.draft) is offered the draft button');
$pb = page(person(28), $path($w['r_braise'], $D, '60')); $pa = page(person(29), $path($w['r_braise'], $D, '60'));
ok($pb['code'] === 200 && $pa['code'] === 200, 'Bea (Kitchen) and Ann (Viewer) read the special check — quantities only (the worker brief\'s gate rule)');
ok(!has($pb, 'id="special-draft-btn"') && !has($pa, 'id="special-draft-btn"'), 'but neither is offered the draft button (purchasing.draft)');
$sv = activity('screen.view', $a0); $last = $sv[count($sv) - 1]; $af = json_decode($last['after'] ?? 'null', true);
$found = false; foreach ($sv as $s) { $x = json_decode($s['after'] ?? 'null', true); if (($s['screen'] ?? '') === 'special-check' && isset($x['recipe_id']) && $x['recipe_id'] === $w['r_braise'] && $x['date'] === $D && $x['portions'] == 60) { $found = true; } }
ok($found, 'screen.view logs the special check with after.recipe_id, date and portions');
finish();
