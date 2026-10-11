<?php
declare(strict_types=1);
/** /purchasing/ — the purchase orders at this site (screen `purchase-order-list`; params status, supplier, from, to, page). Reads open to every reader at the site (ri.read), cost walled per field. JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$sees = sees_cost($site);
$filters = ['status' => request_string('status'), 'supplier' => request_string('supplier'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = po_list($pdo, $site, $filters, $page);
log_screen_view($pdo, 'purchase-order-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'purchase_orders' => array_map(static fn (array $p): array => present_po_row($p, $sees), $r['rows']),
        'may' => ['draft' => has_right('purchasing.draft'), 'send' => has_right('purchasing.send'), 'cost' => $sees]]);
}
$windows = [];
foreach ($r['rows'] as $p) {
    if ($p['status'] === 'draft' && !isset($windows[$p['supplier_id']])) { $windows[$p['supplier_id']] = supplier_window($pdo, $p['supplier_id'], $site); }
}
$notices = ['cancelled' => ['success', 'The purchase order is cancelled.']];
render_screen('Purchase orders', view('purchasing/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'suppliers' => find_suppliers($pdo, $site, ['status' => 'active'], 500, 0)['rows'],
    'windows' => $windows, 'seesCost' => $sees, 'mayDraft' => has_right('purchasing.draft'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-list', 'entity' => 'purchase_order']);
