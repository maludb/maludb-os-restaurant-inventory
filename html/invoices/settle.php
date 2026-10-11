<?php
declare(strict_types=1);
/**
 * Action `invoice_match_settle`: invoices.write (an agent's call pauses as `other` — it changes the books) — ri_invoice_settle(): the invoice becomes the truth. A price difference writes price history
 * dated the RECEIPT's moment (source invoice) and the supplier item's last price; a quantity difference posts a data_entry adjustment dated the receipt; a credit note reverses on the original
 * date. The invoice is `settled`. Log invoice.settle.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$errors = [];
$note = ri_text('note', null, 500, 'The note', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$before = invoice_lines($pdo, (int) $inv['invoice_id']);
$prices = count(array_filter($before, static fn (array $l): bool => in_array($l['match_state'], ['price_diff', 'both_diff'], true)));
$qtys = count(array_filter($before, static fn (array $l): bool => in_array($l['match_state'], ['qty_diff', 'both_diff'], true) && (float) $l['diff_qty'] != 0.0));
ri_guard($pdo, static function () use ($pdo, $inv, $me, $note, $prices, $qtys): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT * FROM ri_invoice_settle(:i, :by, :n)')->execute(['i' => $inv['invoice_id'], 'by' => $me, 'n' => $note]);
    invoice_log($pdo, 'invoice.settle', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => 'settled', 'price_differences' => $prices, 'quantity_corrections' => $qtys, 'total' => $inv['total']] + ($note !== null ? ['note' => $note] : [])]);
    $pdo->commit();
});
$hist = array_map('intval', $pdo->query("SELECT id FROM price_history WHERE reference_kind = 'invoice' AND reference_id = " . (int) $inv['invoice_id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$adj = array_map('intval', $pdo->query("SELECT id FROM inventory_transactions WHERE reference_kind = 'invoice' AND reference_id = " . (int) $inv['invoice_id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
ri_done('Settled ' . $inv['number'] . ': ' . $prices . ' price difference' . ($prices === 1 ? '' : 's') . ' written to cost history, ' . $qtys . ' quantity correction' . ($qtys === 1 ? '' : 's'), (int) $inv['invoice_id'],
    ri_land('/invoices/' . $inv['invoice_id'], 'settled'), 'invoiceChanged', ['invoice_id' => (int) $inv['invoice_id'], 'status' => 'settled', 'price_history_ids' => $hist, 'adjustment_transaction_ids' => $adj, 'price_differences' => $prices, 'quantity_corrections' => $qtys]);
