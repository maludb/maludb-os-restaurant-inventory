<?php
declare(strict_types=1);
/** /invoices/new (screen `invoice-add`; params receipt, purchase_order, supplier) and /invoices/{id}/edit (screen `invoice-edit`, a draft or a disputed invoice: the header, the lines, the match). invoices.write. The vhost sends /edit to this file. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('invoices.write');
$pdo = db();
$site = (int) current_site_id();
$id = request_integer('id');
$cur = $id === null ? null : invoice_or_404($pdo, $id);
if ($cur !== null) { invoice_require_editable($cur); }
$prefill = ['receipt' => request_string('receipt'), 'purchase_order' => request_string('purchase_order'), 'supplier' => request_string('supplier')];
$receipt = null;
if ($cur === null && ctype_digit($prefill['receipt'])) {
    $receipt = receipt_for($pdo, (int) $prefill['receipt']);
    if ($receipt === null || $receipt['scope_id'] !== $site) { respond_not_found('That receipt is not here.'); }
    if ($prefill['supplier'] === '' && $receipt['supplier_id'] !== null) { $prefill['supplier'] = (string) $receipt['supplier_id']; }
    if ($prefill['purchase_order'] === '' && $receipt['purchase_order_id'] !== null) { $prefill['purchase_order'] = (string) $receipt['purchase_order_id']; }
}
if ($cur === null && $receipt === null && ctype_digit($prefill['purchase_order'])) {
    $po = po_for($pdo, (int) $prefill['purchase_order']);
    if ($po === null || $po['scope_id'] !== $site) { respond_not_found('That purchase order is not here.'); }
    if ($prefill['supplier'] === '') { $prefill['supplier'] = (string) $po['supplier_id']; }
}
$supplierId = $cur['supplier_id'] ?? (ctype_digit($prefill['supplier']) ? (int) $prefill['supplier'] : null);
$receipts = $supplierId === null ? [] : receipts_for_supplier($pdo, $site, $supplierId, $cur['goods_receipt_id'] ?? ($receipt['goods_receipt_id'] ?? null));
$pos = $supplierId === null ? [] : pos_for_supplier($pdo, $site, $supplierId, isset($cur['purchase_order_id']) ? (int) $cur['purchase_order_id'] : (ctype_digit($prefill['purchase_order']) ? (int) $prefill['purchase_order'] : null));
log_screen_view($pdo, $cur === null ? 'invoice-add' : 'invoice-edit', $cur === null ? [] : ['purchase_order_id' => $cur['purchase_order_id']]);
if ($cur === null) {
    if (wants_json()) { respond_screen(['prefill' => $prefill, 'receipts' => $receipts, 'purchase_orders' => $pos, 'receipt_lines' => $receipt === null ? [] : receipt_lines_for_invoice($pdo, $receipt['goods_receipt_id'])]); }
    render_screen('Enter an invoice', view('invoices/form.php', ['prefill' => $prefill, 'receipts' => $receipts, 'pos' => $pos, 'receipt' => $receipt, 'receiptLines' => $receipt === null ? [] : receipt_lines_for_invoice($pdo, $receipt['goods_receipt_id']), 'today' => site_today($site)]),
        ['activeNav' => 'invoice-list', 'screen' => 'invoice-add', 'entity' => 'supplier_invoice']);
    exit;
}
$lines = invoice_lines($pdo, $id);
if (wants_json()) { respond_screen(['invoice' => present_invoice($cur, $lines), 'receipts' => $receipts, 'purchase_orders' => $pos]); }
$line = null;
$lineId = request_integer('line');
if ($lineId !== null) { foreach ($lines as $l) { if ($l['invoice_line_id'] === $lineId) { $line = $l; } } }
$iid = $line['ingredient_id'] ?? null;
$unitOptions = $iid !== null ? unit_options_for($pdo, $iid, 'receive') : array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo));
$rlines = $cur['goods_receipt_id'] === null ? [] : receipt_lines_for_invoice($pdo, (int) $cur['goods_receipt_id']);
$notices = ['created' => ['success', 'The invoice is started. Correct the lines against the paper, then Match.'], 'saved' => ['success', 'Saved.'], 'line_saved' => ['success', 'Line saved.'], 'line_removed' => ['success', 'Line removed.']];
render_screen('Change ' . $cur['number'], view('invoices/edit.php', ['inv' => $cur, 'lines' => $lines, 'line' => $line, 'unitOptions' => $unitOptions, 'receipts' => $receipts, 'pos' => $pos, 'rlines' => $rlines, 'today' => site_today($site),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'invoice-list', 'screen' => 'invoice-edit', 'entity' => 'supplier_invoice', 'recordId' => (string) $id]);
