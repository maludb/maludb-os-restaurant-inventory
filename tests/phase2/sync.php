<?php
/** Proof: the directory sync (bin/directory_sync.php) against the fake kernel — the full pass applies the fixture (members, departments, sites, holdings), the scopes read first, the incremental pass advances the cursor, a role change at a site reaches the badge, access withdrawn ends sessions, a department delivered and a member admitted appear, a failing kernel is reported (sso-shell.md "Proof: sync"). */
require __DIR__ . '/lib.php';
$state = fn () => q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$L = 'https://app.example.invalid/launcher?app=restaurant_inventory';

echo "1. The full pass\n";
ok((int) one('SELECT count(*) FROM members WHERE capability IS NOT NULL AND id IN (1, 26, 27, 28, 29, 30, 31, 40, 42, 43)') === 10 && (int) one('SELECT count(*) FROM departments') === 3, 'the run.sh full pass admitted the ten fixture members with a grant and the three departments');
$expected = 0; foreach (fixture()['feed']['access'] as $a) { $expected += count($a['scopes'] ?? []); }
ok((int) one('SELECT count(*) FROM sites WHERE removed_at IS NULL') === 2 && (int) one('SELECT count(*) FROM member_site_roles WHERE member_id <= 43') === $expected, "two sites and the fixture's $expected holdings landed");
ok(q('SELECT role_key FROM member_site_roles WHERE member_id = 27 AND scope_id = 101')[0]['role_key'] === 'manager' && q('SELECT role_key FROM member_site_roles WHERE member_id = 27 AND scope_id = 102')[0]['role_key'] === 'user' && q('SELECT capability FROM members WHERE id = 32')[0]['capability'] === 'write' && (int) one('SELECT count(*) FROM member_site_roles WHERE member_id = 32') === 0, 'Marco manager at Downtown and user at Airport; Nico has a capability but no site');
$st = $state();
ok($st['full_at'] !== null && $st['next_cursor'] !== null && $st['last_error'] === null && $st['scopes_at'] !== null, 'the sync state records the full pass (full_at, a cursor, no error) and the scopes read (scopes_at)');
$out = sync('--full');
ok(str_contains($out, '(full)'), "running --full again changes nothing: $out");
ok(count(activity('directory.sync', 0)) >= 1 && activity('directory.sync', 0)[0]['source'] === 'cron', 'directory.sync is logged (source cron)');

echo "2. The incremental pass advances the cursor\n";
kernel_state(function ($s) { $s['feed']['next'] = '2026-03-01T00:00:00.000000Z'; unset($s['incremental']); return $s; });
$before = $state()['next_cursor'];
$out = sync();
ok(str_contains($out, 'applied 0 members') && !str_contains($out, '(full)'), "no change: $out");
ok($state()['next_cursor'] !== $before && str_starts_with($state()['next_cursor'], '2026-03-01'), "the cursor moved to the kernel's next (" . $state()['next_cursor'] . ')');

echo "3. A role change at a site reaches the badge in one pass\n";
[$js, ] = sign_on(28);
ok(badge_of($js) === 'Kitchen' && page($js, '/modifiers/')['code'] === 403, 'Bea is Kitchen (Modifiers 403)');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(28, [[101, 'chef', ['chef'], 'write']], 'write')]], '2026-03-02T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 holdings') && q('SELECT role_key FROM member_site_roles WHERE member_id = 28')[0]['role_key'] === 'chef', "one holding applied, chef at Downtown: $out");
ok(badge_of($js) === 'Chef' && right_of(28, 'recipes.write') === true && page($js, '/modifiers/')['code'] === 200, 'her open session wears Chef at once and opens Modifiers — no new sign-on needed');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(28, [[101, 'admin', ['admin'], 'admin']], 'admin')]], '2026-03-02T00:01:00.000000Z'); return $s; });
sync();
ok(badge_of($js) === 'Restaurant Inventory admin' && page($js, '/admin/settings')['code'] === 200 && str_contains(page($js, '/')['body'], 'id="nav-group-admin"'), 'made an admin by the feed: the badge, the Admin group and the admin screens follow');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(28, [[101, 'user', [], 'write']], 'write')]], '2026-03-03T00:00:00.000000Z'); return $s; });
sync();
ok(badge_of($js) === 'Kitchen' && page($js, '/admin/settings')['code'] === 403, 'and back to Kitchen: admin settings 403 again');

