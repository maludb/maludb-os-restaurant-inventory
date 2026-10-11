<?php
declare(strict_types=1);
/** Action `supplier_schedule_remove` (log supplier.schedule_remove): suppliers.write at the row's site. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
$pdo = db();
$id = request_integer('schedule') ?? (ctype_digit((string) req_val('schedule')) ? (int) req_val('schedule') : null);
$st = $pdo->prepare('SELECT * FROM mcp_supplier_schedules WHERE supplier_schedule_id = :id');
$st->execute(['id' => $id ?? 0]);
$row = $st->fetch();
if ($row === false) { respond_not_found('That schedule row is not here.'); }
require_right('suppliers.write', (int) $row['scope_id']);
ri_guard($pdo, static function () use ($pdo, $row, $id): void {
    $gone = remove_schedule($pdo, (int) $id);
    catalog_log($pdo, 'supplier.schedule_remove', 'supplier', (int) $row['supplier_id'], ['scope_id' => (int) $row['scope_id'], 'before' => ['schedule_id' => $id, 'order_dow' => (int) $row['order_dow'], 'cutoff_time' => $row['cutoff_time'], 'delivery_dow' => (int) $row['delivery_dow']]]);
});
ri_done('Removed the schedule row', $id, ri_land('/suppliers/' . (int) $row['supplier_id'] . '/schedules', 'schedule_removed'), 'supplierChanged');
