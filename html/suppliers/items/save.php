<?php
declare(strict_types=1);
/** Actions `supplier_item_create` / `supplier_item_update` (log supplier_item.create / .update): suppliers.write; a price is accepted only from one who sees cost; the trigger refuses a unit the ingredient cannot convert. Location /suppliers/{supplier}/items#supplier-item-row-{id}. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('suppliers.write');
$pdo = db();
$raw = (string) (req_val('supplier_item') ?? req_val('supplier_item_id') ?? '');
$cur = $raw === '' ? null : (ctype_digit($raw) ? find_supplier_item($pdo, (int) $raw) : null);
if ($raw !== '' && $cur === null) { respond_not_found('That supplier item is not here.'); }
$s = $cur === null ? supplier_param_or_404($pdo) : catalog_supplier_or_404($pdo, $cur['supplier_id']);
$errors = [];
$f = [];
if ($cur === null || req_has('ingredient')) { $i = ingredient_param_or_404($pdo); $f['ingredient_id'] = $i['ingredient_id']; } else { $f['ingredient_id'] = $cur['ingredient_id']; }
$f['purchase_unit_code'] = ri_text('purchase_unit', $cur['purchase_unit_code'] ?? null, 24, 'The purchase unit', $errors, $cur === null) ?? ($cur['purchase_unit_code'] ?? '');
if ($f['purchase_unit_code'] === '') { $errors['purchase_unit'] = 'Say the unit it is bought in.'; }
$f['supplier_sku'] = ri_text('supplier_sku', $cur['supplier_sku'] ?? null, 60, 'The SKU', $errors);
$f['description'] = ri_text('description', $cur['description'] ?? null, 200, 'The description', $errors);
$f['pack'] = ri_text('pack', $cur['pack'] ?? null, 80, 'The pack', $errors);
$f['moq'] = ri_num('moq', $cur['moq'] ?? '1.0000', 'The minimum order', $errors, 0.0001, null, false, 4) ?? '1.0000';
$f['catch_weight'] = ri_yes('catch_weight', $cur['catch_weight'] ?? false);
$f['lead_time_days'] = ri_int('lead_time_days', isset($cur['lead_time_days']) ? (int) $cur['lead_time_days'] : null, 0, 90, 'The lead time', $errors, true);
$f['active'] = ri_yes('active', $cur['active'] ?? true);
$sees = sees_cost();
foreach (['last_price', 'contract_price'] as $k) {
    if (req_has($k) && (string) req_val($k) !== '' && !$sees) { $errors[$k] = 'You may not see cost — a price is set by someone who does.'; continue; }
    $f[$k] = ri_num($k, $cur[$k] ?? null, 'The ' . str_replace('_', ' ', $k), $errors, 0, null, true, 4);
}
$cu = ri_text('contract_until', $cur['contract_until'] ?? null, 10, 'The contract end', $errors);
if ($cu !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cu)) { $errors['contract_until'] = 'The contract end is a date (YYYY-MM-DD).'; }
$f['contract_until'] = $cu;
if ($errors !== []) { ri_refuse_fields($errors); }
$sid = ri_guard($pdo, static function () use ($pdo, $s, $cur, $f, $sees): int {
    $pdo->beginTransaction();
    try { $sid = save_supplier_item($pdo, $s['supplier_id'], $cur['supplier_item_id'] ?? null, $f, $sees, (int) current_member_id()); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['purchase_unit' => 'That item is already on the list in that unit.']); } if ((string) $e->getCode() === '23514') { ri_refuse_fields(['purchase_unit' => db_message($e, 'That unit cannot be converted.')]); } throw $e; }
    $after = ['ingredient_id' => $f['ingredient_id'], 'supplier_sku' => $f['supplier_sku'], 'purchase_unit_code' => $f['purchase_unit_code'], 'pack' => $f['pack'] ?? null, 'description' => $f['description'] ?? null, 'moq' => $f['moq'], 'lead_time_days' => $f['lead_time_days'] ?? null, 'contract_until' => $f['contract_until'], 'catch_weight' => $f['catch_weight'], 'active' => $f['active']] + ($sees ? ['last_price' => $f['last_price'], 'contract_price' => $f['contract_price']] : []);
    catalog_log($pdo, $cur === null ? 'supplier_item.create' : 'supplier_item.update', 'supplier_item', $sid, ['ingredient_id' => $f['ingredient_id'], 'after' => $after + ['supplier_id' => $s['supplier_id']]]);
    $pdo->commit();
    return $sid;
});
$i = find_ingredient($pdo, $f['ingredient_id']);
ri_done(($cur === null ? 'Added ' : 'Saved ') . $i['name'] . ' at ' . $s['name'] . ' in ' . $f['purchase_unit_code'], $sid, ri_land('/suppliers/' . $s['supplier_id'] . '/items', 'item_saved', 'supplier-item-row-' . $sid), 'supplierChanged', ['supplier_id' => $s['supplier_id'], 'ingredient_id' => $f['ingredient_id']]);
