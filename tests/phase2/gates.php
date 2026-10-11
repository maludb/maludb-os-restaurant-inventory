<?php
/** Proof: rights per role on every screen of the manifest at the current site (200, 403 in the right's words, 302 anonymous, 501 to a POST or JSON on a placeholder), the menu per role, CSRF, My settings, tokens, notifications and the bell, the trail, search, the command bar, action tokens, cost as the wall, the picker, health (sso-shell.md "Proof: gates"). */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=restaurant_inventory';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every menu screen answers 200 or 403 at the current site\n";
// owner 1 (admin both), priya 26 (Kitchen at Airport), marco 27 (Manager at Downtown — signed on there), bea 28 (Kitchen at Downtown), ann 29 (Viewer), dana 30 (Chef)
$jars = [];
foreach (['owner' => [1, DOWNTOWN], 'priya' => [26, AIRPORT], 'marco' => [27, DOWNTOWN], 'bea' => [28, DOWNTOWN], 'ann' => [29, DOWNTOWN], 'dana' => [30, DOWNTOWN]] as $who => [$id, $scope]) { [$jars[$who], ] = sign_on($id, ['scope' => $scope]); }
$everyone = 'owner priya marco bea ann dana';
$kitchen = 'owner priya marco bea dana';
$chef = 'owner marco dana';
$manager = 'owner marco';
$matrix = [
    '/' => $everyone, '/search' => $everyone, '/ingredients/' => $everyone, '/suppliers/' => $everyone, '/areas/' => $everyone, '/categories/' => $chef, '/units/' => 'owner',
    '/recipes/' => $everyone, '/menu/' => $everyone, '/modifiers/' => $chef, '/menu/engineering' => $chef, '/menu/categories' => $chef,
    '/stock/' => $everyone, '/stock/movements' => $everyone, '/stock/value' => $everyone, '/receipts/' => $everyone, '/transfers/' => $everyone, '/adjustments/' => $everyone, '/production/' => $everyone, '/waste/' => $everyone,   // slice 3: the ledger's lists read under ri.read (design §2, ledger.md); its doors that act keep the role's right
    '/receipts/new' => $kitchen, '/transfers/new' => $kitchen, '/adjustments/new' => $chef, '/production/new' => $kitchen, '/waste/new' => $kitchen,
    '/counts/' => $kitchen, '/counts/sheets/' => $chef, '/counts/containers/' => $chef,
    '/purchasing/suggested' => $chef, '/purchasing/' => $everyone, '/purchasing/due' => $kitchen, '/invoices/' => $manager, '/purchasing/alerts' => $chef,
    '/sales/' => $everyone, '/sales/sources/' => 'owner', '/sales/upload' => $kitchen, '/sales/runs/' => $everyone, '/sales/mappings/' => 'owner',
    '/usage/' => $chef, '/periods/' => $chef, '/usage/cogs' => $chef, '/periods/calendars' => 'owner',
    '/projection/' => $chef, '/projection/stock' => $everyone, '/projection/demand' => $chef, '/projection/calendar' => $manager, '/projection/special' => $everyone, '/projection/proposals' => $chef, '/projection/watches' => $everyone,
    '/reports/' => $chef, '/reports/exports' => $manager, '/reports/sites' => $chef,
    '/me/settings' => $everyone, '/me/notifications' => $everyone, '/me/tokens' => $everyone, '/me/trail' => $everyone,
    '/admin/settings' => 'owner', '/admin/sequences' => 'owner', '/admin/tax-rates' => 'owner', '/admin/reasons' => 'owner', '/admin/agents' => 'owner', '/admin/tokens' => 'owner', '/admin/trail' => 'owner',
];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($jars as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 6 people) answer 200 or 403 as the rights say at the site' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
ok(str_contains(page($jars['ann'], '/receipts/new')['body'], 'Your role at SMOKE Downtown (Viewer) does not let you do that') && str_contains(page($jars['bea'], '/modifiers/')['body'], '(Kitchen) does not let you') && str_contains(page($jars['dana'], '/invoices/')['body'], '(Chef) does not let you'), "a refusal says what the person may not do, in words (Ann on Receive, Bea on Modifiers, Dana on Invoices)");
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
$stubsWanted = count(array_filter(json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true)['screens'] ?? [], static fn (array $s): bool => !empty($s['stub'])));
ok((int) $stubs === $stubsWanted && $stubsWanted > 0, "every placeholder controller names its slice ($stubs; the registry reads $stubsWanted stubs — 52 before slice 1)");
$b = page($jars['dana'], '/counts/')['body'];
ok(str_contains($b, 'is not built yet') && str_contains($b, 'slice 6 builds this screen') && str_contains($b, 'data-screen="count-list"'), 'a placeholder renders inside the shell, names its slice (Counts → slice 6; Recipes was the example until slice 2 built it) and stamps its screen id');
ok(str_contains(page($jars['owner'], '/admin/agents')['body'], 'slice 9 builds this screen') && str_contains(page($jars['dana'], '/counts/')['body'], 'slice 6 builds') && str_contains(page($jars['dana'], '/projection/')['body'], 'slice 8 builds'), 'the agents page says slice 9, counts slice 6, the forecast slice 8');
$r = req('POST', '/counts/', ['jar' => $jars['dana'], 'form' => ['csrf_token' => page_csrf($jars['dana'])]]);
ok($r['code'] === 501, 'a POST to a placeholder: 501 not_built');
$r = req('GET', '/counts/', ['jar' => $jars['dana']] + $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'JSON to a placeholder: 501 not_built');
ok(req('GET', '/projection/stock', ['jar' => $jars['ann']] + $json)['code'] === 501 && req('GET', '/counts/', ['jar' => $jars['ann']] + $json)['code'] === 403, 'the right is checked before the placeholder answers (a Viewer: Projected stock 501 not_built, Counts 403)');
$menu = fn (string $j): array => (preg_match_all('/id="nav-((?!group-)[a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption" id="nav-group-[a-z]+"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
ok(count($menu($jars['ann'])) === 18 && $groups($jars['ann']) === ['Kitchen', 'Ingredients', 'Recipes', 'Stock', 'Purchasing', 'Sales', 'Projection', 'Me'], 'Ann sees the 18 ri.read items (home and search among them) and no Count, Usage, Reports or Admin group: ' . implode(', ', $groups($jars['ann'])));
ok(count($menu($jars['dana'])) === 44 && !in_array('invoice-list', $menu($jars['dana']), true) && !in_array('unit-list', $menu($jars['dana']), true) && !in_array('admin-settings', $menu($jars['dana']), true), "Dana (Chef) sees 44 items — no Invoices, no Units, no Admin");
ok(count($menu($jars['marco'])) === 47 && in_array('invoice-list', $menu($jars['marco']), true) && !in_array('unit-list', $menu($jars['marco']), true), 'Marco at Downtown (Manager) sees 47 — Invoices yes, Units no');
ok(count($menu($jars['owner'])) === 58 && array_slice($groups($jars['owner']), -1)[0] === 'Admin', 'the owner sees everything (58 items) with the Admin group last');
ok(count($menu($jars['priya'])) === 26 && in_array('receipt-list', $menu($jars['priya']), true) && !in_array('count-sheet-list', $menu($jars['priya']), true), 'Priya (Kitchen at Airport) sees 26 — Receive yes, Count sheets no');
$r = page(jar(), '/recipes/');
ok($r['code'] === 302 && $r['location'] === $L, 'no session: a screen goes to the launcher with ?app=restaurant_inventory');
$r = req('GET', '/recipes/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$h = page($jars['ann'], '/')['body'];
ok(str_contains($h, 'id="home-purchasing"') && str_contains($h, 'id="home-stockouts"') && !str_contains($h, 'id="home-suggested"') && !str_contains($h, 'id="home-admin"') && str_contains($h, 'id="home-bell"'), "Ann's home: the open-orders and stock-out cards, no suggested orders, nothing for the admin; the bell's card");
ok(str_contains(page($jars['owner'], '/')['body'], 'id="home-admin"') && str_contains(page($jars['dana'], '/')['body'], 'id="home-suggested"') && str_contains(page($jars['bea'], '/')['body'], 'id="home-kitchen"') && !str_contains(page($jars['bea'], '/')['body'], 'id="home-period"'), "the owner's the agents card; Dana's suggested orders; Bea's the kitchen card and no period");
ok(str_contains($h, 'fills this card'), 'every region is an empty state naming its slice');

echo "2. My settings — units, the default area of this site, how I am told, who I am here, my time zone\n";
pdo()->exec("INSERT INTO storage_areas (scope_id, name, kind, walk_order) VALUES (101, 'SMOKE Walk-in', 'walk_in', 1), (101, 'SMOKE Dry', 'dry', 2), (102, 'SMOKE Airport walk-in', 'walk_in', 1) ON CONFLICT DO NOTHING");
$areaD = (int) one("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Walk-in'");
$areaA = (int) one("SELECT id FROM storage_areas WHERE scope_id = 102");
$t = csrf_of(page($jars['bea'], '/')['body']);
$d = json_decode(page($jars['bea'], '/me/settings', $json)['body'], true)['data'];
ok($d['prefs']['email_enabled'] === true && $d['prefs']['text_enabled'] === false && $d['prefs']['saved'] === false && $d['prefs']['display_units'] === 'metric' && $d['prefs']['default_area_id'] === null && in_array('watch', $d['prefs']['kinds'], true), 'the defaults before any save: email on, text off, metric, no default area, the default kinds');
ok($d['timezone'] === 'UTC' && $d['badge'] === 'Kitchen' && $d['sees_cost'] === false && count($d['kinds']) === 16 && count($d['areas']) === 2, "the time zone is the directory's; the badge Kitchen; cost withheld; sixteen kinds; this site's two areas");
ok(req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['units' => 'imperial']])['code'] === 403, 'saving without the CSRF token: 403');
$since = last_activity_id();
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['units' => 'imperial', 'default_area' => $areaD, 'email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['watch', 'stockout'], 'text_kinds' => ['stockout'], 'csrf_token' => $t]]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/me/settings?notice=prefs_saved'), 'with it: 302 back to the settings with the notice');
$row = q('SELECT display_units, default_area_id, text_enabled, kinds, text_kinds FROM notification_prefs WHERE member_id = 28')[0] ?? null;
ok($row && $row['display_units'] === 'imperial' && (int) $row['default_area_id'] === $areaD && $row['text_enabled'] && $row['kinds'] === '{watch,stockout}' && $row['text_kinds'] === '{stockout}', 'the row holds the choices (imperial, the walk-in, text on, two kinds, one text kind)');
$log = activity('member.preferences_save', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'imperial') && !str_contains((string) $log[0]['after'], 'A watch I set'), 'member.preferences_save logs the changed keys, never the sentences');
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['default_area' => $areaA, 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'not at this restaurant') && (int) one('SELECT default_area_id FROM notification_prefs WHERE member_id = 28') === $areaD, "an area of the other site is refused in words and nothing changed");
ok(req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['kinds' => ['watch', 'nonsense'], 'csrf_token' => $t]])['code'] === 422, 'an unknown kind: 422');
ok(req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['text_enabled' => 'maybe', 'csrf_token' => $t]])['code'] === 422, 'a yes/no field that is neither: 422');
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['timezone' => 'Europe/Paris', 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'operating system'), "the time zone is refused in words (it is the directory's)");
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['text_enabled' => 'no', 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT display_units, text_enabled, kinds FROM notification_prefs WHERE member_id = 28')[0] === ['display_units' => 'imperial', 'text_enabled' => false, 'kinds' => '{watch,stockout}'], 'a field left out stays as it was (imperial and the kinds kept, text off)');
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'form' => ['kinds' => [''], 'default_area' => '', 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT kinds, default_area_id FROM notification_prefs WHERE member_id = 28')[0] === ['kinds' => '{}', 'default_area_id' => null], 'every kind unticked persists as none; an emptied default area is cleared');
$h = page($jars['bea'], '/me/settings')['body'];
ok(str_contains($h, 'id="prefs-form-field-display-units"') && str_contains($h, 'id="prefs-form-field-default-area"') && str_contains($h, 'id="settings-timezone-value"') && str_contains($h, 'id="settings-role-badge">Kitchen<') && str_contains($h, 'withheld') && str_contains($h, 'id="settings-roles-site-101"'), 'the screen shows the units, the area select, the time zone read-only, the badge, cost withheld, the roles per site');
ok(str_contains(page($jars['dana'], '/me/settings')['body'], 'You see cost here'), "Dana's says she sees cost");
$r = req('POST', '/me/settings/save.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['email_enabled' => 'yes', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], 'prefsChanged'), 'under HTMX: HX-Location to the settings and the prefsChanged trigger');

echo "3. The tokens screen\n";
ok(req('POST', '/me/tokens/mint.php', ['jar' => $jars['bea'], 'form' => ['label' => 'proof']])['code'] === 403, 'minting a token without the CSRF token: 403');
$r = req('POST', '/me/tokens/mint.php', ['jar' => $jars['bea'], 'form' => ['label' => 'proof', 'expires_in_days' => 30, 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/me/tokens', 'with it: 302 to the tokens screen');
$r = page($jars['bea'], '/me/tokens');
preg_match('/id="token-minted-value">(ri_[0-9a-f]{48})</', $r['body'], $m);
$raw = $m[1] ?? '';
ok($raw !== '' && str_contains($r['body'], 'id="token-minted"') && !str_contains(page($jars['bea'], '/me/tokens')['body'], $raw), 'the new token is shown once (ri_ + 48 hex, in a warning box) and never again');
$row = q('SELECT id, token_hash, label, scope, expires_at FROM mcp_access_tokens WHERE member_id = 28 ORDER BY id DESC LIMIT 1')[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['scope'] === 'mcp' && $row['expires_at'] !== null, 'only its SHA-256 is stored; scope mcp; an expiry in 30 days');
$logged = q("SELECT after::text AS a, token_id FROM activity_log WHERE action = 'token.mint' ORDER BY id DESC LIMIT 1")[0];
ok(!str_contains($logged['a'], $raw) && str_contains($logged['a'], 'proof') && (int) $logged['token_id'] === (int) $row['id'], 'token.mint logs the label and the token_id key, never the token');
$mine = json_decode(page($jars['bea'], '/me/tokens', $json)['body'], true)['data']['tokens'];
ok($mine !== [] && !isset($mine[0]['token_hash']) && !str_contains(json_encode($mine), $raw) && $mine[0]['state'] === 'live' && $mine[0]['last4'] === substr(hash('sha256', $raw), -4), "the tokens screen's JSON lists tokens without the value or the hash, with the state and the last four of the hash");
pdo()->exec("SELECT set_config('app.member_id', '28', false)");
$resolved = q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]);
ok(count($resolved) === 1 && (int) $resolved[0]['member_id'] === 28, 'mcp_resolve_token() resolves the minted token to Bea');
ok(req('POST', '/me/tokens/mint.php', ['jar' => $jars['bea'], 'form' => ['label' => str_repeat('x', 81), 'csrf_token' => $t]])['code'] === 422, 'a label over 80 characters: 422');
$id = (int) $row['id'];
$r = req('POST', '/me/tokens/revoke.php', ['jar' => $jars['dana'], 'form' => ['token' => $id, 'csrf_token' => page_csrf($jars['dana'])]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null, 'another person cannot revoke it: 404, still live');
$r = req('POST', '/me/tokens/revoke.php', ['jar' => $jars['bea'], 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null && count(q("SELECT 1 FROM activity_log WHERE action = 'token.revoke' AND entity_id = :i", ['i' => $id])) === 1, 'the owner revokes it: 302, revoked_at set, token.revoke logged');
ok(q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]) === [] && str_contains(page($jars['bea'], '/me/tokens')['body'], '>revoked<'), 'the revoked token no longer resolves; the screen marks it revoked');

echo "4. Notifications and the bell\n";
ok(str_contains(page($jars['bea'], '/me/notifications')['body'], 'id="notifications-empty"') && !str_contains(page($jars['bea'], '/')['body'], 'id="bell-count"'), 'the empty shape ("Nothing yet"); no count on the bell');
pdo()->exec("INSERT INTO notifications (member_id, scope_id, kind, record_type, record_id, title, body) VALUES (28, 101, 'stockout', 'ingredient', 7, 'SMOKE Limes run out Friday', 'at Downtown'), (28, 101, 'count_review', 'inventory_count', 3, 'SMOKE Count CNT-00003 is ready', null), (30, 101, 'po_ack', 'purchase_order', 5, 'SMOKE for Dana', null)");
$h = page($jars['bea'], '/')['body'];
ok(preg_match('/id="bell-count">2</', $h) === 1 && str_contains($h, 'SMOKE Limes run out') && !str_contains($h, 'SMOKE for Dana'), "two unread: the bell shows 2; the home's Unread card lists hers, never Dana's");
$d = json_decode(page($jars['bea'], '/me/notifications', $json)['body'], true)['data'];
ok(count($d['notifications']) === 2 && $d['unread'] === 2 && $d['notifications'][1]['url'] === '/counts/3' && $d['notifications'][0]['site'] === 'SMOKE Downtown', 'the screen lists her two with their records (a count links to /counts/3) and the site');
$h = page($jars['bea'], '/me/notifications')['body'];
ok(str_contains($h, 'href="/ingredients/7?back=') && str_contains($h, 'SMOKE Downtown') && preg_match('/badge bg-soft-danger[^>]*>Stock-out</', $h) === 1, 'a notification links to its record with the way back, names its site, wears its kind chip (Stock-out: danger)');
$nid = (int) one("SELECT id FROM notifications WHERE member_id = 28 AND kind = 'stockout'");
$since = last_activity_id();
$r = req('POST', '/me/notifications/read.php', ['jar' => $jars['bea'], 'form' => ['notification' => $nid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/me/notifications' && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $nid]) !== null && preg_match('/id="bell-count">1</', page($jars['bea'], '/')['body']) === 1, 'marking one read: 302, read_at set, the bell shows 1');
$mid = (int) one("SELECT id FROM notifications WHERE member_id = 30");
ok(req('POST', '/me/notifications/read.php', ['jar' => $jars['bea'], 'form' => ['notification' => $mid, 'csrf_token' => $t]])['code'] === 302 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $mid]) === null, "marking Dana's: nothing changes (own rows only)");
ok(req('POST', '/me/notifications/read.php', ['jar' => $jars['bea'], 'form' => ['csrf_token' => $t]])['code'] === 302 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL') === 0 && count(activity('member.notification_read', $since)) === 3, 'no id marks all of hers read; member.notification_read logged each time');
$r = page($jars['bea'], '/me/notifications?count=1', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<span id="bell-count-wrap"') && !str_contains($r['body'], '<html'), 'the bell re-fetches its count alone (Pattern A)');

echo "5. The trail and search\n";
$rows = json_decode(page($jars['bea'], '/me/trail', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 28 && $rows[0]['site'] === 'SMOKE Downtown', 'Bea sees only her own rows (' . count($rows) . '), each with its site');
ok(str_contains(json_encode($rows), 'changed their settings') && str_contains(json_encode($rows), 'made an access token') && str_contains(json_encode($rows), 'opened my notifications'), 'each row has its sentence (settings, a token, a screen opened)');
$r = page($jars['bea'], '/me/trail');
ok($r['code'] === 200 && str_contains($r['body'], 'id="trail-list"') && preg_match('/id="trail-row-\d+"/', $r['body']) === 1 && str_contains($r['body'], 'id="trail-filter-from"'), 'the screen renders (trail-row-{id}, the from/to filters)');
$r = page($jars['bea'], '/me/trail?from=2020-01-01', ['headers' => ['HX-Request: true', 'HX-Target: trail-results']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div id="trail-results"') && !str_contains($r['body'], '<html'), 'the filter swaps only #trail-results');
ok(page($jars['bea'], '/me/trail?member=30')['code'] === 403 && page($jars['owner'], '/me/trail?member=30')['code'] === 200 && page($jars['bea'], '/me/trail?member=40')['code'] === 200, "another person's trail: 403 for Bea, 200 for the admin; an agent's to any reader");
$r = page($jars['ann'], '/search?q=flour');
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-empty"') && str_contains($r['body'], 'Nothing matches “flour”') && str_contains($r['body'], 'value="flour"'), 'search finds nothing gracefully (no catalog yet) and echoes the query');
$r = page($jars['ann'], '/search?q=' . rawurlencode('<b>x</b>'));
ok($r['code'] === 200 && !str_contains($r['body'], '<b>x</b>') && str_contains($r['body'], '&lt;b&gt;x&lt;/b&gt;'), 'the query is echoed escaped');
ok(str_contains(page($jars['ann'], '/search')['body'], 'id="search-hint"'), 'an empty q renders the box alone');
pdo()->exec("INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES ('SMOKE-FL', 'SMOKE Flour', (SELECT id FROM ingredient_categories WHERE key = 'dry'), 'weight', 'g', 'g')");
$d = json_decode(page($jars['ann'], '/search?q=smoke', $json)['body'], true)['data'];
ok(isset($d['groups']['ingredient']) && $d['groups']['ingredient'][0]['url'] === '/ingredients/' . $d['groups']['ingredient'][0]['id'], 'a seeded ingredient is found with its url through record_url()');
ok(str_contains(page($jars['ann'], '/search?q=smoke')['body'], 'id="search-group-ingredient"'), 'and grouped under Ingredients');

echo "6. The command bar goes through the kernel's chat endpoint\n";
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'What do we have of limes?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), "the utterance is answered with the kernel's reply");
$a = activity('assistant.ask', 0);
$la = json_decode((string) end($a)['after'], true);
ok($a !== [] && ($la['length'] ?? 0) === 25 && ($la['run_id'] ?? 0) === 1 && ($la['scope_id'] ?? 0) === DOWNTOWN && !str_contains((string) end($a)['after'], 'limes'), 'assistant.ask logs the length, the run id and the site, never the text');
$chatLines = array_values(array_filter(explode("\n", (string) file_get_contents(need('FAKE_KERNEL_STATE') . '.chat'))));
$chatLog = json_decode((string) end($chatLines), true);
ok(($chatLog['agent'] ?? '') === 'expert' && (int) ($chatLog['acting'] ?? 0) === 28, 'the kernel saw agent=expert and X-Acting-Member 28');
ok(req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'form' => ['utterance' => 'hi']])['code'] === 403, 'without the CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'No expert for this application.'), "the kernel answers 404: the bar shows the kernel's words");
kernel_state(function ($s) { unset($s['chat_status']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'The order is drafted; sending it waits for approval.',
    'actions' => [['tool' => 'purchase_order_send', 'status' => 'awaiting_approval', 'record_id' => 5], ['tool' => 'purchase_order_draft', 'status' => 'ok', 'record_id' => 5]], 'approval_request_id' => 77]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'order it', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'waits for approval') && str_contains($r['body'], 'request #77') && str_contains($r['headers'], 'HX-Trigger: purchase_orderChanged'), 'a paused action is shown as "waits for approval"; the succeeded one fires purchase_orderChanged');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening the recipes.', 'actions' => [], 'navigate' => '/recipes/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open the recipes', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/recipes/'), 'a navigate answer is followed (HX-Location to /recipes/)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
ok(req('POST', '/assistant/ask.php', ['jar' => $jars['bea'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]])['code'] === 422, 'an utterance over 2,000 characters: 422');

echo "7. Action tokens — the gate for the actions server; the site per request\n";
$r = req('GET', '/me/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(28)]]);
$rows = json_decode($r['body'], true)['data']['rows'] ?? [];
ok($r['code'] === 200 && $rows !== [] && $rows[0]['actor']['member_id'] === 28 && !str_contains($r['headers'], 'Set-Cookie'), "a person's action token acts as that member for one request (200, her rows, no Set-Cookie)");
ok(req('GET', '/me/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(28, -5)]])['code'] === 401, 'an expired action token: 401');
$r = req('POST', '/me/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(28)], 'form' => ['label' => 'by action token']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && str_starts_with($d['token'] ?? '', 'ri_') && ($d['record_id'] ?? 0) > 0 && str_ends_with((string) $d['location'], '#token-row-' . $d['record_id']), 'a write under the action token needs no CSRF token: JSON carries the token once, record_id and a location ending in the id');
$r = req('POST', '/me/settings/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(28)], 'form' => ['text_enabled' => 'yes']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && $d['did'] === 'Saved your settings', 'preferences_save under the action token answers from emit_action_status (ok, did)');
$r = req('GET', '/projection/stock', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(27), 'X-Site: 102']]);
$r2 = req('GET', '/counts/sheets/?site=102', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(27)]]);
ok($r['code'] === 501 && $r2['code'] === 403, "Marco under an action token: Projected stock at his first site is the placeholder (501); Count sheets with site=102 (Kitchen there) is 403");
ok(req('GET', '/counts/sheets/?site=101', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(27)]])['code'] === 501, 'and with site=101 (Manager) the placeholder answers 501');

echo "8. Cost is the wall\n";
ok(right_of(28, 'recipes.write') === false && right_of(30, 'recipes.write') === true, 'ri_has_right at Downtown: Dana writes recipes, Bea does not');
pdo()->exec("SELECT set_config('app.member_id', '28', false)");
ok(one('SELECT ri_sees_cost(101)') === false, 'Bea (Kitchen) does not see cost (kitchen_sees_cost off)');
pdo()->exec("UPDATE site_settings SET kitchen_sees_cost = true WHERE scope_id = 101");
ok(one('SELECT ri_sees_cost(101)') === true && json_decode(page($jars['bea'], '/me/settings', $json)['body'], true)['data']['sees_cost'] === true && str_contains(page($jars['bea'], '/')['body'], 'data-sees-cost="1"'), 'with the site setting on, Kitchen sees cost — the shell reads the same function and stamps data-sees-cost="1"');
pdo()->exec("UPDATE site_settings SET kitchen_sees_cost = false WHERE scope_id = 101");
ok(str_contains(page($jars['bea'], '/')['body'], 'data-sees-cost="0"') && str_contains(page($jars['dana'], '/')['body'], 'data-sees-cost="1"'), 'the shell stamps data-sees-cost on the body (0 for Bea, 1 for Dana)');

echo "9. The picker and health\n";
$r = page($jars['bea'], '/pick/?source=member&q=', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="record-picker-list"') && str_contains($r['body'], 'data-label="SMOKE Dana"'), 'the picker endpoint answers the proof source\'s rows');
ok(page($jars['bea'], '/pick/?source=member&q=dana')['code'] === 200 && !str_contains(page($jars['bea'], '/pick/?source=member&q=dana')['body'], 'SMOKE Owner'), 'and filters by the query');
ok(page($jars['bea'], '/pick/?source=nosuch')['code'] === 404 && req('GET', '/pick/?source=member')['code'] === 401, 'an unknown source 404; anonymous 401 (a fragment, never a redirect)');
$r = req('GET', '/api/v1/health');
$h = json_decode($r['body'], true);
ok($r['code'] === 200 && $h['ok'] === true && $h['application'] === 'restaurant_inventory' && $h['database'] === 'ok' && $h['storage'] === 'ok', 'GET /api/v1/health: ok, application restaurant_inventory, database ok, storage ok');
ok(array_key_exists('ingest_lag', $h) && is_array($h['directory']) && $h['directory']['synced'] === true && $h['directory']['error'] === null, 'it carries ingest_lag and the directory sync state (synced, no error)');
ok(req('POST', '/api/v1/health')['code'] === 405, 'POST to health: 405');
finish();
