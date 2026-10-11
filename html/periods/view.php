<?php
declare(strict_types=1);
/** /periods/{period} — one period: the figures (frozen when closed, live while open), the usage by category, the counts, the late rows that landed in it, the trail (screen `period-view`; param tab). Every reader at the site opens it (ri.read); values are walled. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$p = period_or_404($pdo, request_integer('id') ?? request_integer('period'));
$id = (int) $p['period_id'];
$tab = request_string('tab', 'usage');
$tab = isset(PERIOD_TABS[$tab]) ? $tab : 'usage';
$sees = sees_cost($site);
$f = period_figures($pdo, $p);
$toClose = period_to_close($pdo, $site);
$marks = period_marks($pdo, $site);
$undepleted = in_array($id, $marks['closes_next'], true) ? period_undepleted_days($pdo, $p) : [];
$data = match ($tab) {
    'usage' => ['usage' => usage($pdo, $site, $p['starts_on'], $p['ends_on'])],
    'counts' => ['counts' => period_counts($pdo, $p)],
    'late' => ['late' => period_late_rows($pdo, $id)],
    default => ['trail' => array_values(array_filter(find_record_activity($pdo, 'period', $id, 100), static fn (array $a): bool => $a['action'] !== 'screen.view'))],
};
log_screen_view($pdo, 'period-view', ['after' => ['period_no' => $p['no'], 'tab' => $tab]]);
if (wants_json()) {
    $out = ['period' => present_period($p, $f, $sees), 'tab' => $tab, 'closes_next' => in_array($id, $marks['closes_next'], true), 'can_reopen' => in_array($id, $marks['reopenable'], true), 'undepleted_days' => $undepleted];
    $out += match ($tab) {
        'usage' => ['usage' => present_usage($data['usage'])],
        'counts' => ['counts' => array_map(static fn (array $c): array => present_period_count($c, $sees), $data['counts'])],
        'late' => ['late' => array_map(static fn (array $r): array => present_late_row($r, $sees), $data['late'])],
        default => ['trail' => array_map(static fn (array $a): array => ['at' => json_ts($a['occurred_at']), 'by' => $a['actor_name'], 'action' => $a['action']], $data['trail'])],
    };
    respond_screen($out);
}
$notices = ['closed' => ['success', 'The period is closed — its figures are frozen.'], 'reopened' => ['success', 'The period is open again — its figures are live.']];
render_screen(ucfirst($p['label']), view('periods/view.php', $data + ['p' => $p, 'f' => $f, 'tab' => $tab, 'seesCost' => $sees, 'marks' => $marks, 'undepleted' => $undepleted,
    'closingCounts' => in_array($id, $marks['closes_next'], true) ? period_closing_count_options($pdo, $p) : [], 'mayClose' => has_right('periods.close'), 'mayReopen' => has_right('settings.manage'), 'mayExport' => has_right('exports.all'),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'period-list', 'screen' => 'period-view', 'entity' => 'period', 'recordId' => (string) $id]);
