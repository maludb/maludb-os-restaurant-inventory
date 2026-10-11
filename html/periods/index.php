<?php
declare(strict_types=1);
/** /periods/ — the periods of this site by fiscal year (screen `period-list`; params year, status). Every reader at the site opens it (ri.read); the figures are walled per field. Close sits on the first open period past its end (periods.close), Reopen on the last closed one (settings.manage), Make the year's periods when the year has none. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$years = period_years($pdo, $site);
$year = request_integer('year') ?? period_default_year($pdo, $site);
$status = request_string('status');
$status = isset(PERIOD_STATUS_CHIPS[$status]) ? $status : '';
$all = periods($pdo, $site, $year);
$rows = $status === '' ? $all : array_values(array_filter($all, static fn (array $p): bool => $p['status'] === $status));
$marks = period_marks($pdo, $site);
$sees = sees_cost($site);
$figures = [];
foreach ($rows as $p) { $figures[$p['period_id']] = period_figures($pdo, $p); }
$toClose = period_to_close($pdo, $site);
$undepleted = $toClose === null ? [] : period_undepleted_days($pdo, $toClose);
$closingCounts = $toClose === null ? [] : period_closing_count_options($pdo, $toClose);
log_screen_view($pdo, 'period-list', ['after' => ['year' => $year]]);
if (wants_json()) {
    respond_screen(['year' => $year, 'years' => $years, 'status' => $status, 'periods' => array_map(static fn (array $p): array => present_period($p, $figures[$p['period_id']], $sees) + ['closes_next' => in_array($p['period_id'], $marks['closes_next'], true), 'can_reopen' => in_array($p['period_id'], $marks['reopenable'], true)], $rows),
        'to_close' => $toClose === null ? null : $toClose['period_id'], 'undepleted_days' => $undepleted, 'closing_counts' => array_map(static fn (array $c): array => ['count_id' => $c['count_id'], 'number' => $c['number'], 'as_of' => json_ts($c['as_of'])], $closingCounts)]);
}
$notices = ['ensured' => ['success', 'The year\'s periods are made.'], 'closed' => ['success', 'The period is closed — its figures are frozen.'], 'reopened' => ['success', 'The period is open again — its figures are live.']];
render_screen('Periods', view('periods/index.php', ['rows' => $rows, 'yearEmpty' => $all === [], 'figures' => $figures, 'marks' => $marks, 'year' => $year, 'years' => $years, 'status' => $status, 'toClose' => $toClose, 'undepleted' => $undepleted, 'closingCounts' => $closingCounts,
    'seesCost' => $sees, 'mayClose' => has_right('periods.close'), 'mayReopen' => has_right('settings.manage'), 'calendars' => calendars($pdo), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'period-list', 'screen' => 'period-list', 'entity' => 'period']);
