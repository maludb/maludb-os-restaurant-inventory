<?php
/** Slice 3 — JSON mode under signed action tokens, agents (run token + relay), an evaluation run (ledger.md "Proof": JSON mode). */
require __DIR__ . '/lib.php';
$w = ledger_world();
$hp = static fn (int $m, int $site = DOWNTOWN): array => ['Accept: application/json', 'X-Action-Token: ' . person_token($m), 'X-Site: ' . $site];
$jpost = static function (array $h, string $path, array $form): array { $r = req('POST', $path, ['headers' => $h, 'form' => $form]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; };
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['did'], $r['json']['location']) && array_key_exists('record_id', $r['json']) && array_key_exists('refresh', $r['json']);
$dana = $hp(30); $bea = $hp(28); $marco = $hp(27);

echo "1. Receipts in JSON\n";
$r = $jpost($dana, '/receipts/save.php', ['area' => 'SMOKE Dry', 'supplier' => 'SMOKE Sysco', 'delivery_note_ref' => 'DN-J1', 'notes' => 'the morning drop', 'lines' => json_encode([
    ['ingredient' => 'L-FLOUR', 'qty' => 2, 'unit' => 'bag50', 'unit_cost' => 25], ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 38.5, 'weight_unit' => 'lb', 'unit_cost' => 3.10],
    ['ingredient' => 'L-EGG', 'qty' => 2, 'unit' => 'case', 'temperature_c' => 3.5, 'temperature_ok' => 'yes', 'unit_cost' => 54], ['ingredient' => 'L-LIME', 'qty' => 1, 'unit' => 'case', 'unit_cost' => 38],
    ['ingredient' => 'L-GIN', 'qty' => 1, 'unit' => 'case6', 'unit_cost' => 108, 'putaway_area' => 'SMOKE Bar']])]);
$g = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $g > 0 && $r['json']['refresh'] === 'receiptChanged' && str_contains($r['json']['location'], '/receipts/' . $g), 'receipt_draft: {ok, did, record_id, location, refresh} with five lines');
ok(preg_match('/^Started GR-\S+ from SMOKE Sysco with 5 lines$/', (string) $r['json']['did']) === 1, '…its did says what happened in words');
$r = $jpost($dana, '/receipts/save.php', ['receipt' => (string) $g, '_partial' => '1', 'invoice_ref' => 'INV-J1']);
$row = q('SELECT delivery_note_ref, notes, invoice_ref, storage_area_id, supplier_id FROM goods_receipts WHERE id = :g', ['g' => $g])[0];
ok($shape($r) && $row['invoice_ref'] === 'INV-J1' && $row['delivery_note_ref'] === 'DN-J1' && $row['notes'] === 'the morning drop' && (int) $row['storage_area_id'] === $w['dry'] && (int) $row['supplier_id'] === $w['sysco'], '_partial=1 on receipt_update: the invoice set, the untouched header kept');
$r = $jpost($dana, '/receipts/line.php', ['receipt' => (string) $g, 'ingredient' => 'L-FLOUR', 'qty' => '0', 'unit' => 'bag50']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['qty']), '422 {error: {code: invalid, fields}} naming the field (qty)');
$r = $jpost($dana, '/receipts/line.php', ['receipt' => (string) $g, 'ingredient' => 'L-LIME', 'qty' => '2', 'unit' => 'kg']);
ok($r['code'] === 422 && str_contains(json_encode($r['json']['error']['fields'] ?? []), 'each-weight'), "…and a schema refusal carries the schema's sentence on its field");
$r = $jpost($bea, '/receipts/line.php', ['receipt' => (string) $g, 'ingredient' => 'L-FLOUR', 'qty' => '1', 'unit' => 'bag50', 'unit_cost' => '25']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['unit_cost']), "Bea's cost: 422 on unit_cost");
$r = $jpost($dana, '/receipts/post.php', ['receipt' => (string) $g]);
$t = $r['json']['transactions'] ?? [];
ok($shape($r) && $r['json']['refresh'] === 'stockChanged' && count($t) === 5 && count(array_filter($t, static fn ($x) => (int) ($x['transaction_id'] ?? 0) > 0)) === 5, 'receipt_post → the five transaction ids');
$ls = array_column($r['json']['lines'] ?? [], null, 'ingredient_id');
ok(($ls[$w['flour']]['unit'] ?? '') === 'bag50' && near($ls[$w['flour']]['qty_base'] ?? null, 45359.237, 0.001) && ($ls[$w['flour']]['base_unit'] ?? '') === 'g' && near($ls[$w['chicken']]['qty_base'] ?? null, 17463.31, 0.01), '…and the quantities with their units (2 bag50 = 45 359.237 g; the chicken at its declared weight)');
ok(isset($t[0]['unit_cost_per_base']) && !isset($t[0]['cost_withheld']), 'Dana sees each movement\'s cost');
$r = $jpost($bea, '/receipts/save.php', ['area' => 'SMOKE Dry', 'lines' => json_encode([['ingredient' => 'L-EGG', 'qty' => 1, 'unit' => 'flat']])]);
$g2 = (int) ($r['json']['record_id'] ?? 0);
$r = $jpost($bea, '/receipts/post.php', ['receipt' => (string) $g2]);
$t = $r['json']['transactions'][0] ?? [];
ok($shape($r) && !array_key_exists('unit_cost_per_base', $t) && !array_key_exists('value', $t) && ($t['cost_withheld'] ?? false) === true, "Bea's receipt_post: the movements without cost (cost_withheld)");
$r = req('GET', '/receipts/' . $g, ['headers' => $bea]);
$j = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($j['data']['receipt']['number'] ?? '') !== '' && !str_contains($r['body'], '"unit_cost"') && !str_contains($r['body'], '"cost_per_base"'), "the receipt's JSON for Bea: no cost key anywhere");

