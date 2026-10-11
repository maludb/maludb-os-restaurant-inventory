<?php
declare(strict_types=1);
/** Action `purchase_order_line_decline`: purchasing.draft — the supplier cannot fill a line; a person records it (reason required). ri_po_supplier_event('decline', line, source `manual`). Notifies the creator and the sender (po_decline). Log purchase_order.line_decline. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
$me = (int) current_member_id();
[$l, $po] = po_line_from_request($pdo);
$errors = [];
$reason = ri_text('reason', null, 300, 'The reason', $errors, true);
if (!req_has('reason') && !isset($errors['reason'])) { $errors['reason'] = 'Say why the supplier cannot fill it.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
if (!in_array($l['status'], ['open', 'acknowledged'], true)) { refuse(422, 'Line ' . (int) $l['line_no'] . ' is ' . (PO_LINE_WORDS[$l['status']] ?? $l['status']) . ' — only an open line is declined.'); }
ri_guard($pdo, static function () use ($pdo, $po, $l, $me, $reason): void {
    $pdo->beginTransaction();
    $pdo->prepare("SELECT ri_po_supplier_event(:p, 'decline', :l, NULL, :r, NULL, 'manual', :m)")->execute(['p' => $po['purchase_order_id'], 'l' => $l['purchase_order_line_id'], 'r' => $reason, 'm' => $me]);
    po_notify($pdo, $po, 'po_decline', $po['supplier'] . ' declined ' . $l['ingredient'] . ' on ' . $po['number'], 'Recorded by ' . (current_member()['display_name'] ?? 'a colleague') . ' · ' . $reason);
    po_log($pdo, 'purchase_order.line_decline', (int) $po['purchase_order_id'], ['ingredient_id' => (int) $l['ingredient_id'], 'after' => ['line_id' => (int) $l['purchase_order_line_id'], 'ingredient_id' => (int) $l['ingredient_id'], 'reason' => $reason, 'source' => 'manual']]);
    $pdo->commit();
});
ri_done('Recorded: ' . $po['supplier'] . ' declined ' . $l['ingredient'] . ' on ' . $po['number'], (int) $l['purchase_order_line_id'], ri_land('/purchasing/' . $po['purchase_order_id'], 'declined'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $po['purchase_order_id'], 'line_id' => (int) $l['purchase_order_line_id']]);
