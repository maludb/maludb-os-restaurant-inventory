<?php
/** Slice 6 — who sees what (counts.md "Proof": Who sees): a Viewer reads and never fills, another site's counts are 404, the blind rule for the counter, the cost wall. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $ann = person(29); $marcoA = person(27, AIRPORT); $leeA = person(31, AIRPORT); $priyaA = person(26, AIRPORT);
$cid = (int) one("SELECT id FROM inventory_counts WHERE scope_id = :s AND status = 'approved' AND kind = 'full' ORDER BY id DESC LIMIT 1", ['s' => DOWNTOWN]);
$sid = (int) one("SELECT id FROM count_sheets WHERE name = 'Full house'");

echo "1. A Viewer reads and does not fill\n";
ok(page($ann, '/counts/')['code'] === 200 && page($ann, "/counts/$cid/history")['code'] === 200 && page($ann, "/counts/$cid/review")['code'] === 200 && page($ann, '/counts/sheets/')['code'] === 200 && page($ann, "/counts/sheets/$sid")['code'] === 200 && page($ann, '/counts/containers/')['code'] === 200, 'Ann reads the list, a review, a history, the sheets and the bottles');
$r = page($ann, "/counts/$cid");
ok($r['code'] === 403 && says($r, 'does not let you'), 'Ann cannot open the count sheet to fill: 403 in words');
ok(page($ann, '/counts/new')['code'] === 403, '…nor start one');
$h = page($ann, "/counts/$cid/review");
ok(!has($h, 'id="count-approve') && !has($h, 'id="count-discard"') && !has($h, 'id="count-review-btn"') && substr_count($h['body'], 'cost withheld') >= 5, 'her review of an approved count has no buttons and no cost');
$h = page(person(30), "/counts/sheets/$sid");
ok(has($h, 'id="sheet-fill"') && !has(page($ann, "/counts/sheets/$sid"), 'id="sheet-fill"') && !has(page($ann, "/counts/sheets/$sid"), 'id="sheet-add-form-' . $w['bar'] . '"'), 'the sheet editor\'s controls are the Chef\'s; Ann sees the sheet without them');

echo "2. Another site's counts do not exist here\n";
ok(page($marcoA, "/counts/$cid")['code'] === 404 && page($marcoA, "/counts/$cid/review")['code'] === 404 && page($marcoA, "/counts/$cid/history")['code'] === 404, 'Marco signed on at Airport: a Downtown count, its review and its history are 404');
$l = page($marcoA, '/counts/');
ok($l['code'] === 200 && !has($l, 'id="count-row-' . $cid . '"'), '…and the Airport list does not list it');
$ln = (int) one('SELECT id FROM inventory_count_lines WHERE count_id = :c LIMIT 1', ['c' => $cid]);
ok(post($marcoA, '/counts/line.php', ['count_line' => $ln, 'state' => 'zero'])['code'] === 404 && post($marcoA, '/counts/approve.php', ['count' => $cid])['code'] === 403, 'a Downtown line from Airport: 404; an approval there needs the right AT Airport, where Marco is Kitchen: 403');
$r = post($marcoA, '/counts/start.php', ['sheet' => $sid]);
ok($r['code'] === 422 && says($r, 'not at this site'), 'a Downtown sheet at Airport: "not at this site"');
ok(page($leeA, "/counts/$cid/history")['code'] === 404 && page($leeA, '/counts/')['code'] === 200, 'Lee (a Viewer who holds only Airport) cannot reach it either');
$r = post($priyaA, '/counts/start.php', ['kind' => 'full']);
$air = (int) one("SELECT max(id) FROM inventory_counts WHERE scope_id = :s", ['s' => AIRPORT]);
ok($r['code'] === 302 && $air > 0 && (int) one('SELECT count(*) FROM inventory_count_lines WHERE count_id = :c', ['c' => $air]) >= 0, 'Priya (Kitchen at Airport) starts a count there: a full count is one per site, not one per business');
ok(says(page($priyaA, "/counts/$air"), 'Nothing to count in this area') || says(page($priyaA, "/counts/$air"), 'no lines yet') || has(page($priyaA, "/counts/$air"), 'id="count-lines"'), 'a count with nothing on the book opens (lines are added as they are found)');
ri_sql("UPDATE inventory_counts SET status = 'discarded' WHERE id = :i", ['i' => $air]);

echo "3. The blind rule, again, on a fresh count\n";
reset_counts();
[$b, ] = start_count_as($bea, ['kind' => 'spot']);
$fl = line_of($b, $w['flour']);
$jb = get_json($bea, "/counts/$b?area=" . $w['dry']); $jd = get_json($dana, "/counts/$b?area=" . $w['dry']);
$lb = array_column($jb['json']['data']['lines'] ?? [], null, 'count_line_id')[$fl] ?? []; $ld = array_column($jd['json']['data']['lines'] ?? [], null, 'count_line_id')[$fl] ?? [];
ok(($lb['book_hidden'] ?? false) === true && !isset($lb['system_qty_base']) && isset($ld['system_qty_base']) && !isset($ld['book_hidden']), 'the Chef sees the book figure on an open blind count; the counter does not');
post($bea, '/counts/line.php', ['count_line' => $fl, 'whole' => '1', 'whole_unit' => 'bag50']);
$r = get_json($bea, "/counts/$b/review");
ok(($r['json']['data']['lines'][0]['book_hidden'] ?? false) === true || count(array_filter($r['json']['data']['lines'], fn ($x) => isset($x['book_hidden']))) > 0, 'and while it is open the counter\'s review is blind too');
ri_sql('UPDATE site_settings SET kitchen_sees_cost = true WHERE scope_id = :s', ['s' => DOWNTOWN]);
$hc = page($bea, "/counts/$cid/history");
ri_sql('UPDATE site_settings SET kitchen_sees_cost = false WHERE scope_id = :s', ['s' => DOWNTOWN]);
ok(has($hc, '$12.30') && substr_count(page($bea, "/counts/$cid/history")['body'], 'cost withheld') >= 5, 'cost is the wall: Bea sees the values only while the site lets the Kitchen see cost');
$tol = page($bea, "/counts/$cid/review");
ok(has($tol, '±5 %') && !str_contains(strip_tags($tol['body']), ' or $10'), 'a Kitchen member is shown the tolerance in per cent, not the dollar figure');
reset_counts();

echo "4. Agents\n";
agent_facts(41, 7411);
ok(in_array(agent_req('GET', '/counts/', 41, 7411)['code'], [401, 403, 404], true), 'the Watcher (an agent no one admitted): refused');
agent_facts(40, 7401);
$r = agent_req('GET', "/counts/$cid/history", 40, 7401);
ok($r['code'] === 200 && ($r['json']['data']['count']['status'] ?? '') === 'approved' && isset($r['json']['data']['corrections'][0]['value']), 'the expert (a Chef) reads a history, cost included');
finish();
