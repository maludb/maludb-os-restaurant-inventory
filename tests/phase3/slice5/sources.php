<?php
/** Slice 5 — sources (pos.md "Proof": Sources). The admin makes the four sources at Downtown through the handlers; the refusals; pause, schedule, delete. */
require __DIR__ . '/lib.php';
$w = pos_world();
$admin = at(1, DOWNTOWN);
$dana = at(30, DOWNTOWN);
$ann = at(29, DOWNTOWN);
$since = last_activity_id();

echo "1. Four sources by the admin\n";
$r = page($admin, '/sales/sources/');
ok($r['code'] === 200 && has($r, 'id="source-list-empty"'), 'the empty list answers 200 with its empty state');
$r = page($admin, '/sales/sources/new');
ok($r['code'] === 200 && has($r, 'id="source-form-file"') && has($r, 'id="source-form-square"') && has($r, 'Toast — Extended'), 'the form renders both sub-forms and lists Toast disabled "Extended"');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE Toast export', 'connector' => 'file', 'settings_mapping_key' => 'toast_items', 'settings_intake' => 'upload']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && (int) $r['json']['record_id'] === source_id('SMOKE Toast export'), 'the Toast export (file, toast_items, by upload) is made (' . $r['code'] . ')');
$toast = source_id('SMOKE Toast export');
$row = q('SELECT scope_id, connector, settings, schedule_minutes FROM sources WHERE id = :i', ['i' => $toast])[0];
$set = json_decode($row['settings'], true);
ok((int) $row['scope_id'] === DOWNTOWN && $set['mapping_key'] === 'toast_items' && $set['intake'] === 'upload' && (int) $row['schedule_minutes'] === 0, 'at Downtown, mapping toast_items, intake upload, on arrival (0)');
$log = activity('source.create', $since);
ok(count($log) === 1 && (int) $log[0]['scope_id'] === DOWNTOWN && (int) $log[0]['entity_id'] === $toast && json_decode($log[0]['after'], true)['connector'] === 'file', 'source.create logged with the site');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE Nightly mail', 'connector' => 'file', 'settings_mapping_key' => 'toast_items', 'settings_intake' => 'email', 'settings_email_from' => 'pos@smoke.invalid']);
ok($r['code'] === 200, 'the Nightly mail (file, intake email, from pos@smoke.invalid) is made');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE SFTP', 'connector' => 'file', 'settings_mapping_key' => 'generic', 'settings_intake' => 'sftp', 'settings_sftp_host' => 'sftp.smoke.invalid',
    'settings_sftp_path' => '/exports', 'settings_sftp_pattern' => 'sales_*.csv', 'settings_sftp_local_dir' => FIX . '/sftp']);
$sftp = source_id('SMOKE SFTP');
$s = json_decode((string) one('SELECT settings FROM sources WHERE id = :i', ['i' => $sftp]), true);
ok($r['code'] === 200 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $sftp]) === 60 && $s['sftp']['host'] === 'sftp.smoke.invalid' && $s['sftp']['local_dir'] === FIX . '/sftp', 'the SFTP source: every 60 min by default, its host and the fixture folder');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE Square', 'connector' => 'square', 'settings_location_id' => 'L-SMOKE', 'base_url' => 'http://127.0.0.1:' . (getenv('P_FIX') ?: '9323')]);
$square = source_id('SMOKE Square');
ok($r['code'] === 200 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $square]) === 60 && json_decode((string) one('SELECT settings FROM sources WHERE id = :i', ['i' => $square]), true)['location_id'] === 'L-SMOKE', 'the Square source: location L-SMOKE, every 60 min');

