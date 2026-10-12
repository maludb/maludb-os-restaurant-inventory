<?php
declare(strict_types=1);
/** Action `forecast_run` (log forecast.run): forecast.adjust; method (baseline | holt_winters — the setting's by default), horizon_days (21), history_days (364). Makes a run, marks it current, scores the baseline. Location /projection/. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$method = ri_choice('method', (string) (one_value($pdo, 'SELECT forecast_method FROM ri_settings WHERE id = 1') ?? 'baseline'), ['baseline', 'holt_winters'], 'The method', $errors, false);
$horizon = ri_int('horizon_days', 21, 1, 92, 'The horizon', $errors);
$history = ri_int('history_days', 364, 28, 1096, 'The history', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$run = ri_guard($pdo, static fn (): array => run_forecast($pdo, $site, (string) $method, (int) $horizon, (int) $history, $me));
proj_log($pdo, 'forecast.run', 'forecast_run', $run['run_id'], ['after' => ['run_id' => $run['run_id'], 'method' => $run['method'], 'horizon_days' => $run['horizon_days'], 'history_days' => $run['history_days'], 'items' => $run['items'], 'seconds' => $run['seconds']]]);
$skipped = count($run['skipped']);
ri_done('Forecast run: ' . $run['items'] . ' item' . ($run['items'] === 1 ? '' : 's') . ' by ' . ($run['method'] === 'holt_winters' ? 'Holt-Winters' : 'the baseline') . ($skipped > 0 ? ' (' . $skipped . ' with under twelve weeks of history stay on the baseline)' : ''),
    $run['run_id'], ri_land('/projection/', 'run'), 'forecastChanged', ['run_id' => $run['run_id'], 'method' => $run['method'], 'items' => $run['items'], 'skipped' => $skipped]);
