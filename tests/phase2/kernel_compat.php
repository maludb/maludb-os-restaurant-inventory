<?php
/** Proof: this application speaks the kernel's REAL wire formats (its own minting functions, read from /var/www/app/auth.php), the run-facts gate (valid:false, the kernel down), the run token with the relay acting as the expert at the site given, the approval replay, /sso/logout 204 on a bad notice, the application switcher (sso-shell.md "Proof: kernel_compat"). */
require __DIR__ . '/lib.php';
$src = file_get_contents('/var/www/app/auth.php');
$grab = function (string $name) use ($src): string {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $src, $m)) { fwrite(STDERR, "cannot find $name in the kernel's app/auth.php\n"); exit(2); }
    return $m[0];
};
if (!function_exists('env')) { function env(string $k, ?string $d = null): ?string { $v = getenv($k); return $v === false ? $d : $v; } }
$code = '';
foreach (['base64url_encode', 'mint_sso_token', 'sign_sso_claims', 'mint_sso_logout_notice', 'mint_action_token', 'mint_kernel_token', 'action_token_key'] as $fn) {
    $code .= preg_replace('/^function ' . $fn . '\(/m', 'function kernel_' . $fn . '(', $grab($fn)) . "\n";
}
$code = preg_replace('/\b(base64url_encode|mint_sso_token|sign_sso_claims|mint_sso_logout_notice|mint_action_token|mint_kernel_token|action_token_key)\(/', 'kernel_$1(', $code);
$code = preg_replace('/function kernel_kernel_/', 'function kernel_', $code);
eval($code);

echo "The kernel mints; the application accepts\n";
$claims = ['member_id' => 30, 'display_name' => 'Kernel Minted', 'email' => 'km@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [],
    'capability' => 'write', 'role' => 'chef', 'roles' => ['chef'], 'rights' => [], 'scopes' => [['scope_id' => 101, 'kind' => 'location', 'id' => 10, 'name' => 'SMOKE Downtown', 'role' => 'chef', 'roles' => ['chef'], 'capability' => 'write']], 'scope' => 101];
