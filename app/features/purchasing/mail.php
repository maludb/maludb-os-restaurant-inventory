<?php
declare(strict_types=1);

/**
 * The mail a purchase order sends to a SUPPLIER (purchasing.md "Send, place …"): the order with its secure link, a message about an order, the
 * one-line cancellation. They go straight through MaluMail's REST send (app/mail.php) from the handler after the commit — not through the
 * outbox, which is for members. The body never carries our cost: the lines are in the supplier's units with the SKU and our reference, the
 * expected date, our notes to them and the link. A failure to send is an answer ({ok: false, detail}), never an exception: the PO stays sent.
 */

/** The supplier's door URL for a raw token — RI_PUBLIC_BASE_URL (the name the supplier can reach), else APP_URL. */
function po_door_url(string $raw): string
{
    $base = trim((string) env('RI_PUBLIC_BASE_URL', '')) ?: (string) env('APP_URL', '');
    return rtrim($base, '/') . '/s/' . $raw;
}

/** The last four characters of a token — all that is ever logged of it. */
function token_last4(string $raw): string
{
    return substr($raw, -4);
}

/** One send through MaluMail: ['ok' => bool, 'detail' => string, 'message_id' => ?string]. Never throws. */
function supplier_mail(string $to, string $subject, string $text, string $html): array
{
    $from = trim((string) env('MAIL_FROM', ''));
    if ($from === '') {
        return ['ok' => false, 'detail' => 'No sending address is set (MAIL_FROM).', 'message_id' => null];
    }
    try {
        $r = malumail_send(['from' => $from, 'from_name' => business_name(db()), 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html]);
    } catch (Throwable $e) {
        error_log('supplier mail: ' . $e->getMessage());
        return ['ok' => false, 'detail' => str_contains($e->getMessage(), 'MALUMAIL_API_KEY') ? 'Mail is not set up here (no MaluMail key).' : 'MaluMail could not be reached.', 'message_id' => null];
    }
    $rejected = (array) ($r['body']['rejected'] ?? []);
    if ($r['status'] >= 400 || ((array) ($r['body']['accepted'] ?? [$to])) === []) {
        $why = (string) ($rejected[0]['reason'] ?? ($r['body']['error'] ?? 'refused'));
        return ['ok' => false, 'detail' => 'MaluMail did not take it (' . $why . ').', 'message_id' => null];
    }
    return ['ok' => true, 'detail' => 'sent', 'message_id' => $r['message_id']];
}

/** Where a supplier is written to about orders: the order email, else its general email; null when neither. */
function supplier_order_address(array $s): ?string
{
    foreach (['order_email', 'email'] as $k) {
        $v = trim((string) ($s[$k] ?? ''));
        if ($v !== '') { return $v; }
    }
    return null;
}

/** The data both renderings of the order email read: the PO, its lines (no cost), the site, the supplier, the link and our note. */
function po_email_data(array $po, array $lines, array $site, array $supplier, string $link, ?string $message): array
{
    return ['po' => $po, 'lines' => $lines, 'site' => $site, 'supplier' => $supplier, 'link' => $link, 'message' => $message, 'business' => business_name(db())];
}

function po_email_html(array $d): string
{
    return view('emails/purchase-order.php', $d);
}

function po_email_text(array $d): string
{
    $po = $d['po'];
    $out = [$d['business'] . ' — purchase order ' . $po['number'], '', 'For: ' . $d['supplier']['name'], 'Deliver to: ' . $d['site']['name'] . (($d['site']['address'] ?? '') !== '' ? ', ' . $d['site']['address'] : '')];
    if (!empty($po['expected_on'])) { $out[] = 'Delivery wanted: ' . format_date($po['expected_on']); }
    $out[] = '';
    foreach ($d['lines'] as $l) {
        $out[] = fmt_num($l['qty_ordered'], 4) . ' ' . $l['purchase_unit_code'] . ' — ' . ($l['supplier_sku'] ? $l['supplier_sku'] . ' · ' : '') . $l['ingredient'];
    }
    if (trim((string) ($po['notes'] ?? '')) !== '') { $out[] = ''; $out[] = 'Notes: ' . trim((string) $po['notes']); }
    if (trim((string) ($d['message'] ?? '')) !== '') { $out[] = ''; $out[] = trim((string) $d['message']); }
    $out[] = '';
    $out[] = 'Acknowledge it, decline a line or set the delivery date here (no account needed):';
    $out[] = $d['link'];
    return implode("\n", $out) . "\n";
}

/** Email the order to the supplier: ['ok', 'detail', 'to']. $raw is the new link's token. */
function po_send_mail(PDO $pdo, array $po, array $lines, array $site, array $supplier, string $raw, ?string $message): array
{
    $to = supplier_order_address($supplier);
    if ($to === null) { return ['ok' => false, 'detail' => 'The supplier has no email.', 'to' => null]; }
    $d = po_email_data($po, $lines, $site, $supplier, po_door_url($raw), $message);
    return supplier_mail($to, 'Purchase order ' . $po['number'] . ' from ' . $d['business'], po_email_text($d), po_email_html($d)) + ['to' => $to];
}

/** The one-line cancellation to a supplier that was emailed the order. */
function po_cancel_mail(array $po, array $site, array $supplier, ?string $reason): array
{
    $to = supplier_order_address($supplier);
    if ($to === null) { return ['ok' => false, 'detail' => 'The supplier has no email.', 'to' => null]; }
    $d = ['po' => $po, 'site' => $site, 'supplier' => $supplier, 'reason' => $reason, 'business' => business_name(db())];
    $text = $d['business'] . ' has cancelled purchase order ' . $po['number'] . ' (' . $site['name'] . ').' . ($reason ? "\nReason: " . $reason : '') . "\nPlease do not fill it. The link you were sent no longer takes an answer.\n";
    return supplier_mail($to, 'Cancelled: purchase order ' . $po['number'], $text, view('emails/po-cancelled.php', $d)) + ['to' => $to];
}

/** A message to a supplier about an order (or in general). */
function supplier_message_send(array $supplier, string $subject, string $body, ?array $po, array $site): array
{
    $to = supplier_order_address($supplier);
    if ($to === null) { return ['ok' => false, 'detail' => $supplier['name'] . ' has no email — set one on the supplier.', 'to' => null]; }
    $d = ['supplier' => $supplier, 'subject' => $subject, 'body' => $body, 'po' => $po, 'site' => $site, 'business' => business_name(db())];
    $text = ($po ? 'About purchase order ' . $po['number'] . " (" . $site['name'] . ")\n\n" : '') . $body . "\n\n— " . $d['business'] . "\n";
    return supplier_mail($to, $subject, $text, view('emails/supplier-message.php', $d)) + ['to' => $to];
}
