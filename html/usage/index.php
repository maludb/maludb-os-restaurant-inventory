<?php
declare(strict_types=1);
/** /usage/ — actual vs theoretical (screen `usage-report`; params from, to, pick, category, basis, sort, format=csv). Every reader at the site opens it (ri.read): units for all, values only where cost is seen. ri_usage() is the referee; the window is up to 400 days. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$w = usage_window_or_refuse($pdo, $site);
$filters = ['category' => request_string('category'), 'basis' => request_string('basis'), 'sort' => request_string('sort')];
$u = usage($pdo, $site, $w['from'], $w['to'], $filters);
if (request_string('format') === 'csv') {
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'usage-report', 'scope_id' => $site, 'after' => ['from' => $w['from'], 'to' => $w['to'], 'basis' => $u['basis'], 'format' => 'csv', 'rows' => count($u['rows'])]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usage-' . $w['from'] . '-' . $w['to'] . '.csv"');
    echo usage_csv($u, 'ingredient');
    exit;
}
log_screen_view($pdo, 'usage-report', ['after' => ['from' => $w['from'], 'to' => $w['to'], 'basis' => $u['basis']]]);
if (wants_json()) {
    $sales = $u['withheld'] ? null : number_format((float) one_value($pdo, 'SELECT ri_food_sales(:s, :f::date, :t::date, NULL)', ['s' => $site, 'f' => $w['from'], 't' => $w['to']]), 2, '.', '');
    respond_screen(['window' => ['from' => $w['from'], 'to' => $w['to'], 'pick' => $w['pick']]] + present_usage($u) + ($sales === null ? ['cost_withheld' => true] : ['food_sales' => $sales, 'actual_pct' => period_pct($u['totals']['actual_value'], $sales), 'theoretical_pct' => period_pct($u['totals']['theoretical_value'], $sales)]));
}
render_screen('Actual vs theoretical', view('usage/index.php', ['u' => $u, 'w' => $w, 'filters' => $filters, 'categories' => categories($pdo), 'mayExport' => has_right('exports.all'), 'seesCost' => !$u['withheld']]),
    ['activeNav' => 'usage-report', 'screen' => 'usage-report', 'entity' => 'ingredient']);
