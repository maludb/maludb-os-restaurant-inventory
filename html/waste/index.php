<?php
declare(strict_types=1);
/** /waste/ — the waste logs at this site (screen `waste-list`; params from, to, stage, reason, station, page, highlight); the week's tiles by stage and the reasons (cost walled). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['from' => request_string('from'), 'to' => request_string('to'), 'stage' => request_string('stage'), 'reason' => request_string('reason'), 'station' => request_string('station')];
$page = max(1, request_integer('page') ?? 1);
$r = waste_list($pdo, $site, $filters, $page);
$sees = sees_cost($site);
$week = waste_week($pdo, $site, $filters['from'] !== '' ? $filters['from'] : date('Y-m-d', strtotime(site_today($site) . ' -6 days')), $filters['to'] !== '' ? $filters['to'] : site_today($site));
log_screen_view($pdo, 'waste-list');
if (wants_json()) { respond_screen(['filters' => $filters + ['from' => $r['from']], 'total' => $r['total'], 'waste' => array_map(static fn (array $w): array => present_waste($w, $sees), $r['rows']), 'week' => $week]); }
if ($filters['from'] === '') { $filters['from'] = $r['from']; }
$notices = ['logged' => ['success', 'Logged — it left the shelf at its cost.'], 'reviewed' => ['success', 'Reviewed.'], 'voided' => ['success', 'Voided — its movements are reversed.']];
render_screen('Waste', view('waste/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'week' => $week, 'reasons' => reasons_for($pdo, 'waste'), 'seesCost' => $sees,
    'mayLog' => has_right('stock.waste'), 'mayReview' => has_right('waste.review'), 'highlight' => request_integer('highlight'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'waste-list', 'screen' => 'waste-list', 'entity' => 'waste_log']);
