<?php
declare(strict_types=1);
/** Action `invoice_void`: invoices.write — a draft, matched or disputed invoice is voided (the reason kept on the invoice and in the log); a settled one is refused: enter a credit note. Log invoice.void. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$inv = invoice_from_request($pdo);
$errors = [];
$reason = ri_text('reason', null, 300, 'The reason', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
if ($inv['status'] === 'settled') { refuse(422, 'Invoice ' . $inv['number'] . ' is settled — enter a credit note instead.'); }
if ($inv['status'] === 'void') { refuse(422, 'Invoice ' . $inv['number'] . ' is already void.'); }
ri_guard($pdo, static function () use ($pdo, $inv, $reason): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE supplier_invoices SET status = 'void', notes = CASE WHEN :r::text IS NULL THEN notes ELSE COALESCE(notes || E'\n', '') || 'Voided: ' || :r2::text END WHERE id = :id AND status IN ('draft', 'matched', 'disputed')")
        ->execute(['r' => $reason, 'r2' => $reason, 'id' => $inv['invoice_id']]);
    invoice_log($pdo, 'invoice.void', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => 'void'] + ($reason !== null ? ['reason' => $reason] : [])]);
    $pdo->commit();
});
ri_done('Voided ' . $inv['number'], (int) $inv['invoice_id'], ri_land('/invoices/', 'voided'), 'invoiceChanged', ['invoice_id' => (int) $inv['invoice_id'], 'status' => 'void']);
