<?php
declare(strict_types=1);
/** /suppliers/{id}?tab= — one supplier at this site (screen `supplier-view`). JSON: supplier_full(). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$s = catalog_supplier_or_404($pdo, request_integer('id') ?? request_integer('supplier'));
$full = supplier_full($pdo, $s['supplier_id'], $site);
$tab = request_string('tab') ?: 'schedules';
log_activity($pdo, 'screen.view', 'supplier', $s['supplier_id'], ['screen' => 'supplier-view', 'after' => ['supplier_id' => $s['supplier_id'], 'tab' => $tab]]);
if (wants_json()) {
    respond_screen(['supplier' => present_supplier($full['supplier']), 'schedules' => $full['schedules'], 'next_windows' => $full['next_windows'], 'items' => array_map('present_supplier_item', $full['items']), 'guide' => $full['guide'], 'open_purchase_orders' => $full['open_purchase_orders'],
        'notes' => $full['notes'], 'attachments' => $full['attachments'], 'deletable' => has_right('records.delete') ? supplier_deletable($pdo, $s['supplier_id']) : null, 'may' => ['write' => has_right('suppliers.write'), 'delete' => has_right('records.delete'), 'cost' => sees_cost($site)]]);
}
$notices = ['created' => ['success', 'The supplier is made. Give it its schedule and its items.'], 'saved' => ['success', 'Saved.'], 'archived' => ['success', 'The supplier is archived.'], 'restored' => ['success', 'The supplier is active again.']];
render_screen($s['name'], view('catalog/supplier.php', ['full' => $full, 'tab' => $tab, 'site' => $site, 'may' => ['write' => has_right('suppliers.write'), 'delete' => has_right('records.delete'), 'draft' => has_right('purchasing.draft'), 'notes' => has_right('notes.write')],
    'deletable' => has_right('records.delete') ? supplier_deletable($pdo, $s['supplier_id']) : 'n/a', 'seesCost' => sees_cost($site), 'tz' => member_timezone(), 'here' => here_url(), 'me' => (int) current_member_id(), 'isAdmin' => is_ri_admin(),
    'purchase_orders' => $tab === 'purchase_orders' ? supplier_purchase_orders($pdo, $s['supplier_id'], $site) : [], 'invoices' => $tab === 'invoices' && sees_cost($site) ? supplier_invoices($pdo, $s['supplier_id'], $site) : [], 'alerts' => $tab === 'alerts' ? supplier_price_alerts($pdo, $s['supplier_id']) : [],
    'trail' => $tab === 'trail' ? find_record_activity($pdo, 'supplier', $s['supplier_id']) : [], 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'supplier-list', 'screen' => 'supplier-view', 'entity' => 'supplier', 'recordId' => (string) $s['supplier_id']]);
