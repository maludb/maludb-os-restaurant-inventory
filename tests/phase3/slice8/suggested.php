<?php
/** Slice 8 — the suggested orders (projection.md "Proof": Suggested orders; projection-and-ordering.md §3's worked numbers): the windows, the periodic-review rule re-derived from the raw inputs, every rounding case, the minimum, Draft the PO, the second Draft, Recompute, Draft all due, a supplier with no schedule, the wall. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$a0 = last_activity_id();
$marco = person(27);
$dana = person(30);
$bea = person(28);
$sysco = $w['sysco'];

/** The rule again, in PHP, from the raw tables (not from ri_suggested_order): returns need, safety, have, cap, raw, units, note. */
function derive(int $supplier, int $ing, int $siId, bool $split = false): array
{
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    $win = q('SELECT * FROM ri_next_order_windows(:s, 101, now())', ['s' => $supplier])[0];
    $P = max(1, (int) round((strtotime($win['next_delivery_on']) - strtotime($win['delivery_on'])) / 86400));
    $dem = (float) one('SELECT COALESCE(sum(qty_base), 0) FROM ri_ingredient_demand(101, :a::date, CAST(:b AS date) - 1) WHERE ingredient_id = :i', ['a' => $win['delivery_on'], 'b' => $win['next_delivery_on'], 'i' => $ing]);
    $st = q('SELECT * FROM ingredient_site_settings WHERE ingredient_id = :i AND scope_id = 101', ['i' => $ing])[0];
    $item = q('SELECT * FROM supplier_items WHERE id = :s', ['s' => $siId])[0];
    $ingr = q('SELECT * FROM ingredients WHERE id = :i', ['i' => $ing])[0];
    $cat = q('SELECT * FROM ingredient_categories WHERE id = :c', ['c' => $ingr['category_id']])[0];
    $daily = array_map(fn ($r) => -(float) $r['q'], q("SELECT business_date, sum(qty_base) AS q FROM inventory_transactions WHERE scope_id = 101 AND ingredient_id = :i AND txn_type = 'depletion' AND business_date >= current_date - 28 GROUP BY 1", ['i' => $ing]));
    $z = ['0.95' => 1.645, '0.98' => 2.054, '0.90' => 1.282][number_format((float) $st['service_level'], 2)] ?? 1.645;
    $safety = null;
    if ($st['par_mode'] === 'dynamic') {
        if (count($daily) >= 7) { $mu = array_sum($daily) / count($daily); $sd = sqrt(array_sum(array_map(fn ($x) => ($x - $mu) ** 2, $daily)) / (count($daily) - 1)); $safety = $z * $sd * sqrt($P); }
        else { $safety = $dem * (float) $cat['safety_stock_pct'] / 100; }
        $need = $dem + $safety;
    } else { $need = $st['par_base'] !== null ? (float) $st['par_base'] : $dem + $dem * (float) $cat['safety_stock_pct'] / 100; }
    $onHand = (float) one('SELECT ri_on_hand(:i, 101)', ['i' => $ing]);
    $onOrder = (float) one('SELECT ri_on_order(:i, 101, :d::date)', ['i' => $ing, 'd' => $win['delivery_on']]);
    $cap = null;
    if ($ingr['shelf_life_days'] !== null && (int) $ingr['shelf_life_days'] < $P + 7) {
        $v = array_map(fn ($r) => -(float) $r['q'], q("SELECT business_date, sum(qty_base) AS q FROM inventory_transactions WHERE scope_id = 101 AND ingredient_id = :i AND txn_type = 'depletion' AND business_date >= current_date - 56 GROUP BY 1", ['i' => $ing]));
        sort($v);
        if ($v !== []) { $rank = 0.7 * (count($v) - 1); $lo = (int) floor($rank); $hi = (int) ceil($rank); $q70 = $v[$lo] + ($v[$hi] - $v[$lo]) * ($rank - $lo); $cap = $q70 * (int) $ingr['shelf_life_days']; }
    }
    $raw = max(min($need, $cap ?? $need) - ($onHand + $onOrder), 0);
    $per = (float) one('SELECT ri_to_base(:i, 1, :u)', ['i' => $ing, 'u' => $item['purchase_unit_code']]);
    if ($raw <= 0) { return ['P' => $P, 'demand' => $dem, 'safety' => $safety, 'need' => $need, 'have' => $onHand + $onOrder, 'cap' => $cap, 'raw' => 0.0, 'units' => 0.0, 'note' => 'covered', 'per' => $per, 'win' => $win]; }
    $u = $raw / $per;
    if ($split) { $units = ceil($u * 100) / 100; $note = 'split case allowed'; }
    else {
        $fl = floor($u);
        if ($fl >= 1 && (ceil($u) * $per - $raw) > $raw * 0.2 && ($raw - $fl * $per) < $per * 0.2) { $units = $fl; $note = 'rounded down (within 20 %)'; }
        else { $units = ceil($u); $note = 'rounded up to the ' . $item['purchase_unit_code']; }
    }
    if ($units < (float) $item['moq']) { $units = (float) $item['moq']; $note .= '; minimum ' . rtrim(rtrim(number_format((float) $item['moq'], 4, '.', ''), '0'), '.'); }
    return ['P' => $P, 'demand' => $dem, 'safety' => $safety, 'need' => $need, 'have' => $onHand + $onOrder, 'cap' => $cap, 'raw' => $raw, 'units' => $units, 'note' => $note, 'per' => $per, 'win' => $win];
}

