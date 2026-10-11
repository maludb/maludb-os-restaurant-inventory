<?php
/** Proof: the supplier's door /s/{token} — read without cost, the three answers, the signed form token, the link's life (purchasing.md "Proof: The door"). */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$dana = person(30); $marco = person(27);
const SECRET = 'SECRETINTERNAL';

$po = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_flour'], 'qty' => 3, 'unit_cost' => 30], ['supplier_item' => $w['si_egg'], 'qty' => 1], ['supplier_item' => $w['si_chicken'], 'qty' => 10]], ['notes' => 'Back door, please.', 'internal_notes' => SECRET, 'expected_on' => site_day(4)]);
post($marco, '/purchasing/send.php', ['purchase_order' => $po]);
$mail = array_slice(mail_log(), -1)[0] ?? [];
preg_match('#/s/([a-f0-9]{48})#', (string) ($mail['text'] ?? ''), $m);
$tok = $m[1] ?? '';
$lines = po_lines_of($po);
$lFlour = (int) $lines[0]['id']; $lEgg = (int) $lines[1]['id'];
$door = function (string $path = '', array $o = []) use (&$tok): array { return req('GET', "/s/$tok$path", $o); };
$form = static function (string $html): string { preg_match('/name="door_token" value="([a-f0-9]{64})"/', $html, $m); return $m[1] ?? ''; };

echo "1. What the supplier sees\n";
$r = $door();
ok($tok !== '' && $r['code'] === 200 && has($r, 'data-screen="supplier-door"') && has($r, po_row($po)['number']), 'GET /s/<token> with no session: 200, the door\'s screen stamp and the PO number');
ok(has($r, 'SMOKE Downtown') && has($r, '1 Main St') && has($r, format_date(site_day(4))) && has($r, 'Back door, please.'), 'our site\'s name and delivery address, the expected date and our notes to them');
ok(has($r, '3 bag50') && has($r, '1 case') && has($r, '10 lb') && has($r, 'SY-FL50') && has($r, 'SY-CHK') && has($r, 'SMOKE Flour'), 'the lines in the supplier\'s units, with their SKU and our reference');
ok(substr_count($r['body'], '$') === 0 && !has($r, SECRET) && !has($r, 'SMOKE Produce Co') && !has($r, 'unit_cost') && !has($r, '144'), 'no cost, no total, no internal note and no other supplier anywhere on the page');
ok(!preg_match('/^Set-Cookie:/mi', $r['headers']) && preg_match('/^Cache-Control: no-store/mi', $r['headers']) && preg_match('/^X-Robots-Tag: noindex/mi', $r['headers']) && preg_match('/^Referrer-Policy: no-referrer/mi', $r['headers']), 'no cookie is set; the page is not cached, indexed or referred');
ok(has($r, 'id="door-ack-form"') && has($r, 'id="door-date-form"') && has($r, 'id="door-decline-form-' . $lEgg . '"') && has($r, 'id="door-ack-submit"'), 'the three forms: acknowledge, set the date, decline a line');
$views0 = (int) one("SELECT view_count FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $po]);
$door();
ok((int) one("SELECT view_count FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $po]) === $views0 + 1 && one("SELECT last_used_at FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $po]) !== null, 'the view count climbed and last_used_at is set');
ok(activity('purchase_order.supplier_view', 0) !== [] && activity('purchase_order.supplier_view', 0)[0]['source'] === 'portal' && activity('purchase_order.supplier_view', 0)[0]['actor_member_id'] === null, 'a view is logged as the portal, with no actor');
$withCookie = req('GET', "/s/$tok", ['headers' => ['Cookie: BDSID=garbage']]);
ok($withCookie['code'] === 200, 'a stale session cookie changes nothing: the door has no session');
$mj = req('GET', "/s/$tok", ['jar' => $marco]);
ok($mj['code'] === 200 && has($mj, 'data-screen="supplier-door"'), 'a signed-in person opening the link meets the same door, not the shell');

echo "2. A link that is not the link\n";
foreach (['/s/' . str_repeat('0', 48), '/s/' . str_repeat('ab', 24) . '/acknowledge'] as $path) {
    $r = req('GET', $path);
    ok($r['code'] === 404 && has($r, 'This link is not valid or has expired.') && !has($r, 'SMOKE Flour'), "$path → 404 and the plain sentence");
}
foreach (['/s/short', '/s/' . str_repeat('A', 48)] as $path) { ok(req('GET', $path)['code'] === 404, "$path (not a token's shape) → 404"); }

