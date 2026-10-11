<?php
declare(strict_types=1);
/** /stock/movements — the ledger at this site (screen `stock-movements`; params ingredient, area, type, from, to, page; the last 30 days by default). JSON: the rows (cost withheld when walled). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['ingredient' => request_string('ingredient'), 'area' => request_string('area'), 'type' => request_string('type'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = stock_movements($pdo, $site, $filters, $page);
$sees = sees_cost($site);
log_screen_view($pdo, 'stock-movements', $filters['ingredient'] !== '' && ctype_digit($filters['ingredient']) ? ['ingredient_id' => (int) $filters['ingredient']] : []);
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'movements' => array_map(static fn (array $t): array => present_movement($t, $sees), $r['rows'])]);
}
$notices = ['reversed' => ['success', 'Reversed — the opposite quantity is posted.']];
render_screen('Movements', view('stock/movements.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'areas' => area_options($pdo, $site), 'seesCost' => $sees, 'mayReverse' => has_right('counts.approve'),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'stock-movements', 'screen' => 'stock-movements', 'entity' => 'inventory_transaction']);
