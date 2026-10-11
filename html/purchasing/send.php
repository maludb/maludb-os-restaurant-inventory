<?php
declare(strict_types=1);
/**
 * Action `purchase_order_send`: purchasing.send — a PERSON's word to pay (an agent's call pauses as money_out in the kernel before this runs). ri_po_send() makes the draft `sent`
 * (the `sent` event); the handler then mints the link (ri_supplier_link_mint, 60 days) and, AFTER the commit, emails the order with the link through MaluMail. A failure to send is
 * logged (after.mail = failed) and shown as a notice; the PO stays sent and the link is shown once on the page. `via` defaults to the supplier's order method; only email is sent here
 * (a portal, phone, API or EDI order is marked placed). Log purchase_order.send. Location /purchasing/{id}.
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
$via = ri_choice('via', $s['order_method'], array_keys(SENT_VIA_WORDS), 'The way it is sent', $errors, false) ?? $s['order_method'];
$message = ri_text('message', null, 2000, 'The message', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
if ($via !== 'email') { ri_refuse_fields(['via' => $s['name'] . ' takes orders by ' . (SENT_VIA_WORDS[$via] ?? $via) . ' — mark it placed instead (or choose email to send it).']); }
if (supplier_order_address($s) === null) {
    ri_refuse_fields(['via' => $s['name'] . ' has no order email — set one on the supplier or mark it placed.']);
}
$raw = ri_guard($pdo, static function () use ($pdo, $po, $me, $via): string {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT * FROM ri_po_send(:p, :by, :via, NULL)')->execute(['p' => $po['purchase_order_id'], 'by' => $me, 'via' => $via]);
    $raw = (string) one_value($pdo, 'SELECT ri_supplier_link_mint(:p)', ['p' => $po['purchase_order_id']]);
    $pdo->commit();
    return $raw;
});
$g = po_for($pdo, (int) $po['purchase_order_id']);
$mail = po_send_mail($pdo, $g, po_email_lines($pdo, (int) $g['purchase_order_id']), po_site_row($pdo, $site), $s, $raw, $message);
po_log($pdo, 'purchase_order.send', (int) $g['purchase_order_id'], ['before' => ['status' => 'draft'], 'after' => ['status' => 'sent', 'via' => $via, 'mail' => $mail['ok'] ? 'sent' : 'failed', 'link_last4' => token_last4($raw),
    'lines' => po_line_count($pdo, (int) $g['purchase_order_id'])] + ($sees ? ['total' => $g['total']] : []) + ($mail['ok'] ? [] : ['mail_detail' => $mail['detail']])]);
po_link_once_set((int) $g['purchase_order_id'], $raw);
ri_done('Sent ' . $g['number'] . ' to ' . $s['name'] . ($mail['ok'] ? ' by email' : ' — the email did not go (' . $mail['detail'] . ')'), (int) $g['purchase_order_id'],
    ri_land('/purchasing/' . $g['purchase_order_id'], $mail['ok'] ? 'sent' : 'sent_unmailed'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $g['purchase_order_id'], 'number' => $g['number'], 'status' => 'sent', 'via' => $via, 'mail' => $mail['ok'] ? 'sent' : 'failed', 'link_last4' => token_last4($raw)] + ($mail['ok'] ? [] : ['mail_detail' => $mail['detail']]));
