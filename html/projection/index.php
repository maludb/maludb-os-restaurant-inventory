<?php
declare(strict_types=1);
/** /projection/ — the forecast (screen `forecast-list`; params category, horizon, sort): every active menu item sold in the last 84 days, its next days from ri_item_forecast() with the source of each, the total, the run's band, the accuracy (WAPE and bias) and a thin-history flag. reports.read. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$horizon = (int) (request_integer('horizon') ?? 21);
$l = forecast_list($pdo, $site, ['category' => request_string('category'), 'sort' => request_string('sort')], $horizon);
log_screen_view($pdo, 'forecast-list', ['after' => ['horizon' => $l['horizon'], 'sort' => $l['sort']]]);
if (wants_json()) { respond_screen(present_forecast_list($l)); }
$notices = ['run' => ['success', 'The forecast was run — the cells below are the new run\'s.'], 'override' => ['success', 'The override is set.'], 'removed' => ['success', 'The override is removed.']];
render_screen('Forecast', view('projection/index.php', ['l' => $l, 'categories' => menu_categories($pdo), 'category' => request_string('category'), 'mayRun' => has_right('forecast.adjust'), 'thinWeeks' => thin_after_weeks($pdo),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'forecast-list', 'screen' => 'forecast-list', 'entity' => 'forecast']);
