<?php
declare(strict_types=1);
/** /projection/items/{menu_item} — one item's forecast (screen `forecast-item`; params on, days): the chart (the last eight weeks sold, the next three forecast with the band), the same weekdays the baseline weighs, the overrides and the proposed ones, the covers factor, the accuracy, the Override form. reports.read. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$id = request_integer('id') ?? request_integer('menu_item');
$f = $id === null ? [] : item_forecast($pdo, $site, $id, 21);
if ($f === []) { respond_not_found('That menu item is not here.'); }
$today = proj_today($pdo);
$on = proj_query_date('on', $today);
$weekdays = same_weekdays($pdo, $site, $id, $on);
$actuals = item_actuals($pdo, $site, $id, 8);
log_screen_view($pdo, 'forecast-item', ['after' => ['menu_item_id' => $id]]);
if (wants_json()) { respond_screen(present_item_forecast($f) + ['same_weekdays' => array_map(static fn (array $w): array => ['on_date' => $w['on_date'], 'weight' => $w['weight'], 'qty' => $w['qty'], 'used' => $w['used'], 'why' => $w['why']], $weekdays), 'on' => $on]); }
$notices = ['override' => ['success', 'The override is set.'], 'proposed' => ['success', 'The override is proposed — a person sets it before it counts.'], 'dismissed' => ['info', 'The proposal is dismissed.'], 'removed' => ['success', 'The override is removed.']];
$m = $f['menu_item'];
render_screen($m['name'] . ' — forecast', view('projection/item.php', ['f' => $f, 'item' => $m, 'weekdays' => $weekdays, 'on' => $on, 'actuals' => $actuals, 'mayAdjust' => has_right('forecast.adjust'), 'thinWeeks' => thin_after_weeks($pdo),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'forecast-list', 'screen' => 'forecast-item', 'entity' => 'menu_item', 'recordId' => (string) $id]);
