<?php
declare(strict_types=1);
/** /receipts/ — the receipts at this site (screen `receipt-list`; params status, supplier, from, to, page). JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['status' => request_string('status'), 'supplier' => request_string('supplier'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = receipts_list($pdo, $site, $filters, $page);
log_screen_view($pdo, 'receipt-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'receipts' => array_map(static fn (array $g): array => ['receipt_id' => $g['goods_receipt_id'], 'number' => $g['number'], 'status' => $g['status'], 'supplier' => $g['supplier'],
        'po_number' => $g['po_number'], 'area' => $g['area'], 'received_on' => $g['received_on'], 'lines' => $g['lines'], 'temperatures_ok' => $g['temps_ok'] === null ? null : (bool) $g['temps_ok']], $r['rows'])]);
}
$notices = ['cancelled' => ['success', 'The receipt is cancelled; nothing was posted.']];
render_screen('Receiving', view('receipts/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'suppliers' => find_suppliers($pdo, $site, ['status' => 'active'], 500, 0)['rows'],
    'mayReceive' => has_right('stock.receive'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'receipt-list', 'screen' => 'receipt-list', 'entity' => 'goods_receipt']);
