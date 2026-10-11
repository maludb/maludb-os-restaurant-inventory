<?php
declare(strict_types=1);
/** Actions `supplier_create` / `supplier_update` (log supplier.create / supplier.update): suppliers.write. Location /suppliers/{id}; refresh supplierChanged. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('suppliers.write');
$pdo = db();
$raw = (string) (req_val('supplier') ?? req_val('supplier_id') ?? '');
$cur = $raw === '' ? null : (supplier_by_ref($pdo, $raw) ?? respond_not_found('That supplier is not here.'));
$id = $cur['supplier_id'] ?? null;
$errors = [];
$f = supplier_from_request($pdo, $cur, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$newId = ri_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    try { $newId = save_supplier($pdo, $id, $f, (int) current_member_id()); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['name' => 'That supplier already exists.']); } throw $e; }
    $after = supplier_loggable(find_supplier($pdo, $newId));
    if ($cur === null) { catalog_log($pdo, 'supplier.create', 'supplier', $newId, ['after' => $after]); }
    else { $d = ri_diff(supplier_loggable($cur), $after); if ($d['after'] !== []) { catalog_log($pdo, 'supplier.update', 'supplier', $newId, ['before' => $d['before'], 'after' => $d['after']]); } }
    $pdo->commit();
    return $newId;
});
ri_done(($id === null ? 'Made ' : 'Saved ') . $f['name'], $newId, ri_land(return_path('/suppliers/' . $newId), $id === null ? 'created' : 'saved'), 'supplierChanged', ['supplier_id' => $newId]);
