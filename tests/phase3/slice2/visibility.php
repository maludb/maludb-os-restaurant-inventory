<?php
/** Proof: who sees what, deletion, JSON mode under action and run tokens, the expert as an agent (recipes.md "Proof": Who sees, JSON mode). */
require __DIR__ . '/lib.php';
$f = recipes_fixture();
[$jd, ] = sign_on(30); [$jb, ] = sign_on(28); [$ja, ] = sign_on(29); [$jo, ] = sign_on(1, ['scope' => 101]);
$since = last_activity_id();

echo "1. Who sees — Ann and Bea read, never cost; Bea with kitchen_sees_cost; the owner deletes\n";
foreach ([['Ann', $ja], ['Bea', $jb]] as [$who, $j]) {
    $r = page($j, '/recipes/' . $f['sandwich']);
    ok($r['code'] === 200 && has($r, 'id="recipe-lines-table"') && has($r, 'id="recipe-method"') && has($r, 'id="version-strip"') && !has($r, '<th class="text-end">Cost</th>') && !has($r, 'id="recipe-cost-portion"') && has($r, 'id="recipe-cost-withheld"') && !has($r, 'id="recipe-view-edit"'), "$who reads the lines, the method and the versions — no cost column, no Edit");
    ok(page($j, '/recipes/' . $f['sandwich'] . '/what-if')['code'] === 403 && page($j, '/recipes/' . $f['sandwich'] . '/cost')['code'] === 403 && page($j, '/menu/engineering')['code'] === 403, "$who: the what-if, the cost history and menu engineering are 403");
    ok(page($j, '/recipes/new')['code'] === 403 && post_json($j, '/recipes/save.php', ['name' => 'x', 'kind' => 'prep'])['code'] === 403 && post_json($j, '/menu/map.php', ['menu_item' => $f['plu1001']])['code'] === 403, "$who: no recipe form, no save, no map (403)");
}
$r = page($jb, '/recipes/');
ok($r['code'] === 200 && !preg_match('/recipe-card-\d+-cost">\s*<span class="fw-semibold">\$/', $r['body']), 'Bea\'s cards: no cost');
ri_sql('UPDATE site_settings SET kitchen_sees_cost = true WHERE scope_id = 101');
$r = page($jb, '/recipes/' . $f['sandwich']);
ok($r['code'] === 200 && has($r, '<th class="text-end">Cost</th>') && has($r, 'id="recipe-cost-portion"'), 'with kitchen_sees_cost on at Downtown, Bea sees the cost column');
ok(page($ja, '/recipes/' . $f['sandwich'])['code'] === 200 && !has(page($ja, '/recipes/' . $f['sandwich']), '<th class="text-end">Cost</th>'), 'Ann (Viewer) still does not');
ri_sql('UPDATE site_settings SET kitchen_sees_cost = false WHERE scope_id = 101');
$r = post_json($jd, '/recipes/save.php', ['name' => 'SMOKE Throwaway', 'kind' => 'menu_item']);
$throw = (int) $r['json']['record_id'];
ok(post_json($jd, '/recipes/delete.php', ['recipe' => $throw])['code'] === 403, 'a Chef may not delete (records.delete)');
$r = post_json($jo, '/recipes/delete.php', ['recipe' => $throw]);
ok($r['code'] === 200 && one('SELECT count(*) FROM recipes WHERE id = :r', ['r' => $throw]) === 0, 'the owner deletes a never-activated recipe');
$r = post_json($jo, '/recipes/delete.php', ['recipe' => $f['stock']]);
ok($r['code'] === 422 && str_contains($r['body'], 'activated'), 'and is refused on the stock in words (activated, used as a sub-recipe)');
$r = page($jo, '/recipes/' . $f['stock']);
ok(has($r, 'id="recipe-view-undeletable"') && !has($r, 'id="recipe-view-delete"'), "the stock's page says why it cannot be deleted");
ok(count(activity('recipe.delete', $since)) === 1, 'recipe.delete logged once');

