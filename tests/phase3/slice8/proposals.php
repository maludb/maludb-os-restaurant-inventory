<?php
/** Slice 8 — the Purchasing agent's proposals and the morning note (projection.md "Proof": Proposals and the note): the agent proposes through its run token, a person accepts or dismisses, the acceptance rate, the seven headings, one send a day, the Markdown, a recipient without cost. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$a0 = last_activity_id();
$marco = person(27); $dana = person(30); $bea = person(28);
$site = 101;
$date = (string) one("SELECT (now() AT TIME ZONE timezone)::date FROM sites WHERE scope_id = 101");

echo "1. The agent proposes\n";
$r = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'yes']);
$po = (int) $r['json']['record_id'];
$r = apost(42, 9301, '/projection/proposals/save.php', ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => $w['sysco'], 'title' => 'Drafted a PO for Sysco: 5 lines', 'detail' => json_encode(['summary' => 'A draft for the next cut-off.', 'lines' => ['Flour — 4 bag50', 'Eggs — 1 case'], 'total' => '878.00']), 'drafted_record_type' => 'purchase_order', 'drafted_record_id' => $po]);
$p1 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $p1 > 0 && str_contains($r['json']['location'] ?? '', '/projection/proposals?date=' . $date), 'buyer_propose (the agent\'s run token): an order proposal with its drafted PO');
$row = q('SELECT * FROM buyer_proposals WHERE id = :i', ['i' => $p1])[0];
ok($row['status'] === 'proposed' && (int) $row['proposed_by'] === 42 && $row['note_date'] === $date && (int) $row['scope_id'] === 101 && $row['drafted_record_type'] === 'purchase_order' && (int) $row['drafted_record_id'] === $po && json_decode($row['detail'], true)['request_id'] === 'req-run-9301', 'status proposed, by the agent, the site\'s date, the drafted record, the kernel\'s request id kept in the detail');
$r = apost(42, 9302, '/projection/proposals/save.php', ['kind' => 'price', 'subject_type' => 'ingredient', 'subject_id' => $w['flour'], 'title' => 'Flour is up 8 % on the last invoice', 'detail' => json_encode(['summary' => 'Sysco raised the price of the 50 lb bag.', 'value' => '+8 %'])]);
$p2 = (int) $r['json']['record_id'];
ok($r['code'] === 200 && $p2 > $p1, 'a price finding with no drafted record');
$lg = activity('buyer.propose', $a0);
ok(count($lg) === 2 && $lg[0]['source'] === 'agent' && (int) $lg[0]['actor_member_id'] === 42 && (int) $lg[0]['purchase_order_id'] === $po && (int) $lg[0]['scope_id'] === 101, 'buyer.propose is logged as the agent\'s, with the site and the PO');
foreach ([['kind' => 'nonsense', 'subject_type' => 'x', 'subject_id' => 1, 'title' => 't'], ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => 1], ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => 1, 'title' => 't', 'detail' => 'not json'], ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => 1, 'title' => 't', 'drafted_record_type' => 'purchase_order', 'drafted_record_id' => 999999]] as $i => $bad) {
    $r = apost(42, 9310 + $i, '/projection/proposals/save.php', $bad);
    ok($r['code'] === 422, 'a bad proposal is refused in words (' . ['an unknown kind', 'no title', 'detail that is not JSON', 'a drafted record that is not here'][$i] . ')');
}
ok(row_count('buyer_proposals') === 2, 'no refusal left a row');

echo "2. A person decides\n";
$h = page($marco, '/projection/proposals?date=' . $date);
ok($h['code'] === 200 && has($h, 'data-screen="proposal-list"') && has($h, 'id="proposal-card-' . $p1 . '"') && has($h, 'id="proposal-accept-' . $p1 . '"') && has($h, 'id="proposal-dismiss-' . $p2 . '"') && has($h, 'id="note-send-btn"') && has($h, 'id="proposal-group-orders"') && has($h, 'id="proposal-group-prices"'), 'the day: a card per proposal under its heading, Accept and Dismiss, Send the morning note');
ok(says($h, 'Flour — 4 bag50') && says($h, 'A draft for the next cut-off.') && has($h, 'id="proposal-drafted-' . $p1 . '"'), 'the card shows the agent\'s detail and links the drafted PO');
$r = jpost(27, '/projection/proposals/decide.php', ['proposal' => $p1, 'decision' => 'accept']);
ok($r['code'] === 200 && $r['json']['location'] === '/purchasing/' . $po . '?notice=accepted' && one('SELECT status FROM buyer_proposals WHERE id = :i', ['i' => $p1]) === 'accepted' && (int) one('SELECT decided_by FROM buyer_proposals WHERE id = :i', ['i' => $p1]) === 27, 'Marco accepts the order: status accepted, decided by Marco, and he lands on the PO');
ok(po_row($po)['status'] === 'draft', 'accepting does not send it — the PO is still a draft for a Manager to send');
$r = jpost(27, '/projection/proposals/decide.php', ['proposal' => $p2, 'decision' => 'dismiss']);
ok($r['code'] === 200 && one('SELECT status FROM buyer_proposals WHERE id = :i', ['i' => $p2]) === 'dismissed', 'he dismisses the price finding');
$r = jpost(27, '/projection/proposals/decide.php', ['proposal' => $p2, 'decision' => 'accept']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'already decided'), 'a decided proposal cannot be decided again');
$h = page($marco, '/projection/proposals?date=' . $date);
ok(has($h, 'id="acceptance-rate"') && str_contains(says_text($h), 'Accepted 1 of 2 decided in 30 days (50 %)') && !has($h, 'id="proposal-accept-' . $p1 . '"'), 'the acceptance rate for the last 30 days: 1 of 2 (50 %); decided cards lose their buttons');
$ar = acceptance_probe();
ok($ar['rate'] == 50 && $ar['decided'] === 2, 'acceptance_rate() agrees: ' . json_encode($ar));
$lg = activity('buyer.decide', $a0);
ok(count($lg) === 2 && json_decode($lg[0]['after'], true)['decision'] === 'accept' && (int) $lg[0]['actor_member_id'] === 27, 'buyer.decide is logged with the decision');
$k = jget(27, '/projection/proposals?date=' . $date . '&kind=price')['json']['data'] ?? [];
ok(count($k['proposals'] ?? []) === 1 && $k['proposals'][0]['kind'] === 'price', 'the kind filter keeps the price finding');
$k = jget(27, '/projection/proposals?date=' . $date . '&status=accepted')['json']['data'] ?? [];
ok(count($k['proposals'] ?? []) === 1 && $k['proposals'][0]['status'] === 'accepted', 'the status filter keeps the accepted one');
$e = jget(27, '/projection/proposals?date=' . day(-3))['json']['data'] ?? [];
ok(($e['proposals'] ?? 1) === [], 'another day has none');

echo "3. An accepted forecast proposal sets its override\n";
$r = apost(42, 9320, '/projection/override-propose.php', ['menu_item' => $w['mi_sand'], 'from_date' => day(6), 'to_date' => day(6), 'factor' => '1.5', 'reason' => 'SMOKE the fair']);
$ov = (int) $r['json']['record_id'];
$r = apost(42, 9321, '/projection/proposals/save.php', ['kind' => 'forecast', 'subject_type' => 'menu_item', 'subject_id' => $w['mi_sand'], 'title' => 'A fair on the weekend: +50 % on the sandwich', 'drafted_record_type' => 'forecast_override', 'drafted_record_id' => $ov]);
$p3 = (int) $r['json']['record_id'];
ok(one('SELECT status FROM forecast_overrides WHERE id = :i', ['i' => $ov]) === 'proposed', 'the override is proposed');
$r = jpost(27, '/projection/proposals/decide.php', ['proposal' => $p3, 'decision' => 'accept']);
ok($r['code'] === 200 && one('SELECT status FROM forecast_overrides WHERE id = :i', ['i' => $ov]) === 'set' && (int) one('SELECT set_by FROM forecast_overrides WHERE id = :i', ['i' => $ov]) === 27, 'accepting the forecast proposal sets the override (by Marco)');
$r = apost(42, 9322, '/projection/override-propose.php', ['menu_item' => $w['mi_sand'], 'from_date' => day(7), 'to_date' => day(7), 'factor' => '0.5', 'reason' => 'SMOKE snow']);
$ov2 = (int) $r['json']['record_id'];
$r = apost(42, 9323, '/projection/proposals/save.php', ['kind' => 'forecast', 'subject_type' => 'menu_item', 'subject_id' => $w['mi_sand'], 'title' => 'Snow on Sunday', 'drafted_record_type' => 'forecast_override', 'drafted_record_id' => $ov2]);
$r = jpost(30, '/projection/proposals/decide.php', ['proposal' => (int) $r['json']['record_id'], 'decision' => 'dismiss']);
ok($r['code'] === 200 && one('SELECT status FROM forecast_overrides WHERE id = :i', ['i' => $ov2]) === 'dismissed', 'dismissing it dismisses the override (Dana, purchasing.draft, may decide too)');

echo "4. The morning note\n";
reset_projection_proposals_only();
$a1 = last_activity_id();
$pn = apost(42, 9330, '/projection/proposals/save.php', ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => $w['sysco'], 'title' => 'Drafted ' . po_row($po)['number'] . ' for Sysco: 5 lines', 'detail' => json_encode(['total' => '878.00']), 'drafted_record_type' => 'purchase_order', 'drafted_record_id' => $po]);
$n0 = nid();
$r = jpost(27, '/projection/proposals/note.php', ['date' => $date]);
$ns = notes(27, 'morning_note', $n0);
ok($r['code'] === 200 && ($r['json']['sent'] ?? false) === true && ($r['json']['recipients'] ?? 0) === 1 && count($ns) === 1, 'morning_note_send: one notification to the site\'s Manager (Marco)');
$md = $ns[0]['body'];
$pos = []; foreach (['1. Suggested orders', '2. Stock-outs projected', '3. Prices', '4. The POS', '5. The last count', '6. The forecast', '7. Purchase orders and deliveries'] as $hd) { $pos[] = strpos($md, '## ' . $hd); }
ok(!in_array(false, $pos, true) && $pos === array_values(array_filter($pos, 'is_int')) && $pos == (function ($p) { $q = $p; sort($q); return $q; })($pos), 'the Markdown has the seven headings, in the design\'s order');
ok(mb_strlen($md) <= 4000 && str_starts_with($md, '# Morning note — SMOKE Downtown'), 'at most 4 000 characters, headed with the site: ' . mb_strlen($md));
$so = q('SELECT * FROM ri_stockouts_projected(101, 7)');
preg_match('/## 2\. Stock-outs projected \((\d+)\)/', $md, $m2);
ok((int) ($m2[1] ?? -1) === count($so) && count($so) >= 2, 'heading 2\'s count matches the gatherer (' . count($so) . ' stock-outs in a week)');
preg_match('/## 1\. Suggested orders \((\d+)\)/', $md, $m1);
ok((int) ($m1[1] ?? -1) === 1 && str_contains($md, 'Drafted ' . po_row($po)['number']) && str_contains($md, '/purchasing/' . $po), 'heading 1 reads the agent\'s proposal (one) and links the drafted PO');
preg_match('/## 7\. Purchase orders and deliveries \((\d+)\)/', $md, $m7);
ok((int) ($m7[1] ?? -1) === (int) one("SELECT count(*) FROM ri_purchase_orders_open(101) o WHERE o.status = 'sent' AND o.days_unacknowledged > 2") + (int) one('SELECT count(*) FROM ri_deliveries_due(101, current_date) WHERE NOT received'), 'heading 7\'s count matches the gatherers');
ok(str_contains($md, '$878.00') && (int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 27 AND kind = 'morning_note' AND channel = 'email'") >= 1, 'the Manager sees the value ($878.00) and the mail copy is queued in the outbox');
$lg = activity('buyer.note', $a1); $la = json_decode($lg[0]['after'], true);
ok(count($lg) === 1 && $la['date'] === $date && $la['sent'] === true && $la['recipients'] === 1 && $la['headings']['orders'] === 1 && (int) $lg[0]['scope_id'] === 101, 'buyer.note is logged with the date, the counts per heading and the recipients');
$r = jpost(27, '/projection/proposals/note.php', ['date' => $date]);
ok($r['code'] === 200 && str_contains($r['json']['did'], 'already sent at') && count(notes(27, 'morning_note', $n0)) === 1, 'a second send the same day: a 200 that names the earlier one, and no second notification');
ok(str_contains(says_text(page($marco, '/projection/proposals?date=' . $date)), 'Morning note sent'), 'the screen says when the note went');

echo "5. A recipient who sees no cost\n";
ri_sql('UPDATE site_settings SET manager_member_id = 28 WHERE scope_id = 101');
$n1 = nid();
$tomorrow = day(1);
$r = jpost(27, '/projection/proposals/note.php', ['date' => $tomorrow]);
$nb = notes(28, 'morning_note', $n1);
ok(count($nb) === 1 && !str_contains($nb[0]['body'], '$') && str_contains($nb[0]['body'], '—'), 'Bea (Kitchen) is sent the note with "—" where a value was: no dollar sign in it');
ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
$n2 = nid();
$r = jpost(27, '/projection/proposals/note.php', ['date' => day(2)]);
ok(count(notes(27, 'morning_note', $n2)) === 1 && str_contains(notes(27, 'morning_note', $n2)[0]['body'], '$'), '(and the Manager gets the values on another day)');
ri_sql('UPDATE site_settings SET manager_member_id = NULL WHERE scope_id = 101');
ri_sql('UPDATE ri_settings SET manager_member_id = NULL WHERE id = 1');
$n3 = nid();
$r = jpost(27, '/projection/proposals/note.php', ['date' => day(3)]);
$sentTo = array_map('intval', array_column(q("SELECT member_id FROM notifications WHERE kind = 'morning_note' AND id > :n", ['n' => $n3]), 'member_id'));
ok($r['code'] === 200 && in_array(27, $sentTo, true) && !in_array(28, $sentTo, true), 'with no Manager named it goes to those who may send purchase orders (Marco, holding purchasing.send)');
ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');

echo "6. The rights\n";
ok(jpost(28, '/projection/proposals/note.php', [])['code'] === 403 && jpost(28, '/projection/proposals/decide.php', ['proposal' => $p1, 'decision' => 'accept'])['code'] === 403 && page($bea, '/projection/proposals')['code'] === 403 && page(person(29), '/projection/proposals')['code'] === 403, 'Bea and Ann may not open, decide or send');
ok(page($dana, '/projection/proposals?date=' . $date)['code'] === 200, 'Dana (Chef) opens the day');
finish();
