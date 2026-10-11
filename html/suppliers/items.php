<?php
declare(strict_types=1);
/** /suppliers/{id}/items — what the supplier sells us (screen `supplier-items`); the add/edit form at the top. suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('suppliers.write');
$pdo = db();
$s = catalog_supplier_or_404($pdo, request_integer('id') ?? request_integer('supplier'));
$rows = supplier_items($pdo, $s['supplier_id'], null, false);
$edit = request_integer('item');
$cur = null;
foreach ($rows as $r) { if ($edit !== null && $r['supplier_item_id'] === $edit) { $cur = $r; } }
log_screen_view($pdo, 'supplier-items');
if (wants_json()) { respond_screen(['supplier' => present_supplier($s), 'items' => array_map('present_supplier_item', $rows)]); }
$notices = ['item_saved' => ['success', 'The item is saved.'], 'item_retired' => ['success', 'The item is retired.'], 'item_restored' => ['success', 'The item is active again.']];
render_screen($s['name'] . ' · items', view('catalog/supplier-items.php', ['s' => $s, 'rows' => $rows, 'cur' => $cur, 'seesCost' => sees_cost(), 'here' => here_url(), 'tz' => member_timezone(), 'options' => $cur === null ? [] : ri_unit_options($pdo, $cur['ingredient_id']), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'supplier-list', 'screen' => 'supplier-items', 'entity' => 'supplier', 'recordId' => (string) $s['supplier_id']]);
