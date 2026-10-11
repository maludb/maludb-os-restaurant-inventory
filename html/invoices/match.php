<?php
declare(strict_types=1);
/** Action `invoice_match`: invoices.write — ri_invoice_match(): every line against its receipt line (the named one, or found by supplier item on the receipt it bills) within the supplier's tolerances; the invoice is `matched` when every line agrees and `disputed` when any differs (the invoice's creator is told: invoice_dispute). A settled or void invoice is refused in the function's words. Log invoice.match. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$inv = invoice_from_request($pdo);
$status = ri_guard($pdo, static function () use ($pdo, $inv): string {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT status FROM ri_invoice_match(:i)');
    $st->execute(['i' => $inv['invoice_id']]);
    $status = (string) $st->fetchColumn();
    $states = array_count_values(array_column(invoice_lines($pdo, (int) $inv['invoice_id']), 'match_state'));
    invoice_log($pdo, 'invoice.match', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => $status, 'states' => $states]]);
    if ($status === 'disputed' && $inv['created_by'] !== null) {
        $pdo->prepare("SELECT ri_notify(:m, 'invoice_dispute', 'supplier_invoice', :id, :t, :b, :d, false, :s)")->execute(['m' => $inv['created_by'], 'id' => $inv['invoice_id'], 't' => $inv['supplier'] . ' ' . $inv['invoice_no'] . ' does not match its receipt',
            'b' => (int) ($states['qty_diff'] ?? 0) + (int) ($states['both_diff'] ?? 0) . ' quantity and ' . ((int) ($states['price_diff'] ?? 0) + (int) ($states['both_diff'] ?? 0)) . ' price differences, ' . (int) ($states['unordered'] ?? 0) . ' unordered. Open ' . $inv['number'] . ' to correct or settle.',
            'd' => 'invoice_dispute:' . $inv['invoice_id'] . ':' . date('YmdHi'), 's' => $inv['scope_id']]);
    }
    $pdo->commit();
    return $status;
});
$lines = invoice_lines($pdo, (int) $inv['invoice_id']);
ri_done('Matched ' . $inv['number'] . ': ' . ($status === 'matched' ? 'every line agrees' : 'some lines differ'), (int) $inv['invoice_id'], ri_land('/invoices/' . $inv['invoice_id'], $status), 'invoiceChanged',
    ['invoice_id' => (int) $inv['invoice_id'], 'status' => $status, 'lines' => array_map(static fn (array $l): array => ['line_id' => $l['invoice_line_id'], 'line_no' => $l['line_no'], 'match_state' => $l['match_state'], 'diff_qty' => $l['diff_qty_unit'] ?? null, 'diff_value' => $l['diff_value']], $lines)]);
