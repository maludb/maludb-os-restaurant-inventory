<?php
declare(strict_types=1);
/** /usage/cogs — COGS and food-cost % by period for a fiscal year, actual and theoretical, with the variance and a chart (screen `cogs-report`; param year). Every reader at the site opens it (ri.read); every value is walled ("—"). The open period is live. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$years = period_years($pdo, $site);
$year = request_integer('year') ?? period_default_year($pdo, $site);
$c = cogs_by_period($pdo, $site, $year);
log_screen_view($pdo, 'cogs-report', ['after' => ['year' => $year]]);
if (wants_json()) {
    $sees = !$c['withheld'];
    respond_screen(['fiscal_year' => $year, 'years' => $years, 'periods' => array_map(static fn (array $r): array => present_cogs_row($r, $sees), $c['rows']),
        'months' => $sees ? $c['months'] : [], 'totals' => $sees ? $c['totals'] : ['cost_withheld' => true]] + ($sees ? [] : ['cost_withheld' => true]));
}
render_screen('COGS', view('usage/cogs.php', ['c' => $c, 'years' => $years, 'year' => $year, 'seesCost' => !$c['withheld']]), ['activeNav' => 'cogs-report', 'screen' => 'cogs-report', 'entity' => 'period']);