echo "1. The windows\n";
$win = q('SELECT * FROM ri_next_order_windows(:s, 101, now())', ['s' => $sysco])[0];
$j = jget(27, '/purchasing/suggested?supplier=' . $sysco)['json']['data'] ?? [];
$card = $j['suppliers'][0] ?? [];
ok(($card['supplier_id'] ?? 0) === $sysco && ($card['delivery_on'] ?? '') === $win['delivery_on'] && strtotime($card['cutoff_at'] ?? '0') === strtotime($win['cutoff_at']) && $card['scheduled'] === true, 'the Sysco card shows the schedule\'s next cut-off and its delivery');
$P = (int) round((strtotime($win['next_delivery_on']) - strtotime($win['delivery_on'])) / 86400);
ok($card['protection_days'] === $P && ($P === 3 || $P === 4), 'the protection interval is the delivery to the delivery after the next cut-off: ' . $P . ' days (Saturday → Tuesday and Wednesday → Friday make 3 or 4)');
$cut = new DateTimeImmutable($win['cutoff_at'], new DateTimeZone('UTC')); $cutLocal = $cut->setTimezone(new DateTimeZone('America/New_York'));
ok(in_array($cutLocal->format('D H:i'), ['Sat 13:00', 'Wed 13:00'], true), 'the cut-off is Saturday or Wednesday at 13:00 site time (' . $cutLocal->format('D H:i') . ')');
$rows = array_column($card['lines'] ?? [], null, 'ingredient_id');

