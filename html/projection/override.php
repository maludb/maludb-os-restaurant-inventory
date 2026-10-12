<?php
declare(strict_types=1);
/** Action `forecast_override_set` (log forecast.override): forecast.adjust; menu_item (every item when absent), from_date, to_date, factor OR qty, reason. A person's override counts at once. Location the item's forecast, else /projection/. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$f = override_fields($pdo, $site, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static fn (): int => set_override($pdo, $site, $f, $me, 'set'));
$name = $f['menu_item_id'] === null ? null : (string) one_value($pdo, 'SELECT name FROM mcp_menu_items WHERE menu_item_id = :i', ['i' => $f['menu_item_id']]);
proj_log($pdo, 'forecast.override', 'forecast_override', $id, ['after' => ['override_id' => $id, 'menu_item_id' => $f['menu_item_id'], 'from_date' => $f['from_date'], 'to_date' => $f['to_date'], 'factor' => $f['factor'], 'qty' => $f['qty'], 'reason' => $f['reason']]]);
ri_done('Override set: ' . override_words($f, $name), $id, ri_land($f['menu_item_id'] === null ? '/projection/' : '/projection/items/' . $f['menu_item_id'], 'override'), 'forecastChanged', ['override_id' => $id]);
