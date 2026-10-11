<?php
/** Slice 6 — history, the list and discarding (counts.md "Proof": History and discard). Reads the approved count approve.php left. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $ann = person(29);
$cid = (int) one("SELECT id FROM inventory_counts WHERE scope_id = :s AND status = 'approved' AND kind = 'full' ORDER BY id DESC LIMIT 1", ['s' => DOWNTOWN]);
$cnt = ccount($cid); $num = $cnt['number'];

echo "1. The history of an approved count\n";
$h = page($dana, "/counts/$cid/history");
ok($h['code'] === 200 && has($h, 'data-screen="count-history"') && has($h, 'id="history-corrections-table"') && has($h, 'id="history-categories-table"') && has($h, 'id="history-counters-table"') && has($h, 'id="review-skipped"') && has($h, 'id="count-trail"'), 'the history: the corrections, the variance by category, who counted what, the areas skipped, the trail');
preg_match_all('/id="history-correction-(\d+)"/', $h['body'], $m);
ok(count($m[1]) === 5 && has($h, '+41') === false && has($h, '$12.30') && has($h, 'SMOKE Eggs') && has($h, 'SMOKE Gin'), 'five corrections listed with the cost of each for a cost-seer (eggs +$12.30)');
ok(has($h, 'approved by SMOKE Dana') && has($h, 'started by SMOKE Bea') && has($h, 'id="count-total-variance"'), 'who started it, who approved it, the total variance');
preg_match('#id="history-counters-table".*?</table>#s', $h['body'], $t);
$tt = preg_replace('/\s+/', ' ', strip_tags(str_replace('</td>', ' | ', $t[0] ?? '')));
ok(str_contains($tt, 'SMOKE Bea | 8 | 0') && str_contains($tt, 'SMOKE Dana | 0 | 2'), "who counted what: Bea 8 lines, Dana 2 recounts ($tt)");
preg_match('#id="history-categories-table".*?</table>#s', $h['body'], $t);
ok(str_contains($t[0] ?? '', 'dairy') || str_contains(strtolower($t[0] ?? ''), 'dairy'), 'the variance by category lists the categories of the lines');
$trail = (int) one("SELECT count(*) FROM activity_log WHERE count_id = :c AND action <> 'screen.view'", ['c' => $cid]);
preg_match_all('/id="count-trail-row-\d+"/', $h['body'], $tr);
ok(count($tr[0]) === min($trail, 200) && $trail > 10, 'the trail lists everything that touched the count (' . count($tr[0]) . ' rows)');
$j = get_json($dana, "/counts/$cid/history");
ok(count($j['json']['data']['corrections'] ?? []) === 5 && isset($j['json']['data']['corrections'][0]['value']) && count($j['json']['data']['counters'] ?? []) === 2 && ($j['json']['data']['count']['status'] ?? '') === 'approved', 'JSON: five corrections with value, two counters, the status');

echo "2. The walls\n";
$h = page($ann, "/counts/$cid/history");
ok($h['code'] === 200 && has($h, 'id="history-correction-') && !str_contains(strip_tags($h['body']), '$12.30') && substr_count($h['body'], 'cost withheld') >= 5, 'Ann (Viewer) reads the history: the corrections and no cost — "—" for every value');
ok(!has($h, 'unit_cost') && !preg_match('/\$\d/', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $h['body']))), 'no dollar figure anywhere on her page');
$j = get_json($ann, "/counts/$cid/history");
ok(($j['json']['data']['corrections'][0]['cost_withheld'] ?? false) === true && !array_key_exists('value', $j['json']['data']['corrections'][0]) && ($j['json']['data']['count']['cost_withheld'] ?? false) === true, 'JSON for Ann: cost_withheld, no value, no total');

echo "3. The list\n";
$l = page($dana, '/counts/');
ok($l['code'] === 200 && has($l, 'id="counts-table"') && has($l, 'id="count-row-' . $cid . '"') && has($l, 'id="count-list-new-btn"') && has($l, 'href="/counts/' . $cid . '/history"'), 'the list: a row per count, a number that leads to the history of an approved one, Start a count');
preg_match('/id="count-row-' . $cid . '">(.*?)<\/tr>/s', $l['body'], $m);
$row = preg_replace('/\s+/', ' ', strip_tags(str_replace('</td>', ' | ', $m[1] ?? '')));
ok(str_contains($row, $num) && str_contains($row, 'approved') && str_contains($row, '8 / 8') && str_contains($row, '| 2 |') && str_contains($row, '$6.') && str_contains($row, 'SMOKE Dana'), "a row: number, status, lines 8 / 8, 2 over tolerance, the variance, the approver ($row)");
ok(has(page($dana, '/counts/?status=approved&kind=full'), 'id="count-row-' . $cid . '"') && !has(page($dana, '/counts/?status=open'), 'id="count-row-' . $cid . '"') && !has(page($dana, '/counts/?kind=cycle'), 'id="count-row-' . $cid . '"'), 'the filters: status and kind');
ok(has(page($dana, '/counts/?from=2020-01-01&to=2099-01-01'), 'id="count-row-' . $cid . '"') && !has(page($dana, '/counts/?from=2099-01-01'), 'id="count-row-' . $cid . '"') && has(page($dana, '/counts/?from=2099-01-01'), 'id="count-list-empty"'), 'from and to (site dates); a list with nothing in it says so');
$la = page($ann, '/counts/');
preg_match('/id="count-row-' . $cid . '">(.*?)<\/tr>/s', $la['body'], $m);
ok($la['code'] === 200 && !has($la, 'id="count-list-new-btn"') && str_contains($m[1] ?? '', 'cost withheld'), 'Ann reads the list: no Start button, the variance is "—"');

echo "4. Discarding\n";
[$d1, ] = start_count_as($bea, ['kind' => 'spot']);
$posted = (int) one("SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = :c", ['c' => $d1]);
$r = post($bea, '/counts/discard.php', ['count' => $d1]);
ok($r['code'] === 403 && ccount($d1)['status'] === 'open', 'Bea cannot discard: 403');
$before = last_activity_id();
$r = post($dana, '/counts/discard.php', ['count' => $d1, 'reason' => 'started by mistake']);
$a = activity('count.discard', $before);
ok($r['code'] === 302 && preg_match('#^/counts/\?notice=discarded#', $r['location']) === 1 && ccount($d1)['status'] === 'discarded' && $posted === 0 && (int) one("SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = :c", ['c' => $d1]) === 0, 'a spot count discarded: discarded, nothing posted, back to the list');
ok(count($a) === 1 && json_decode($a[0]['after'], true)['reason'] === 'started by mistake' && (int) $a[0]['count_id'] === $d1, 'count.discard logged with the reason and count_id');
$r = post($dana, '/counts/discard.php', ['count' => $d1]);
ok($r['code'] === 422 && says($r, 'already discarded'), 'discarding it again: refused in words');
$h = page($dana, "/counts/$d1/history");
ok($h['code'] === 200 && has($h, 'Nothing was posted') && says($h, 'discarded'), 'its history says nothing was posted');
$r = post($bea, '/counts/line.php', ['count_line' => (int) one('SELECT id FROM inventory_count_lines WHERE count_id = :c LIMIT 1', ['c' => $d1]), 'state' => 'zero']);
ok($r['code'] === 422 && says($r, 'is discarded'), 'a discarded count\'s lines no longer change');
finish();