echo "2. The worked numbers\n";
$fl = $rows[$w['flour']] ?? [];
ok(near($fl['need_base'] ?? null, 250000, 0.001) && near($fl['have_base'] ?? null, 161025, 0.001) && near($fl['raw_base'] ?? null, 88975, 0.001) && ($fl['par_mode'] ?? '') === 'fixed', 'flour: a fixed par of 250 kg against 161.025 kg on hand — short by 88 975 g');
ok(near($fl['qty_units'] ?? null, 4, 0) && ($fl['rounding_note'] ?? '') === 'rounded up to the bag50' && near(88975 / 22679.6185, 3.9231, 0.0001), '88 975 g is 3.92 bags: "rounded up to the bag50" = 4 (the shortfall below, 20 936 g, is not under 20 % of a bag)');
$dflour = derive($sysco, $w['flour'], $w['si_flour']);
ok(near($dflour['units'], 4, 0) && $dflour['note'] === 'rounded up to the bag50', 'the independent derivation agrees');
$eg = $rows[$w['egg']] ?? [];
$de = derive($sysco, $w['egg'], $w['si_egg']);
ok(($eg['par_mode'] ?? '') === 'dynamic' && ($eg['thin_history'] ?? true) === false, 'eggs: dynamic, and sixteen weeks of history is not thin');
ok(near($eg['demand_base'] ?? null, $de['demand'], 0.001) && near($eg['safety_base'] ?? null, $de['safety'], 0.001) && near($eg['need_base'] ?? null, $de['need'], 0.001), 'eggs: need = Σ demand over the protection interval (' . round($de['demand'], 2) . ') + 1.645 × σ × √P (' . round($de['safety'], 4) . ') = ' . round($de['need'], 4) . ' — re-derived from the raw tables');
ok(near($eg['have_base'] ?? null, $de['have'], 0.001) && near($eg['raw_base'] ?? null, $de['raw'], 0.001) && near($eg['qty_units'] ?? null, $de['units'], 0) && ($eg['rounding_note'] ?? '') === $de['note'], 'eggs: have, short, units (' . ($eg['qty_units'] ?? '?') . ' ' . ($eg['purchase_unit'] ?? '') . ') and the note agree');
ok(array_key_exists('cap_base', $eg) && $eg['cap_base'] === null, 'eggs keep 21 days: no perishable cap');
$ch = $rows[$w['chicken']] ?? [];
ok(near($ch['qty_units'] ?? 1, 0, 0) && ($ch['rounding_note'] ?? '') === 'covered' && ($ch['purchase_unit'] ?? '') === 'lb', 'chicken: covered, bought by the pound (a catch-weight line)');
$oil = $rows[$w['oil']] ?? [];
$doil = derive($sysco, $w['oil'], $w['si_oil']);
ok(near($oil['qty_units'] ?? null, 4, 0) && ($oil['rounding_note'] ?? '') === 'rounded down (within 20 %)' && $doil['note'] === 'rounded down (within 20 %)' && near($oil['raw_base'] / 15141.65, 4.1, 0.0001), 'olive oil: 4.1 cases short — the case above would over-order by more than 20 % and the shortfall below is under 20 % of a case: "rounded down (within 20 %)" = 4');
$gin = $rows[$w['gin']] ?? [];
ok(near($gin['qty_units'] ?? null, 3, 0) && ($gin['rounding_note'] ?? '') === 'rounded up to the case6; minimum 3' && near($gin['raw_base'] ?? null, 5400, 0.001), 'gin: 1.2 cases short is 2 rounded up, and the supplier\'s minimum lifts it to 3: "rounded up to the case6; minimum 3"');
$rib = $rows[$w['rib']] ?? [];
ok(near($rib['qty_units'] ?? null, 5, 0) && ($rib['rounding_note'] ?? '') === 'rounded up to the case', 'short rib: 20 kg is 4.41 cases, 5 ordered');
$lim = jget(27, '/purchasing/suggested?supplier=' . $w['produce'])['json']['data']['suppliers'][0]['lines'] ?? [];
$lim = array_column($lim, null, 'ingredient_id')[$w['lime']] ?? [];
$dl = derive($w['produce'], $w['lime'], $w['si_lime']);
ok($dl['cap'] !== null && near($lim['cap_base'] ?? null, $dl['cap'], 0.001) && $dl['cap'] < 5000, 'limes (a shelf life of 5 days): the perishable cap is the 0.7 quantile of the last 56 days\' daily use × the shelf life = ' . round($dl['cap'], 3) . ', well under the par of 5 000');
ok(near($lim['raw_base'] ?? null, $dl['raw'], 0.001) && near($lim['raw_base'] ?? null, $dl['cap'] - $dl['have'], 0.001) && near($lim['qty_units'] ?? null, $dl['units'], 0), 'the cap binds: short = cap − have = ' . round($dl['raw'], 3) . ' → ' . ($lim['qty_units'] ?? '?') . ' case');
ok(array_sum(array_map(fn ($l) => $l['par_mode'] === 'fixed' ? 1 : 0, $card['lines'])) === count($card['lines']) - 1, 'every line shows its par mode (all fixed but the eggs)');
$sp = suggestion($w['splitter'])[$w['honey']];
$dh = derive($w['splitter'], $w['honey'], $w['si_honey'], true);
ok(near($sp['qty_units'], 1.85, 0.00001) && $sp['rounding_note'] === 'split case allowed' && near($dh['units'], 1.85, 0.00001), 'honey at a split-case supplier: 3 700 g of a 2 kg tub is 1.85 tubs, to 0.01: "split case allowed"');
ok(near($sp['unit_cost'], 18, 0.0001) && $sp['thin_history'] === false, 'the unit cost is the supplier item\'s price per purchase unit');

