<?php
/** Proof: the activity ingest bridge ships the log to the fake MaluDB tagged "restaurant_inventory" first, with the audit keys (scope_id, ingredient_id, recipe_id, purchase_order_id, count_id) where set, advances the checkpoint only past accepted rows, stops on a rejected row (sso-shell.md "Proof: ingest"). */
require __DIR__ . '/lib.php';
$py = getenv('RI_PY') ?: '/var/www/mcp/venv/bin/python';
$log = need('FAKE_MALUDB_LOG');
$run = fn (string $env = '') => trim((string) shell_exec($env . ' ' . escapeshellarg($py) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/mcp/activity_ingest.py') . ' 2>&1; echo "exit=$?"'));
$state = fn () => (int) one('SELECT last_id FROM activity_ingest_state WHERE id = 1');
$max = fn () => (int) one('SELECT max(id) FROM activity_log');
$lines = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
file_put_contents($log, '');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, scope_id, ingredient_id, recipe_id, after) VALUES (30, 'web', 'recipe.line_set', 'recipe_line', 77, 101, 5, 9, '{\"qty\": 170, \"unit_code\": \"g\"}'), (NULL, 'cron', 'worker.pass', NULL, NULL, NULL, NULL, NULL, '{\"pass\": \"outbox\"}')");
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($state() === 0 && $h['ingest_lag'] === $max() && $max() > 50, "before: checkpoint 0, health ingest_lag {$h['ingest_lag']} = {$max()} rows waiting");
$out = $run('MALUDB_API_TOKEN=wrong-token');
ok(str_contains($out, 'episode POST failed') && str_contains($out, 'exit=1') && $state() === 0 && $lines() === [], 'the API refuses (401): the run reports it, exits 1 and the checkpoint does NOT move');
$total = $max();
$out = $run();
$runs = 1;
while (preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[3] < $total && $runs < 12) { $out = $run(); $runs++; }
ok(preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[3] === $total && str_contains($out, 'exit=0'), "good runs ship every row ($runs run(s)): $out");
ok($state() === $total, "the checkpoint is at the last row ($total)");
$eps = $lines();
ok(count($eps) === $total, count($eps) . ' episodes reached the API, one per activity row');
ok(count(array_filter($eps, fn ($e) => ($e['kind'] ?? '') === 'activity' && ($e['payload']['application'] ?? '') === 'restaurant_inventory')) === $total && array_keys($eps[0]['payload'])[0] === 'application', 'every one is an activity episode whose payload STARTS with "application": "restaurant_inventory"');
$line = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'recipe.line_set'));
ok($line !== [] && ($line[0]['payload']['scope_id'] ?? 0) === 101 && ($line[0]['payload']['ingredient_id'] ?? 0) === 5 && ($line[0]['payload']['recipe_id'] ?? 0) === 9 && !array_key_exists('purchase_order_id', $line[0]['payload']), 'a recipe episode carries scope_id, ingredient_id and recipe_id (and not the null keys)');
$signOns = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'member.sign_on'));
ok($signOns !== [] && ($signOns[0]['payload']['scope_id'] ?? 0) > 0 && isset($signOns[0]['payload']['after']['capability']), 'a sign-on episode carries the site and its after.capability');
ok(preg_match('/ri_[0-9a-f]{48}/', file_get_contents($log)) === 0, 'no episode carries a token value');
$out = $run();
ok($out === 'exit=0' && count($lines()) === $total, 'a further run has nothing to do (no duplicates)');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($h['ingest_lag'] <= 1 && $h['maludb'] === 'ok', "health: ingest_lag {$h['ingest_lag']}, maludb ok");
sign_on(30);
$out = $run();
ok(preg_match('/shipped (\d+)\/(\d+)/', $out, $m) && (int) $m[1] >= 1 && $state() === $max(), 'new activity ships on the next run and the checkpoint follows');
finish();