echo "3. Acknowledge\n";
$ft = $form($door()['body']);
$n0 = last_note_id();
$ack = req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => $ft, 'ref' => 'SY-778', 'date' => site_day(5)]]);
$p = po_row($po);
ok($ack['code'] === 303 && $ack['location'] === "/s/$tok?done=acknowledge", 'POST /s/<token>/acknowledge → 303 back to the page: ' . $ack['location']);
ok($p['status'] === 'acknowledged' && $p['expected_on'] === site_day(5) && $p['supplier_order_ref'] === 'SY-778' && $p['acknowledged_at'] !== null, 'the PO is acknowledged, delivery set to the date they gave, their reference kept');
ok(count(array_filter(po_lines_of($po), fn ($l) => $l['status'] === 'acknowledged')) === 3, 'every open line is acknowledged');
$ev = q("SELECT * FROM purchase_order_events WHERE purchase_order_id = :p AND kind = 'acknowledge'", ['p' => $po])[0] ?? [];
ok(($ev['source'] ?? '') === 'door' && $ev['member_id'] === null && $ev['supplier_order_ref'] === 'SY-778', 'the event: source door, no member, their reference');
ok(count(notes_for(30, 'po_ack', $n0)) === 1 && count(notes_for(27, 'po_ack', $n0)) === 1, 'the PO\'s creator (Dana) and the one who sent it (Marco) are both told: po_ack');
$lg = activity('purchase_order.supplier_ack', 0)[0] ?? [];
ok(($lg['source'] ?? '') === 'portal' && $lg['actor_member_id'] === null && (int) $lg['scope_id'] === 101 && (int) $lg['purchase_order_id'] === $po && str_contains((string) $lg['after'], 'SY-778') && str_contains((string) $lg['after'], '"source": "door"'), 'purchase_order.supplier_ack: portal, no actor, the site and the PO, the reference and source=door in after');
$pg = $door('?done=acknowledge');
ok($pg['code'] === 200 && has($pg, 'id="door-notice"') && has($pg, 'acknowledged'), 'the page after says thank you');

echo "4. Decline a line\n";
$ft = $form($door()['body']);
$r = req('POST', "/s/$tok/decline", ['form' => ['door_token' => $ft, 'line' => $lEgg, 'reason' => '']]);
ok($r['code'] === 422 && has($r, 'Say why') && po_lines_of($po)[1]['status'] === 'acknowledged', 'a decline with no reason → 422, the line unchanged');
$bad = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 1]]);
$other = (int) po_lines_of($bad)[0]['id'];
$r = req('POST', "/s/$tok/decline", ['form' => ['door_token' => $ft, 'line' => $other, 'reason' => 'x']]);
ok($r['code'] === 422 && has($r, 'not on this order'), 'a line of another order → 422');
$n1 = last_note_id();
$r = req('POST', "/s/$tok/decline", ['form' => ['door_token' => $ft, 'line' => $lEgg, 'reason' => 'out of stock']]);
$le = q('SELECT * FROM purchase_order_lines WHERE id = :i', ['i' => $lEgg])[0];
ok($r['code'] === 303 && $le['status'] === 'declined' && $le['supplier_note'] === 'out of stock', 'Decline the eggs with "out of stock": 303, the line declined with their note');
$ev = q("SELECT * FROM purchase_order_events WHERE purchase_order_id = :p AND kind = 'decline'", ['p' => $po])[0] ?? [];
ok(($ev['source'] ?? '') === 'door' && (int) $ev['purchase_order_line_id'] === $lEgg && $ev['reason'] === 'out of stock' && count(notes_for(30, 'po_decline', $n1)) === 1 && count(notes_for(27, 'po_decline', $n1)) === 1, 'the decline event names the line and the reason; Dana and Marco get po_decline');
ok(activity('purchase_order.supplier_decline', 0) !== [] && str_contains((string) activity('purchase_order.supplier_decline', 0)[0]['after'], 'out of stock'), 'purchase_order.supplier_decline is logged');
$r = req('POST', "/s/$tok/decline", ['form' => ['door_token' => $ft, 'line' => $lEgg, 'reason' => 'again']]);
ok($r['code'] === 422 && has($r, 'declined already'), 'declining a declined line → 422');
$pg = $door();
ok(has($pg, 'you cannot fill it') && has($pg, 'out of stock') && !has($pg, 'id="door-decline-form-' . $lEgg . '"'), 'the page shows the line declined with their note and no longer offers to decline it');

echo "5. Set the delivery date\n";
$n2 = last_note_id();
$r = req('POST', "/s/$tok/date", ['form' => ['door_token' => $ft, 'date' => site_day(-2)]]);
ok($r['code'] === 422 && has($r, 'not before today') && po_row($po)['expected_on'] === site_day(5), 'a delivery date before today → 422');
$r = req('POST', "/s/$tok/date", ['form' => ['door_token' => $ft, 'date' => '']]);
ok($r['code'] === 422 && has($r, 'Say the delivery date'), 'no date → 422');
$r = req('POST', "/s/$tok/date", ['form' => ['door_token' => $ft, 'date' => site_day(7)]]);
ok($r['code'] === 303 && po_row($po)['expected_on'] === site_day(7) && po_lines_of($po)[0]['expected_on'] === site_day(7), 'Set the date: the PO\'s and its lines\' expected date follow');
ok(count(notes_for(30, 'po_date', $n2)) === 1 && activity('purchase_order.supplier_date', 0) !== [] && array_slice(q("SELECT kind FROM purchase_order_events WHERE purchase_order_id = :p ORDER BY id", ['p' => $po]), -1)[0]['kind'] === 'date', 'po_date to the creator, the date event, purchase_order.supplier_date logged');

