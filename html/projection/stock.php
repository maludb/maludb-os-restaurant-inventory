<?php
declare(strict_types=1);
/** /projection/stock — projected stock (screen `projected-stock`; params category, days, stockouts_only): per ingredient per day on hand + receipts due − demand − planned waste (ri_projected_stock_all()), the stock-out marked. reports.read. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$days = (int) (request_integer('days') ?? 14);
$filters = ['category' => request_string('category'), 'stockouts_only' => request_bool('stockouts_only') ? 'yes' : '', 'q' => request_string('q')];
$p = projected_stock($pdo, $site, $filters, $days);
log_screen_view($pdo, 'projected-stock', ['after' => ['days' => $p['days'], 'stockouts_only' => $filters['stockouts_only'] === 'yes']]);
if (wants_json()) { respond_screen(present_projected($p)); }
render_screen('Projected stock', view('projection/stock.php', ['p' => $p, 'filters' => $filters, 'categories' => categories($pdo)]), ['activeNav' => 'projected-stock', 'screen' => 'projected-stock', 'entity' => 'ingredient']);
