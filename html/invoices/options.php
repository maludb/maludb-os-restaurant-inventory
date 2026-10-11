<?php
declare(strict_types=1);
/** GET /invoices/options?supplier= — the supplier's posted receipts (last 60 days) and sent purchase orders at this site as the invoice form's two selects (a block HTMX swaps when the supplier is chosen). invoices.write. JSON: the two lists. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('invoices.write');
$pdo = db();
$site = (int) current_site_id();
$s = request_string('supplier');
$receipts = ctype_digit($s) ? receipts_for_supplier($pdo, $site, (int) $s) : [];
$pos = ctype_digit($s) ? pos_for_supplier($pdo, $site, (int) $s) : [];
if (wants_json()) { respond_screen(['receipts' => $receipts, 'purchase_orders' => $pos]); }
echo view('invoices/_refs.php', ['receipts' => $receipts, 'pos' => $pos, 'receiptNow' => '', 'poNow' => '', 'errors' => []]);
