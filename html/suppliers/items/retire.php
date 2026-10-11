<?php
declare(strict_types=1);
/** Action `supplier_item_retire` (log supplier_item.retire): suppliers.write; active yes|no. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('suppliers.write');
$pdo = db();
$raw = (string) (req_val('supplier_item') ?? '');
$si = ctype_digit($raw) ? find_supplier_item($pdo, (int) $raw) : null;
if ($si === null) { respond_not_found('That supplier item is not here.'); }
$active = ri_yes('active', false);
ri_guard($pdo, static function () use ($pdo, $si, $active): void {
    retire_supplier_item($pdo, $si['supplier_item_id'], $active);
    catalog_log($pdo, 'supplier_item.retire', 'supplier_item', $si['supplier_item_id'], ['ingredient_id' => $si['ingredient_id'], 'before' => ['active' => $si['active']], 'after' => ['active' => $active, 'supplier_id' => $si['supplier_id']]]);
});
ri_done(($active ? 'Restored ' : 'Retired ') . $si['ingredient'] . ' at ' . $si['supplier'], $si['supplier_item_id'], ri_land('/suppliers/' . $si['supplier_id'] . '/items', $active ? 'item_restored' : 'item_retired', 'supplier-item-row-' . $si['supplier_item_id']), 'supplierChanged');
