<?php
declare(strict_types=1);
/** /receipts/new (screen `receipt-add`; params purchase_order, supplier, area) and /receipts/{id}/edit (screen `receipt-edit`, a draft's header). stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.receive');
$pdo = db();
$site = (int) current_site_id();
$id = request_integer('id');
$cur = $id === null ? null : receipt_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, 'Receipt ' . $cur['number'] . ' is ' . $cur['status'] . ' — only a draft\'s header changes.'); }
$prefill = ['supplier' => request_string('supplier'), 'purchase_order' => request_string('purchase_order'), 'area' => request_string('area')];
$po = null;
if ($cur === null && $prefill['purchase_order'] !== '' && ctype_digit($prefill['purchase_order'])) {
    $po = po_for($pdo, (int) $prefill['purchase_order']);
    if ($po === null || $po['scope_id'] !== $site) { respond_not_found('That purchase order is not here.'); }
    $prefill['supplier'] = (string) $po['supplier_id'];
    if ($prefill['area'] === '' && $po['storage_area_id'] !== null) { $prefill['area'] = (string) $po['storage_area_id']; }
}
$supplier = $cur['supplier_id'] ?? ($prefill['supplier'] !== '' && ctype_digit($prefill['supplier']) ? (int) $prefill['supplier'] : null);
log_screen_view($pdo, $cur === null ? 'receipt-add' : 'receipt-edit');
if (wants_json()) {
    respond_screen(['purchase_order' => $po === null ? null : ['purchase_order_id' => $po['purchase_order_id'], 'number' => $po['number'], 'open_lines' => po_open_lines($pdo, $po['purchase_order_id'])], 'areas' => array_map('present_area', area_options($pdo, $site))]);
}
render_screen($cur === null ? 'New receipt' : 'Change ' . $cur['number'], view('receipts/form.php', ['cur' => $cur, 'areas' => area_options($pdo, $site), 'poOptions' => po_options_for_supplier($pdo, $supplier, $site),
    'poLines' => $po === null ? [] : po_open_lines($pdo, $po['purchase_order_id']), 'prefill' => $prefill]), ['activeNav' => 'receipt-list', 'screen' => $cur === null ? 'receipt-add' : 'receipt-edit', 'entity' => 'goods_receipt', 'recordId' => $id === null ? '' : (string) $id]);
