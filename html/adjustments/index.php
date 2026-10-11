<?php
declare(strict_types=1);
/** /adjustments/ — the adjustments at this site (screen `adjustment-list`; params status, reason, page). counts.approve opens it (the menu's right). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['status' => request_string('status'), 'reason' => request_string('reason')];
$page = max(1, request_integer('page') ?? 1);
$r = adjustments_list($pdo, $site, $filters, $page);
log_screen_view($pdo, 'adjustment-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'total' => $r['total'], 'adjustments' => array_map(static fn (array $j): array => ['adjustment_id' => $j['adjustment_id'], 'number' => $j['number'], 'status' => $j['status'], 'area' => $j['area'], 'reason' => $j['reason_code'], 'lines' => $j['line_count']], $r['rows'])]); }
$notices = ['cancelled' => ['success', 'The adjustment is cancelled; nothing changed.']];
render_screen('Adjustments', view('adjustments/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'reasons' => reasons_for($pdo, 'adjustment'), 'mayAdjust' => has_right('counts.approve'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'adjustment-list', 'screen' => 'adjustment-list', 'entity' => 'inventory_adjustment']);
