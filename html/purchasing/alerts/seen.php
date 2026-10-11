<?php
declare(strict_types=1);
/** Action `price_alert_seen`: purchasing.draft — one alert (`alert`, an id) or, with none named, every open alert at this site. The view is the wall: one who sees no cost sees no alert to mark. Log price_alert.seen (a count). */
require_once dirname(__DIR__, 3) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
if (!sees_cost($site)) { respond_not_found('There are no price alerts for you to see here.'); }
$one = req_has('alert') && trim((string) req_val('alert')) !== '' ? (int) req_val('alert') : null;
if ($one !== null) {
    $a = q_row($pdo, 'SELECT price_alert_id, ingredient_id, scope_id FROM mcp_price_alerts WHERE price_alert_id = :id', ['id' => $one]);
    if ($a === null || ($a['scope_id'] !== null && (int) $a['scope_id'] !== $site)) { respond_not_found('That alert is not here.'); }
}
$n = ri_guard($pdo, static function () use ($pdo, $site, $me, $one): int {
    $pdo->beginTransaction();
    $st = $pdo->prepare('UPDATE price_alerts SET seen_by = :by, seen_at = now() WHERE seen_at IS NULL AND (scope_id = :s OR scope_id IS NULL)' . ($one === null ? '' : ' AND id = :id'));
    $st->execute(['by' => $me, 's' => $site] + ($one === null ? [] : ['id' => $one]));
    $n = $st->rowCount();
    log_activity($pdo, 'price_alert.seen', 'price_alert', $one, ['scope_id' => $site, 'after' => ['marked' => $n, 'all' => $one === null]]);
    $pdo->commit();
    return $n;
});
ri_done($one === null ? 'Marked ' . $n . ' alert' . ($n === 1 ? '' : 's') . ' seen' : ($n === 1 ? 'Marked the alert seen' : 'That alert was already seen'), $one, ri_land('/purchasing/alerts', 'seen'), 'priceAlertChanged', ['marked' => $n]);
