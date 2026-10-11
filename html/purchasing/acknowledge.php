<?php
declare(strict_types=1);
/** Action `purchase_order_acknowledge`: purchasing.draft — the supplier confirmed by phone or email, a person records it. ri_po_supplier_event('acknowledge', …, source `manual`, the member). Notifies the PO's creator and its sender (po_ack). Log purchase_order.acknowledge. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
$me = (int) current_member_id();
$po = po_from_request($pdo);
$errors = [];
$date = req_has('expected_on') ? po_date(req_val('expected_on'), 'expected_on', 'The delivery date', $errors) : null;
$ref = ri_text('supplier_order_ref', null, 80, 'The supplier\'s reference', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
ri_guard($pdo, static function () use ($pdo, $po, $me, $date, $ref): void {
    $pdo->beginTransaction();
    $pdo->prepare("SELECT ri_po_supplier_event(:p, 'acknowledge', NULL, :d::date, NULL, :r, 'manual', :m)")->execute(['p' => $po['purchase_order_id'], 'd' => $date, 'r' => $ref, 'm' => $me]);
    $g = po_for($pdo, (int) $po['purchase_order_id']);
    po_notify($pdo, $g, 'po_ack', $po['supplier'] . ' acknowledged ' . $po['number'], 'Recorded by ' . (current_member()['display_name'] ?? 'a colleague') . ($ref ? ' · their reference ' . $ref : '') . ($g['expected_on'] ? ' · delivery ' . format_date($g['expected_on']) : ''));
    po_log($pdo, 'purchase_order.acknowledge', (int) $po['purchase_order_id'], ['before' => ['status' => $po['status'], 'expected_on' => $po['expected_on']], 'after' => ['status' => $g['status'], 'expected_on' => $g['expected_on'], 'supplier_order_ref' => $g['supplier_order_ref'], 'source' => 'manual']]);
    $pdo->commit();
});
$g = po_for($pdo, (int) $po['purchase_order_id']);
ri_done('Recorded: ' . $po['supplier'] . ' acknowledged ' . $po['number'] . ($g['expected_on'] ? ', delivery ' . format_date($g['expected_on']) : ''), (int) $po['purchase_order_id'], ri_land('/purchasing/' . $po['purchase_order_id'], 'acknowledged'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $po['purchase_order_id'], 'status' => $g['status'], 'expected_on' => $g['expected_on'], 'supplier_order_ref' => $g['supplier_order_ref']]);
