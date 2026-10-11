<?php
/** Slice 7 — who sees what (periods.md "Proof": Who sees): a Viewer and a Kitchen member read the periods, the usage and the COGS with every value "—" and the units shown; a Chef and a Manager see values; the right of each action; another site's period is a 404; the export is exports.all's. */
require __DIR__ . '/lib.php';
$w = period_world();
$ann = person(29); $bea = person(28); $dana = person(30); $marco = person(27); $marcoA = person(27, AIRPORT); $owner = person(1);
$f = wk(4); $t = wk(4, 6);
$content = fn (array $r): string => substr($r['body'], (int) strpos($r['body'], 'class="main-content"'));
$money = fn (array $r): int => preg_match_all('/\$\d[\d,]*\.\d\d/', strip_tags($content($r)));

echo "1. A Viewer and a Kitchen member read, and cost is withheld\n";
foreach (['Ann' => $ann, 'Bea' => $bea] as $who => $jar) {
    $l = page($jar, '/periods/'); $u = page($jar, "/usage/?from=$f&to=$t"); $c = page($jar, '/usage/cogs'); $v = page($jar, '/periods/' . period_id(4));
    ok($l['code'] === 200 && $u['code'] === 200 && $c['code'] === 200 && $v['code'] === 200, "$who opens the period list, a period, the usage report and COGS (ri.read)");
    ok($money($l) === 0 && $money($u) === 0 && $money($c) === 0 && $money($v) === 0 && substr_count($u['body'], 'cost withheld') >= 20, "…and not one dollar figure is on them: every value is \"—\" (" . substr_count($u['body'], 'cost withheld') . ' withheld cells on the usage table)');
}
$uj = jget(29, "/usage/?from=$f&to=$t")['json']['data'] ?? [];
$egg = array_column($uj['rows'] ?? [], null, 'ingredient_id')[$w['egg']] ?? [];
ok(($uj['cost_withheld'] ?? false) === true && ($egg['cost_withheld'] ?? false) === true && !isset($egg['unexplained_value']) && !isset($egg['unit_cost_per_base']) && near($egg['unexplained_base'] ?? null, -41, 0.0001) && ($egg['count_unit'] ?? '') === 'flat', 'JSON for a Viewer: the units (eggs −41 each, in flats) and cost_withheld, no value key anywhere on the row');
$pj = jget(29, '/periods/')['json']['data']['periods'] ?? [];
ok(($pj[3]['cost_withheld'] ?? false) === true && !isset($pj[3]['cogs_value']) && !isset($pj[3]['opening_value']) && $pj[3]['status'] === 'closed', 'the period list for a Viewer: status and dates, no value (cost_withheld)');
ok(!has(page($ann, '/periods/'), 'id="period-close-form"') && !has(page($bea, '/periods/'), 'id="period-reopen-form"'), 'neither is offered Close or Reopen');

echo "2. A Chef and a Manager see values\n";
foreach (['Dana' => $dana, 'Marco' => $marco] as $who => $jar) {
    $u = page($jar, "/usage/?from=$f&to=$t"); $c = page($jar, '/usage/cogs');
    ok($money($u) >= 20 && $money($c) >= 20 && substr_count($u['body'], 'cost withheld') === 0, "$who sees the values on the usage table and COGS");
}
ri_sql('UPDATE site_settings SET kitchen_sees_cost = true WHERE scope_id = :s', ['s' => DOWNTOWN]);
$bu = page($bea, "/usage/?from=$f&to=$t");
ri_sql('UPDATE site_settings SET kitchen_sees_cost = false WHERE scope_id = :s', ['s' => DOWNTOWN]);
ok($money($bu) >= 20, 'cost is the wall: Bea sees the values while the site lets the Kitchen see cost, and not otherwise');

echo "3. Another site's periods\n";
$ap = period_id(1, AIRPORT);
ok($ap > 0 && page($dana, '/periods/' . $ap)['code'] === 404 && page($marco, '/periods/' . $ap)['code'] === 404, 'Airport\'s week 1 is a 404 for Dana (Downtown only) and for Marco signed on at Downtown');
ok(page($marcoA, '/periods/' . $ap)['code'] === 200 && page($marcoA, '/periods/' . period_id(2))['code'] === 404, 'Marco signed on at Airport reads Airport\'s period and not Downtown\'s');
$aj = jget(27, '/periods/', AIRPORT)['json']['data'] ?? [];
ok(count($aj['periods'] ?? []) === (int) one('SELECT count(*) FROM periods WHERE scope_id = 102 AND fiscal_year = :y', ['y' => fiscal_year()]) && ($aj['periods'][0]['scope_id'] ?? 0) === AIRPORT && ($aj['periods'][0]['status'] ?? '') === 'open', 'the Airport list is Airport\'s own, all open');
$ua = jget(27, "/usage/?from=$f&to=$t", AIRPORT)['json']['data'] ?? [];
ok(($ua['rows'] ?? ['x']) === [] , 'Airport\'s usage over week 4 is empty: Downtown\'s movements are not Airport\'s');
ok(post(person(1, AIRPORT), '/periods/close.php', ['period' => period_id(1)])['code'] === 404 && post($marcoA, '/periods/close.php', ['period' => period_id(1)])['code'] === 403, 'closing a Downtown period signed on at Airport: 404 for the owner who holds the right there, 403 for Marco who does not');

echo "4. The export is exports.all's\n";
$q = http_build_query(['from' => $f, 'to' => $t, 'format' => 'csv']);
ok(req('GET', '/usage/export.php?' . $q, ['jar' => $ann])['code'] === 403 && req('GET', '/usage/export.php?' . $q, ['jar' => $dana])['code'] === 403, 'a Viewer and a Chef cannot export (exports.all is the Manager\'s): 403');
$r = req('GET', '/usage/export.php?' . $q, ['jar' => $marco]);
ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv') && preg_match('/filename="usage-[a-z0-9-]+-' . $f . '-' . $t . '\.csv"/', $r['headers']) === 1, 'Marco downloads usage-<site>-<from>-<to>.csv');
ok(has(page($marco, "/usage/?from=$f&to=$t"), 'id="usage-export-csv"') && !has(page($dana, "/usage/?from=$f&to=$t"), 'id="usage-export-csv"'), 'the export links are on the report for who may use them');
finish();