echo "3. The screen\n";
$h = page($marco, '/purchasing/suggested?supplier=' . $sysco);
ok($h['code'] === 200 && has($h, 'data-screen="suggested-order-list"') && has($h, 'id="supplier-card-' . $sysco . '"') && has($h, 'id="sugg-line-' . $w['si_flour'] . '"') && has($h, 'id="sugg-draft-btn-' . $sysco . '"') && has($h, 'id="sugg-draft-all"') && has($h, 'id="sugg-recompute-' . $sysco . '"') && has($h, 'id="supplier-window-' . $sysco . '"'), 'the card: the window, a line per supplier item, Draft the PO, Draft all due, Recompute');
$t = says_text($h);
ok(str_contains($t, 'rounded up to the bag50') && str_contains($t, 'rounded down (within 20 %)') && str_contains($t, 'minimum 3') && str_contains($t, 'covered'), 'every line says how it was rounded');
ok(has($h, 'id="supplier-total-' . $sysco . '"') && has($h, 'id="supplier-minimum-' . $sysco . '"') && str_contains($t, 'Minimum order $500.00'), 'the card carries the draft total and the supplier\'s minimum order value');
$tot = (float) $card['total'];
$expect = 0.0; foreach ($card['lines'] as $l) { $expect += round((float) $l['qty_units'] * (float) $l['unit_cost'], 2); }
ok(near($tot, $expect, 0.005) && near($tot, 4 * 25 + 54 * (float) $eg['qty_units'] + 4 * 40 + 3 * 108 + 5 * 60, 0.005), 'the total is the sum of the lines (units × unit cost): $' . number_format($tot, 2));
ri_sql('UPDATE suppliers SET min_order_value = 100 WHERE id = :s', ['s' => $w['splitter']]);
$hs = page($marco, '/purchasing/suggested?supplier=' . $w['splitter']);
ok(has($hs, 'id="supplier-under-min-' . $w['splitter'] . '"') && str_contains(says_text($hs), 'under the minimum'), 'a card whose draft is under the supplier\'s minimum order value says so (honey $33.30 against $100)');
ok(!has($hs, 'id="supplier-nolines-' . $w['splitter'] . '"') && str_contains(says_text($hs), 'No order schedule here'), 'a supplier with no schedule says it orders any day, delivered on its lead time');
ri_sql('UPDATE suppliers SET min_order_value = NULL WHERE id = :s', ['s' => $w['splitter']]);

echo "4. Draft the PO\n";
$mail0 = count(array_filter(explode("\n", (string) @file_get_contents(need('FAKE_MALUMAIL_LOG')))));
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $sysco, 'draft' => 'yes']);
$po = (int) ($r['json']['record_id'] ?? 0);
$prow = po_row($po);
ok($r['code'] === 200 && $po > 0 && $r['json']['location'] === '/purchasing/' . $po . '?notice=drafted' && str_contains($r['json']['did'], 'Drafted') && ($r['json']['refresh'] ?? '') === 'orderChanged', 'suggested_order_run (supplier, draft = yes) answers record_id = the PO and lands on it');
ok($prow['status'] === 'draft' && $prow['origin'] === 'suggested' && $prow['expected_on'] === $win['delivery_on'] && strtotime($prow['cutoff_at']) === strtotime($win['cutoff_at']) && (int) $prow['created_by'] === 27, 'a draft with origin suggested, the cut-off and the expected delivery of the window, made by Marco');
$pl = po_lines_of($po);
ok(count($pl) === 5, 'one line per suggestion with units: flour, eggs, oil, gin, rib (chicken is covered and left out)');
$byIng = array_column($pl, null, 'ingredient_id');
$f = $byIng[$w['flour']];
ok(near($f['qty_ordered'], 4, 0) && near($f['need_base'], 250000, 0.001) && near($f['have_base'], 161025, 0.001) && near($f['on_order_base'], 0, 0) && $f['cap_base'] === null && near($f['raw_base'], 88975, 0.001) && str_starts_with($f['rounding_note'], 'rounded up to the bag50 · fixed') && near($f['unit_cost'], 25, 0.0001), 'the flour line keeps need, have, on order, cap, raw and the rounding note with the par mode: "' . $f['rounding_note'] . '"');
$e2 = $byIng[$w['egg']];
ok(near($e2['safety_base'], $de['safety'], 0.001) && near($e2['need_base'], $de['need'], 0.001) && str_contains($e2['rounding_note'], '· dynamic') && !str_contains($e2['rounding_note'], 'thin'), 'the egg line keeps its safety stock and says dynamic, not thin');
ok(count(array_filter($pl, fn ($l) => $l['need_base'] !== null && $l['have_base'] !== null && $l['raw_base'] !== null && $l['rounding_note'] !== null)) === 5, 'every drafted line carries its arithmetic');
ok(count(array_filter(explode("\n", (string) @file_get_contents(need('FAKE_MALUMAIL_LOG'))))) === $mail0 && $prow['sent_at'] === null, 'it never sends: nothing is mailed and sent_at is empty');
$lg = activity('purchase_order.suggest', $a0); $la = json_decode($lg[count($lg) - 1]['after'], true);
ok((int) $lg[count($lg) - 1]['purchase_order_id'] === $po && (int) $lg[count($lg) - 1]['scope_id'] === 101 && $la['lines'] === 5 && $la['drafted_po_id'] === $po && isset($la['total']), 'purchase_order.suggest is logged with the site, the PO, the lines and the value');
$view = page($marco, '/purchasing/' . $po);
ok($view['code'] === 200 && has($view, 'rounded up to the bag50'), 'the drafted PO opens on the purchase order page with the rounding on its line');

