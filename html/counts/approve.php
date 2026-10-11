<?php
declare(strict_types=1);
/** Action `count_approve` (log count.approve; `other` for an agent — it pauses): counts.approve; a count in review or recount — a count_correction per line whose counted figure differs from the book figure, dated at the count's moment, one group; `force` accepts lines still awaiting a recount; skipped lines post nothing. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('counts.approve');
$pdo = db();
$me = (int) current_member_id();
$count = count_or_404($pdo, request_integer('count'));
$force = ri_yes('force', false);
$res = ri_guard($pdo, static function () use ($pdo, $count, $me, $force): array {
    $skipped = count_areas_skipped($pdo, (int) $count['count_id']);
    $pdo->beginTransaction();
    $r = approve_count($pdo, (int) $count['count_id'], $me, $force);
    $c = $r['count'];
    count_log($pdo, 'count.approve', 'inventory_count', (int) $c['count_id'], (int) $c['count_id'], ['after' => ['number' => $c['number'], 'corrections' => count($r['corrections']), 'units_delta' => $r['units_delta'],
        'total_variance_value' => $c['total_variance_value'], 'forced' => $force && (int) $count['lines_awaiting_recount'] > 0, 'areas_skipped' => array_column($skipped, 'storage_area_id')]]);
    count_notify_variance($pdo, $c);
    $pdo->commit();
    return $r;
});
$c = $res['count'];
ri_done('Approved ' . $c['number'] . ': ' . count($res['corrections']) . ' correction' . (count($res['corrections']) === 1 ? '' : 's') . ' posted', (int) $c['count_id'], ri_land('/counts/' . $c['count_id'] . '/history', 'approved'), 'stockChanged',
    ['count_id' => (int) $c['count_id'], 'corrections' => array_map(static fn (array $t): array => present_correction($t, sees_cost((int) $c['scope_id'])), $res['corrections'])]);
