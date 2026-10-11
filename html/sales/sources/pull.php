<?php
declare(strict_types=1);
/** Action source_pull (purchasing.draft at the source's site): a `manual` pull now, inline with a 20-second budget — Square's orders, or the SFTP folder's newest files; the days it brings depleted when due. A source fed by upload or email → 422 "fed by upload — drop a file"; a paused one → 422. The reply carries the pull's counts and runs. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$src = source_or_404($pdo, req_val('source'), 'purchasing.draft');
try {
    $r = pos_run_pull($pdo, $src, 'manual', $me, true);
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
$did = 'Pulled ' . $src['name'] . ': ' . $r['sales_new'] . ' new check' . ($r['sales_new'] === 1 ? '' : 's') . ($r['sales_changed'] ? ', ' . $r['sales_changed'] . ' changed' : '') . ', ' . count($r['dates_seen']) . ' day' . (count($r['dates_seen']) === 1 ? '' : 's') . ', ' . count($r['runs']) . ' run' . (count($r['runs']) === 1 ? '' : 's') . ' posted';
ri_done($did, $r['pull_id'], ri_land(return_path('/sales/sources/' . $src['id'] . '?tab=pulls'), 'pulled'), 'salesChanged', ['pull_id' => $r['pull_id'], 'status' => $r['status'], 'dates_seen' => $r['dates_seen'], 'sales_new' => $r['sales_new'], 'sales_changed' => $r['sales_changed'], 'lines_seen' => $r['lines_seen'], 'plus_new' => $r['plus_new'], 'runs' => $r['runs']]);