echo "5. A second Draft, Recompute, Draft all due\n";
$po0 = row_count('purchase_orders');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $sysco, 'draft' => 'yes']);
ok($r['code'] === 200 && str_contains($r['json']['did'], 'Already drafted today') && str_contains($r['json']['did'], $prow['number']) && row_count('purchase_orders') === $po0, 'a second Draft the same day: "Already drafted today — ' . $prow['number'] . '" and no second PO');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $sysco, 'draft' => 'no']);
ok($r['code'] === 200 && row_count('purchase_orders') === $po0 && !str_contains($r['json']['did'], 'Drafted'), 'Recompute (draft = no) changes nothing');
$hh = page($marco, '/purchasing/suggested?supplier=' . $sysco);
ok(has($hh, 'id="supplier-drafted-' . $sysco . '"') && !has($hh, 'id="sugg-draft-btn-' . $sysco . '"') && str_contains(says_text($hh), $prow['number']), 'the card shows "drafted … — ' . $prow['number'] . '" instead of the button');
set_on_hand($w['lime'], $w['walkin'], 10000);
$r = jpost(27, '/purchasing/suggested/run.php', ['draft' => 'yes', 'due_hours' => 168]);
$sup = array_column($r['json']['suppliers'] ?? [], null, 'supplier_id');
ok($r['code'] === 200 && count($sup) === 2 && ($sup[$sysco]['reason'] ?? '') === 'already drafted today' && ($sup[$w['produce']]['reason'] ?? '') === 'covered' && row_count('purchase_orders') === $po0 && str_contains($r['json']['did'], 'covered') && str_contains($r['json']['did'], 'already drafted today'), 'Draft all due (168 hours): Sysco already drafted, Produce Co covered — nothing doubled: "' . $r['json']['did'] . '"');
set_on_hand($w['lime'], $w['walkin'], 60);
$r = jpost(27, '/purchasing/suggested/run.php', ['draft' => 'yes', 'due_hours' => 168]);
$sup = array_column($r['json']['suppliers'] ?? [], null, 'supplier_id');
$limePo = (int) ($sup[$w['produce']]['purchase_order_id'] ?? 0);
ok(($sup[$w['produce']]['skipped'] ?? true) === false && $limePo > 0 && po_row($limePo)['origin'] === 'suggested' && $r['json']['location'] === '/purchasing/suggested?d=1&c=0&a=1&notice=run' && str_contains($r['json']['did'], '1 drafted'), 'Draft all due drafts the supplier that has something to order (Produce Co) and lands on the list with the tally');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['splitter'], 'draft' => 'yes']);
$hp = (int) $r['json']['record_id']; $hrow = po_row($hp);
ok($hp > 0 && $hrow['expected_on'] === (string) one('SELECT delivery_on FROM ri_next_order_windows(:s, 101, now())', ['s' => $w['splitter']]) && $hrow['expected_on'] <= day(1) && near(po_lines_of($hp)[0]['qty_ordered'], 1.85, 0.00001), 'a supplier with no schedule drafts for tomorrow (its lead time), 1.85 tubs');
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'yes', 'due_hours' => 7]);
ok($r['code'] === 200, 'due_hours with a supplier named is read for that supplier');
$h = page($marco, '/purchasing/suggested?due_hours=168');
ok(has($h, 'id="supplier-card-' . $sysco . '"') && has($h, 'id="supplier-card-' . $w['produce'] . '"') && !has($h, 'id="supplier-card-' . $w['splitter'] . '"'), 'the list of suppliers due in a week names the two with schedules (a supplier with none is a person\'s to order)');
$t24 = jget(27, '/purchasing/suggested?due_hours=24')['json']['data'] ?? [];
$dueNow = array_column(q('SELECT * FROM ri_suppliers_due(101, 24)'), 'supplier_id');
ok(array_map(fn ($c) => $c['supplier_id'], $t24['suppliers'] ?? []) == array_map('intval', $dueNow), 'due_hours=24 lists exactly ri_suppliers_due(101, 24)');

