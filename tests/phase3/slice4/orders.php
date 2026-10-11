<?php
/** Proof: purchase orders — drafting from the order guide, the lines, the header, the unit the supplier reads, the walls on drafting (purchasing.md "Proof: POs"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27); $bea = person(28); $ann = person(29);

echo "1. Dana drafts to Sysco from the order guide\n";
$win = q('SELECT delivery_on, cutoff_at FROM ri_next_order_windows(:s, 101)', ['s' => $w['sysco']])[0];
$r = post($dana, '/purchasing/save.php', ['supplier' => $w['sysco'], 'guide' => [$w['si_flour'], $w['si_egg']], 'guide_qty' => [$w['si_flour'] => '3', $w['si_egg'] => '1'], 'notes' => 'Back door, please.', 'internal_notes' => 'Price check with Sam.']);
$po = last_id('purchase_orders');
$p = po_row($po); $ls = po_lines_of($po);
ok($r['code'] === 302 && $r['location'] === "/purchasing/$po/edit?notice=created", 'Create lands on the draft\'s edit page with the notice: ' . $r['location']);
ok($p['status'] === 'draft' && $p['origin'] === 'manual' && (int) $p['created_by'] === 30 && (int) $p['scope_id'] === 101 && preg_match('/^PO-\d{5}$/', $p['number']), 'a draft, origin manual, by Dana at Downtown, numbered PO-nnnnn: ' . $p['number']);
ok(count($ls) === 2 && (int) $ls[0]['line_no'] === 1 && $ls[0]['purchase_unit_code'] === 'bag50' && $ls[0]['supplier_sku'] === 'SY-FL50' && $ls[1]['purchase_unit_code'] === 'case', 'the ticked guide rows became two lines in the items\' purchase units, with their SKUs');
ok(near($ls[0]['qty_ordered_base'], 68038.8555, 0.01) && near($ls[1]['qty_ordered_base'], 180, 0.001), 'the trigger converted 3 bags to 68 038.86 g and a case of eggs to 180 each');
ok(near($ls[0]['unit_cost'], 25) && near($ls[1]['unit_cost'], 54) && near($p['total'], 129), 'unit cost defaulted to the items\' last price ($25, $54); the total is the trigger\'s $129.00');
ok($p['expected_on'] === $win['delivery_on'] && $p['cutoff_at'] !== null, "the expected date is the supplier's next delivery by its schedule ({$win['delivery_on']}) and the cut-off is kept");
ok($p['notes'] === 'Back door, please.' && $p['internal_notes'] === 'Price check with Sam.', 'notes and internal notes saved');
ok(near((float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]), 0), 'ri_on_order() is 0 while the order is a draft');

echo "2. The screens\n";
$v = page($dana, "/purchasing/$po");
ok($v['code'] === 200 && has($v, 'id="po-lines-table"') && has($v, 'id="po-line-row-' . $ls[0]['id'] . '"') && has($v, 'data-screen="purchase-order-view"') && has($v, 'data-entity="purchase_order"') && has($v, 'data-record-id="' . $po . '"'), 'the PO view: lines table, a row per line, the shell stamps');
ok(has($v, '3 bag50') && has($v, '68,038.856 g') && has($v, 'SY-FL50') && !has($v, 'id="po-send-btn"') && has($v, 'id="po-edit-btn"') && has($v, 'Back door, please.') && has($v, 'Price check with Sam.'), 'quantities in the purchase unit with the base muted, the SKU, Edit but no Send for a Chef (sending is for the Manager), both notes to a cost-seer');
$e = page($dana, "/purchasing/$po/edit");
ok($e['code'] === 200 && has($e, 'id="po-form"') && has($e, 'id="po-line-form"') && has($e, 'id="po-guide-row-' . $w['si_chicken'] . '"') && !has($e, 'id="po-guide-row-' . $w['si_flour'] . '"') && has($e, 'data-screen="purchase-order-edit"'), 'the edit page: header form, line form, and the guide offers only the chicken (flour and eggs are on the order)');
$l = page($dana, '/purchasing/');
ok($l['code'] === 200 && has($l, 'id="po-table"') && has($l, "id=\"po-row-$po\"") && has($l, 'PO-') && has($l, 'id="po-list-new-btn"') && has($l, 'id="po-list-suggested-btn"'), 'the list: the table with a row per PO, New purchase order and Suggested orders');
ok(has($l, 'next cut-off'), "a draft's row shows the supplier's next cut-off");
$n = page($dana, '/purchasing/new?supplier=' . $w['sysco']);
ok($n['code'] === 200 && has($n, 'id="po-form"') && has($n, 'value="' . $win['delivery_on'] . '"') && has($n, 'data-screen="purchase-order-add"'), 'the new-PO form prefilled with the supplier\'s next delivery date');
ok(page($dana, '/purchasing/?status=draft&supplier=' . $w['sysco'])['code'] === 200 && has(page($dana, '/purchasing/?status=sent'), 'No purchase orders'), 'the list filters by status and supplier');

echo "3. The unit the supplier reads — the line keeps it\n";
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_flour'], 'qty' => '10', 'unit' => 'lb']);
$lb = po_lines_of($po)[2] ?? [];
ok($r['code'] === 302 && ($lb['purchase_unit_code'] ?? '') === 'lb' && near($lb['qty_ordered'] ?? 0, 10) && near($lb['qty_ordered_base'] ?? 0, 4535.9237, 0.01), 'a line of flour in lb keeps lb; the base quantity (4 535.92 g) is what a receipt will match on');
ok(near($lb['unit_cost'] ?? 0, 0.5, 0.001), 'the price follows the unit: $25 a 50-lb bag is $0.50 a lb (the database converted it)');
$flourLine = (int) $lb['id'];
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'line' => $flourLine, 'qty' => '12']);
$lb2 = q('SELECT * FROM purchase_order_lines WHERE id = :i', ['i' => $flourLine])[0];
ok($r['code'] === 302 && near($lb2['qty_ordered'], 12) && $lb2['purchase_unit_code'] === 'lb' && near($lb2['unit_cost'], 0.5, 0.001), 'changing only the quantity keeps the unit and the price');
$r = post($dana, '/purchasing/line-remove.php', ['line' => $flourLine]);
ok($r['code'] === 302 && count(po_lines_of($po)) === 2 && near(po_row($po)['total'], 129), 'Remove takes the line and the total follows');

echo "4. Refusals in words\n";
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_lime'], 'qty' => '1']);
ok($r['code'] === 422 && says($r, "SMOKE Produce Co's, not SMOKE Sysco's"), "a supplier item of the Produce Co on a Sysco PO → 422: it names whose item it is");
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $po, 'supplier' => $w['produce']]);
ok($r['code'] === 422 && has($r, 'Remove the lines first') && (int) po_row($po)['supplier_id'] === $w['sysco'], 'changing the supplier with lines on → 422, nothing changed');
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'ingredient' => $w['stock'], 'qty' => '2']);
ok($r['code'] === 422 && has($r, 'has no purchase unit — add one on the ingredient'), 'an ingredient with no purchase unit and no unit given → the sentence');
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_lime'] + 0, 'qty' => '1']);
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'ingredient' => $w['lime'], 'qty' => '2', 'unit' => 'kg']);
ok($r['code'] === 422 && (has($r, 'each') || has($r, 'convert')), 'limes in kg (no each-weight) → the database\'s refusal, on the unit');
foreach (['0', '-2', 'abc'] as $bad) {
    $r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_egg'], 'qty' => $bad]);
    ok($r['code'] === 422, "a quantity of '$bad' → 422");
}
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $po, 'expected_on' => site_day(-3)]);
ok($r['code'] === 422 && has($r, 'not before today'), 'a delivery date before today → 422');
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $po, 'expected_on' => '2026-13-45']);
ok($r['code'] === 422 && has($r, 'is a date'), 'a date that is not a date → 422');
$r = post($dana, '/purchasing/save.php', ['supplier' => 999999, 'lines' => '[]']);
ok($r['code'] === 422 && has($r, 'not here'), 'a supplier that does not exist → 422');
$r = post($dana, '/purchasing/save.php', ['supplier' => $w['sysco'], 'lines' => '{"a":1}']);
ok($r['code'] === 422 && has($r, 'JSON list'), 'lines that are not a list of objects → 422');

echo "5. The header — area, dates, a note\n";
$area = $w['walkin'];
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $po, 'area' => $area, 'expected_on' => site_day(9), 'notes' => 'Ring the bell.']);
$p = po_row($po);
ok($r['code'] === 302 && (int) $p['storage_area_id'] === $area && $p['expected_on'] === site_day(9) && $p['notes'] === 'Ring the bell.' && $p['internal_notes'] === 'Price check with Sam.', 'the header saved; the internal note, left out of the request, stayed');
$r = post($dana, '/purchasing/save.php', ['purchase_order' => 'PO-99999']);
ok($r['code'] === 404, 'a PO number that does not exist → 404');
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $p['number'], 'notes' => 'By number.']);
ok($r['code'] === 302 && po_row($po)['notes'] === 'By number.', 'a PO is named by its number as well as its id');
$r = post($dana, '/purchasing/save.php', ['supplier' => $w['produce'], 'lines' => json_encode([['ingredient' => $w['lime'], 'qty' => 2]])]);
$pc = last_id('purchase_orders');
$l1 = po_lines_of($pc)[0] ?? [];
ok($r['code'] === 302 && ($l1['purchase_unit_code'] ?? '') === 'case' && near($l1['qty_ordered_base'] ?? 0, 400), 'an ingredient the supplier has no item for: the ingredient\'s own purchase unit (case = 200 each) stands in; the PO has the Produce Co\'s next-day delivery date');
ok(po_row($pc)['expected_on'] !== null && po_row($pc)['cutoff_at'] === null, 'a supplier with no schedule: any day, its lead time; no cut-off kept');
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $pc, 'supplier' => $w['sysco']]);
ok($r['code'] === 422, 'moving a draft with lines to Sysco is refused too');
post($dana, '/purchasing/line-remove.php', ['line' => $l1['id']]);
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $pc, 'supplier' => $w['sysco']]);
ok($r['code'] === 302 && (int) po_row($pc)['supplier_id'] === $w['sysco'], 'with the lines gone the supplier may change');

echo "6. Who may draft\n";
foreach (['Bea (Kitchen)' => $bea, 'Ann (Viewer)' => $ann] as $who => $j) {
    $r = post($j, '/purchasing/save.php', ['supplier' => $w['sysco'], 'lines' => '[]']);
    ok($r['code'] === 403 && has($r, 'does not let you'), "$who may not draft: 403 in words");
    ok(page($j, '/purchasing/new')['code'] === 403 && page($j, "/purchasing/$po/edit")['code'] === 403, "…and neither opens the New or Edit pages");
    ok(post($j, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_egg'], 'qty' => 1])['code'] === 403, "…nor adds a line");
}
$mid = $po;
ok(post($marco, '/purchasing/line.php', ['purchase_order' => $mid, 'supplier_item' => $w['si_chicken'], 'qty' => '5'])['code'] === 302 && count(po_lines_of($mid)) === 3, 'Marco (Manager) may draft and add lines too (Chef\'s rights and more)');

echo "7. Log\n";
foreach (['purchase_order.draft', 'purchase_order.update', 'purchase_order.line_set', 'purchase_order.line_remove', 'screen.view'] as $a) {
    $row = q('SELECT * FROM activity_log WHERE action = :a AND purchase_order_id = :p ORDER BY id DESC LIMIT 1', ['a' => $a, 'p' => $po])[0] ?? null;
    ok($row !== null && (int) $row['scope_id'] === 101 && $row['source'] === 'web', "$a is logged with the site and the PO's audit key");
}
$d = q("SELECT after FROM activity_log WHERE action = 'purchase_order.draft' AND purchase_order_id = :p", ['p' => $po])[0]['after'];
ok(!str_contains($d, 'token') && str_contains($d, 'bag50'), 'the draft log carries the lines with their units');
finish();
