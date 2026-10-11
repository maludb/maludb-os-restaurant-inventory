<?php
declare(strict_types=1);
/** /sales/{date}?day_part= — screen sales-day-view: three tiles (checks, covers, net) and the day parts; sales by item (Map on an unmapped one); the checks with their lines on expand; the modifiers seen; what the day depleted by ingredient in the count unit (cost walled) and the run's card; Re-run, Void the run. */
require_once dirname(__DIR__, 2) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$date = request_string('date');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { refuse(404, 'That day is not here.'); }
$expand = request_integer('check');
if ($expand !== null) {                                                       // a check's lines (Pattern A)
    $c = array_values(array_filter(day_checks($pdo, $site, $date), static fn ($x) => (int) $x['sale_id'] === $expand))[0] ?? null;
    if ($c === null) { refuse(404, 'That check is not here.'); }
    $lines = check_lines($pdo, $expand);
    if (wants_json()) { respond_screen(present_check($c, $lines)); }
    echo view('pos/_check-lines.php', ['c' => $c, 'lines' => $lines, 'mayMap' => has_right('recipes.write', $site)]);
    return;
}
$day = day_for($pdo, $site, $date);
if ($day === null) { refuse(404, 'No sales at this site on ' . $date . '.'); }
$dayPart = request_integer('day_part');
$items = day_items($pdo, $site, $date, $dayPart);
$checks = day_checks($pdo, $site, $date);
$mods = day_modifiers($pdo, $site, $date);
$run = $day['run'];
$depleted = $run === null ? [] : day_depletion($pdo, (int) $run['depletion_run_id']);
log_screen_view($pdo, 'sales-day-view');
if (wants_json()) {
    respond_screen(['business_date' => $date, 'checks' => $day['checks'], 'covers' => $day['covers'], 'net' => round($day['net'], 2), 'comps' => round($day['comps'], 2), 'voids' => round($day['voids'], 2), 'due' => $day['due'],
        'day_parts' => $day['day_parts'], 'items' => $items, 'modifiers' => $mods, 'run' => $run === null ? null : present_run($run),
        'depleted' => array_map(static fn ($d) => ['ingredient_id' => (int) $d['ingredient_id'], 'name' => $d['name'], 'qty_base' => -(float) $d['qty_base'], 'qty' => $d['qty_display'], 'value' => $d['value'] === null ? null : -(float) $d['value']], $depleted),
        'checks_list' => array_map(static fn ($c) => present_check($c), $checks)]);
}
$notices = ['depleted' => ['success', 'The day was depleted.']];
render_screen('Sales · ' . format_date($date), view('pos/day.php', ['day' => $day, 'date' => $date, 'dayPart' => $dayPart, 'items' => $items, 'checks' => $checks, 'mods' => $mods, 'run' => $run, 'depleted' => $depleted,
    'seesCost' => sees_cost($site), 'mayRun' => has_right('stock.receive', $site), 'mayVoid' => has_right('counts.approve', $site), 'mayMap' => has_right('recipes.write', $site), 'tz' => site_timezone($site),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]), ['activeNav' => 'sales-day-list', 'screen' => 'sales-day-view', 'entity' => 'pos_day', 'recordId' => $date]);
