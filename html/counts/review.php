<?php
declare(strict_types=1);
/**
 * /counts/{count}/review — GET: the review (screen `count-review`; param over_only); POST: action `count_review` (log count.review): stock.count — every
 * line counted, zeroed or skipped; ri_count_review() computes the variances and flags the lines over tolerance (status review or recount), and the
 * members who approve are told. One file for both by method (counts.md DECISION 1).
 */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    counts_begin('stock.count');
    $me = (int) current_member_id();
    $count = count_or_404($pdo, request_integer('count') ?? request_integer('id'));
    $c = ri_guard($pdo, static function () use ($pdo, $count, $me): array {
        $pdo->beginTransaction();
        $c = review_count($pdo, (int) $count['count_id'], $me);
        count_log($pdo, 'count.review', 'inventory_count', (int) $c['count_id'], (int) $c['count_id'], ['after' => ['number' => $c['number'], 'status' => $c['status'], 'lines' => $c['lines'], 'over_tolerance' => $c['lines_over'], 'total_variance_value' => $c['total_variance_value']]]);
        count_notify_review($pdo, $c);
        $pdo->commit();
        return $c;
    });
    ri_done('Reviewed ' . $c['number'] . ($c['status'] === 'recount' ? ': ' . $c['lines_awaiting_recount'] . ' line' . ($c['lines_awaiting_recount'] === 1 ? '' : 's') . ' need a second count' : ''), (int) $c['count_id'], ri_land('/counts/' . $c['count_id'] . '/review', 'reviewed'), 'countChanged',
        ['count_id' => (int) $c['count_id'], 'status' => $c['status'], 'over_tolerance' => $c['lines_over']]);
}
require_login();
require_right('ri.read');
$site = (int) current_site_id();
$count = count_or_404($pdo, request_integer('id') ?? request_integer('count'));
$id = (int) $count['count_id'];
$overOnly = request_bool('over_only');
$rows = count_variance($pdo, $id, $overOnly);
$skipped = count_areas_skipped($pdo, $id);
log_screen_view($pdo, 'count-review', ['count_id' => $id, 'after' => ['number' => $count['number'], 'over_only' => $overOnly]]);
$sees = sees_cost($site);
if (wants_json()) {
    respond_screen(['count' => present_count($count, $sees), 'over_only' => $overOnly, 'lines' => array_map('present_count_line', $rows), 'areas_skipped' => $skipped]);
}
$prefs = my_prefs($pdo, (int) current_member_id());
$units = [];
if ($count['status'] === 'recount' && has_right('stock.count')) { foreach ($rows as $l) { if ($l['over_tolerance'] && $l['recount_qty_base'] === null) { $units[$l['count_line_id']] = count_line_units($pdo, $l, $prefs['display_units']); } } }
$notices = ['reviewed' => ['success', 'Reviewed. The variances are below.'], 'recounted' => ['success', 'Recounted — the review is refreshed.']];
render_screen('Review ' . $count['number'], view('counts/review.php', ['count' => $count, 'rows' => $rows, 'skipped' => $skipped, 'overOnly' => $overOnly, 'units' => $units, 'me' => (int) current_member_id(),
    'mayFill' => has_right('stock.count'), 'mayApprove' => has_right('counts.approve'), 'seesCost' => $sees, 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'count-list', 'screen' => 'count-review', 'entity' => 'inventory_count', 'recordId' => (string) $id]);
