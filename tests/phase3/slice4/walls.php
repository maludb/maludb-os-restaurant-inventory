<?php
/** Proof: the walls — cost, the internal note, the site, the arithmetic (purchasing.md "Proof: Walls"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27); $bea = person(28); $ann = person(29);
$po = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 3, 'unit_cost' => 30], ['supplier_item' => $w['si_egg'], 'qty' => 1, 'unit_cost' => 54]], ['notes' => 'Back door.', 'internal_notes' => 'WALLEDNOTE']);
post($marco, '/purchasing/send.php', ['purchase_order' => $po]);
$lids = array_map(fn ($l) => (int) $l['id'], po_lines_of($po));
$inv = (int) (one("SELECT id FROM supplier_invoices ORDER BY id LIMIT 1") ?: 0);

echo "1. The PO without cost\n";
foreach (['Ann (Viewer)' => $ann, 'Bea (Kitchen)' => $bea] as $who => $j) {
    $l = page($j, '/purchasing/');
    ok($l['code'] === 200 && has($l, "id=\"po-row-$po\"") && !has($l, '>Total<') && !has($l, '$'), "$who: the list shows the order without a Total column and without a dollar sign");
    $v = page($j, "/purchasing/$po");
    ok($v['code'] === 200 && has($v, 'id="po-lines-table"') && !has($v, 'Unit cost') && !has($v, 'id="po-totals"') && !has($v, 'WALLEDNOTE') && !has($v, 'id="po-internal-notes"') && has($v, 'Back door.'), "$who: the view has the lines in units, our note to the supplier, and no unit cost, total or internal note");
    ok(!str_contains(preg_replace('#<script.*?</script>#s', '', $v['body']), '$'), "$who: not a dollar sign on the page");
    $json = get_json($j, "/purchasing/$po")['json']['data']['purchase_order'] ?? [];
    ok(($json['cost_withheld'] ?? false) === true && !array_key_exists('total', $json) && !array_key_exists('internal_notes', $json) && !array_key_exists('unit_cost', $json['lines'][0] ?? ['unit_cost' => 1]) && $json['lines'][0]['qty'] === '3.0000', "$who JSON: cost_withheld, no total, no internal note, no unit cost on a line; the quantities are there");
}
$v = page($ann, "/purchasing/$po");
ok(!has($v, 'id="po-send-btn"') && !has($v, 'id="po-edit-btn"') && !has($v, 'id="po-actions-draft"') && !has($v, 'id="po-tab-link"') && !has($v, 'id="po-tab-invoices"') && !has($v, 'id="po-invoice-btn"'), 'a Viewer is offered nothing to do: no send, edit, link or invoices tab');

echo "2. Cost-seers see it all\n";
foreach (['Dana (Chef)' => $dana, 'Marco (Manager)' => $marco] as $who => $j) {
    $v = page($j, "/purchasing/$po");
    ok(has($v, 'id="po-totals"') && has($v, '$144.00') && has($v, '$30.00') && has($v, 'WALLEDNOTE') && has($v, 'Unit cost'), "$who: totals, the unit cost and the internal note");
    ok(has(page($j, '/purchasing/'), '>Total<') && has(page($j, '/purchasing/'), '$144.00'), "$who: the list's Total column");
}
$jm = get_json($marco, "/purchasing/$po")['json']['data'];
ok($jm['purchase_order']['total'] === '144.00' && $jm['purchase_order']['lines'][0]['unit_cost'] === '30.0000' && $jm['may']['send'] === true && isset($jm['purchase_order']['link']), 'JSON for Marco: the total, the unit cost, may.send, the link\'s state (never the token)');
ok(!array_key_exists('link', get_json($dana, "/purchasing/$po")['json']['data']['purchase_order']), '…and no link state for Dana (purchasing.send holders only)');
ok(!has(page($dana, "/purchasing/$po?tab=link"), 'id="po-link"') && has(page($marco, "/purchasing/$po?tab=link"), 'id="po-link"'), 'the Link tab opens for Marco and falls back to Events for Dana');

echo "3. Kitchen sees cost when the site says so\n";
ri_sql('UPDATE site_settings SET kitchen_sees_cost = true WHERE scope_id = 101');
$v = page($bea, "/purchasing/$po");
ok(has($v, 'id="po-totals"') && has($v, '$144.00') && has($v, 'Unit cost') && has($v, 'WALLEDNOTE'), 'kitchen_sees_cost on: Bea sees the totals, the unit costs and the internal note (walled like cost)');
ok(has(page($bea, '/invoices/'), 'id="invoices-table"') && !has(page($bea, '/invoices/'), 'id="invoices-withheld"'), '…and the invoices list opens with its rows');
ok(!has(page($bea, '/purchasing/alerts'), 'id="alerts-withheld"'), '…and the alerts are not withheld');
ri_sql('UPDATE site_settings SET kitchen_sees_cost = false WHERE scope_id = 101');
ok(has(page($bea, "/purchasing/$po"), 'id="po-totals"') === false, 'switched off again, the wall is back');

echo "4. Invoices are cost\n";
foreach (['Ann (Viewer)' => $ann, 'Bea (Kitchen)' => $bea] as $who => $j) {
    $l = page($j, '/invoices/');
    ok($l['code'] === 200 && has($l, 'id="invoices-withheld"') && !has($l, 'id="invoices-table"') && !has($l, 'id="invoice-list-new-btn"'), "$who: /invoices/ opens and says it is money — no table, no Enter button");
    ok($inv > 0 && page($j, "/invoices/$inv")['code'] === 404, "$who: an invoice is not here (404, the view is empty)");
    ok((get_json($j, '/invoices/')['json']['data']['cost_withheld'] ?? false) === true && (get_json($j, '/invoices/')['json']['data']['invoices'] ?? null) === [], "$who JSON: cost_withheld, no rows");
}

echo "5. The site is a wall\n";
ri_sql("INSERT INTO purchase_orders (scope_id, supplier_id, created_by, expected_on) VALUES (102, :s, 27, current_date + 2)", ['s' => $w['sysco']]);
$air = last_id('purchase_orders');
ri_sql("INSERT INTO purchase_order_lines (purchase_order_id, line_no, ingredient_id, supplier_item_id, purchase_unit_code, qty_ordered, qty_ordered_base, unit_cost) VALUES (:p, 1, :i, :si, 'bag50', 1, 0, 25)", ['p' => $air, 'i' => $w['flour'], 'si' => $w['si_flour']]);
ok(!has(page($dana, '/purchasing/'), "id=\"po-row-$air\"") && page($dana, "/purchasing/$air")['code'] === 404, 'Downtown does not see Airport\'s order, in the list or by its id (404)');
ok(post($dana, '/purchasing/line.php', ['purchase_order' => $air, 'supplier_item' => $w['si_egg'], 'qty' => 1])['code'] === 404, 'a write to it is 404 too');
$mAir = person(27, AIRPORT);
ok(has(page($mAir, '/purchasing/'), "id=\"po-row-$air\"") && !has(page($mAir, '/purchasing/'), "id=\"po-row-$po\"") && page($mAir, "/purchasing/$po")['code'] === 404, 'Marco signed in at Airport sees Airport\'s order and not Downtown\'s');
$vm = page($mAir, "/purchasing/$air");
ok($vm['code'] === 200 && !has($vm, 'id="po-totals"') && !has($vm, '$'), 'at Airport Marco is Kitchen: no cost');
ok(post($mAir, '/purchasing/send.php', ['purchase_order' => $air])['code'] === 403 && page($mAir, '/invoices/new')['code'] === 403, '…and cannot send or enter an invoice there');
ok(post($marco, '/purchasing/send.php', ['purchase_order' => $air])['code'] === 404, 'at Downtown the same order is not even here to send (404)');
$dueA = page($mAir, '/purchasing/due?on=' . site_day(5, 'America/Chicago'));
ok($dueA['code'] === 200 && !has($dueA, "id=\"due-row-$po\""), 'deliveries due is the site\'s own');

echo "6. The arithmetic of a suggested order\n";
ri_sql("UPDATE purchase_orders SET origin = 'suggested' WHERE id = :p", ['p' => $po]);
ri_sql("UPDATE purchase_order_lines SET need_base = 90718.47, have_base = 22679.6185, on_order_base = 0, cap_base = 136077.7, safety_base = 4535.9237, raw_base = 72574.77, rounding_note = 'rounded up to whole bags (3)' WHERE id = :l", ['l' => $lids[0]]);
foreach (['Dana' => $dana, 'Ann' => $ann] as $who => $j) {
    $v = page($j, "/purchasing/$po");
    ok(has($v, 'id="po-line-math-' . $lids[0] . '"') && has($v, 'rounded up to whole bags (3)') && has($v, 'Need') && has($v, 'On order') && has($v, 'id="po-line-why-' . $lids[0] . '"') && !has($v, 'id="po-line-math-' . $lids[1] . '"'), "$who: the arithmetic row under the line that has one (need · have · on order · the rounding) and a Why toggle on the phone card; none under the other");
}
$mathJ = get_json($ann, "/purchasing/$po")['json']['data']['purchase_order']['lines'][0]['arithmetic'] ?? [];
ok(($mathJ['rounding_note'] ?? '') === 'rounded up to whole bags (3)' && near($mathJ['need_base'] ?? 0, 90718.47, 0.01), 'JSON carries the arithmetic for a Viewer too (quantities, no money)');
ok(has(page($dana, '/purchasing/'), 'Suggested') , 'the list marks the origin');
finish();