$url = '/sso?' . http_build_query(['token' => kernel_mint_sso_token(30, APP, 60), 'claims' => kernel_sign_sso_claims($claims)]);
$j = jar();
$r = req('GET', $url, ['jar' => $j]);
ok($r['code'] === 302 && $r['location'] === '/', "a token and claims minted by the kernel's mint_sso_token() and sign_sso_claims(): 302 to /");
ok(q('SELECT display_name FROM members WHERE id = 30')[0]['display_name'] === 'Kernel Minted' && badge_of($j) === 'Chef' && site_of($j) === 'SMOKE Downtown', 'the mirror follows the claims: the name; Chef at Downtown');
$r = req('GET', '/sso?' . http_build_query(['token' => kernel_mint_sso_token(30, 'hr', 60), 'claims' => kernel_sign_sso_claims($claims)]), ['jar' => jar()]);
ok($r['code'] === 403, "the kernel's token for another application: 403");
$notice = kernel_mint_sso_logout_notice(30, APP);
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice]]);
ok($r['code'] === 204 && page($j, '/')['code'] === 302, "the kernel's mint_sso_logout_notice(): 204 and the session ended");
ok(req('POST', '/sso/logout', ['form' => ['notice' => 'not.a.notice']])['code'] === 204, '/sso/logout answers 204 on a bad notice too');
$t = kernel_mint_action_token(28, 300);
ok(req('GET', '/me/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $t]])['code'] === 200, "the kernel's mint_action_token() is honoured as a person's action token (200)");
$kt = kernel_mint_kernel_token(APP, 60);
ok(count(explode('.', $kt)) === 5 && explode('.', $kt)[0] === 'kernel', 'the kernel token has the shape the records MCP will verify in Phase 4');
sign_on(30);

echo "The run-facts gate, the site per request and the approval replay\n";
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 901, 'member_kind' => 'agent', 'display_name' => 'SMOKE Other agent', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]]], '2026-01-01T00:05:00.000000Z'); return $s; });
sync();
$tok = run_token(901, 88);
kernel_state(function ($s) { $s['facts']['88'] = ['valid' => false]; return $s; });
ok(req('GET', '/me/trail', ['headers' => as_agent($tok)])['code'] === 401 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === null, 'run-facts valid:false → refused, no admission');
$pid = (int) file_get_contents(need('RI_DEV_STATE') . '/kernel.pid');
posix_kill($pid, 15);
usleep(400000);
kernel_state(function ($s) { $s['facts']['88'] = ['valid' => true, 'is_agent' => true, 'member_id' => 901, 'run_id' => 88, 'request_id' => 'req-run-88', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
ok(req('GET', '/me/trail', ['headers' => as_agent($tok)])['code'] === 401 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === null, 'the kernel down → refused (fail closed), no admission');
$cmd = 'sh -c ' . escapeshellarg('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && exec php -S 127.0.0.1:' . (getenv('P_KERNEL') ?: '9302') . ' tests/fake_kernel.php >' . escapeshellarg(need('RI_DEV_STATE') . '/kernel.log') . ' 2>&1') . ' & echo $!';
file_put_contents(need('RI_DEV_STATE') . '/kernel.pid', trim((string) shell_exec($cmd)));
for ($i = 0; $i < 30; $i++) { usleep(200000); if (req('GET', 'http://127.0.0.1:' . (getenv('P_KERNEL') ?: '9302') . '/api/v1/runs/facts.php')['code'] > 0) { break; } }
ok(req('GET', '/me/trail', ['headers' => as_agent($tok)])['code'] === 200 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === 'write', 'the kernel back and vouching → 200, admitted');
// the expert (40, Chef at both sites) with a run token: source agent, run id, the site given
$tok2 = run_token(40, 89);
kernel_state(function ($s) { $s['facts']['89'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 89, 'request_id' => 'req-run-89', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
$r = req('GET', '/search?q=x&site=102', ['headers' => array_merge(as_agent($tok2), ['X-Screen-View: 1'])]);
$row = q("SELECT source, agent_run_id, request_id, scope_id FROM activity_log WHERE action = 'screen.view' AND actor_member_id = 40 AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok($r['code'] === 200 && ($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 89 && ($row['request_id'] ?? '') === 'req-run-89' && (int) ($row['scope_id'] ?? 0) === 102, "the expert's rows are source agent, run 89, the run's request id, at the site given (102)");
$since = last_activity_id();
req('GET', '/search?q=x', ['headers' => array_merge(as_agent($tok2), ['X-Screen-View: 1'])]);
$row = q("SELECT scope_id FROM activity_log WHERE action = 'screen.view' AND actor_member_id = 40 AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok((int) ($row['scope_id'] ?? 0) === 102, 'and at its first held site by name (Airport, 102) when none is given');
$replayTok = run_token(28, 90, 120);
kernel_state(function ($s) { $s['facts']['90'] = ['valid' => true, 'is_agent' => false, 'member_id' => 28, 'run_id' => 90, 'request_id' => 'req-run-90', 'trigger' => 'manual', 'endpoints' => []]; return $s; });
$r = req('POST', '/me/settings/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $replayTok, 'X-Approval-Replay: 1'], 'form' => ['email_enabled' => 'no']]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true, 'an approval replay (the requester\'s token without the relay + X-Approval-Replay) acts as the requester — Bea — for one request');
ok(req('POST', '/me/settings/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $replayTok], 'form' => ['email_enabled' => 'yes']])['code'] === 401, 'the same token without the replay header and without the relay: 401');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });

echo "The application switcher\n";
[$j, ] = sign_on(30);
ok(!str_contains(page($j, '/')['body'], 'id="header-apps-toggle"'), 'with no apps/mine route at the kernel the switcher renders nothing');
kernel_state(function ($s) { $s['apps'] = ['schema' => 'os.my-applications/1', 'launcher_url' => 'https://app.example.invalid/launcher', 'applications' => [
    ['id' => 1, 'key' => 'helpdesk', 'name' => 'Help Desk', 'icon' => 'feather-life-buoy', 'launch_path' => '/launch/1', 'current' => false],
    ['id' => 2, 'key' => 'restaurant_inventory', 'name' => 'Restaurant Inventory', 'icon' => 'feather-coffee', 'launch_path' => '/launch/2', 'current' => true, 'scopes' => [['id' => 101, 'name' => 'SMOKE Downtown', 'launch_path' => '/launch/2?scope=101']]]]]; return $s; });
[$j, ] = sign_on(30);
$h = page($j, '/')['body'];
ok(str_contains($h, 'id="header-apps-toggle"') && str_contains($h, 'id="header-helpdesk-btn"') && str_contains($h, 'id="header-apps-item-1"') && str_contains($h, 'aria-current="page"'), "the switcher renders the fake kernel's rows: the Helpdesk button, the list, this application marked current");
kernel_state(function ($s) { unset($s['apps']); return $s; });
finish();