echo "2. Transfers, production, waste in JSON\n";
$r = $jpost($marco, '/transfers/save.php', ['from_area' => 'SMOKE Dry', 'to_site' => 'SMOKE Airport', 'to_area' => 'SMOKE Airport walk-in', 'lines' => json_encode([['ingredient' => 'L-LIME', 'qty' => 50, 'unit' => 'each'], ['ingredient' => 'L-EGG', 'qty' => 1, 'unit' => 'flat']])]);
$tr = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $tr > 0 && $r['json']['refresh'] === 'transferChanged', 'transfer_draft: the shape and the record');
$r = $jpost($marco, '/transfers/send.php', ['transfer' => (string) $tr]);
ok($shape($r) && count($r['json']['transactions'] ?? []) === 2 && ($r['json']['transactions'][0]['type'] ?? '') === 'transfer_out', 'transfer_send: the two transfer_out movements');
$ll = (int) one('SELECT id FROM inventory_transfer_lines WHERE transfer_id = :t AND ingredient_id = :l', ['t' => $tr, 'l' => $w['lime']]);
$r = $jpost($marco, '/transfers/receive.php', ['transfer' => (string) $tr, 'received' => json_encode([['line' => $ll, 'qty' => 48, 'unit' => 'each']])]);
$rc = array_column($r['json']['received'] ?? [], null, 'line_id');
ok($shape($r) && near($rc[$ll]['qty_received_base'] ?? null, 48) && near($rc[$ll]['qty_base'] ?? null, 50) && count($rc) === 2, 'transfer_receive → the received per line (48 of 50 limes; the eggs whole)');
$r = $jpost($marco, '/transfers/receive.php', ['transfer' => (string) $tr, 'received' => '{not json']);
ok($r['code'] === 422, 'a malformed received list: 422');
$r = $jpost($dana, '/production/save.php', ['recipe' => 'L-R-STOCK', 'batches' => '1', 'area' => 'SMOKE Walk-in']);
ok($shape($r) && near($r['json']['output_qty_base'] ?? null, 3600) && (float) ($r['json']['cost_total'] ?? 0) > 0 && count($r['json']['transactions'] ?? []) === 2, 'production_post → output_qty_base 3 600 and cost_total for Dana, its two movements');
$r = $jpost($bea, '/production/save.php', ['recipe' => 'L-R-STOCK', 'batches' => '0.5', 'area' => 'SMOKE Walk-in']);
ok($shape($r) && near($r['json']['output_qty_base'] ?? null, 1800) && !array_key_exists('cost_total', $r['json']), '…for Bea without cost_total (walled)');
$r = $jpost($bea, '/production/save.php', ['recipe' => 'L-R-SAND']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['recipe']), 'a menu item: 422 on recipe');
$r = $jpost($dana, '/waste/save.php', ['stage' => 'plate', 'recipe' => 'L-R-SAND', 'qty' => '2', 'reason' => 'plate_waste', 'station' => 'pass']);
ok($shape($r) && count($r['json']['transactions'] ?? []) === 3 && (float) ($r['json']['cost'] ?? 0) > 0 && $r['json']['refresh'] === 'wasteChanged', 'waste_log → the three rows and the cost for Dana');
$r = $jpost($bea, '/waste/save.php', ['stage' => 'ingredient', 'ingredient' => 'L-LIME', 'qty' => '2', 'unit' => 'each', 'reason' => 'spoilage']);
$wl = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && !array_key_exists('cost', $r['json']) && count($r['json']['transactions'] ?? []) === 1 && !array_key_exists('value', $r['json']['transactions'][0]), '…for Bea: the row without cost');
$r = $jpost($bea, '/waste/save.php', ['stage' => 'ingredient', 'ingredient' => 'L-LIME', 'qty' => '2', 'reason' => 'theft']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['reason']), 'theft: 422 on reason');
$r = $jpost($dana, '/waste/review.php', ['waste' => (string) $wl]);
ok($shape($r), 'waste_review answers the shape');
$r = $jpost($dana, '/waste/void.php', ['waste' => (string) $wl, 'note' => 'miscounted']);
ok($shape($r) && count($r['json']['transactions'] ?? []) === 2, 'waste_void: the row and its reversal');
$r = $jpost($dana, '/adjustments/save.php', ['area' => 'SMOKE Walk-in', 'reason' => 'breakage', 'lines' => json_encode([['ingredient' => 'L-EGG', 'qty_delta' => -1, 'unit' => 'each']])]);
$aj = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $aj > 0, 'adjustment_draft: the shape');
$r = $jpost($dana, '/adjustments/post.php', ['adjustment' => (string) $aj]);
ok($shape($r) && near($r['json']['transactions'][0]['qty_base'] ?? null, -1), 'stock_adjust: its movement');
$tid = (int) $r['json']['transactions'][0]['transaction_id'];
$r = $jpost($dana, '/stock/reverse.php', ['transaction' => (string) $tid]);
ok($shape($r) && near($r['json']['qty_base'] ?? null, 1) && (int) ($r['json']['reverses_id'] ?? 0) === $tid, 'stock_reverse: the reversal and what it reverses');

