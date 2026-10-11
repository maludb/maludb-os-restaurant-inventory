<?php
declare(strict_types=1);

/**
 * The worker's two purchasing passes (design §6; bin/worker.php names them price_alerts and link_expiry). Each idempotent, each counts and never
 * logs a payload.
 *  - price_alerts: the rows are raised by the price-history trigger (db/010); this pass TELLS the site's Manager (site_settings.manager_member_id,
 *    else the business's ri_settings.manager_member_id, else nobody) of the alerts raised since the last telling: ONE notification per site per
 *    pass with the count, its record_id the newest alert it covers — so a pass that finds nothing newer says nothing, and a second pass changes
 *    nothing. The bell and the mail copy go through ri_notify() (kind price_alert, deduped by site and newest alert).
 *  - link_expiry: a supplier link dies when its expiry has passed or 30 days after its purchase order closed or was cancelled (design §4): the
 *    live link row is rotated out (rotated_at set), after which the door answers "not valid or expired" — ri_supplier_link_resolve() already
 *    refuses an expired link; this pass makes the row say so and keeps the Link tab honest.
 */

function purchasing_schema_ready(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT to_regclass('public.price_alerts') IS NOT NULL AND to_regclass('public.supplier_links_secure') IS NOT NULL")->fetchColumn();
}

function purchasing_pass_price_alerts(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!purchasing_schema_ready($pdo)) { return ['raised' => 0, 'skipped' => 'no purchasing schema']; }
    $out = ['sites' => 0, 'alerts' => 0, 'told' => 0];
    $sites = $pdo->query("SELECT DISTINCT a.scope_id FROM price_alerts a WHERE a.seen_at IS NULL AND a.scope_id IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_slice($sites, 0, $limit) as $scope) {
        $scope = (int) $scope;
        $last = (int) one_value($pdo, "SELECT COALESCE(max(record_id), 0) FROM notifications WHERE kind = 'price_alert' AND record_type = 'price_alert' AND scope_id = :s", ['s' => $scope]);
        $st = $pdo->prepare('SELECT count(*) AS n, COALESCE(max(id), 0) AS newest FROM price_alerts WHERE scope_id = :s AND seen_at IS NULL AND id > :l');
        $st->execute(['s' => $scope, 'l' => $last]);
        $r = $st->fetch();
        $n = (int) $r['n'];
        if ($n === 0) { continue; }
        $out['sites']++;
        $out['alerts'] += $n;
        $manager = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $scope]);
        if ($manager === null) { continue; }
        $site = (string) one_value($pdo, 'SELECT name FROM sites WHERE scope_id = :s', ['s' => $scope]);
        $pdo->prepare("SELECT ri_notify(:m, 'price_alert', 'price_alert', :id, :t, :b, :d, false, :s)")->execute([
            'm' => (int) $manager, 'id' => (int) $r['newest'], 't' => $n . ' price alert' . ($n === 1 ? '' : 's') . ' at ' . $site,
            'b' => 'A cost moved past its threshold. Open the price alerts to see which and mark them seen.', 'd' => 'price_alert:' . $scope . ':' . (int) $r['newest'], 's' => $scope]);
        $out['told']++;
    }
    return $out;
}

function purchasing_pass_link_expiry(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!purchasing_schema_ready($pdo)) { return ['expired' => 0, 'skipped' => 'no purchasing schema']; }
    $st = $pdo->prepare("UPDATE supplier_links_secure l SET rotated_at = :now::timestamptz
        WHERE l.id IN (SELECT l2.id FROM supplier_links_secure l2 JOIN purchase_orders p ON p.id = l2.purchase_order_id
                        WHERE l2.rotated_at IS NULL AND ((l2.expires_at IS NOT NULL AND l2.expires_at <= :now2::timestamptz)
                           OR (p.status IN ('closed', 'closed_short') AND COALESCE(p.closed_at, p.updated_at) + interval '30 days' <= :now3::timestamptz)
                           OR (p.status = 'cancelled' AND COALESCE(p.cancelled_at, p.updated_at) + interval '30 days' <= :now4::timestamptz))
                        ORDER BY l2.id LIMIT :l)");
    $at = $now->format(DATE_ATOM);
    $st->bindValue('now', $at);
    $st->bindValue('now2', $at);
    $st->bindValue('now3', $at);
    $st->bindValue('now4', $at);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return ['expired' => $st->rowCount()];
}
