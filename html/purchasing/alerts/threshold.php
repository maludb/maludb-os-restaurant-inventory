<?php
declare(strict_types=1);
/** Action `price_alert_threshold_set`: prices.write (an agent's call pauses as `other`) — `pct` sets the default threshold (ri_settings.price_alert_pct); `category` + `category_pct` sets one category's own (empty = the default again). The trigger raises an alert when a move reaches the category's threshold, else the default. Log price_alert.threshold_set. */
require_once dirname(__DIR__, 3) . '/app/features/purchasing/handler.php';
po_begin('prices.write');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$pct = ri_num('pct', null, 'The default threshold', $errors, 0, 1000, false, 2);
$cat = null;
$catPct = null;
if (req_has('category') && trim((string) req_val('category')) !== '') {
    $cat = category_by_id_or_key($pdo, trim((string) req_val('category')));
    if ($cat === null) { $errors['category'] = 'That category is not here.'; }
    if (!req_has('category_pct')) { $errors['category_pct'] = 'Say the category\'s threshold (empty puts it back to the default).'; }
    else { $catPct = ri_num('category_pct', null, 'The category\'s threshold', $errors, 0, 1000, true, 2); }
}
if (!req_has('pct') && $cat === null && !isset($errors['category'])) { $errors['pct'] = 'Say the default threshold, or a category and its threshold.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$did = ri_guard($pdo, static function () use ($pdo, $site, $me, $pct, $cat, $catPct): array {
    $pdo->beginTransaction();
    $did = [];
    if ($pct !== null) {
        $before = (string) one_value($pdo, 'SELECT price_alert_pct FROM ri_settings WHERE id = 1');
        $pdo->prepare('UPDATE ri_settings SET price_alert_pct = :p, updated_by = :by, updated_at = now() WHERE id = 1')->execute(['p' => $pct, 'by' => $me]);
        log_activity($pdo, 'price_alert.threshold_set', 'settings', null, ['scope_id' => $site, 'before' => ['price_alert_pct' => $before], 'after' => ['price_alert_pct' => $pct]]);
        $did[] = 'default ' . fmt_num($pct, 2) . ' %';
    }
    if ($cat !== null) {
        $pdo->prepare('UPDATE ingredient_categories SET price_alert_pct = :p WHERE id = :id')->execute(['p' => $catPct, 'id' => $cat['category_id']]);
        log_activity($pdo, 'price_alert.threshold_set', 'ingredient_category', (int) $cat['category_id'], ['scope_id' => $site, 'before' => ['price_alert_pct' => $cat['price_alert_pct']], 'after' => ['price_alert_pct' => $catPct, 'category' => $cat['key']]]);
        $did[] = $cat['name'] . ' ' . ($catPct === null ? 'back to the default' : fmt_num($catPct, 2) . ' %');
    }
    $pdo->commit();
    return $did;
});
ri_done('Alert threshold set: ' . implode(', ', $did), $cat['category_id'] ?? null, ri_land('/purchasing/alerts', 'threshold'), 'priceAlertChanged', ['pct' => $pct, 'category_id' => $cat['category_id'] ?? null, 'category_pct' => $catPct]);
