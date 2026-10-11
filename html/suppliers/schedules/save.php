<?php
declare(strict_types=1);
/** Action `supplier_schedule_set` (log supplier.schedule_set with scope_id): suppliers.write at the current site. Location /suppliers/{supplier}/schedules. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('suppliers.write');
$pdo = db();
$site = (int) current_site_id();
$s = supplier_param_or_404($pdo);
$id = request_integer('schedule') ?? (req_val('schedule') !== null && ctype_digit((string) req_val('schedule')) ? (int) req_val('schedule') : null);
$errors = [];
$f = [];
$f['order_dow'] = ri_int('order_dow', null, 0, 6, 'The order day', $errors, false);
$f['delivery_dow'] = ri_int('delivery_dow', null, 0, 6, 'The delivery day', $errors, false);
$ct = (string) (req_val('cutoff_time') ?? '');
if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ct)) { $errors['cutoff_time'] = 'The cut-off is a time like 13:00.'; }
$f['cutoff_time'] = $ct;
$f['active'] = ri_yes('active', true);
if ($errors !== []) { ri_refuse_fields($errors); }
$sid = ri_guard($pdo, static function () use ($pdo, $s, $site, $id, $f): int {
    $pdo->beginTransaction();
    try { $sid = save_schedule($pdo, $s['supplier_id'], $site, $id, $f); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['cutoff_time' => 'That cut-off is already on the schedule.']); } throw $e; }
    $st = $pdo->prepare('SELECT lead_days FROM mcp_supplier_schedules WHERE supplier_schedule_id = :id'); $st->execute(['id' => $sid]);
    catalog_log($pdo, 'supplier.schedule_set', 'supplier', $s['supplier_id'], ['scope_id' => $site, 'after' => ['schedule_id' => $sid, 'order_dow' => $f['order_dow'], 'cutoff_time' => $f['cutoff_time'], 'delivery_dow' => $f['delivery_dow'], 'lead_days' => (int) $st->fetchColumn()]]);
    $pdo->commit();
    return $sid;
});
ri_done('Order ' . DOW[$f['order_dow']] . ' by ' . $f['cutoff_time'] . ' → delivered ' . DOW[$f['delivery_dow']], $sid, ri_land('/suppliers/' . $s['supplier_id'] . '/schedules', 'schedule_saved', 'schedule-row-' . $sid), 'supplierChanged', ['supplier_id' => $s['supplier_id'], 'scope_id' => $site]);
