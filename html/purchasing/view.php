<?php
declare(strict_types=1);
/** /purchasing/{id} — one purchase order (screen `purchase-order-view`; param tab = events | receipts | invoices | link). Reads open to every reader at the site (ri.read); cost, the internal note and the invoices are walled. JSON: present_po() + the events. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$sees = sees_cost($site);
$po = po_or_404($pdo, request_integer('id') ?? request_integer('purchase_order'));
$id = $po['purchase_order_id'];
$lines = po_lines($pdo, $id);
$mayLink = has_right('purchasing.send');
$tab = request_string('tab', 'events');
if (!in_array($tab, ['events', 'receipts', 'invoices', 'link'], true) || ($tab === 'invoices' && !$sees) || ($tab === 'link' && !$mayLink)) { $tab = 'events'; }
$link = $mayLink ? po_link($pdo, $id) : null;
log_screen_view($pdo, 'purchase-order-view', ['purchase_order_id' => $id]);
if (wants_json()) {
    respond_screen(['purchase_order' => present_po($po, $lines, $sees, $mayLink, $link), 'events' => array_map('present_event', po_events($pdo, $id)),
        'receipts' => array_map(static fn (array $g): array => ['receipt_id' => $g['goods_receipt_id'], 'number' => $g['number'], 'status' => $g['status'], 'received_on' => $g['received_on']], po_receipts($pdo, $id)),
        'invoices' => $sees ? array_map(static fn (array $i): array => ['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'invoice_no' => $i['invoice_no'], 'status' => $i['status'], 'total' => $i['total']], po_invoices($pdo, $id)) : [],
        'may' => ['edit' => has_right('purchasing.draft'), 'send' => $mayLink, 'receive' => has_right('stock.receive'), 'cost' => $sees]]);
}
$once = po_link_once_take($id);
$notices = ['sent' => ['success', 'Sent. The supplier has the order and its link.'], 'sent_unmailed' => ['warning', 'Sent, but the email did not go. Copy the link below and send it yourself.'], 'placed' => ['success', 'Marked placed.'],
    'acknowledged' => ['success', 'Recorded: the supplier acknowledged it.'], 'declined' => ['success', 'Recorded: the line is declined.'], 'closed' => ['success', 'Closed.'], 'rotated' => ['success', 'A new link is made. The old one no longer works.'],
    'messaged' => ['success', 'The message is sent.'], 'cancelled_unmailed' => ['warning', 'Cancelled. The supplier could not be emailed — tell them.'], 'saved' => ['success', 'Saved.']];
render_screen($po['number'], view('purchasing/view.php', ['po' => $po, 'lines' => $lines, 'tab' => $tab, 'events' => $tab === 'events' ? po_events($pdo, $id) : [], 'receipts' => $tab === 'receipts' ? po_receipts($pdo, $id) : [],
    'invoices' => $tab === 'invoices' ? po_invoices($pdo, $id) : [], 'link' => $link, 'once' => $once === null ? null : po_door_url($once), 'window' => supplier_window($pdo, $po['supplier_id'], $site), 'seesCost' => $sees,
    'may' => ['draft' => has_right('purchasing.draft'), 'send' => $mayLink, 'receive' => has_right('stock.receive'), 'invoice' => has_right('invoices.write')],
    'trail' => find_activity_by_key($pdo, 'purchase_order_id', $id), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-view', 'entity' => 'purchase_order', 'recordId' => (string) $id]);
