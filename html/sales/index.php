<?php
declare(strict_types=1);
/** /sales/?from=&to=&page=&highlight=&did= — screen sales-day-list: the days of this site (checks, covers, net, comps, voids), the run's status chip (none / done / partial "unmapped N" / void), the value depleted (walled); Deplete on a day with no live run, Re-run on a day due; Deplete all due. The last 30 days by default. */
require_once dirname(__DIR__, 2) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$to = request_date('to') ?: date('Y-m-d');
$from = request_date('from') ?: date('Y-m-d', strtotime($to . ' -30 days'));
$page = max(1, request_integer('page') ?? 1);
$r = days_list($pdo, $site, $from, $to, $page);
$due = array_values(array_filter($r['rows'], static fn ($d) => (bool) $d['due']));
log_screen_view($pdo, 'sales-day-list');
if (wants_json()) { respond_screen(['from' => $from, 'to' => $to, 'page' => $page, 'total' => $r['total'], 'sees_cost' => sees_cost($site), 'days' => array_map('present_day', $r['rows'])]); }
$highlight = array_filter(explode(',', request_string('highlight')));
$did = request_string('did');
render_screen('Sales by day', view('pos/days.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'from' => $from, 'to' => $to, 'due' => $due, 'seesCost' => sees_cost($site), 'mayRun' => has_right('stock.receive', $site),
    'mayUpload' => has_right('stock.receive', $site), 'highlight' => $highlight, 'notice' => $did !== '' ? ['success', mb_substr($did, 0, 200)] : null]), ['activeNav' => 'sales-day-list', 'screen' => 'sales-day-list', 'entity' => 'pos_day']);
