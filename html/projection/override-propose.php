<?php
declare(strict_types=1);
/** Action `forecast_override_propose` (log forecast.override_propose): purchasing.draft; the override form's fields — an agent's proposal (status proposed): it does not count until a person sets it. The site's Manager is told (agent_drafted). */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
proj_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$f = override_fields($pdo, $site, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $site, $me, $f): int {
    $pdo->beginTransaction();
    $id = set_override($pdo, $site, $f, $me, 'proposed');
    $manager = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $site]);
    if ($manager !== null) {
        $name = $f['menu_item_id'] === null ? null : (string) one_value($pdo, 'SELECT name FROM menu_items WHERE id = :i', ['i' => $f['menu_item_id']]);
        $who = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $me]);
        $pdo->prepare("SELECT ri_notify(:m, 'agent_drafted', 'forecast_override', :id, :t, :b, :d, false, :s)")->execute(['m' => (int) $manager, 'id' => $id, 't' => mb_substr($who . ' proposes ' . override_words($f, $name), 0, 200),
            'b' => ($f['reason'] ?? '') . ' — open the item\'s forecast to set it or dismiss it.', 'd' => 'override:' . $id, 's' => $site]);
    }
    $pdo->commit();
    return $id;
});
proj_log($pdo, 'forecast.override_propose', 'forecast_override', $id, ['after' => ['override_id' => $id, 'menu_item_id' => $f['menu_item_id'], 'from_date' => $f['from_date'], 'to_date' => $f['to_date'], 'factor' => $f['factor'], 'qty' => $f['qty'], 'reason' => $f['reason']]]);
ri_done('Override proposed — a person sets it before it counts', $id, ri_land($f['menu_item_id'] === null ? '/projection/' : '/projection/items/' . $f['menu_item_id'], 'proposed'), 'forecastChanged', ['override_id' => $id]);