echo "6. The thin-history flag, the wall, the rights\n";
ri_sql('UPDATE ri_settings SET dynamic_par_after_weeks = 40 WHERE id = 1');
$thin = suggestion($sysco)[$w['egg']];
ok($thin['thin_history'] === true && $thin['par_mode'] === 'fixed', 'under the history the setting asks for, an ingredient set to dynamic orders on the fixed rule and says thin');
ri_sql('UPDATE ri_settings SET dynamic_par_after_weeks = 4 WHERE id = 1');
ok(suggestion($sysco)[$w['egg']]['par_mode'] === 'dynamic', '(and goes back once the history is long enough)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
$ann = q('SELECT * FROM ri_suggested_order(101, :s, now())', ['s' => $sysco]);
ok(count($ann) >= 5 && count(array_filter($ann, fn ($l) => $l['unit_cost'] !== null)) === 0 && count(array_filter($ann, fn ($l) => $l['raw_base'] !== null)) === count($ann), 'a Viewer who reaches the function reads the arithmetic and never a cost (db/028)');
pdo()->exec("SELECT set_config('app.member_id', '30', false)");
ok(count(array_filter(q('SELECT * FROM ri_suggested_order(101, :s, now())', ['s' => $sysco]), fn ($l) => $l['unit_cost'] !== null)) >= 5, 'a Chef reads the unit cost');
pdo()->exec("SELECT set_config('app.member_id', '', false)");
$r = jpost(28, '/purchasing/suggested/run.php', ['supplier' => $sysco, 'draft' => 'yes']);
ok($r['code'] === 403, 'Bea (Kitchen, no purchasing.draft) may not draft');
ok(page($bea, '/purchasing/suggested')['code'] === 403, 'and may not open the screen');
ok(page(person(29), '/purchasing/suggested')['code'] === 403, 'Ann (Viewer) may not open it either');
ok(page($dana, '/purchasing/suggested?supplier=' . $sysco)['code'] === 200 && has(page($dana, '/purchasing/suggested?supplier=' . $sysco), 'id="sugg-recompute-' . $sysco . '"'), 'Dana (Chef, purchasing.draft) opens it');
$r = jpost(30, '/purchasing/suggested/run.php', ['supplier' => 999999, 'draft' => 'yes']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['supplier']), 'a supplier that is not here is refused');

echo "7. An agent drafts\n";
set_on_hand($w['lime'], $w['walkin'], 60);
ri_sql("DELETE FROM purchase_order_lines WHERE purchase_order_id = :p", ['p' => $limePo]);
ri_sql("DELETE FROM purchase_orders WHERE id = :p", ['p' => $limePo]);
$n0 = nid();
$r = apost(40, 9201, '/purchasing/suggested/run.php', ['supplier' => $w['produce'], 'draft' => 'yes']);
$apo = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $apo > 0 && po_row($apo)['origin'] === 'agent' && po_row($apo)['status'] === 'draft', 'the expert (a run token) drafts: origin agent, a draft, nothing paused');
$nn = notes(27, 'agent_drafted', $n0);
ok(count($nn) === 1 && str_contains($nn[0]['title'], 'drafted') && str_contains($nn[0]['body'], '$') && (int) $nn[0]['record_id'] === $apo, 'the Manager is told: "The Purchasing agent drafted PO-… for … " with the value (he sees cost)');
$lg = activity('purchase_order.suggest', $a0);
ok($lg[count($lg) - 1]['source'] === 'agent', 'logged as the agent\'s');
finish();
