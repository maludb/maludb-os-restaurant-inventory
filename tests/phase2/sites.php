<?php
/** Proof: the sites — the claim's scope, the switcher, a site not held, one site and no dropdown, a holding removed moving a parked session, a site closed, Nico gaining a site, data-scope-id, screen.view rows with scope_id (sso-shell.md "Proof: sites"). */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=restaurant_inventory';

echo "1. The claim's scope and the switcher\n";
[$jm, ] = sign_on(27, ['scope' => AIRPORT]);
ok(site_of($jm) === 'SMOKE Airport' && badge_of($jm) === 'Kitchen', "Marco's hand-off with scope 102 lands at Airport as Kitchen");
$h = page($jm, '/')['body'];
ok(str_contains($h, 'id="site-switcher"') && str_contains($h, 'id="site-switch-' . DOWNTOWN . '"') && str_contains($h, 'id="site-switch-' . AIRPORT . '"'), 'the header carries the site switcher with both sites');
ok(!str_contains($h, 'id="nav-suggested-order-list"') && !str_contains($h, 'id="nav-invoice-list"') && str_contains($h, 'id="nav-receipt-list"'), 'at Airport (Kitchen) the menu has no Suggested orders, no Invoices; Receive is there');
$t = page_csrf($jm);
$since = last_activity_id();
$r = req('POST', '/switch-site.php', ['jar' => $jm, 'form' => ['site' => DOWNTOWN, 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/' && site_of($jm) === 'SMOKE Downtown' && badge_of($jm) === 'Manager', 'site_switch to 101: 302 to /, the header shows Downtown and the Manager badge');
$ev = activity('member.site_switch', $since);
ok(count($ev) === 1 && (int) $ev[0]['scope_id'] === DOWNTOWN && str_contains((string) $ev[0]['before'], '102'), 'member.site_switch logged with scope_id 101 and the site left');
ok(str_contains(page($jm, '/')['body'], 'id="nav-suggested-order-list"') && str_contains(page($jm, '/')['body'], 'id="nav-invoice-list"'), "at Downtown the Manager's items appear");
ok(str_contains(page($jm, '/')['body'], 'data-scope-id="101"'), '#page-content is stamped data-scope-id="101"');
$r = req('POST', '/switch-site.php', ['jar' => $jm, 'form' => ['site' => AIRPORT, 'csrf_token' => $t], 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Redirect: /'), 'under HTMX the switch answers HX-Redirect to /');
$r = req('POST', '/switch-site.php', ['form' => ['site' => DOWNTOWN], 'headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(27)]]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && ($d['record_id'] ?? 0) === DOWNTOWN && ($d['refresh'] ?? '') === 'siteChanged', 'as an action (JSON): record_id the site, refresh siteChanged');
$r = page($jm, '/switch-site');
ok($r['code'] === 200 && str_contains($r['body'], 'id="site-switcher-' . DOWNTOWN . '"') && str_contains($r['body'], 'id="site-switcher-' . AIRPORT . '"') && str_contains($r['body'], 'data-screen="site-switch"'), '/switch-site renders the page of buttons (screen site-switch)');

echo "2. A site not held; one site and no dropdown\n";
[$jp, ] = sign_on(26);
$since = last_activity_id();
$r = req('POST', '/switch-site.php', ['jar' => $jp, 'form' => ['site' => DOWNTOWN, 'csrf_token' => page_csrf($jp)]]);
ok($r['code'] === 403 && str_contains($r['body'], 'You do not hold that restaurant.') && count(activity('member.site_switch.refused', $since)) === 1 && site_of($jp) === 'SMOKE Airport', 'Priya (Airport only) switching to Downtown: 403 in words, member.site_switch.refused, still at Airport');
[$ja, ] = sign_on(29);
$h = page($ja, '/')['body'];
ok(!str_contains($h, 'id="site-switcher"') && str_contains($h, 'id="header-site-name"') && !str_contains($h, 'id="sidebar-site-row"'), 'Ann holds one site: no switcher dropdown, the name alone');
ok(page($ja, '/recipes/')['code'] === 200, 'her screens are of her one site');

echo "3. A holding removed moves a parked session; a closed site does the same\n";
[$jm2, ] = sign_on(27, ['scope' => AIRPORT]);
ok(site_of($jm2) === 'SMOKE Airport', 'Marco parked at Airport');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(27, [[DOWNTOWN, 'manager', ['manager'], 'write']], 'write')]], '2026-02-01T00:00:00.000000Z'); return $s; });
$since = last_activity_id();
sync();
ok((int) one('SELECT count(*) FROM member_site_roles WHERE member_id = 27 AND scope_id = 102') === 0, 'the feed removed his Airport holding');
$r = page($jm2, '/');
ok($r['code'] === 200 && site_of($jm2) === 'SMOKE Downtown' && badge_of($jm2) === 'Manager', 'his session moved to Downtown on the next request');
ok(count(activity('member.site_moved', $since)) >= 1, 'member.site_moved logged');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [fixture()['feed']['access'][2]]], '2026-02-01T00:01:00.000000Z'); return $s; });
sync();
ok((int) one('SELECT count(*) FROM member_site_roles WHERE member_id = 27') === 2, 'restored by the feed: both holdings');
// a closed site: Priya's only site
[$jp2, ] = sign_on(26);
ok(page($jp2, '/')['code'] === 200, 'Priya is in at Airport');
kernel_state(function ($s) { $sc = fixture()['feed']['scopes'][1]; $sc['removed_at'] = '2026-02-02T00:00:00Z'; $s['incremental'] = incr(['scopes' => [$sc]], '2026-02-02T00:00:00.000000Z'); return $s; });
sync();
ok(one('SELECT removed_at FROM sites WHERE scope_id = 102') !== null, 'the feed closed Airport');
$r = page($jp2, '/');
ok($r['code'] === 302 && $r['location'] === $L, 'Priya, with no site left, is out: 302 to the launcher');
$r = page($jm2, '/');
ok($r['code'] === 200 && site_of($jm2) === 'SMOKE Downtown', 'Marco keeps Downtown');
ok(page($jm2, '/switch-site')['code'] === 200 && !str_contains(page($jm2, '/switch-site')['body'], 'id="site-switcher-102"'), 'the closed site is gone from his switcher');
kernel_state(function ($s) { $s['incremental'] = incr(['scopes' => [fixture()['feed']['scopes'][1]]], '2026-02-02T00:01:00.000000Z'); return $s; });
sync();
ok(one('SELECT removed_at FROM sites WHERE scope_id = 102') === null && (int) one('SELECT count(*) FROM member_site_roles WHERE scope_id = 102 AND member_id = 27') === 1 && page($jm2, '/switch-site')['code'] === 200 && str_contains(page($jm2, '/switch-site')['body'], 'id="site-switcher-102"'), 'reopened by the feed: the holdings survived the closure (db/019) — Airport is back in Marco\'s switcher with no access row resent');