echo "3. The expert under its run token\n";
agent_facts(40, 9401);
$r = agent_req('POST', '/receipts/save.php', 40, 9401, ['area' => 'SMOKE Dry', 'supplier' => 'SMOKE Produce Co', 'lines' => json_encode([['supplier_item' => 'PC-LIME', 'qty' => 1]])]);
$ag = (int) ($r['json']['record_id'] ?? 0);
ok($shape($r) && $ag > 0 && one("SELECT source FROM activity_log WHERE action = 'receipt.draft' AND entity_id = :g", ['g' => $ag]) === 'agent', 'the expert drafts a receipt (source agent)');
$r = agent_req('POST', '/transfers/save.php', 40, 9401, ['from_area' => 'SMOKE Walk-in', 'to_area' => 'SMOKE Dry', 'ingredient' => 'L-EGG', 'qty' => '1', 'unit' => 'flat']);
ok($shape($r) && (int) $r['json']['record_id'] > 0, '…drafts a transfer');
$r = agent_req('POST', '/waste/save.php', 40, 9401, ['stage' => 'ingredient', 'ingredient' => 'L-LIME', 'qty' => '1', 'reason' => 'spoilage']);
ok($shape($r) && one("SELECT source FROM activity_log WHERE action = 'waste.log' AND entity_id = :w", ['w' => (int) $r['json']['record_id']]) === 'agent', '…and logs waste');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['stock_adjust']['approval'] ?? null) === 'deletion' && ($reg['receipt_draft']['approval'] ?? null) === null && ($reg['waste_log']['approval'] ?? null) === null, "its stock_adjust pauses at the kernel's hook (deletion); its drafts and waste do not");
$r = agent_req('POST', '/receipts/save.php', 40, 9499, ['area' => 'SMOKE Dry']);
ok($r['code'] === 401, 'a run the kernel does not vouch for: 401');