echo "2. Refusals\n";
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE Toast export', 'connector' => 'file', 'settings_intake' => 'upload']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'already has a source called SMOKE Toast export'), 'a second "SMOKE Toast export" at the site → 422 in words');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T2', 'connector' => 'toast']);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'Extended'), 'toast as a connector → 422 "Extended"');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T3', 'connector' => 'carrier_pigeon']);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'no connector called carrier_pigeon'), 'a connector not admitted → 422');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T4', 'connector' => 'file', 'settings_intake' => 'sftp', 'settings_sftp_path' => '/x']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['settings_sftp_host']), 'sftp with no host → 422 naming the host');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T5', 'connector' => 'file', 'settings_intake' => 'email']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['settings_email_from']), 'email with no sender → 422 naming it');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T6', 'connector' => 'square']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['settings_location_id']), 'Square with no location → 422 naming it');
$r = post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE T7', 'connector' => 'file', 'settings_mapping_key' => 'nope']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['settings_mapping_key']), 'an unknown mapping → 422');
ok(post_json($dana, '/sales/sources/save.php', ['name' => 'SMOKE Dana', 'connector' => 'file'])['code'] === 403, 'Dana (Chef) cannot make a source: 403');
ok(post_json($ann, '/sales/sources/save.php', ['name' => 'SMOKE Ann', 'connector' => 'file'])['code'] === 403, 'Ann (Viewer) cannot: 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['source_create']['approval'] ?? null) === 'other' && ($reg['source_delete']['approval'] ?? null) === 'deletion' && ($reg['source_credential_set']['approval'] ?? null) === 'other' && ($reg['source_schedule_set']['approval'] ?? null) === 'other', "an agent's source_create / credential / schedule pause as other, a delete as deletion (the kernel's hook — the registry says so)");

echo "3. The screens and the walls\n";
$r = page($admin, '/sales/sources/');
ok($r['code'] === 200 && has($r, 'id="source-card-' . $toast . '"') && has($r, 'id="source-card-' . $square . '"') && substr_count($r['body'], 'class="card h-100 record-card"') === 4, 'the list shows the four cards');
$r = page($admin, '/sales/sources/' . $sftp);
ok($r['code'] === 200 && has($r, 'id="source-view-settings-json"') && has($r, 'sftp.smoke.invalid') && has($r, 'id="source-view-pull"'), "the admin sees the SFTP source's settings as JSON and Pull now");
$r = req('GET', '/sales/sources/' . $sftp, ['jar' => $dana, 'headers' => ['Accept: application/json']]);
$j = json_decode($r['body'], true)['data']['source'] ?? [];
ok($r['code'] === 200 && array_key_exists('settings', $j) && $j['settings'] === null && $j['intake'] === 'sftp', "Dana reads the source with its settings null (mcp_sources' wall) and its intake");
$r = page($dana, '/sales/sources/' . $sftp);
ok($r['code'] === 200 && has($r, 'id="source-view-settings-summary"') && !has($r, 'sftp.smoke.invalid') && has($r, 'id="source-view-pull"'), 'her page shows the summary, never the host; she may pull (purchasing.draft)');
$r = page($ann, '/sales/sources/' . $sftp);
ok($r['code'] === 200 && !has($r, 'id="source-view-pull"') && !has($r, 'id="source-view-edit"'), 'Ann reads it with no Pull, no Edit');
ok(page($admin, '/sales/sources/' . $toast . '/edit')['code'] === 200 && page($dana, '/sales/sources/' . $toast . '/edit')['code'] === 403, 'the edit form: the admin 200, Dana 403');
ok(page($dana, '/sales/sources/')['code'] === 403 && page($ann, '/sales/sources/')['code'] === 403 && page(at(28, DOWNTOWN), '/sales/sources/')['code'] === 403, "the list is the admin's (the menu's gate, settings.manage): Dana, Bea and Ann 403");
ok(preg_match('#<a[^>]+href="/sales/sources/"#', page($dana, '/sales/sources/' . $sftp)['body']) === 0 && preg_match('#<a[^>]+href="/sales/sources/"#', page($admin, '/sales/sources/' . $sftp)['body']) === 1, "the source's crumb links the list only for the admin");

echo "4. Update, schedule, pause, delete\n";
$r = post_json($admin, '/sales/sources/save.php', ['source' => (string) $sftp, '_partial' => 'yes', 'schedule_minutes' => '30']);
$s = json_decode((string) one('SELECT settings FROM sources WHERE id = :i', ['i' => $sftp]), true);
ok($r['code'] === 200 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $sftp]) === 30 && ($s['sftp']['host'] ?? '') === 'sftp.smoke.invalid' && ($s['sftp']['path'] ?? '') === '/exports', '_partial=1 with only the schedule keeps settings.sftp');
$d = activity('source.update', $since);
ok($d !== [] && json_decode(end($d)['after'], true) === ['schedule_minutes' => 30], 'source.update logs only what changed');
$r = post_json($admin, '/sales/sources/schedule.php', ['source' => (string) $sftp, 'schedule_minutes' => '60']);
ok($r['code'] === 200 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $sftp]) === 60 && activity('source.schedule_set', $since) !== [], 'source_schedule_set back to 60, logged');
ok(post_json($admin, '/sales/sources/schedule.php', ['source' => (string) $sftp])['code'] === 422, 'a schedule with no minutes → 422');
$r = post_json($admin, '/sales/sources/pause.php', ['source' => (string) $toast, 'paused' => 'yes', 'reason' => 'SMOKE closed for a refit']);
ok($r['code'] === 200 && one('SELECT paused_reason FROM sources WHERE id = :i', ['i' => $toast]) === 'SMOKE closed for a refit', 'paused with a reason');
ok(has(page($admin, '/sales/sources/'), 'id="source-card-' . $toast . '-state">paused'), 'its card says paused');
$r = post_json($admin, '/sales/sources/pause.php', ['source' => (string) $toast, 'paused' => 'no']);
ok($r['code'] === 200 && one('SELECT paused_at FROM sources WHERE id = :i', ['i' => $toast]) === null, 'resumed');
post_json($admin, '/sales/sources/save.php', ['name' => 'SMOKE Temp', 'connector' => 'file']);
$tmp = source_id('SMOKE Temp');
ok(post_json($dana, '/sales/sources/delete.php', ['source' => (string) $tmp])['code'] === 403, 'Dana cannot delete (records.delete)');
$r = post_json($admin, '/sales/sources/delete.php', ['source' => (string) $tmp]);
ok($r['code'] === 200 && one('SELECT count(*) FROM sources WHERE id = :i', ['i' => $tmp]) == 0 && activity('source.delete', $since) !== [], 'a source with no sales is deleted, logged');
ok(post_json($admin, '/sales/sources/save.php', ['source' => '999999', 'name' => 'x'])['code'] === 404, 'an unknown source → 404');
// map the Downtown sources' PLUs for the proofs that follow (slice 2's map, by SQL)
foreach ([$toast, source_id('SMOKE Nightly mail'), $sftp, $square] as $sid) { map_source($sid); }
ok((int) one("SELECT count(*) FROM menu_items WHERE pos_source_id = :s AND recipe_id IS NOT NULL", ['s' => $toast]) === 2, "the Toast export's PLUs 1001 and 3001 are mapped (slice 2's map)");
finish();
