<?php
/** Slice 5 — the file mappings and the preview (pos.md "Proof": Mappings and the preview). The five seeded read-only; a copy; samples through the preview; nothing written. */
require __DIR__ . '/lib.php';
pos_world();
$admin = at(1, DOWNTOWN);
$marco = at(27, DOWNTOWN);
$ann = at(29, DOWNTOWN);
$since = last_activity_id();
$tmp = state_dir() . '/samples';
@mkdir($tmp, 0777, true);
/** A copy of a fixture with one header renamed. */
function sample_with(string $fixture, string $from, string $to, string $dir): string
{
    $lines = file(FIX . '/' . $fixture);
    $lines[0] = preg_replace('/(^|,)' . preg_quote($from, '/') . '(,|$)/', '$1' . $to . '$2', $lines[0], 1);
    $p = $dir . '/' . str_replace('.csv', '', $fixture) . '-' . preg_replace('/\W+/', '_', $to) . '.csv';
    file_put_contents($p, implode('', $lines));
    return $p;
}
$pulls0 = (int) one('SELECT count(*) FROM source_pulls');

echo "1. The five seeded mappings, read-only\n";
ok((int) one("SELECT count(*) FROM sales_file_mappings WHERE is_builtin AND key IN ('generic','toast_items','square_items','clover_items','lightspeed_items')") === 5, 'generic and the four vendors are seeded built-in');
$r = page($admin, '/sales/mappings/');
ok($r['code'] === 200 && has($r, 'id="mapping-row-generic"') && has($r, 'id="mapping-row-lightspeed_items"') && substr_count($r['body'], '>built-in<') >= 5 && has($r, 'id="mapping-list-new"'), 'the list: five rows marked built-in, New for the admin');
$r = page($ann, '/sales/mappings/');
ok($r['code'] === 200 && has($r, 'id="mapping-row-generic"') && !has($r, 'id="mapping-list-new"'), 'Ann reads the list, no New');
$r = page($admin, '/sales/mappings/generic');
ok($r['code'] === 200 && has($r, 'id="mapping-edit-builtin"') && has($r, 'id="mapping-edit-copy"') && !has($r, 'id="mapping-form-save"'), 'a built-in opens read-only with Copy, no Save');
$r = req('GET', '/sales/mappings/toast_items', ['jar' => $admin, 'headers' => ['Accept: application/json']]);
$j = json_decode($r['body'], true)['data']['mapping'] ?? [];
ok($r['code'] === 200 && $j['builtin'] === true && $j['mapping']['columns']['plu'] === 'PLU' && $j['mapping']['columns']['external_id'] === 'Order #', 'its JSON: builtin, the Toast columns');
$sq = json_decode((string) one("SELECT mapping FROM sales_file_mappings WHERE key = 'square_items'"), true);
ok(array_key_exists('unit_price', $sq['columns']) && $sq['columns']['unit_price'] === null && $sq['comp_rule'] === 'full_discount' && in_array('refund', $sq['void_values'], true), "square_items as db/023 left it: no unit price column, comp a full discount, a Refund a void");
ok(page($admin, '/sales/mappings/nope')['code'] === 404, 'an unknown mapping → 404');
$r = post_json($admin, '/sales/mappings/save.php', ['mapping_key' => 'generic', 'name' => 'Mine now']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'Copy it to change it'), 'a built-in\'s save → 422 "Copy it to change it"');
ok((string) one("SELECT name FROM sales_file_mappings WHERE key = 'generic'") !== 'Mine now', 'generic unchanged');

echo "2. A copy of generic\n";
$r = page($admin, '/sales/mappings/new?copy=generic');
ok($r['code'] === 200 && has($r, 'id="mapping-form"') && has($r, 'id="mapping-form-field-col-plu"') && has($r, 'value="plu"'), "the new form starts from generic's columns");
$gen = json_decode((string) one("SELECT mapping FROM sales_file_mappings WHERE key = 'generic'"), true);
$form = ['key' => 'smoke_custom', 'name' => 'SMOKE custom', 'delimiter' => ',', 'void_values' => 'yes, void', 'comp_values' => 'yes'];
foreach ($gen['columns'] as $f => $h) { $form['col_' . $f] = (string) $h; }
$form['col_plu'] = 'Item Code';
ok(post_json($marco, '/sales/mappings/save.php', $form)['code'] === 403 && post_json($ann, '/sales/mappings/save.php', $form)['code'] === 403, "Marco and Ann cannot save a mapping (settings.manage is the admin's)");
$bad = $form; $bad['key'] = 'Smoke Custom!';
ok(post_json($admin, '/sales/mappings/save.php', $bad)['code'] === 422, 'a key that is not lower_case → 422');
$bad = $form; $bad['col_qty'] = '';
$r = post_json($admin, '/sales/mappings/save.php', $bad);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['col_qty']), 'no quantity column → 422 naming it (required)');
$bad = $form; $bad['col_plu'] = ''; $bad['col_item_name'] = '';
$r = post_json($admin, '/sales/mappings/save.php', $bad);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['col_plu']), 'neither a PLU nor an item name column → 422');
$r = post_json($admin, '/sales/mappings/save.php', $form);
$m = json_decode((string) one("SELECT mapping FROM sales_file_mappings WHERE key = 'smoke_custom'"), true);
ok($r['code'] === 200 && ($m['columns']['plu'] ?? '') === 'Item Code' && one("SELECT is_builtin FROM sales_file_mappings WHERE key = 'smoke_custom'") === false && $m['void_values'] === ['yes', 'void'], '"SMOKE custom" saved: ours, plu → Item Code, the void words');
$log = activity('mapping.save', $since);
ok(count($log) === 1 && json_decode($log[0]['after'], true)['created'] === true && json_decode($log[0]['after'], true)['columns']['plu'] === 'Item Code', 'mapping.save logged, created');
$r = post_json($admin, '/sales/mappings/save.php', ['mapping_key' => 'smoke_custom', 'name' => 'SMOKE custom export']);
ok($r['code'] === 200 && one("SELECT name FROM sales_file_mappings WHERE key = 'smoke_custom'") === 'SMOKE custom export' && json_decode((string) one("SELECT mapping FROM sales_file_mappings WHERE key = 'smoke_custom'"), true)['columns']['plu'] === 'Item Code', 'renamed in place: the columns kept');
$r = page($admin, '/sales/mappings/smoke_custom');
ok($r['code'] === 200 && has($r, 'id="mapping-form-save"') && has($r, 'value="Item Code"') && has($r, 'id="mapping-sample-form"'), 'ours opens editable with the sample preview');

