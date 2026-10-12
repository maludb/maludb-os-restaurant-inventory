<?php
/** Slice 8 — who sees what (projection.md "Proof": Who sees): a Viewer and a Kitchen member are told projection is the Chef's and the Manager's; a Chef reads everything with costs; the right of each action; another site's records are a 404. */
require __DIR__ . '/lib.php';
$w = projection_world();
reset_projection();
$ann = person(29); $bea = person(28); $dana = person(30); $marco = person(27); $owner = person(1, AIRPORT); $marcoA = person(27, AIRPORT);
$sand = $w['mi_sand'];
$screens = ['/projection/', '/projection/items/' . $sand, '/projection/calendar', '/projection/demand', '/projection/stock', '/projection/stock/' . $w['egg'], '/projection/special'];

echo "1. Read-only screens open to every reader (worker-brief.md's gate rule, aligned by the planning model in review)\n";
foreach (['Ann (Viewer)' => $ann, 'Bea (Kitchen)' => $bea] as $who => $jar) {
    $codes = array_map(fn ($p) => page($jar, $p)['code'], $screens);
    ok($codes === array_fill(0, count($screens), 200), "$who opens all seven projection screens — quantities, no cost (200)");
}
$annJson = json_encode(jget(29, '/projection/stock')['json'] ?? []);
ok(!preg_match('/"(unit_cost|line_value|total|value|cost_per_base)"\s*:\s*[0-9"]/', $annJson), 'a Viewer\'s projected stock carries no cost figure');
ok(page($bea, '/purchasing/suggested')['code'] === 403 && page($bea, '/projection/proposals')['code'] === 403 && page($ann, '/purchasing/suggested')['code'] === 403, 'the suggested orders and the proposals are purchasing.draft\'s: refused too');
ok(page($ann, '/projection/watches')['code'] === 200 && page($bea, '/projection/watches')['code'] === 200, 'the watches are ri.read\'s: both open them');
$j = jget(29, '/purchasing/suggested');
ok($j['code'] === 403 && ($j['json']['error']['code'] ?? '') === 'forbidden', 'JSON says forbidden on the suggested orders (purchasing.draft)');
$menuAnn = page($ann, '/')['body'];
ok(!str_contains($menuAnn, 'href="/projection/"') && !str_contains($menuAnn, 'href="/projection/stock"') && !str_contains($menuAnn, 'href="/purchasing/suggested"') && str_contains($menuAnn, 'href="/projection/watches"'), 'the menu shows a Viewer the watches only');
$menuDana = page($dana, '/')['body'];
ok(str_contains($menuDana, 'href="/projection/"') && str_contains($menuDana, 'href="/projection/stock"') && str_contains($menuDana, 'href="/projection/special"') && str_contains($menuDana, 'href="/purchasing/suggested"') && !str_contains($menuDana, 'href="/projection/calendar"'), 'a Chef\'s menu: forecast, projected stock, can we run it, suggested orders — not the trading calendar (that is a Manager\'s to change)');

echo "2. A Chef reads everything, with costs\n";
$allOk = true; foreach (array_merge($screens, ['/projection/proposals', '/projection/watches', '/purchasing/suggested']) as $p) { $allOk = $allOk && page($dana, $p)['code'] === 200; }
ok($allOk, 'Dana (Chef) opens all ten screens');
$dj = jget(30, '/purchasing/suggested?supplier=' . $w['sysco'])['json']['data']['suppliers'][0] ?? [];
ok(!isset($dj['cost_withheld']) && isset($dj['total']) && isset($dj['lines'][0]['unit_cost']) && isset($dj['lines'][0]['line_value']), 'the suggested orders carry unit cost, line value and the total');
$dh = page($dana, '/purchasing/suggested?supplier=' . $w['sysco']);
ok(has($dh, 'Unit cost') && preg_match('/\$\d+\.\d\d/', strip_tags($dh['body'])) === 1 && has($dh, 'id="supplier-total-' . $w['sysco'] . '"'), 'and on the page');
$n = activity('screen.view', 0);
ok(count($n) > 0, 'every opening is logged');

