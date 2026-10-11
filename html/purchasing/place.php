<?php
declare(strict_types=1);
/**
 * Action `purchase_order_place`: purchasing.send (money_out pauses an agent) — the order was placed on the supplier's portal or by phone (or API or EDI): ri_po_send() with that way and
 * the supplier's order reference (the `placed` event); no email. The link is minted anyway — the supplier may still acknowledge through it when it is sent by hand — and shown once on
 * the page. Log purchase_order.place. Location /purchasing/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.send');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$sees = sees_cost($site);
$po = po_from_request($pdo);
$s = find_supplier($pdo, (int) $po['supplier_id']);
$errors = [];
$default = in_array($s['order_method'], ['portal', 'phone', 'api', 'edi'], true) ? $s['order_method'] : 'portal';
$via = ri_choice('via', $default, ['portal', 'phone', 'api', 'edi'], 'The way it was placed', $errors, false) ?? $default;
$ref = ri_text('supplier_order_ref', null, 80, 'The supplier\'s reference', $errors, true);
if (!req_has('supplier_order_ref') && !isset($errors['supplier_order_ref'])) { $errors['supplier_order_ref'] = 'The supplier\'s reference is required.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$raw = ri_guard($pdo, static function () use ($pdo, $po, $me, $via, $ref): string {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT * FROM ri_po_send(:p, :by, :via, :ref)')->execute(['p' => $po['purchase_order_id'], 'by' => $me, 'via' => $via, 'ref' => $ref]);
    $raw = (string) one_value($pdo, 'SELECT ri_supplier_link_mint(:p)', ['p' => $po['purchase_order_id']]);
    $g = po_for($pdo, (int) $po['purchase_order_id']);
    po_log($pdo, 'purchase_order.place', (int) $po['purchase_order_id'], ['before' => ['status' => 'draft'], 'after' => ['status' => 'sent', 'via' => $via, 'supplier_order_ref' => $ref, 'link_last4' => token_last4($raw)] + (sees_cost() ? ['total' => $g['total']] : [])]);
    $pdo->commit();
    return $raw;
});
po_link_once_set((int) $po['purchase_order_id'], $raw);
ri_done('Marked ' . $po['number'] . ' placed with ' . $s['name'] . ' (' . (SENT_VIA_WORDS[$via] ?? $via) . ', their reference ' . $ref . ')', (int) $po['purchase_order_id'], ri_land('/purchasing/' . $po['purchase_order_id'], 'placed'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $po['purchase_order_id'], 'number' => $po['number'], 'status' => 'sent', 'via' => $via, 'supplier_order_ref' => $ref, 'link_last4' => token_last4($raw)]);
