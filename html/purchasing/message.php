<?php
declare(strict_types=1);
/** Action `supplier_message`: purchasing.send (external_send pauses an agent) — an email to the supplier's order email (else its email), about an order when one is named. Logged with the subject only (never the body). A send that fails is refused in words. Log supplier.message. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.send');
$pdo = db();
$site = (int) current_site_id();
$sref = trim((string) (req_val('supplier') ?? ''));
$po = null;
if (req_has('purchase_order') && trim((string) req_val('purchase_order')) !== '') { $po = po_from_request($pdo); }
$s = $sref !== '' ? supplier_by_ref($pdo, $sref) : ($po !== null ? find_supplier($pdo, (int) $po['supplier_id']) : null);
if ($s === null) { ri_refuse_fields(['supplier' => $sref === '' ? 'Choose the supplier.' : 'That supplier is not here.']); }
if ($po !== null && (int) $po['supplier_id'] !== $s['supplier_id']) { ri_refuse_fields(['purchase_order' => 'Purchase order ' . $po['number'] . ' is to ' . $po['supplier'] . ', not ' . $s['name'] . '.']); }
$errors = [];
$body = ri_text('body', null, 4000, 'The message', $errors, true);
if (!req_has('body') && !isset($errors['body'])) { $errors['body'] = 'The message is required.'; }
$subject = ri_text('subject', null, 150, 'The subject', $errors) ?? ($po !== null ? 'About purchase order ' . $po['number'] : 'A message from ' . business_name($pdo));
if ($errors !== []) { ri_refuse_fields($errors); }
if (supplier_order_address($s) === null) { ri_refuse_fields(['supplier' => $s['name'] . ' has no email — set one on the supplier.']); }
$mail = supplier_message_send($s, $subject, (string) $body, $po, po_site_row($pdo, $site));
if (!$mail['ok']) { ri_refuse_fields(['body' => 'The message did not go: ' . $mail['detail']]); }
log_activity($pdo, 'supplier.message', 'supplier', $s['supplier_id'], ['scope_id' => $site, 'purchase_order_id' => $po['purchase_order_id'] ?? null, 'after' => ['subject' => $subject, 'purchase_order_id' => $po['purchase_order_id'] ?? null, 'mail' => 'sent']]);
ri_done('Sent your message to ' . $s['name'], $s['supplier_id'], ri_land($po !== null ? '/purchasing/' . $po['purchase_order_id'] : '/suppliers/' . $s['supplier_id'], 'messaged'), 'purchaseOrderChanged',
    ['supplier_id' => $s['supplier_id'], 'subject' => $subject, 'mail' => 'sent'] + ($po !== null ? ['purchase_order_id' => (int) $po['purchase_order_id']] : []));