echo "3. The preview\n";
$r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', FIX . '/generic.csv');
$j = $r['json'];
ok($r['code'] === 200 && count($j['rows'] ?? []) === 5 && ($j['missing'] ?? null) === [], 'generic.csv through generic: five rows parsed, nothing missing');
ok(($j['rows'][0]['external_id'] ?? '') === 'SMK-001' && $j['rows'][0]['plu'] === '1001' && (float) $j['rows'][0]['qty'] === 2.0 && $j['rows'][0]['modifiers'] === ['XCHK'] && $j['rows'][0]['business_date'] === D1, 'row 1: SMK-001, PLU 1001, 2, XCHK, the business date');
ok(($j['rows'][2]['voided'] ?? false) === true && ($j['rows'][3]['comp'] ?? false) === true, 'the void and the comp flagged');
ok(count($j['bad'] ?? []) === 1 && trim((string) ($j['bad'][0]['reason'] ?? '')) !== '', 'the malformed row listed with its reason (' . ($j['bad'][0]['reason'] ?? '') . ')');
$custom = sample_with('generic.csv', 'plu', 'Item Code', $tmp);
$r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'smoke_custom'], 'sample', $custom);
ok($r['code'] === 200 && count($r['json']['rows'] ?? []) === 5 && ($r['json']['rows'][1]['plu'] ?? '') === '3001', 'a sample with "Item Code" through SMOKE custom: five rows, the PLUs read');
$r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', $custom);
ok($r['code'] === 200 && ($r['json']['missing'] ?? null) === [] && ($r['json']['rows'][0]['plu'] ?? '') === 'SMOKE Chicken Sandwich', 'the same sample through generic still reads: with no PLU column the item name is the key (DECISION)');
$noqty = sample_with('generic.csv', 'qty', 'quantity_sold', $tmp);
$r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', $noqty);
ok($r['code'] === 200 && ($r['json']['missing'] ?? []) === ['qty'] && ($r['json']['rows'] ?? [1]) === [], 'the qty column renamed: missing [qty], no rows');
$r = post_file($admin, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', $noqty);
ok($r['code'] === 200 && has($r, 'id="mapping-preview-missing"') && has($r, 'alert-danger') && has($r, 'quantity_sold'), "the HTML: the missing column red, naming the file's columns");
$r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'generic', 'col_qty' => 'quantity_sold'], 'sample', $noqty);
ok($r['code'] === 200 && count($r['json']['rows'] ?? []) === 5, 'the form as it stands (col_qty = quantity_sold) previews five rows before any save');
foreach (['toast_items', 'square_items', 'clover_items', 'lightspeed_items'] as $k) {
    $r = post_file_json($admin, '/sales/mappings/preview.php', ['key' => $k], 'sample', FIX . '/' . $k . '.csv');
    $rows = $r['json']['rows'] ?? [];
    ok($r['code'] === 200 && count($rows) === 5 && ($r['json']['missing'] ?? null) === [] && ($rows[0]['business_date'] ?? '') === D1 && ($rows[0]['plu'] ?? '') === '1001', "$k.csv through $k: five rows, day 1, PLU 1001");
}
$txt = $tmp . '/notes.pdf'; file_put_contents($txt, '%PDF');
ok(post_file_json($admin, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', $txt)['code'] === 422, 'a sample that is not CSV or XLSX → 422');
ok(post_file_json($ann, '/sales/mappings/preview.php', ['key' => 'generic'], 'sample', FIX . '/generic.csv')['code'] === 403, 'Ann cannot preview (settings.manage)');
ok((int) one('SELECT count(*) FROM source_pulls') === $pulls0 && (int) one('SELECT count(*) FROM pos_sales') === 0, 'a preview writes nothing: no pull, no check');
finish();
