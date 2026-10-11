<?php
/** Slice 6 — bottles and kegs (counts.md "Proof": Containers): the gin bottle, a refused weight, one default per ingredient, edit, walls. */
require __DIR__ . '/lib.php';
$w = count_world();
$bea = person(28); $dana = person(30); $ann = person(29);

echo "1. The screen\n";
$r = page($dana, '/counts/containers/');
ok($r['code'] === 200 && has($r, 'data-screen="container-list"') && has($r, 'id="container-form"') && has($r, 'id="container-form-field-full_weight_g"') && has($r, 'id="container-list-empty"'), 'Dana opens it: the form with its field ids, and the empty state in words');
$r = page($bea, '/counts/containers/');
ok($r['code'] === 200 && !has($r, 'id="container-form"'), 'Bea reads the list (ri.read) with no form');
ok(post($bea, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => 'x', 'full_weight_g' => 100, 'empty_weight_g' => 10, 'volume_ml' => 50])['code'] === 403, 'Bea saving a container: 403');

echo "2. The 750 ml gin bottle\n";
$before = last_activity_id();
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => '750 ml bottle', 'full_weight_g' => '1240', 'empty_weight_g' => '500', 'pourer_tare_g' => '30', 'volume_ml' => '750']);
$c1 = last_id('containers');
ok($r['code'] === 302 && str_contains($r['location'], '/counts/containers/?ingredient=' . $w['gin']) && str_contains($r['location'], '#container-row-' . $c1), 'container_save lands on the ingredient\'s bottles at the new row');
$row = q('SELECT * FROM containers WHERE id = :i', ['i' => $c1])[0];
ok((float) $row['full_weight_g'] === 1240.0 && (float) $row['empty_weight_g'] === 500.0 && (float) $row['pourer_tare_g'] === 30.0 && (float) $row['volume_ml'] === 750.0 && $row['is_default'] === true, 'saved with its weights and volume; the first one is the default');
$a = activity('container.save', $before);
ok(count($a) === 1 && (int) $a[0]['ingredient_id'] === $w['gin'] && json_decode($a[0]['after'], true)['volume_ml'] === '750.00', 'container.save logged with ingredient_id');
$h = page($dana, '/counts/containers/?ingredient=' . $w['gin']);
ok(has($h, 'id="container-row-' . $c1 . '"') && has($h, 'id="containers-table"') && has($h, '1,240') && has($h, 'id="container-filter-note"'), 'the table shows the row (container-row-{id}) and says it is filtered to the gin');
// the fraction the database reads: 900 g gross with the 30 g pourer = (900 - 500 - 30) / 740 of 750 ml = 375 ml
ok(near(one('SELECT ri_container_remaining_base(:c, 900, true)', ['c' => $c1]), 375.0, 0.001), 'ri_container_remaining_base: a 900 g bottle with its pourer holds 375 ml (the schema reads the weights)');

echo "3. Refusals\n";
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => 'Backwards', 'full_weight_g' => '500', 'empty_weight_g' => '500', 'volume_ml' => '750']);
ok($r['code'] === 422 && says($r, 'The empty weight must be less than the full weight'), 'empty weight not less than full: refused in words');
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['flour'], 'name' => 'Sack', 'full_weight_g' => '5000', 'empty_weight_g' => '100', 'volume_ml' => '5000']);
ok($r['code'] === 422 && says($r, 'not measured by volume'), 'a dry ingredient has no bottle: refused in words');
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => '750 ml bottle', 'full_weight_g' => '1240', 'empty_weight_g' => '500', 'volume_ml' => '750']);
ok($r['code'] === 422 && says($r, 'already has a bottle or keg with that name'), 'the same name twice for one ingredient: the unique, in words');
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => 'No volume', 'full_weight_g' => '1240', 'empty_weight_g' => '500']);
ok($r['code'] === 422 && says($r, 'volume'), 'no volume: asked for');

echo "4. One default per ingredient\n";
$r = post($dana, '/counts/containers/save.php', ['ingredient' => $w['gin'], 'name' => '1 L bottle', 'full_weight_g' => '1700', 'empty_weight_g' => '600', 'pourer_tare_g' => '30', 'volume_ml' => '1000']);
$c2 = last_id('containers');
ok($r['code'] === 302 && one('SELECT is_default FROM containers WHERE id = :i', ['i' => $c2]) === false && one('SELECT is_default FROM containers WHERE id = :i', ['i' => $c1]) === true, 'a second bottle is not the default; the first still is');
$r = post($dana, '/counts/containers/save.php', ['container' => $c2, 'is_default' => 'yes']);
ok($r['code'] === 302 && (int) one('SELECT count(*) FROM containers WHERE ingredient_id = :i AND is_default AND active', ['i' => $w['gin']]) === 1 && one('SELECT is_default FROM containers WHERE id = :i', ['i' => $c2]) === true, 'making it the default clears the old one in the same step (one default)');
$h = page($dana, "/counts/containers/?ingredient={$w['gin']}&edit=$c2");
ok(has($h, 'value="1 L bottle"') && has($h, 'name="container" value="' . $c2 . '"') && has($h, 'Change 1 L bottle'), 'Edit loads the row\'s values into the form');
$r = post($dana, '/counts/containers/save.php', ['container' => $c1, 'is_default' => 'yes', 'full_weight_g' => '1245']);
ok($r['code'] === 302 && (float) one('SELECT full_weight_g FROM containers WHERE id = :i', ['i' => $c1]) === 1245.0 && one('SELECT is_default FROM containers WHERE id = :i', ['i' => $c2]) === false, 'editing the 750 ml bottle (a weight and the default back): only what was sent changes');
$r = post($dana, '/counts/containers/save.php', ['container' => $c1, 'full_weight_g' => '1240']);
ok($r['code'] === 302 && (float) one('SELECT full_weight_g FROM containers WHERE id = :i', ['i' => $c1]) === 1240.0, '…and back to 1 240 g (the count proofs read it)');

echo "5. JSON and the walls\n";
$j = get_json($dana, '/counts/containers/?ingredient=' . $w['gin']);
ok(count($j['json']['data']['containers'] ?? []) === 2 && ($j['json']['data']['containers'][0]['name'] ?? '') === '750 ml bottle' && ($j['json']['data']['containers'][0]['is_default'] ?? false) === true, 'JSON: the ingredient\'s containers, the default first');
$j = get_json($ann, '/counts/containers/');
ok($j['code'] === 200 && count($j['json']['data']['containers'] ?? []) === 2, 'Ann (Viewer) reads them');
$r = post_json($dana, '/counts/containers/save.php', ['container' => $c2, '_partial' => '1', 'pourer_tare_g' => '25']);
ok(($r['json']['ok'] ?? false) === true || $r['code'] === 302, 'a save in JSON mode answers');
finish();