echo "2. JSON mode under a person's action token — the contract\n";
$tok = person_token(30);
$h = ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Site: 101'];
$r = req('POST', '/recipes/save.php', ['headers' => $h, 'form' => ['name' => 'SMOKE Dictated soup', 'kind' => 'menu_item', 'lines' => json_encode([['ingredient' => 'SMK-STOCK-F', 'qty' => 300, 'unit' => 'ml'], ['ingredient' => $f['egg'], 'qty' => 1, 'unit' => 'each']])]]);
$j = json_decode($r['body'], true);
ok($r['code'] === 200 && ($j['ok'] ?? false) === true && isset($j['did'], $j['record_id'], $j['location'], $j['refresh']) && $j['refresh'] === 'recipeChanged', 'recipe_create answers {ok, did, record_id, location, refresh}');
ok((int) one('SELECT count(*) FROM recipe_lines WHERE version_id = :v', ['v' => (int) $j['version_id']]) === 2 && (float) one('SELECT qty_base FROM recipe_lines WHERE version_id = :v AND seq = 1', ['v' => (int) $j['version_id']]) === 300.0, "the dictation route: lines JSON makes the draft's lines in one POST, each in base (an ingredient by its code)");
$dict = (int) $j['record_id'];
$r = req('POST', '/recipes/versions/line.php', ['headers' => $h, 'form' => ['version' => (int) $j['version_id'], 'ingredient' => $f['chicken'], 'qty' => 0.5, 'unit' => 'lb']]);
$j2 = json_decode($r['body'], true);
ok($r['code'] === 200 && near((float) one('SELECT qty_base FROM recipe_lines WHERE id = :l', ['l' => (int) $j2['record_id']]), 226.8, 0.01), 'a line with qty + unit lands in base (0.5 lb = 226.8 g)');
$r = req('POST', '/recipes/versions/line.php', ['headers' => $h, 'form' => ['version' => (int) $j['version_id'], 'ingredient' => $f['chicken'], 'qty' => 1, 'unit' => 'cup']]);
$j3 = json_decode($r['body'], true);
ok($r['code'] === 422 && str_contains((string) ($j3['error']['message'] ?? ''), 'density'), '422 names the schema\'s sentence (chicken has no density for a cup)');
$r = req('POST', '/recipes/versions/line.php', ['headers' => $h, 'form' => ['version' => (int) $j['version_id'], 'ingredient' => $f['chicken'], 'unit' => 'g']]);
$j3 = json_decode($r['body'], true);
ok($r['code'] === 422 && isset($j3['error']['fields']['qty']), '422 naming the field (qty)');
ok(req('GET', '/recipes/' . $dict, ['headers' => $h])['code'] === 200 && isset(json_decode(req('GET', '/recipes/' . $dict, ['headers' => $h])['body'], true)['data']['recipe']), 'the recipe\'s page answers JSON');
$r = req('GET', '/recipes/?kind=menu_item', ['headers' => $h]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['recipes']), 'the list answers JSON');
$r = req('POST', '/recipes/save.php', ['headers' => $h, 'form' => ['recipe' => $dict, 'name' => 'SMOKE Dictated soup v2', '_partial' => '1']]);
ok($r['code'] === 200 && one('SELECT kind FROM recipes WHERE id = :r', ['r' => $dict]) === 'menu_item' && one('SELECT name FROM recipes WHERE id = :r', ['r' => $dict]) === 'SMOKE Dictated soup v2', 'a partial update keeps the fields not sent');
$r = req('GET', '/recipes/versions/' . (int) $j['version_id'] . '/cost', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(29), 'X-Site: 101']]);
ok($r['code'] === 200 && json_decode($r['body'], true)['withheld'] === true, "a Viewer's action token: the cost is withheld");

echo "3. The expert (run token + relay) as an agent\n";
$tok = run_token(40, 777);
kernel_state(function ($s) { $s['facts']['777'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 777, 'request_id' => 'req-run-777', 'trigger' => 'chat', 'scope' => 101, 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$ha = as_agent($tok);
$ha[] = 'X-Site: 101';
$r = req('POST', '/recipes/save.php', ['headers' => $ha, 'form' => ['name' => 'SMOKE Agent salad', 'kind' => 'menu_item', 'lines' => json_encode([['ingredient' => $f['lettuce'], 'qty' => 60, 'unit' => 'g']])]]);
$j = json_decode($r['body'], true);
ok($r['code'] === 200 && ($j['ok'] ?? false) === true, 'the expert creates a recipe with lines (' . $r['code'] . ')');
$agentRecipe = (int) ($j['record_id'] ?? 0);
$row = q("SELECT source, actor_member_id, agent_run_id FROM activity_log WHERE action = 'recipe.create' AND entity_id = :id", ['id' => $agentRecipe])[0] ?? null;
ok($row && $row['source'] === 'agent' && (int) $row['actor_member_id'] === 40 && (int) $row['agent_run_id'] === 777, 'logged as source agent, actor 40, the run');
$r = req('POST', '/menu/map.php', ['headers' => $ha, 'form' => ['menu_item' => '2002', 'recipe' => $agentRecipe]]);
ok($r['code'] === 200 && (int) one("SELECT recipe_id FROM menu_items WHERE plu = '2002'") === $agentRecipe, 'and maps a PLU (by its PLU)');
$r = req('POST', '/recipes/delete.php', ['headers' => $ha, 'form' => ['recipe' => $agentRecipe]]);
ok($r['code'] === 403, 'is refused recipe_delete (a Chef holds no records.delete)');
$r = req('POST', '/recipes/versions/activate.php', ['headers' => $ha, 'form' => ['version' => (int) $j['version_id']]]);
ok($r['code'] === 200 && one('SELECT status FROM recipe_versions WHERE id = :v', ['v' => (int) $j['version_id']]) === 'active', "its recipe_version_activate lands here (the pause is the kernel's hook — Phase 4)");
$r = req('GET', '/recipes/' . $agentRecipe, ['headers' => $ha]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['cost']['portion_cost']), 'the expert (Chef) reads the cost as JSON');
kernel_state(function ($s) { $s['facts']['778'] = ['valid' => false]; return $s; });
ok(req('POST', '/recipes/save.php', ['headers' => as_agent(run_token(41, 778)), 'form' => ['name' => 'x', 'kind' => 'prep']])['code'] === 401 && one('SELECT capability FROM members WHERE id = 41') === null, 'a run the kernel does not vouch for (the Watcher, 41): 401, no admission');
finish();