echo "3. The rights of the actions\n";
$cases = [['/projection/run.php', ['method' => 'baseline'], 'forecast.adjust'], ['/projection/override.php', ['from_date' => day(3), 'to_date' => day(3), 'factor' => '2', 'reason' => 'x'], 'forecast.adjust'], ['/projection/calendar/save.php', ['on_date' => day(3), 'closed' => 'yes'], 'forecast.adjust'],
          ['/projection/calendar/covers.php', [], 'forecast.adjust'], ['/projection/override-propose.php', ['from_date' => day(3), 'to_date' => day(3), 'factor' => '2', 'reason' => 'x'], 'purchasing.draft'], ['/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'yes'], 'purchasing.draft'],
          ['/projection/proposals/save.php', ['kind' => 'pos', 'subject_type' => 'site', 'subject_id' => 101, 'title' => 'x'], 'purchasing.draft'], ['/projection/proposals/note.php', [], 'purchasing.draft']];
foreach ($cases as [$path, $form, $right]) {
    $needsAdjust = $right === 'forecast.adjust';
    $codes = [jpost(29, $path, $form)['code'], jpost(28, $path, $form)['code'], $needsAdjust ? jpost(30, $path, $form)['code'] : 200];
    ok($codes[0] === 403 && $codes[1] === 403 && ($needsAdjust ? $codes[2] === 403 : true), "$path is $right's: Ann and Bea" . ($needsAdjust ? ' and Dana' : '') . ' are refused');
}
ok(jpost(29, '/projection/watches/save.php', ['ingredient' => $w['egg'], 'kind' => 'below_par'])['code'] === 200, 'watch_set is ri.read\'s: Ann may');
ri_sql('DELETE FROM watches');

echo "4. Another site\n";
$ov = (int) jpost(27, '/projection/override.php', ['menu_item' => $sand, 'from_date' => day(3), 'to_date' => day(3), 'factor' => '1.1', 'reason' => 'downtown only'])['json']['record_id'];
$po = jpost(27, '/purchasing/suggested/run.php', ['supplier' => $w['sysco'], 'draft' => 'yes']);
ri_sql("INSERT INTO buyer_proposals (scope_id, kind, subject_type, subject_id, title) VALUES (101, 'pos', 'site', 101, 'SMOKE downtown only')");
$bp = last_id('buyer_proposals');
ri_sql("INSERT INTO watches (member_id, scope_id, ingredient_id, kind) VALUES (1, 101, :i, 'below_par')", ['i' => $w['egg']]);
$wt = last_id('watches');
$ja = fn (string $path, array $form) => jpost(1, $path, $form, AIRPORT);
ok($ja('/projection/override-remove.php', ['override' => $ov])['code'] === 404 && $ja('/projection/override-decide.php', ['override' => $ov, 'decision' => 'set'])['code'] === 404, 'an override of Downtown is the same 404 from Airport');
ok($ja('/projection/proposals/decide.php', ['proposal' => $bp, 'decision' => 'accept'])['code'] === 404, 'so is a proposal');
ok($ja('/projection/watches/clear.php', ['watch' => $wt])['code'] === 404, '…and a watch');
$oj = jget(1, '/projection/', AIRPORT);
ok($oj['code'] === 200 && ($oj['json']['data']['items'] ?? 1) === [], 'the owner at Airport sees Airport\'s own projection: no item has sold there (empty)');
ok((jget(1, '/projection/stock', AIRPORT)['json']['data']['ingredients'] ?? 1) === [] && count(array_filter(jget(1, '/projection/calendar', AIRPORT)['json']['data']['days'], fn ($d) => $d['covers'] !== null)) === 0 && (jget(1, '/purchasing/suggested', AIRPORT)['json']['data']['suppliers'] ?? 1) === [] && (jget(1, '/projection/proposals', AIRPORT)['json']['data']['proposals'] ?? 1) === [], 'its projected stock, covers, suggested orders and proposals are empty too — nothing of Downtown shows');
ok(jget(27, '/projection/', AIRPORT)['code'] === 200 && jget(27, '/projection/proposals', AIRPORT)['code'] === 403, 'Marco at Airport (Kitchen there) reads the forecast but not the proposals (purchasing.draft)');
$ij = jget(1, '/projection/items/' . $sand, AIRPORT);
ok($ij['code'] === 200 && ($ij['json']['data']['days'][0]['source'] ?? '') === 'baseline' && (float) ($ij['json']['data']['days'][0]['qty'] ?? 1) === 0.0, 'an item at a site where it has not sold forecasts zero from the baseline');
ri_sql('DELETE FROM watches'); ri_sql('DELETE FROM buyer_proposals'); ri_sql('DELETE FROM forecast_overrides');
finish();
