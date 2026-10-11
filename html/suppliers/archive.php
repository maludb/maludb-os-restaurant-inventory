<?php
declare(strict_types=1);
/** Action `supplier_archive` (log supplier.archive): suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('suppliers.write');
$pdo = db();
$s = supplier_param_or_404($pdo);
$archived = ri_yes('archived', true);
ri_guard($pdo, static function () use ($pdo, $s, $archived): void {
    archive_supplier($pdo, $s['supplier_id'], $archived);
    catalog_log($pdo, 'supplier.archive', 'supplier', $s['supplier_id'], ['before' => ['active' => $s['active']], 'after' => ['archived' => $archived, 'active' => !$archived]]);
});
ri_done(($archived ? 'Archived ' : 'Restored ') . $s['name'], $s['supplier_id'], ri_land('/suppliers/' . $s['supplier_id'], $archived ? 'archived' : 'restored'), 'supplierChanged');
