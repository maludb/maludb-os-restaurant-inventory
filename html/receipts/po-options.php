<?php
declare(strict_types=1);
/** GET /receipts/po-options?supplier= — the supplier's open POs at this site as <option>s (Pattern A, the header form's dependent select). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.receive');
$s = request_string('supplier');
$opts = $s !== '' && ctype_digit($s) ? po_options_for_supplier(db(), (int) $s, (int) current_site_id()) : [];
if (wants_json()) { respond_screen(['purchase_orders' => $opts]); }
echo view('receipts/_po-options.php', ['options' => $opts, 'selected' => '']);
