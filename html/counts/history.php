<?php
declare(strict_types=1);
/** /counts/{count}/history — what an approved count did (screen `count-history`): the corrections it posted, the variance by category, who counted what, the areas it skipped, the trail. ri.read; cost walled. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$count = count_or_404($pdo, request_integer('id') ?? request_integer('count'));
$id = (int) $count['count_id'];
$sees = sees_cost($site);
$corrections = count_corrections($pdo, $id);
$byCategory = count_variance_by_category($pdo, $id);
$counters = count_counters($pdo, $id);
$skipped = count_areas_skipped($pdo, $id);
log_screen_view($pdo, 'count-history', ['count_id' => $id]);
if (wants_json()) {
    respond_screen(['count' => present_count($count, $sees), 'corrections' => array_map(static fn (array $t): array => present_correction($t, $sees), $corrections),
        'by_category' => array_map(static fn (array $r): array => $sees ? $r : array_diff_key($r, ['variance_value' => 1]) + ['cost_withheld' => true], $byCategory), 'counters' => $counters, 'areas_skipped' => $skipped]);
}
$notices = ['approved' => ['success', 'Approved — the corrections are posted at the count\'s moment.']];
render_screen($count['number'] . ' — history', view('counts/history.php', ['count' => $count, 'corrections' => $corrections, 'byCategory' => $byCategory, 'counters' => $counters, 'skipped' => $skipped, 'seesCost' => $sees,
    'trail' => array_values(array_filter(find_activity_by_key($pdo, 'count_id', $id, 200), static fn (array $a): bool => $a['action'] !== 'screen.view')), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'count-list', 'screen' => 'count-history', 'entity' => 'inventory_count', 'recordId' => (string) $id]);
