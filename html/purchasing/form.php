<?php
declare(strict_types=1);
/**
 * /purchasing/new (screen `purchase-order-add`; param supplier) and /purchasing/{id}/edit (screen `purchase-order-edit`, a draft: the header, the lines, the order guide's rows to tick).
 * purchasing.draft. The vhost sends /edit to this file (canonical URLs), as the receipts' does. JSON: the areas and the supplier's window; a draft's lines.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$sees = sees_cost($site);
$id = request_integer('id');
$cur = $id === null ? null : po_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, 'Purchase order ' . $cur['number'] . ' is ' . (PO_STATUS_WORDS[$cur['status']] ?? $cur['status']) . ' — only a draft is edited.'); }
$supplierId = $cur['supplier_id'] ?? (ctype_digit(request_string('supplier')) ? (int) request_string('supplier') : null);
$supplier = $supplierId === null ? null : find_supplier($pdo, $supplierId);
$window = $supplier === null ? null : supplier_window($pdo, $supplier['supplier_id'], $site);
log_screen_view($pdo, $cur === null ? 'purchase-order-add' : 'purchase-order-edit', $cur === null ? [] : ['purchase_order_id' => $id]);
if ($cur === null) {
    if (wants_json()) { respond_screen(['areas' => array_map('present_area', area_options($pdo, $site)), 'supplier' => $supplier === null ? null : ['supplier_id' => $supplier['supplier_id'], 'name' => $supplier['name'], 'order_method' => $supplier['order_method']],
        'next_delivery_on' => $window['delivery_on'] ?? null, 'cutoff_at' => $window !== null && $window['has_schedule'] ? json_ts($window['cutoff_at']) : null]); }
    render_screen('New purchase order', view('purchasing/form.php', ['supplier' => $supplier, 'window' => $window, 'areas' => area_options($pdo, $site), 'today' => site_today($site)]),
        ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-add', 'entity' => 'purchase_order']);
    exit;
}
$lines = po_lines($pdo, $id);
if (wants_json()) { respond_screen(['purchase_order' => present_po($cur, $lines, $sees), 'guide' => array_map(static fn (array $g): array => ['supplier_item_id' => $g['supplier_item_id'], 'supplier_sku' => $g['supplier_sku'], 'ingredient' => $g['ingredient'],
    'unit' => $g['purchase_unit_code'], 'last_price' => $sees ? $g['last_price'] : null], guide_rows_for($pdo, $cur['supplier_id'], $site, $id))]); }
$line = null;
$lineId = request_integer('line');
if ($lineId !== null) { foreach ($lines as $l) { if ($l['purchase_order_line_id'] === $lineId) { $line = $l; } } }
$ingredient = $line['ingredient_id'] ?? null;
$unitOptions = $ingredient !== null ? unit_options_for($pdo, $ingredient, 'receive') : array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo));
$notices = ['created' => ['success', 'The draft is started. Add lines from the order guide or one at a time.'], 'saved' => ['success', 'Saved.'], 'line_saved' => ['success', 'Line saved.'], 'line_removed' => ['success', 'Line removed.']];
render_screen('Change ' . $cur['number'], view('purchasing/edit.php', ['po' => $cur, 'lines' => $lines, 'line' => $line, 'unitOptions' => $unitOptions, 'guide' => guide_rows_for($pdo, $cur['supplier_id'], $site, $id), 'window' => $window,
    'areas' => area_options($pdo, $site), 'today' => site_today($site), 'seesCost' => $sees, 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-edit', 'entity' => 'purchase_order', 'recordId' => (string) $id]);
