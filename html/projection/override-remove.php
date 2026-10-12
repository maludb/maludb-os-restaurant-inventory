<?php
declare(strict_types=1);
/** Action `forecast_override_remove` (log forecast.override_remove): forecast.adjust; override (one in force). The row goes; the forecast is the run's again. Location the item's forecast. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$o = stock_here_or_404(find_override($pdo, (int) request_integer('override')), 'That override');
if ($o['status'] !== 'set') { ri_refuse_fields(['override' => 'Only an override in force is removed — this one is ' . $o['status'] . '.']); }
ri_guard($pdo, static function () use ($pdo, $o): void { remove_override($pdo, $o['override_id']); });
proj_log($pdo, 'forecast.override_remove', 'forecast_override', $o['override_id'], ['before' => ['override_id' => $o['override_id'], 'menu_item_id' => $o['menu_item_id'], 'from_date' => $o['from_date'], 'to_date' => $o['to_date'], 'factor' => $o['factor'], 'qty' => $o['qty']]]);
ri_done('Override removed', $o['override_id'], ri_land($o['menu_item_id'] === null ? '/projection/' : '/projection/items/' . $o['menu_item_id'], 'removed'), 'forecastChanged', ['override_id' => $o['override_id']]);
