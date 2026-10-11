<?php
declare(strict_types=1);
/** /invoices/{id} — one invoice with its lines and their match (screen `invoice-view`). Reads open to every reader (ri.read); an invoice is cost, so one who sees none finds it not here (the view is empty). Match, Settle, Void need invoices.write. JSON: present_invoice(). */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$inv = invoice_or_404($pdo, request_integer('id') ?? request_integer('invoice'));
$id = $inv['invoice_id'];
$lines = invoice_lines($pdo, $id);
$pair = invoice_pair($pdo, $inv);
log_screen_view($pdo, 'invoice-view', ['purchase_order_id' => $inv['purchase_order_id']]);
if (wants_json()) {
    respond_screen(['invoice' => present_invoice($inv, $lines), 'pair' => $pair, 'may' => ['write' => has_right('invoices.write')]]);
}
$notices = ['matched' => ['success', 'Matched: every line agrees with the receipt.'], 'disputed' => ['warning', 'Matched: some lines differ — see below. Correct the paper or settle.'], 'settled' => ['success', 'Settled. The invoice is the truth.'], 'saved' => ['success', 'Saved.']];
render_screen($inv['number'], view('invoices/view.php', ['inv' => $inv, 'lines' => $lines, 'pair' => $pair, 'mayWrite' => has_right('invoices.write'), 'tz' => site_timezone(), 'trail' => find_record_activity($pdo, 'supplier_invoice', $id), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'invoice-list', 'screen' => 'invoice-view', 'entity' => 'supplier_invoice', 'recordId' => (string) $id]);
