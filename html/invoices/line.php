<?php
declare(strict_types=1);
/** Action `invoice_line_set`: invoices.write; a draft or disputed invoice's line — INSERT, or UPDATE when `invoice_line` names one (what is left out stays; a changed line goes back to "not matched yet"). supplier_item | ingredient (optional — a line may be a description only), description, qty + unit, unit_price, receipt_line. Log invoice.line_set. Location /invoices/{id}/edit#invoice-line-row-{line}. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$cur = null;
if (req_has('invoice_line') && trim((string) req_val('invoice_line')) !== '') {
    $lid = (int) req_val('invoice_line');
    $cur = q_row($pdo, 'SELECT * FROM mcp_supplier_invoice_lines WHERE invoice_line_id = :id', ['id' => $lid]);
    if ($cur === null) { respond_not_found('That invoice line is not here.'); }
    $inv = invoice_or_404($pdo, (int) $cur['invoice_id']);
} else {
    $inv = invoice_from_request($pdo);
}
invoice_require_editable($inv);
$in = [];
foreach (['supplier_item', 'ingredient', 'description', 'qty', 'unit', 'unit_price', 'receipt_line'] as $k) { if (req_has($k)) { $in[$k] = req_val($k); } }
$errors = [];
$l = invoice_line_fields($pdo, $in, $inv, $cur, $errors);
if ($errors !== [] || $l === null) { ri_refuse_fields($errors ?: ['qty' => 'Say what is billed.']); }
$lineId = $cur === null ? null : (int) $cur['invoice_line_id'];
$id = ri_guard($pdo, static function () use ($pdo, $inv, $lineId, $l): int {
    $pdo->beginTransaction();
    $id = save_invoice_line($pdo, (int) $inv['invoice_id'], $lineId, $l);
    $row = q_row($pdo, 'SELECT qty, unit_code, unit_price, line_total, receipt_line_id FROM supplier_invoice_lines WHERE id = :id', ['id' => $id]);
    invoice_log($pdo, 'invoice.line_set', $inv, ['ingredient_id' => $l['ingredient_id'], 'after' => ['line_id' => $id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $row['qty'], 'unit' => $row['unit_code'], 'unit_price' => $row['unit_price'],
        'line_total' => $row['line_total'], 'receipt_line_id' => $row['receipt_line_id']] + ($lineId === null ? [] : ['changed' => true])]);
    $pdo->commit();
    return $id;
});
$g = invoice_for($pdo, (int) $inv['invoice_id']);
ri_done(($lineId === null ? 'Added ' : 'Changed ') . fmt_num($l['qty'], 4) . ' ' . $l['unit'] . ' of ' . ($l['description'] ?? 'an item') . ' on ' . $inv['number'], $id,
    ri_land('/invoices/' . $inv['invoice_id'] . '/edit', 'line_saved', 'invoice-line-row-' . $id), 'invoiceChanged', ['invoice_id' => (int) $inv['invoice_id'], 'invoice_line_id' => $id, 'total' => $g['total']]);
