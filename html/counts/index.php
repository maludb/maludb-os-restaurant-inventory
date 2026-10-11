<?php
declare(strict_types=1);
/** /counts/ — the counts at this site (screen `count-list`; params status, kind, from, to, page). Every reader at the site opens it (ri.read); starting one is stock.count's. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['status' => request_string('status'), 'kind' => request_string('kind'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = counts($pdo, $site, $filters, COUNT_PAGE, ($page - 1) * COUNT_PAGE);
log_screen_view($pdo, 'count-list');
$sees = sees_cost($site);
if (wants_json()) { respond_screen(['filters' => $filters, 'total' => $r['total'], 'page' => $page, 'counts' => array_map(static fn (array $c): array => present_count($c, $sees), $r['rows'])]); }
$notices = ['discarded' => ['success', 'The count is discarded; nothing was posted.'], 'started' => ['success', 'The count is started.']];
render_screen('Counts', view('counts/index.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'mayFill' => has_right('stock.count'), 'seesCost' => $sees,
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'count-list', 'screen' => 'count-list', 'entity' => 'inventory_count']);