echo "4. Access withdrawn ends the member's sessions in the same pass\n";
[$jv, ] = sign_on(31);
ok(page($jv, '/')['code'] === 200, 'Lee is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(31, [], null)]], '2026-03-04T00:00:00.000000Z'); return $s; });
sync();
$r = page($jv, '/');
ok($r['code'] === 302 && $r['location'] === $L && (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 31 AND ended_at IS NULL') === 0, 'his next request goes to the launcher; no live session left');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 31 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "ended_by = 'directory'");
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [access_row(31, [[102, 'viewer', [], 'read']], 'read')]], '2026-03-05T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT capability FROM members WHERE id = 31')[0]['capability'] === 'read', 'granted again for the later proofs');

echo "5. A suspended member's sessions end in the same pass\n";
[$jw, ] = sign_on(26);
ok(page($jw, '/')['code'] === 200, 'Priya is signed in');
kernel_state(function ($s) { $m = fixture()['feed']['members'][1]; $m['status'] = 'suspended'; $m['updated_at'] = '2026-03-06T00:00:00Z'; $s['incremental'] = incr(['members' => [$m]], '2026-03-06T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 members'), "the member row applied: $out");
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 26 AND ended_at IS NULL') === 0 && page($jw, '/')['code'] === 302 && q('SELECT status FROM members WHERE id = 26')[0]['status'] === 'inactive', 'in that pass: her sessions ended; the mirror says inactive');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [fixture()['feed']['members'][1]]], '2026-03-06T00:01:00.000000Z'); return $s; });
sync();
ok(q('SELECT status FROM members WHERE id = 26')[0]['status'] === 'active', 'restored');

echo "6. A department delivered and a member admitted appear in the mirror\n";
kernel_state(function ($s) { $s['incremental'] = incr(['departments' => [['id' => 12, 'name' => 'SMOKE Bar', 'description' => null, 'parent_id' => 1, 'manager_member_id' => null, 'is_system' => false, 'system_key' => null, 'archived_at' => null, 'updated_at' => '2026-03-07T00:00:00Z']]], '2026-03-07T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 departments') && (int) one("SELECT count(*) FROM departments WHERE id = 12 AND name = 'SMOKE Bar'") === 1, "a department delivered → in the mirror: $out");
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 47, 'member_kind' => 'human', 'display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active',
    'job_title' => 'Bartender', 'timezone' => 'America/Chicago', 'updated_at' => '2026-03-08T00:00:00Z', 'departments' => [['id' => 12, 'name' => 'SMOKE Bar', 'is_admin' => false, 'is_primary' => true]]]],
    'memberships' => [['member_id' => 47, 'department_id' => 12, 'is_admin' => false, 'is_primary' => true, 'joined_at' => '2026-03-08T00:00:00Z', 'left_at' => null]],
    'access' => [access_row(47, [[102, 'user', ['user'], 'write']], 'write')]], '2026-03-08T00:00:00.000000Z'); return $s; });
$out = sync();
ok(q('SELECT capability, timezone FROM members WHERE id = 47')[0] === ['capability' => 'write', 'timezone' => 'America/Chicago'] && q('SELECT role_key FROM member_site_roles WHERE member_id = 47')[0]['role_key'] === 'user', "a new member with a holding is admitted, with the directory's time zone: $out");
[$jn, ] = sign_on(47, ['claims' => ['display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [], 'capability' => 'write', 'role' => 'user', 'roles' => ['user'], 'rights' => [],
    'scopes' => [['scope_id' => 102, 'kind' => 'location', 'id' => 11, 'name' => 'SMOKE Airport', 'role' => 'user', 'roles' => ['user'], 'capability' => 'write']], 'scope' => null]]);
ok(badge_of($jn) === 'Kitchen' && site_of($jn) === 'SMOKE Airport' && str_contains(page($jn, '/me/settings')['body'], 'id="settings-timezone-value">America/Chicago<'), "Noor signs on as Kitchen at Airport and her settings show the directory's time zone");

echo "7. A fixture (--from-file) and a kernel that fails\n";
$out = sync('--from-file ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/dev_directory.json'));
ok(str_contains($out, '(full)') && str_contains($out, '12 members') && str_contains($out, '2 sites'), "--from-file applies the fixture's feed: $out");
$out = (string) shell_exec('OS_INTERNAL_URL=http://127.0.0.1:1 php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' 2>&1; echo "exit=$?"');
ok(str_contains($out, 'the kernel did not answer') && str_contains($out, 'exit=1'), 'an unreachable kernel: the run says so and exits 1');
ok(($state()['last_error'] ?? '') !== '' && $state()['last_error'] !== null, 'and directory_sync_state.last_error holds the reason');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['directory']['error'] ?? '') !== '', 'health reports it: directory.error');
kernel_state(function ($s) { $s['incremental'] = incr([], '2026-03-09T00:00:00.000000Z'); return $s; });
sync();
ok($state()['last_error'] === null && str_starts_with($state()['next_cursor'], '2026-03-09'), 'the next good pass clears the error and moves the cursor');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
