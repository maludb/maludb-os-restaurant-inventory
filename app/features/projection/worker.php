<?php
declare(strict_types=1);

/**
 * The worker's three projection passes (projection.md "The worker's passes"; bin/worker.php names them forecast_pass, suggested_orders and watches). Each idempotent, each
 * counts and never logs a payload. Base tables and the SQL functions only — the worker has no acting member, so the mcp_* views answer nothing.
 *  - forecast_pass: once a night per site, after 02:00 site time (no run made today in the site's zone): covers_sync first (K7, best effort), then the forecast under the
 *    setting's method, the baseline scored, and a `forecast` proposal per item whose WAPE worsened by more than 0.1.
 *  - suggested_orders: every 15 minutes: a draft PO (origin agent) per supplier due within 36 hours that has none today, an `order` proposal beside it, a `stockout` proposal
 *    per projected stock-out not yet proposed today; the Manager is told of a stock-out within two days. It DRAFTS and never sends.
 *  - watches: ri_watches_fire() — once per change of state — and one `watch.fire` row per watch that fired.
 */
require_once dirname(__DIR__) . '/agents/write.php';
require_once dirname(__DIR__) . '/watches/queries.php';

function projection_schema_ready(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT to_regclass('public.forecast_runs') IS NOT NULL AND to_regclass('public.buyer_proposals') IS NOT NULL AND to_regclass('public.watches') IS NOT NULL")->fetchColumn();
}

/** The sites a pass works through: live, with the zone. */
function projection_sites(PDO $pdo, int $limit): array
{
    return array_slice($pdo->query('SELECT scope_id, name, timezone FROM sites WHERE removed_at IS NULL ORDER BY scope_id')->fetchAll(), 0, $limit);
}

function projection_pass_forecast(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!projection_schema_ready($pdo)) { return ['sites' => 0, 'skipped' => 'no projection schema']; }
    $out = ['sites' => 0, 'items' => 0, 'proposals' => 0, 'covers' => 0, 'seconds' => 0.0];
    $method = (string) (one_value($pdo, 'SELECT forecast_method FROM ri_settings WHERE id = 1') ?? 'baseline');
    $agent = purchasing_agent_member_id($pdo);
    foreach (projection_sites($pdo, $limit) as $site) {
        $scope = (int) $site['scope_id'];
        db_use_site_zone($pdo, $scope);                                // the site's day for current_date
        if ((int) one_value($pdo, 'SELECT count(*) FROM sales_daily WHERE scope_id = :s', ['s' => $scope]) === 0) { continue; }
        $local = $now->setTimezone(new DateTimeZone((string) $site['timezone']));
        if ((int) $local->format('G') < 2) { continue; }                                    // before 02:00 site time: not yet
        $madeToday = (int) one_value($pdo, 'SELECT count(*) FROM forecast_runs WHERE scope_id = :s AND (made_at AT TIME ZONE CAST(:tz AS text))::date = CAST(:d AS date)', ['s' => $scope, 'tz' => $site['timezone'], 'd' => $local->format('Y-m-d')]);
        if ($madeToday > 0) { continue; }
        try {
            $cov = sync_covers($pdo, $scope, proj_today($pdo), (new DateTimeImmutable(proj_today($pdo)))->modify('+61 days')->format('Y-m-d'));
            $out['covers'] += $cov['connected'] ? $cov['rows'] : 0;
        } catch (Throwable $e) {
            error_log('forecast_pass covers_sync: ' . $e->getMessage());
        }
        $run = run_forecast($pdo, $scope, $method, 21, 364, $agent, $now);
        $out['sites']++;
        $out['items'] += $run['items'];
        $out['seconds'] = round($out['seconds'] + $run['seconds'], 2);
        $date = site_date($pdo, $scope, $now);
        foreach ($run['worse'] as $w) {
            $exists = (int) one_value($pdo, "SELECT count(*) FROM buyer_proposals WHERE scope_id = :s AND kind = 'forecast' AND subject_type = 'menu_item' AND subject_id = :i AND note_date = CAST(:d AS date)", ['s' => $scope, 'i' => $w['menu_item_id'], 'd' => $date]);
            if ($exists > 0) { continue; }
            $name = (string) one_value($pdo, 'SELECT name FROM menu_items WHERE id = :i', ['i' => $w['menu_item_id']]);
            propose($pdo, $scope, ['kind' => 'forecast', 'subject_type' => 'menu_item', 'subject_id' => $w['menu_item_id'], 'title' => $name . ': the forecast got worse (WAPE ' . round((float) $w['before'] * 100) . ' % → ' . round((float) $w['after'] * 100) . ' %)',
                'detail' => ['summary' => 'The baseline missed this item by more than 10 points more than the last time it was scored.', 'before' => $w['before'], 'after' => $w['after']], 'note_date' => $date], $agent);
            $out['proposals']++;
        }
        log_activity($pdo, 'forecast.run', 'forecast_run', $run['run_id'], ['scope_id' => $scope, 'source' => 'cron', 'actor_member_id' => null, 'after' => ['run_id' => $run['run_id'], 'method' => $run['method'], 'horizon_days' => $run['horizon_days'], 'history_days' => $run['history_days'], 'items' => $run['items'], 'seconds' => $run['seconds']]]);
    }
    return $out;
}

