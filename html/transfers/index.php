<?php
declare(strict_types=1);
/** /transfers/ — transfers from or to this site (screen `transfer-list`; params status, page). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['status' => request_string('status')];
$page = max(1, request_integer('page') ?? 1);
$r = transfers_list($pdo, $site, $filters, $page);
log_screen_view($pdo, 'transfer-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'total' => $r['total'], 'transfers' => array_map(static fn (array $t): array => present_transfer($t, []), $r['rows'])]); }
$notices = ['cancelled' => ['success', 'The transfer is cancelled; nothing moved.']];
render_screen('Transfers', view('transfers/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'mayTransfer' => has_right('stock.transfer'), 'site' => $site, 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'transfer-list', 'screen' => 'transfer-list', 'entity' => 'inventory_transfer']);
