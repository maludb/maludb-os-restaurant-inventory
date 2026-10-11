<?php
declare(strict_types=1);
/** /suppliers/{id}/schedules — the supplier's order and delivery days at THIS site (screen `supplier-schedules`). suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('suppliers.write');
$pdo = db();
$site = (int) current_site_id();
$s = catalog_supplier_or_404($pdo, request_integer('id') ?? request_integer('supplier'));
$rows = supplier_schedules($pdo, $s['supplier_id'], $site);
log_screen_view($pdo, 'supplier-schedules');
if (wants_json()) { respond_screen(['supplier' => present_supplier($s), 'schedules' => $rows, 'next_windows' => $rows === [] ? [] : next_windows($pdo, $s['supplier_id'], $site)]); }
$notices = ['schedule_saved' => ['success', 'The schedule is saved.'], 'schedule_removed' => ['success', 'The schedule row is removed.']];
render_screen($s['name'] . ' · schedule', view('catalog/supplier-schedules.php', ['s' => $s, 'rows' => $rows, 'windows' => $rows === [] ? [] : next_windows($pdo, $s['supplier_id'], $site), 'site' => current_site(), 'tz' => site_timezone(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'supplier-list', 'screen' => 'supplier-schedules', 'entity' => 'supplier', 'recordId' => (string) $s['supplier_id']]);