function projection_pass_suggested(PDO $pdo, int $limit, DateTimeImmutable $now, bool $forced): array
{
    if (!projection_schema_ready($pdo)) { return ['drafted' => 0, 'skipped' => 'no projection schema']; }
    if (!$forced && ((int) $now->format('i')) % 15 !== 0) { return ['drafted' => 0, 'stockouts' => 0, 'skipped' => 'not a quarter hour']; }
    $out = ['drafted' => 0, 'stockouts' => 0, 'notified' => 0];
    $agent = purchasing_agent_member_id($pdo);
    foreach (projection_sites($pdo, $limit) as $site) {
        $scope = (int) $site['scope_id'];
        db_use_site_zone($pdo, $scope);                                // the site's day for current_date
        $date = site_date($pdo, $scope, $now);
        $res = run_suggested($pdo, $scope, null, 36, true, $agent, 'agent');
        foreach ($res['suppliers'] as $r) {
            if ($r['skipped'] || $r['drafted_po_id'] === null) { continue; }
            $out['drafted']++;
            $lines = $pdo->prepare('SELECT i.name, l.qty_ordered, l.purchase_unit_code, l.rounding_note FROM purchase_order_lines l JOIN ingredients i ON i.id = l.ingredient_id WHERE l.purchase_order_id = :p ORDER BY l.line_no');
            $lines->execute(['p' => $r['drafted_po_id']]);
            $text = array_map(static fn (array $l): string => $l['name'] . ' — ' . fmt_num($l['qty_ordered'], 2) . ' ' . $l['purchase_unit_code'] . ' (' . $l['rounding_note'] . ')', $lines->fetchAll());
            $thin = (int) one_value($pdo, "SELECT count(*) FROM purchase_order_lines WHERE purchase_order_id = :p AND rounding_note LIKE '%thin history%'", ['p' => $r['drafted_po_id']]);
            propose($pdo, $scope, ['kind' => 'order', 'subject_type' => 'supplier', 'subject_id' => $r['supplier_id'], 'title' => 'Drafted ' . $r['number'] . ' for ' . $r['supplier'] . ': ' . $r['lines'] . ' line' . ($r['lines'] === 1 ? '' : 's'),
                'detail' => ['summary' => 'A draft for the next cut-off. A person sends it.', 'lines' => $text, 'total' => $r['total'], 'thin_history_lines' => $thin, 'cutoff_at' => $r['cutoff_at'], 'delivery_on' => $r['delivery_on']],
                'drafted_record_type' => 'purchase_order', 'drafted_record_id' => $r['drafted_po_id'], 'note_date' => $date], $agent);
        }
        $manager = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $scope]);
        $soon = (new DateTimeImmutable(proj_today($pdo)))->modify('+2 days')->format('Y-m-d');
        $st = $pdo->prepare('SELECT s.ingredient_id, i.name, s.stockout_on FROM ri_stockouts_projected(:s, 7) s JOIN ingredients i ON i.id = s.ingredient_id ORDER BY s.stockout_on, i.name');
        $st->execute(['s' => $scope]);
        foreach ($st->fetchAll() as $r) {
            $have = (int) one_value($pdo, "SELECT count(*) FROM buyer_proposals WHERE scope_id = :s AND kind = 'stockout' AND subject_type = 'ingredient' AND subject_id = :i AND note_date = CAST(:d AS date)", ['s' => $scope, 'i' => $r['ingredient_id'], 'd' => $date]);
            if ($have === 0) {
                propose($pdo, $scope, ['kind' => 'stockout', 'subject_type' => 'ingredient', 'subject_id' => (int) $r['ingredient_id'], 'title' => $r['name'] . ' runs out ' . date('D M j', (int) strtotime((string) $r['stockout_on'])),
                    'detail' => ['summary' => 'Projected stock goes under zero before the next delivery covers it.', 'stockout_on' => $r['stockout_on']], 'note_date' => $date], $agent);
                $out['stockouts']++;
            }
            if ($manager !== null && (string) $r['stockout_on'] <= $soon) {
                $made = $pdo->prepare("SELECT ri_notify(:m, 'stockout', 'ingredient', :i, :t, :b, :d, false, :s)");
                $made->execute(['m' => (int) $manager, 'i' => (int) $r['ingredient_id'], 't' => mb_substr($r['name'] . ' runs out ' . date('D M j', (int) strtotime((string) $r['stockout_on'])), 0, 200),
                    'b' => 'Projected stock goes under zero before a delivery covers it. Open the projection to see the day and what is on order.', 'd' => 'stockout:' . $scope . ':' . $r['ingredient_id'] . ':' . $r['stockout_on'], 's' => $scope]);
                if ($made->fetchColumn() !== null) { $out['notified']++; }
            }
        }
    }
    return $out;
}

function projection_pass_watches(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!projection_schema_ready($pdo)) { return ['fired' => 0, 'skipped' => 'no projection schema']; }
    $last = (int) one_value($pdo, 'SELECT COALESCE(max(id), 0) FROM watch_events');
    $fired = (int) one_value($pdo, 'SELECT ri_watches_fire()');
    $st = $pdo->prepare('SELECT id, watch_id, scope_id, ingredient_id, kind FROM watch_events WHERE id > :l ORDER BY id');
    $st->execute(['l' => $last]);
    foreach ($st->fetchAll() as $e) {
        log_activity($pdo, 'watch.fire', 'watch', (int) $e['watch_id'], ['scope_id' => (int) $e['scope_id'], 'ingredient_id' => (int) $e['ingredient_id'], 'source' => 'cron', 'actor_member_id' => null, 'after' => ['watch_id' => (int) $e['watch_id'], 'kind' => $e['kind']]]);
    }
    return ['fired' => $fired];
}