echo "4. An evaluation run writes nothing\n";
kernel_state(function ($s) { $s['facts']['9402'] = ['valid' => true, 'is_agent' => true, 'is_eval' => true, 'member_id' => 40, 'run_id' => 9402, 'request_id' => 'req-run-9402', 'trigger' => 'eval', 'scope' => 101, 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$before = (int) one('SELECT count(*) FROM inventory_transactions') + (int) one('SELECT count(*) FROM goods_receipts') + (int) one('SELECT count(*) FROM waste_logs') + (int) one('SELECT count(*) FROM inventory_adjustments') + (int) one('SELECT count(*) FROM inventory_transfers') + (int) one('SELECT count(*) FROM production_batches');
$writes = [
    ['/receipts/save.php', ['area' => 'SMOKE Dry']], ['/receipts/line.php', ['receipt' => (string) $ag, 'ingredient' => 'L-LIME', 'qty' => '1', 'unit' => 'case']], ['/receipts/post.php', ['receipt' => (string) $ag]],
    ['/receipts/cancel.php', ['receipt' => (string) $ag]], ['/transfers/save.php', ['from_area' => 'SMOKE Walk-in', 'to_area' => 'SMOKE Dry']], ['/adjustments/save.php', ['area' => 'SMOKE Walk-in', 'reason' => 'breakage']],
    ['/production/save.php', ['recipe' => 'L-R-STOCK', 'area' => 'SMOKE Walk-in']], ['/waste/save.php', ['stage' => 'ingredient', 'ingredient' => 'L-LIME', 'qty' => '1', 'reason' => 'spoilage']],
    ['/waste/review.php', ['waste' => (string) $wl]], ['/stock/reverse.php', ['transaction' => (string) $tid]], ['/transfers/receive.php', ['transfer' => (string) $tr]],
];
$all = true;
foreach ($writes as [$p, $f]) { $r = agent_req('POST', $p, 40, 9402, $f); $all = $all && $r['code'] === 403 && str_contains($r['body'], 'evaluation run writes nothing'); }
$after = (int) one('SELECT count(*) FROM inventory_transactions') + (int) one('SELECT count(*) FROM goods_receipts') + (int) one('SELECT count(*) FROM waste_logs') + (int) one('SELECT count(*) FROM inventory_adjustments') + (int) one('SELECT count(*) FROM inventory_transfers') + (int) one('SELECT count(*) FROM production_batches');
ok($all, 'is_eval refuses every write of the ledger (eleven handlers): 403 in words');
ok($before === $after && one('SELECT status FROM goods_receipts WHERE id = :g', ['g' => $ag]) === 'draft', '…and nothing was written');
$r = agent_req('GET', '/stock/', 40, 9402);
ok($r['code'] === 200 && isset($r['json']['data']['levels']), 'an evaluation run still reads');

echo "5. Reads in JSON\n";
$r = req('GET', '/stock/movements?type=receipt', ['headers' => $dana]);
$j = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && count($j['data']['movements'] ?? []) > 0 && isset($j['data']['movements'][0]['unit_cost_per_base']), 'movements: JSON with cost for Dana');
$r = req('GET', '/stock/movements?type=receipt', ['headers' => $hp(29)]);
ok($r['code'] === 200 && !str_contains($r['body'], '"unit_cost_per_base"') && !str_contains($r['body'], '"value"'), '…without for Ann');
$r = req('GET', '/transfers/' . $tr, ['headers' => $marco]);
$j = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($j['data']['transfer']['status'] ?? '') === 'received' && count($j['data']['transfer']['lines'] ?? []) === 2, 'a transfer in JSON with its lines');
$r = req('GET', '/stock/unit-options?ingredient=' . $w['chicken'], ['headers' => $dana]);
$j = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($j['catch_weight'] ?? $j['data']['catch_weight'] ?? null) === true, "the line form's unit options say the chicken is caught by weight");
finish();
