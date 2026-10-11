<?php
declare(strict_types=1);
/** /invoices/ — the supplier invoices at this site (screen `invoice-list`; params status, supplier, kind, from, to, page). Reads open to every reader (ri.read); the whole screen is cost: one who sees none is told so and gets no rows (the view is empty). Entering one needs invoices.write. JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$sees = sees_cost($site);
$filters = ['status' => request_string('status'), 'supplier' => request_string('supplier'), 'kind' => request_string('kind'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = $sees ? invoice_list($pdo, $site, $filters, $page) : ['rows' => [], 'total' => 0];
log_screen_view($pdo, 'invoice-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'invoices' => array_map('present_invoice_row', $r['rows']), 'cost_withheld' => !$sees, 'may' => ['write' => has_right('invoices.write')]]);
}
$notices = ['voided' => ['success', 'The invoice is void.']];
render_screen('Invoices', view('invoices/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'suppliers' => find_suppliers($pdo, $site, ['status' => 'active'], 500, 0)['rows'],
    'seesCost' => $sees, 'mayWrite' => has_right('invoices.write'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'invoice-list', 'screen' => 'invoice-list', 'entity' => 'supplier_invoice']);
