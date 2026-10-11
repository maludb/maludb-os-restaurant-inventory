<?php
declare(strict_types=1);
/** Action `supplier_delete` (log supplier.delete; agent approval: deletion): records.delete; only one no document names. Location /suppliers/. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('records.delete');
$pdo = db();
$s = supplier_param_or_404($pdo);
$why = supplier_deletable($pdo, $s['supplier_id']);
if ($why !== null) { emit_action_status(false, ['error' => $why]); refuse(422, $why); }
ri_guard($pdo, static function () use ($pdo, $s): void {
    delete_supplier($pdo, $s['supplier_id']);
    catalog_log($pdo, 'supplier.delete', 'supplier', $s['supplier_id'], ['after' => ['name' => $s['name']]]);
});
ri_done('Deleted ' . $s['name'], $s['supplier_id'], ri_land('/suppliers/', 'deleted'), 'supplierChanged');
