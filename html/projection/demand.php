<?php
declare(strict_types=1);
/** /projection/demand — ingredient demand (screen `demand-report`; params from, to, category, ingredient): the forecast exploded through the recipes (ri_ingredient_demand()), by ingredient and day in the count unit with the base in a title, the drivers under a tap. A week by default, at most 28 days. reports.read. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$today = proj_today($pdo);
$from = proj_query_date('from', $today);
$to = proj_query_date('to', (new DateTimeImmutable($from))->modify('+6 days')->format('Y-m-d'));
if ($to < $from) { refuse(422, 'The window ends before it starts.'); }
if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1 > DEMAND_MAX_DAYS) { refuse(422, 'A demand window is at most ' . DEMAND_MAX_DAYS . ' days — shorten it.'); }
$filters = ['category' => request_string('category'), 'ingredient' => request_string('ingredient')];
$d = ingredient_demand($pdo, $site, $from, $to, $filters);
$driversFor = request_integer('drivers');
$drivers = $driversFor === null ? null : ['ingredient_id' => $driversFor, 'rows' => demand_drivers($pdo, $site, $driversFor, $from, $to)];
log_screen_view($pdo, 'demand-report', ['after' => ['from' => $from, 'to' => $to]]);
if (wants_json()) { respond_screen(present_demand($d) + ($drivers === null ? [] : ['drivers' => array_map(static fn (array $r): array => ['menu_item_id' => $r['menu_item_id'], 'name' => $r['name'], 'forecast_qty' => $r['forecast_qty'], 'qty_base' => $r['qty_base'], 'share_pct' => $r['share_pct']], $drivers['rows'])])); }
render_screen('Ingredient demand', view('projection/demand.php', ['d' => $d, 'from' => $from, 'to' => $to, 'filters' => $filters, 'drivers' => $drivers, 'categories' => categories($pdo)]),
    ['activeNav' => 'demand-report', 'screen' => 'demand-report', 'entity' => 'ingredient']);