echo "4. Nico gains a site through the feed and signs on\n";
ok(req('GET', handoff(32), ['jar' => jar()])['code'] === 403, 'before: Nico (a grant naming no site) is refused');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(32, [[DOWNTOWN, 'user', ['user'], 'write']], 'write')]], '2026-02-03T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT role_key FROM member_site_roles WHERE member_id = 32')[0]['role_key'] === 'user', 'the feed gave him Kitchen at Downtown');
$c = fixture()['claims']['32']; $c['scopes'] = [['scope_id' => DOWNTOWN, 'kind' => 'location', 'id' => 10, 'name' => 'SMOKE Downtown', 'role' => 'user', 'roles' => ['user'], 'capability' => 'write']];
[$jn, $r] = sign_on(32, ['claims' => $c]);
ok($r['code'] === 302 && badge_of($jn) === 'Kitchen' && site_of($jn) === 'SMOKE Downtown', 'and he signs on as Kitchen at Downtown');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(32, [], 'write')]], '2026-02-03T00:01:00.000000Z'); return $s; });
sync();
ok(page($jn, '/')['code'] === 302, 'the site taken back: his session ends');
kernel_state(function ($s) { unset($s['incremental']); return $s; });

echo "5. screen.view rows carry scope_id\n";
$since = last_activity_id();
page($jm2, '/recipes/');
$rows = activity('screen.view', $since);
ok($rows !== [] && (int) end($rows)['scope_id'] === DOWNTOWN && (int) end($rows)['actor_member_id'] === 27, 'a screen opened at Downtown logs screen.view with scope_id 101');
$d = json_decode(page($jm2, '/', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d['site_id'] ?? 0) === DOWNTOWN && isset($d['regions']['suggested']) && isset($d['regions']['purchasing']) && !isset($d['regions']['admin']), "the home's JSON names the site and the Manager's regions (no admin card)");
finish();
