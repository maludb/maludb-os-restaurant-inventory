<?php
/** Proof: the deploy vhost's URL rules (deploy/apache-restaurant_inventory.conf). Meaningful under RI_APP=apache (a real Apache serves the rendered template; the internal vhost on 9307 answers the same); under php -S the same checks run against tests/dev_router.php, which mirrors the rewrites. */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(30);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) { ok(req('GET', $path)['code'] === $want, "GET $path → $want"); }
ok(page($j, '/me/settings')['code'] === 200 && page($j, '/me/tokens')['code'] === 200 && page($j, '/me/notifications')['code'] === 200 && page($j, '/me/trail')['code'] === 200, '/me/settings, /me/tokens, /me/notifications and /me/trail reach their files');
ok(page($j, '/recipes/')['code'] === 200 && page($j, '/recipes')['code'] === 200 && page($j, '/ingredients')['code'] === 200, "/recipes/ and /recipes reach recipes/index.php (the placeholder's 200); /ingredients too");
ok(page($j, '/stock/movements')['code'] === 200 && page($j, '/menu/engineering')['code'] === 200 && page($j, '/purchasing/suggested')['code'] === 200 && page($j, '/usage/cogs')['code'] === 200, '/stock/movements, /menu/engineering, /purchasing/suggested and /usage/cogs resolve to their own files');
ok(page($j, '/search')['code'] === 200 && page($j, '/switch-site')['code'] === 200, '/search and /switch-site resolve');
ok(page($j, '/admin/settings')['code'] === 403 && page($j, '/units/')['code'] === 403, '/admin/settings and /units/ resolve (403 for Dana, by the right)');
ok(page($j, '/ingredients/new')['code'] === 200 && page($j, '/ingredients/999999')['code'] === 404 && page($j, '/ingredients/999999/edit')['code'] === 404, '/ingredients/new reaches the form (slice 1); /ingredients/999999 and its /edit are 404 by the record');
ok(page($j, '/recipes/999999/cost')['code'] === 404 && page($j, '/recipes/999999/versions/1')['code'] === 404, '/recipes/999999/cost and /recipes/999999/versions/1 are rewritten to slice 2\'s cost history and editor: 404 by the record');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$tok = str_repeat('ab', 24);
ok(req('GET', "/s/$tok")['code'] === 404 && req('GET', "/s/$tok/acknowledge")['code'] === 404, "the supplier's door /s/{token} (and its actions) is rewritten to s.php (slice 4) and answers 404 (no handler yet)");
ok(req('GET', '/s/short')['code'] === 404, 'a door token of the wrong shape: 404');
ok(req('GET', '/files/1')['code'] === 401, '/files/1 without a session: 401 (never a redirect — a file URL sits in an <img>)');
ok(page($j, '/files/1')['code'] === 404, '/files/1 with a session: 404 (no attachment yet)');
ok(req('GET', '/pick/')['code'] === 401, '/pick/ anonymous: 401 (a fragment)');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'restaurant_inventory', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404 && req('GET', '/api/anything')['code'] === 404, 'anything else under /api is 404');
if (getenv('RI_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:9307/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'restaurant_inventory', 'the internal vhost on 9307 answers health the same');
    ok(req('GET', 'http://127.0.0.1:9307/recipes/', ['jar' => $j])['code'] === 200 && req('GET', 'http://127.0.0.1:9307/files/1')['code'] === 401, 'and the canonical rewrites and the attachment door');
    $hdr = req('GET', '/api/v1/health')['headers'];
    $mod = is_file('/etc/apache2/mods-enabled/headers.load');
    ok($mod === (stripos($hdr, 'X-Content-Type-Options') !== false), 'the Header lines are ' . ($mod ? 'present with' : 'absent without') . ' mod_headers (the guard holds)');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && str_contains(json_decode($m['body'], true)['name'] ?? '', 'Restaurant'), '/manifest.webmanifest is served as JSON and names the application');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
