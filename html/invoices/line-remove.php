<?php
declare(strict_types=1);
/** Action `invoice_line_remove`: invoices.write; a draft or disputed invoice's line. Log invoice.line_remove. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$lid = (int) (req_val('invoice_line') ?? req_val('line') ?? 0);
$cur = $lid > 0 ? q_row($pdo, 'SELECT * FROM mcp_supplier_invoice_lines WHERE invoice_line_id = :id', ['id' => $lid]) : null;
if ($cur === null) { respond_not_found('That invoice line is not here.'); }
$inv = invoice_or_404($pdo, (int) $cur['invoice_id']);
invoice_require_editable($inv);
ri_guard($pdo, static function () use ($pdo, $inv, $cur, $lid): void {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM supplier_invoice_lines WHERE id = :id')->execute(['id' => $lid]);
    invoice_log($pdo, 'invoice.line_remove', $inv, ['ingredient_id' => $cur['ingredient_id'], 'before' => ['line_id' => $lid, 'ingredient_id' => $cur['ingredient_id'], 'qty' => $cur['qty'], 'unit' => $cur['unit_code'], 'unit_price' => $cur['unit_price']]]);
    $pdo->commit();
});
ri_done('Removed line ' . (int) $cur['line_no'] . ' of ' . $inv['number'], $lid, ri_land('/invoices/' . $inv['invoice_id'] . '/edit', 'line_removed'), 'invoiceChanged', ['invoice_id' => (int) $inv['invoice_id']]);
