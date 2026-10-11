<?php
/** Proof: send and place — the email with the supplier's link, mark placed, the refusals, the supplier message (purchasing.md "Proof: Send and place"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27); $bea = person(28);
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];

echo "1. Marco sends a draft by email\n";
$po = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 3, 'unit_cost' => 30], ['supplier_item' => $w['si_egg'], 'qty' => 1]], ['notes' => 'Back door, please.', 'internal_notes' => 'SECRET target price']);
$m0 = mail_count(); $ev0 = (int) one('SELECT count(*) FROM purchase_order_events WHERE purchase_order_id = :p', ['p' => $po]);
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po, 'message' => 'Thanks, Sam.']);
$p = po_row($po);
ok($r['code'] === 302 && $r['location'] === "/purchasing/$po?notice=sent", 'Send lands on the PO with the notice: ' . $r['location']);
ok($p['status'] === 'sent' && $p['sent_via'] === 'email' && (int) $p['sent_by'] === 27 && $p['sent_at'] !== null, 'the PO is sent, by email, by Marco');
$ev = q('SELECT * FROM purchase_order_events WHERE purchase_order_id = :p ORDER BY id', ['p' => $po]);
ok(count($ev) === $ev0 + 1 && end($ev)['kind'] === 'sent' && end($ev)['source'] === 'manual' && (int) end($ev)['member_id'] === 27, 'the `sent` event is written (source manual, by Marco)');
$links = q('SELECT * FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $po]);
ok(count($links) === 1 && strtotime($links[0]['expires_at']) - time() > 59 * 86400 && strtotime($links[0]['expires_at']) - time() < 61 * 86400, 'one live link, which dies in 60 days');
$mail = mail_log();
$sent = $mail[count($mail) - 1] ?? [];
preg_match('#/s/([a-f0-9]{48})#', (string) ($sent['text'] ?? ''), $lm);
$tok = $lm[1] ?? '';
ok(count($mail) === $m0 + 1 && ($sent['to'] ?? '') === 'orders@sysco.test' && str_contains($sent['subject'] ?? '', $p['number']), 'one email to the supplier\'s order address with the PO number in the subject');
ok(str_contains($sent['text'], '3 bag50') && str_contains($sent['text'], '1 case') && str_contains($sent['text'], 'SY-FL50') && str_contains($sent['text'], 'SY-EGG15') && str_contains($sent['text'], 'Back door, please.') && str_contains($sent['text'], 'Thanks, Sam.'), 'the three-line order in the supplier\'s units with their SKUs, our note and the sender\'s message');
ok(strlen($tok) === 48 && str_contains($sent['html'], "/s/$tok") && !str_contains($sent['text'] . $sent['html'], '$') && !str_contains($sent['text'] . $sent['html'], 'SECRET') && !str_contains($sent['text'] . $sent['html'], '90.00'), 'the link is in both renderings; no cost and no internal note anywhere in the email');
ok($tok !== '' && (int) one('SELECT count(*) FROM supplier_links_secure WHERE token_hash = encode(sha256(:t::bytea), \'hex\')', ['t' => $tok]) === 1, 'only the token\'s hash is kept');
$v = page($marco, "/purchasing/$po");
ok(link_of($v['body']) === $tok && has($v, 'id="po-link-once"'), 'the sender sees the link once on the PO page, the same as the email carries');
ok(link_of(page($marco, "/purchasing/$po")['body']) === '', 'a second look does not show it again');
ok(near((float) one('SELECT ri_on_order(:i, 101)', ['i' => $w['flour']]), 68038.8555, 0.01), 'ri_on_order() now counts the 3 bags (what is still to come)');
$log = q("SELECT * FROM activity_log WHERE action = 'purchase_order.send' AND purchase_order_id = :p", ['p' => $po])[0] ?? [];
$after = json_decode((string) ($log['after'] ?? 'null'), true) ?: [];
ok(($after['via'] ?? '') === 'email' && ($after['mail'] ?? '') === 'sent' && ($after['link_last4'] ?? '') === substr($tok, -4) && (int) ($after['lines'] ?? 0) === 2 && near($after['total'] ?? 0, 144), 'purchase_order.send logs via, mail sent, the link\'s last four, the line count and the total (the sender sees cost)');
ok((int) one('SELECT count(*) FROM activity_log WHERE after::text LIKE :t OR before::text LIKE :t2', ['t' => "%$tok%", 't2' => "%$tok%"]) === 0, 'the raw token is in no activity row');

echo "2. Sent is sent\n";
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po]);
ok($r['code'] === 422 && says($r, 'only a draft is sent'), 'sending again → 422 "only a draft is sent"');
$r = post($dana, '/purchasing/save.php', ['purchase_order' => $po, 'notes' => 'late']);
ok($r['code'] === 422 && says($r, 'only a draft'), 'the header of a sent PO does not change');
$r = post($dana, '/purchasing/line.php', ['purchase_order' => $po, 'supplier_item' => $w['si_chicken'], 'qty' => 2]);
ok($r['code'] === 422 && says($r, 'only a draft'), 'a line cannot be added to a sent PO');
$r = post($dana, '/purchasing/line-remove.php', ['line' => po_lines_of($po)[0]['id']]);
ok($r['code'] === 422, 'nor removed');
ok(page($dana, "/purchasing/$po/edit")['code'] === 422, 'and its edit page is refused');

echo "3. Marked placed — no email\n";
$po2 = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_chicken'], 'qty' => 10]]);
$m1 = mail_count();
$r = post($marco, '/purchasing/place.php', ['purchase_order' => $po2]);
ok($r['code'] === 422 && says($r, "supplier's reference is required") && po_row($po2)['status'] === 'draft', 'placing without the supplier\'s reference → 422');
$r = post($marco, '/purchasing/place.php', ['purchase_order' => $po2, 'via' => 'phone', 'supplier_order_ref' => 'SY-778']);
$p2 = po_row($po2);
ok($r['code'] === 302 && $p2['status'] === 'sent' && $p2['sent_via'] === 'phone' && $p2['supplier_order_ref'] === 'SY-778', 'placed by phone with SY-778: sent, via phone, their reference kept');
ok(array_slice(q('SELECT kind FROM purchase_order_events WHERE purchase_order_id = :p ORDER BY id', ['p' => $po2]), -1)[0]['kind'] === 'placed' && mail_count() === $m1, 'the `placed` event; no email went');
ok((int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $po2]) === 1 && has(page($marco, "/purchasing/$po2"), 'id="po-link-once-url"'), 'the link is minted anyway and shown once for sending by hand');
$r = post($marco, '/purchasing/place.php', ['purchase_order' => $po2, 'supplier_order_ref' => 'X']);
ok($r['code'] === 422, 'placing a placed order → 422');

echo "4. Who may send\n";
$po3 = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 2]]);
foreach (['send.php' => ['purchase_order' => $po3], 'place.php' => ['purchase_order' => $po3, 'via' => 'phone', 'supplier_order_ref' => 'Q1'], 'cancel.php' => ['purchase_order' => $po3], 'close.php' => ['purchase_order' => $po2], 'link.php' => ['purchase_order' => $po2]] as $f => $form) {
    $r = post($dana, "/purchasing/$f", $form);
    ok($r['code'] === 403 && says($r, 'does not let you'), "Dana (purchasing.draft) on $f → 403 in words");
}
ok(post($bea, '/purchasing/send.php', ['purchase_order' => $po3])['code'] === 403 && po_row($po3)['status'] === 'draft', 'Bea on send → 403 and nothing happened');
$exp = 40;
agent_facts($exp, 7401);
$r = agent_req('POST', '/purchasing/send.php', $exp, 7401, ['purchase_order' => $po3]);
ok($r['code'] === 403 && po_row($po3)['status'] === 'draft', "the expert (a Chef) is refused by the handler's own right check too (the kernel's hook comes first in production)");
foreach (['purchase_order_send' => ['money_out', 'purchase_order.send'], 'purchase_order_place' => ['money_out', 'purchase_order.place'], 'supplier_message' => ['external_send', 'supplier.message'], 'purchase_order_cancel' => ['deletion', 'purchase_order.cancel'],
         'invoice_match_settle' => ['other', 'invoice.settle'], 'price_alert_threshold_set' => ['other', 'price_alert.threshold_set'], 'purchase_order_draft' => [null, 'purchase_order.draft']] as $a => [$cat, $ev]) {
    ok(array_key_exists('approval', $reg[$a] ?? []) && $reg[$a]['approval'] === $cat && ($reg[$a]['log_event'] ?? '') === $ev && !empty($reg[$a]['built']), "the registry: $a " . ($cat ? "pauses as $cat" : 'is free') . " on the log event $ev, and is built");
}

echo "5. A supplier that cannot be emailed\n";
$po4 = draft_po($dana, $w['produce'], [['supplier_item' => $w['si_lime'], 'qty' => 1]]);
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po4]);
ok($r['code'] === 422 && says($r, 'takes orders by phone') && po_row($po4)['status'] === 'draft', 'a supplier that takes phone orders: Send → 422 "mark it placed instead"');
ri_sql("UPDATE suppliers SET order_method = 'email' WHERE id = :s", ['s' => $w['produce']]);
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po4]);
ok($r['code'] === 422 && says($r, 'has no order email — set one on the supplier or mark it placed') && po_row($po4)['status'] === 'draft', 'a supplier with no order email → 422 in words, the PO stays a draft');
ri_sql("INSERT INTO suppliers (name, kind, order_method, order_email) VALUES ('SMOKE Flaky Foods', 'wholesaler', 'email', 'orders@flaky.test')");
$fl = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Flaky Foods'");
ri_sql("INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, last_price) VALUES (:s, :i, 'FL-LIME', 'case', 40)", ['s' => $fl, 'i' => $w['lime']]);
$po5 = draft_po($dana, $fl, [['ingredient' => $w['lime'], 'qty' => 1, 'unit' => 'case']]);
$m2 = mail_count();
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po5]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=sent') && po_row($po5)['status'] === 'sent' && mail_count() === $m2 + 1, 'the mail goes to the fake MaluMail and is accepted (control: the order is sent)');
ri_sql("UPDATE suppliers SET order_email = 'flaky@flaky.test' WHERE id = :s", ['s' => $fl]);
$po6 = draft_po($dana, $fl, [['ingredient' => $w['lime'], 'qty' => 2, 'unit' => 'case']]);
$r = post($marco, '/purchasing/send.php', ['purchase_order' => $po6]);
$lg = json_decode((string) (q("SELECT after FROM activity_log WHERE action = 'purchase_order.send' AND purchase_order_id = :p", ['p' => $po6])[0]['after'] ?? 'null'), true) ?: [];
ok($r['code'] === 302 && str_contains($r['location'], 'notice=sent_unmailed') && po_row($po6)['status'] === 'sent' && ($lg['mail'] ?? '') === 'failed', 'a send MaluMail refuses: the PO stays sent, the log says mail failed, the notice says so');
$v = page($marco, '/purchasing/' . $po6 . '?notice=sent_unmailed');
ok(has($v, 'the email did not go') && strlen(link_of($v['body'])) === 48, 'the page tells the sender and offers the link to copy once');

echo "6. A message to the supplier\n";
$m3 = mail_count();
$r = post($marco, '/purchasing/message.php', ['supplier' => $w['sysco'], 'purchase_order' => $po, 'body' => "Can you deliver before 8?\nSECRETBODY"]);
$mm = array_slice(mail_log(), -1)[0] ?? [];
ok($r['code'] === 302 && mail_count() === $m3 + 1 && ($mm['to'] ?? '') === 'orders@sysco.test' && str_contains($mm['subject'], po_row($po)['number']) && str_contains($mm['text'], 'SECRETBODY') && str_contains($mm['text'], po_row($po)['number']), 'the message goes to the order email, about the PO (its number in the subject and the text)');
$lm2 = q("SELECT * FROM activity_log WHERE action = 'supplier.message' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok(!str_contains((string) $lm2['after'], 'SECRETBODY') && str_contains((string) $lm2['after'], 'subject') && (int) $lm2['scope_id'] === 101 && (int) $lm2['purchase_order_id'] === $po, 'supplier.message logs the subject, never the body');
$r = post($marco, '/purchasing/message.php', ['supplier' => $w['sysco'], 'body' => '']);
ok($r['code'] === 422, 'an empty message → 422');
$r = post($marco, '/purchasing/message.php', ['supplier' => $w['produce'], 'body' => 'hello']);
ok($r['code'] === 422 && says($r, 'has no email'), 'a supplier with no email → 422 in words');
ok(post($dana, '/purchasing/message.php', ['supplier' => $w['sysco'], 'body' => 'hi'])['code'] === 403, 'Dana may not message a supplier');
$r = post($marco, '/purchasing/message.php', ['supplier' => $w['sysco'], 'purchase_order' => $po4, 'body' => 'hi']);
ok($r['code'] === 422 && says($r, 'is to'), 'a PO of another supplier named on the message → 422');
finish();