echo "6. The form's own token\n";
$r = req('POST', "/s/$tok/acknowledge", ['form' => ['ref' => 'X']]);
ok($r['code'] === 403 && has($r, 'expired') && po_row($po)['supplier_order_ref'] === 'SY-778', 'a POST with no token → 403, nothing changed');
$r = req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => str_repeat('0', 64), 'ref' => 'X']]);
ok($r['code'] === 403, 'a POST with a wrong token → 403');
$otherPoToken = hash_hmac('sha256', 'door:' . ($po + 999) . ':' . intdiv(time(), 3600), need('ACTION_TOKEN_KEY'));
ok(req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => $otherPoToken, 'ref' => 'X']])['code'] === 403, 'a token signed for another order is refused');
$lastHour = hash_hmac('sha256', 'door:' . $po . ':' . (intdiv(time(), 3600) - 1), need('ACTION_TOKEN_KEY'));
ok(req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => $lastHour, 'ref' => 'SY-779']])['code'] === 303, 'the last hour\'s token still posts (a form open across the turn of the hour)');
$old = hash_hmac('sha256', 'door:' . $po . ':' . (intdiv(time(), 3600) - 2), need('ACTION_TOKEN_KEY'));
ok(req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => $old, 'ref' => 'X']])['code'] === 403, 'two hours old is not');
ok(req('POST', "/s/$tok/nonsense", ['form' => ['door_token' => $ft]])['code'] === 404, 'an action that is not one of the three → 404');
ok(strlen((string) one('SELECT supplier_order_ref FROM purchase_orders WHERE id = :p', ['p' => $po])) <= 80, 'a reference is kept up to 80 characters');
$r = req('POST', "/s/$tok/acknowledge", ['form' => ['door_token' => $ft, 'ref' => str_repeat('x', 81)]]);
ok($r['code'] === 422 && has($r, 'up to 80'), 'a reference over 80 characters → 422');

echo "7. The link's life\n";
$r = post($marco, '/purchasing/link.php', ['purchase_order' => $po]);
ok($r['code'] === 302 && str_contains($r['location'], 'tab=link') && $door()['code'] === 404, 'Rotate: the old token is 404 at once');
$v = page($marco, "/purchasing/$po?tab=link");
$new = link_of($v['body']);
ok(strlen($new) === 48 && $new !== $tok && req('GET', "/s/$new")['code'] === 200 && has($v, 'id="po-link-state"') && has($v, 'id="po-link-views"'), 'the new token is shown once on the Link tab and works; the tab shows the link\'s state');
$lr = activity('purchase_order.link_rotate', 0)[0] ?? [];
ok(str_contains((string) $lr['after'], substr($new, -4)) && !str_contains((string) $lr['after'], $new) && (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p', ['p' => $po]) === 2 && (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NOT NULL', ['p' => $po]) === 1, 'the log carries the last four only; one row is rotated out, one live');
$tok = $new;
ri_sql("UPDATE supplier_links_secure SET expires_at = now() - interval '1 hour' WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $po]);
ok($door()['code'] === 404, 'a link past its expiry is 404');
ri_sql("UPDATE supplier_links_secure SET expires_at = now() + interval '30 days' WHERE purchase_order_id = :p AND rotated_at IS NULL", ['p' => $po]);
ok($door()['code'] === 200, '…and alive again when the expiry is in the future (control)');

echo "8. A cancelled order's door is read-only\n";
$pc = draft_po($dana, $w['sysco'], [['supplier_item' => $w['si_egg'], 'qty' => 2]]);
post($marco, '/purchasing/send.php', ['purchase_order' => $pc]);
$ctok = link_of(page($marco, "/purchasing/$pc")['body']);
$cform = $form(req('GET', "/s/$ctok")['body']);
post($marco, '/purchasing/cancel.php', ['purchase_order' => $pc, 'reason' => 'closing early']);
$r = req('GET', "/s/$ctok");
ok($r['code'] === 200 && has($r, 'was cancelled') && !has($r, 'id="door-ack-form"') && !has($r, 'id="door-date-form"') && !has($r, 'id="door-decline-form-'), 'the cancelled order\'s door says so and offers no form');
$r = req('POST', "/s/$ctok/acknowledge", ['form' => ['door_token' => $cform, 'ref' => 'late']]);
ok($r['code'] === 409 && has($r, 'was cancelled') && po_row($pc)['status'] === 'cancelled', 'a POST to it → 409 with the sentence, nothing changed');
finish();
