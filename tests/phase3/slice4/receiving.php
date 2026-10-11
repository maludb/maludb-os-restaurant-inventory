<?php
/** Proof: receiving against a PO, deliveries due, the supplier's answers by phone, close short, close and cancel (purchasing.md "Proof: Receiving against" and "Close and cancel"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27); $bea = person(28); $ann = person(29);

$po = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 3, 'unit_cost' => 30], ['supplier_item' => $w['si_egg'], 'qty' => 1], ['supplier_item' => $w['si_chicken'], 'qty' => 10]], ['expected_on' => site_day(3), 'area' => $w['dry']]);
post($marco, '/purchasing/send.php', ['purchase_order' => $po]);
[$lFlour, $lEgg, $lChk] = array_map(fn ($l) => (int) $l['id'], po_lines_of($po));

echo "1. The supplier's answers, recorded by a person\n";
$n0 = last_note_id();
$r = post($dana, '/purchasing/acknowledge.php', ['purchase_order' => $po, 'expected_on' => site_day(2), 'supplier_order_ref' => 'PH-1']);
$p = po_row($po);
ok($r['code'] === 302 && $p['status'] === 'acknowledged' && $p['expected_on'] === site_day(2) && $p['supplier_order_ref'] === 'PH-1', 'Acknowledge by phone: acknowledged, the date and their reference kept');
$ev = q("SELECT * FROM purchase_order_events WHERE purchase_order_id = :p AND kind = 'acknowledge'", ['p' => $po])[0] ?? [];
ok(($ev['source'] ?? '') === 'manual' && (int) $ev['member_id'] === 30, 'the event is source manual, by Dana (the one writer, p_source)');
ok(count(notes_for(30, 'po_ack', $n0)) === 1 && count(notes_for(27, 'po_ack', $n0)) === 1, 'the creator and the sender are told (po_ack)');
ok(post($bea, '/purchasing/acknowledge.php', ['purchase_order' => $po])['code'] === 403 && post($ann, '/purchasing/line-decline.php', ['line' => $lEgg, 'reason' => 'x'])['code'] === 403, 'Bea and Ann may not record the supplier\'s answers');
$r = post($dana, '/purchasing/line-decline.php', ['line' => $lEgg, 'reason' => '']);
ok($r['code'] === 422 && says($r, 'reason is required'), 'a decline with no reason → 422');
$n1 = last_note_id();
$r = post($dana, '/purchasing/line-decline.php', ['line' => $lEgg, 'reason' => 'out of stock']);
ok($r['code'] === 302 && q('SELECT status, supplier_note FROM purchase_order_lines WHERE id = :l', ['l' => $lEgg])[0] === ['status' => 'declined', 'supplier_note' => 'out of stock'] && count(notes_for(27, 'po_decline', $n1)) === 1, 'the eggs declined by phone: the line, the note, po_decline');
ok(post($dana, '/purchasing/line-decline.php', ['line' => $lEgg, 'reason' => 'again'])['code'] === 422, 'a declined line is not declined twice');

echo "2. Receiving against it\n";
$pv = page($dana, "/purchasing/$po");
ok(has($pv, 'id="po-receive-btn"') && has($pv, '/receipts/new?purchase_order=' . $po) && has($pv, 'id="po-line-decline-open-' . $lChk . '"'), 'the PO page offers Receive (to slice 3\'s receipt, the PO named) and per-line "Supplier declined"');
ok(!has(page($ann, "/purchasing/$po"), 'id="po-receive-btn"'), 'a Viewer is not offered Receive');
$before = (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]);
$g = receive_against($dana, $po, $w['dry'], [$lFlour => '2']);
$fl = q('SELECT status, qty_received_base FROM purchase_order_lines WHERE id = :l', ['l' => $lFlour])[0];
ok($fl['status'] === 'partial' && near($fl['qty_received_base'], 45359.237, 0.01) && po_row($po)['status'] === 'partial', 'two bags of three arrive: the flour line is partial, the PO is partial');
ok(near($before - (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]), 45359.237, 0.01), 'ri_on_order() fell by the two bags that came: one bag of this order is still to come');
$ev = q("SELECT * FROM purchase_order_events WHERE purchase_order_id = :p AND kind = 'received'", ['p' => $po])[0] ?? [];
ok($ev !== [] && str_contains((string) $ev['note'], q('SELECT number FROM goods_receipts WHERE id = :g', ['g' => $g])[0]['number']), 'a `received` event names the receipt');
$pv = page($dana, "/purchasing/$po?tab=receipts");
ok(has($pv, 'id="po-receipt-row-' . $g . '"') && has($pv, 'id="po-receipts-table"') && has($pv, 'GR-'), 'the Receipts tab lists the receipt with a link');
$pv = page($dana, "/purchasing/$po");
ok(has($pv, 'id="po-line-status-' . $lFlour . '"') && preg_match('#po-line-row-' . $lFlour . '".*?2 bag50.*?partly received#s', $pv['body']), 'the lines table shows what has come so far (2 bag50) and the line\'s status');
ok(!has(page($dana, "/purchasing/$po/edit"), 'id="po-line-form"'), 'a partly received order has no edit page for lines');

echo "3. Deliveries due\n";
$d = page($ann, '/purchasing/due?on=' . site_day(3));
ok($d['code'] === 200 && has($d, 'id="due-table"') && has($d, 'id="due-row-' . $po . '"') && has($d, 'data-screen="deliveries-due"'), 'the table lists the order expected by then, for any reader');
ok(preg_match('#due-row-' . $po . '".*?>yes<#s', $d['body']) && !has($d, 'id="due-receive-' . $po . '"'), '"received: yes" once a receipt is posted; a Viewer has no Receive button');
ok(has(page($dana, '/purchasing/due?on=' . site_day(3)), 'id="due-receive-' . $po . '"'), '…a Kitchen-or-above reader does');
$old = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 2]], ['expected_on' => site_day(3)]);
post($marco, '/purchasing/send.php', ['purchase_order' => $old]);
ri_sql('UPDATE purchase_orders SET expected_on = :d WHERE id = :p', ['d' => site_day(-2), 'p' => $old]);
$d = page($dana, '/purchasing/due');
ok(has($d, 'id="due-overdue-' . $old . '"') && has($d, 'table-danger') && !has($d, 'id="due-row-' . $po . '"'), 'an earlier delivery with nothing received is marked overdue (red); the later order is not listed for today');
$d = page($dana, '/purchasing/due?on=' . site_day(3));
ok(has($d, 'id="due-row-' . $old . '"') && has($d, 'id="due-row-' . $po . '"') && has($d, 'id="due-overdue-' . $old . '"') && !preg_match('#due-overdue-' . $po . '#', $d['body']), 'both rows listed for the later day; only the unreceived one is overdue');
$j = get_json($dana, '/purchasing/due?on=' . site_day(3));
ok(count($j['json']['data']['deliveries'] ?? []) >= 2 && ($j['json']['data']['deliveries'][0]['overdue'] ?? null) === true, 'JSON: the same rows with received and overdue');

echo "4. Close short\n";
$n2 = last_note_id();
$onF = (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]); $onC = (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['chicken']]);
$r = post($marco, '/purchasing/close.php', ['purchase_order' => $po, 'short' => 'yes']);
$p = po_row($po);
ok($r['code'] === 302 && $p['status'] === 'closed_short' && $p['closed_at'] !== null, 'Close short: the PO is closed_short');
$st = array_column(q('SELECT id, status FROM purchase_order_lines WHERE purchase_order_id = :p', ['p' => $po]), 'status', 'id');
ok($st[$lFlour] === 'closed_short' && $st[$lChk] === 'closed_short' && $st[$lEgg] === 'declined', 'the open lines close short; the declined one stays declined');
ok(near($onF - (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]), 22679.6185, 0.01) && near($onC - (float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['chicken']]), 4535.9237, 0.01), 'what this order still owed (a bag of flour, 10 lb of chicken) is no longer on order');
$r = post($marco, '/purchasing/close.php', ['purchase_order' => $po, 'short' => 'yes']);
ok($r['code'] === 422 && says($r, 'is closed_short'), 'closing a closed order → 422 in the function\'s words');
$v = page($marco, "/purchasing/$po");
ok(!has($v, 'id="po-close-short-btn"') && !has($v, 'id="po-cancel-btn"') && !has($v, 'id="po-receive-btn"'), 'a closed order offers no action buttons');
$r = post($dana, '/receipts/save.php', ['area' => $w['dry'], 'supplier' => $w['sysco'], 'purchase_order' => $po]);
ok($r['code'] === 422 && says($r, 'only an open one is received against'), 'a receipt cannot name a closed order');

echo "5. Received, then closed\n";
$po2 = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 2, 'unit_cost' => 30]], ['area' => $w['dry']]);
post($marco, '/purchasing/send.php', ['purchase_order' => $po2]);
$lf2 = (int) po_lines_of($po2)[0]['id'];
receive_against($dana, $po2, $w['dry'], [$lf2 => '2']);
ok(po_row($po2)['status'] === 'received' && q('SELECT status FROM purchase_order_lines WHERE id = :l', ['l' => $lf2])[0]['status'] === 'received', 'everything arrives: the line and the PO are received');
$v = page($marco, "/purchasing/$po2");
ok(has($v, 'id="po-close-btn"') && !has($v, 'id="po-close-short-btn"'), 'a received order offers Close');
$r = post($marco, '/purchasing/close.php', ['purchase_order' => $po2]);
ok($r['code'] === 302 && po_row($po2)['status'] === 'closed', 'Close → closed');
$r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $po2]);
ok($r['code'] === 422 && says($r, 'nothing to cancel'), 'a closed order cannot be cancelled → 422');

echo "6. Cancel\n";
$po3 = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 2, 'unit_cost' => 30]]);
post($marco, '/purchasing/send.php', ['purchase_order' => $po3]);
$lf3 = (int) po_lines_of($po3)[0]['id'];
receive_against($dana, $po3, $w['dry'], [$lf3 => '1']);
$r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $po3]);
ok($r['code'] === 422 && says($r, 'receive it or close it short'), 'cancelling an order with something received → 422 "Receive it or close it short"');
ok(po_row($po3)['status'] === 'partial', '…and nothing changed');
$d = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 1]]);
$m0 = mail_count();
$r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $d, 'reason' => 'changed my mind']);
ok($r['code'] === 302 && $r['location'] === '/purchasing/?notice=cancelled' && po_row($d)['status'] === 'cancelled' && mail_count() === $m0 && po_row($d)['cancel_reason'] === 'changed my mind' && (int) po_row($d)['cancelled_by'] === 27, 'a draft is cancelled: no event, no email, the reason and who kept');
$e1 = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 1]]);
post($marco, '/purchasing/send.php', ['purchase_order' => $e1]);
$m1 = mail_count();
$r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $e1, 'reason' => 'closing early']);
$cm = array_slice(mail_log(), -1)[0] ?? [];
ok($r['code'] === 302 && po_row($e1)['status'] === 'cancelled' && mail_count() === $m1 + 1 && ($cm['to'] ?? '') === 'orders@sysco.test' && str_starts_with($cm['subject'] ?? '', 'Cancelled: purchase order') && str_contains($cm['text'], 'closing early'), 'a sent-by-email order cancelled: the supplier gets the one-line cancellation email');
ok(array_slice(q('SELECT kind, source FROM purchase_order_events WHERE purchase_order_id = :p ORDER BY id', ['p' => $e1]), -1)[0] === ['kind' => 'cancelled', 'source' => 'manual'] && q('SELECT status FROM purchase_order_lines WHERE purchase_order_id = :p', ['p' => $e1])[0]['status'] === 'cancelled', 'a `cancelled` event; the lines are cancelled');
$pl = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 1]]);
post($marco, '/purchasing/place.php', ['purchase_order' => $pl, 'via' => 'phone', 'supplier_order_ref' => 'PH-9']);
$m2 = mail_count();
$r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $pl]);
ok($r['code'] === 302 && po_row($pl)['status'] === 'cancelled' && mail_count() === $m2, 'a placed-by-phone order cancelled: no email (it was never emailed)');
$r = post($dana, '/receipts/save.php', ['area' => $w['dry'], 'supplier' => $w['sysco'], 'purchase_order' => $pl]);
ok($r['code'] === 422 && says($r, 'only an open one'), 'a cancelled order cannot be received against');
ok(post($dana, '/purchasing/cancel.php', ['purchase_order' => $e1])['code'] === 403, 'Dana may not cancel (purchasing.send)');
$fl = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Flaky Foods'");
if ($fl) {
    ri_sql("UPDATE suppliers SET order_email = 'orders@flaky.test' WHERE id = :s", ['s' => $fl]);
    $pf = draft_po($dana, $fl, [['ingredient' => $w['lime'], 'qty' => 1, 'unit' => 'case']]);
    post($marco, '/purchasing/send.php', ['purchase_order' => $pf]);
    ri_sql("UPDATE suppliers SET order_email = 'flaky@flaky.test' WHERE id = :s", ['s' => $fl]);
    $r = post($marco, '/purchasing/cancel.php', ['purchase_order' => $pf]);
    ok($r['code'] === 302 && str_contains($r['location'], 'notice=cancelled_unmailed') && po_row($pf)['status'] === 'cancelled', 'a cancellation MaluMail refuses: the order is cancelled and the notice says the supplier was not told');
}
$lg = q("SELECT * FROM activity_log WHERE action = 'purchase_order.cancel' AND purchase_order_id = :p", ['p' => $e1])[0] ?? [];
ok(str_contains((string) $lg['after'], '"mail": "sent"') && (int) $lg['scope_id'] === 101, 'purchase_order.cancel is logged with the mail result');
foreach (['purchase_order.acknowledge', 'purchase_order.line_decline', 'purchase_order.close', 'purchase_order.cancel'] as $a) { ok(activity($a, 0) !== [], "$a is logged"); }
finish();
