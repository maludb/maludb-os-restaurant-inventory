<?php
declare(strict_types=1);
/** /projection/stock/{ingredient} — one ingredient's projection (screen `projected-ingredient`; param days): the chart, the table behind it, the open purchase order lines due, what drives the demand, the par and its mode, the suggested order line that covers it, Watch this. reports.read. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$id = request_integer('id') ?? request_integer('ingredient');
$p = $id === null ? null : projected_ingredient($pdo, $site, $id, (int) (request_integer('days') ?? 14));
if ($p === null) { respond_not_found('That ingredient is not here.'); }
$sugg = suggested_line_for_ingredient($pdo, $site, $id);
log_screen_view($pdo, 'projected-ingredient', ['ingredient_id' => $id]);
if (wants_json()) {
    respond_screen(present_projected_ingredient($p) + ['suggested' => $sugg === null ? null : ['supplier_id' => $sugg['supplier_id'], 'supplier' => $sugg['supplier'], 'cutoff_at' => json_ts($sugg['cutoff_at']), 'delivery_on' => $sugg['delivery_on'],
        'line' => array_intersect_key($sugg['line'], array_flip(['par_mode', 'thin_history', 'demand_base', 'safety_base', 'need_base', 'have_base', 'on_hand_base', 'on_order_base', 'cap_base', 'raw_base', 'qty_units', 'purchase_unit', 'rounding_note']))]]);
}
render_screen($p['ingredient']['name'] . ' — projected stock', view('projection/stock-item.php', ['p' => $p, 'sugg' => $sugg, 'mayWatch' => has_right('ri.read')]),
    ['activeNav' => 'projected-stock', 'screen' => 'projected-ingredient', 'entity' => 'ingredient', 'recordId' => (string) $id]);
