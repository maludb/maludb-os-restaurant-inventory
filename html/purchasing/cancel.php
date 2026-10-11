<?php
declare(strict_types=1);
/**
 * Action `purchase_order_cancel`: purchasing.send (deletion pauses an agent) — a draft is cancelled; a sent one with NOTHING received is cancelled with a `cancelled` event, and the supplier
 * is emailed a one-line cancellation when it was sent by email (after the commit; a failure is a notice, the order stays cancelled). Anything received is refused: receive it or close it
 * short. Log purchase_order.cancel. Location the PO.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.send');
$pdo = db();
$me = (int) current_member_id();
$po = po_from_request($pdo);
$errors = [];
$reason = ri_text('reason', null, 300, 'The reason', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
if (!in_array($po['status'], ['draft', 'sent', 'acknowledged', 'partial', 'received'], true)) { refuse(422, 'Purchase order ' . $po['number'] . ' is ' . (PO_STATUS_WORDS[$po['status']] ?? $po['status']) . ' — nothing to cancel.'); }
$received = (float) one_value($pdo, 'SELECT COALESCE(sum(qty_received_base), 0) FROM purchase_order_lines WHERE purchase_order_id = :p', ['p' => $po['purchase_order_id']]);
if ($po['status'] === 'partial' || $po['status'] === 'received' || $received > 0) { refuse(422, 'Purchase order ' . $po['number'] . ' has been received against — receive it or close it short.'); }
ri_guard($pdo, static function () use ($pdo, $po, $me, $reason): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE purchase_order_lines SET status = 'cancelled' WHERE purchase_order_id = :p AND status IN ('open', 'acknowledged', 'declined')")->execute(['p' => $po['purchase_order_id']]);
    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', cancelled_by = :by, cancelled_at = now(), cancel_reason = :r WHERE id = :p AND status IN ('draft', 'sent', 'acknowledged')")->execute(['by' => $me, 'r' => $reason, 'p' => $po['purchase_order_id']]);
    if ($po['status'] !== 'draft') {
        $pdo->prepare("INSERT INTO purchase_order_events (purchase_order_id, kind, source, reason, member_id) VALUES (:p, 'cancelled', 'manual', :r, :by)")->execute(['p' => $po['purchase_order_id'], 'r' => $reason, 'by' => $me]);
    }
    $pdo->commit();
});
$mail = ['ok' => true, 'detail' => '', 'to' => null];
$emailed = $po['status'] !== 'draft' && $po['sent_via'] === 'email';
if ($emailed) {
    $s = find_supplier($pdo, (int) $po['supplier_id']);
    $mail = po_cancel_mail($po, po_site_row($pdo, (int) $po['scope_id']), $s, $reason);
}
po_log($pdo, 'purchase_order.cancel', (int) $po['purchase_order_id'], ['before' => ['status' => $po['status']], 'after' => ['status' => 'cancelled', 'reason' => $reason] + ($emailed ? ['mail' => $mail['ok'] ? 'sent' : 'failed'] : [])]);
ri_done('Cancelled ' . $po['number'] . ($emailed ? ($mail['ok'] ? ' — ' . $po['supplier'] . ' is told' : ' — the email to ' . $po['supplier'] . ' did not go (' . $mail['detail'] . ')') : ''), (int) $po['purchase_order_id'],
    ri_land($po['status'] === 'draft' ? '/purchasing/' : '/purchasing/' . $po['purchase_order_id'], $mail['ok'] ? 'cancelled' : 'cancelled_unmailed'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $po['purchase_order_id'], 'status' => 'cancelled'] + ($emailed ? ['mail' => $mail['ok'] ? 'sent' : 'failed'] : []));
