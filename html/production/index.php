<?php
declare(strict_types=1);
/** /production/ — the prep batches at this site (screen `production-list`; params status, recipe, from, to, page). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['status' => request_string('status'), 'recipe' => request_string('recipe'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = batches_list($pdo, $site, $filters, $page);
$sees = sees_cost($site);
log_screen_view($pdo, 'production-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'total' => $r['total'], 'batches' => array_map(static fn (array $b): array => present_batch($b, $sees), $r['rows'])]); }
$notices = ['cancelled' => ['success', 'The batch is cancelled; nothing moved.']];
render_screen('Prep batches', view('production/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'seesCost' => $sees, 'mayProduce' => has_right('stock.produce'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'production-list', 'screen' => 'production-list', 'entity' => 'production_batch']);
