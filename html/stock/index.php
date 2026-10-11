<?php
declare(strict_types=1);
/** /stock/ — on hand at this site by ingredient and area (screen `stock-levels`; params area, category, q, below_par, page, format=csv). JSON: the rows (value withheld when walled). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['area' => request_string('area'), 'category' => request_string('category'), 'q' => request_string('q'), 'below_par' => request_string('below_par') !== '' && request_string('below_par') !== '0'];
$page = max(1, request_integer('page') ?? 1);
$csv = request_string('format') === 'csv';
$r = stock_levels($pdo, $site, $filters, $csv ? 1 : $page);
$sees = !$r['withheld'];
foreach ($r['rows'] as &$row) { $row['qty_in_count_unit'] = $row['count_unit'] === null ? null : qty_from_base($pdo, $row['ingredient_id'], (string) $row['qty_base'], $row['count_unit']); }
unset($row);
if ($csv) {
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'stock-levels', 'after' => ['format' => 'csv', 'rows' => count($r['rows'])]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="on-hand-' . site_today($site) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['ingredient', 'code', 'category', 'area', 'qty_base', 'base_unit', 'qty_count_unit', 'count_unit', 'days_on_hand', 'below_par'], $sees ? ['value'] : []));
    foreach ($r['rows'] as $x) { fputcsv($out, array_merge([$x['name'], $x['code'], $x['category'], $x['area'], $x['qty_base'], $x['base_unit_code'], $x['qty_in_count_unit'], $x['count_unit'], $x['days_on_hand'], $x['below_par'] ? 'yes' : 'no'], $sees ? [$x['value']] : [])); }
    fclose($out);
    exit;
}
log_screen_view($pdo, 'stock-levels');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'levels' => array_map(static fn (array $x): array => present_level($x, $sees), $r['rows'])] + ($sees ? ['total_value' => $r['total_value']] : ['cost_withheld' => true]));
}
render_screen('On hand', view('stock/levels.php', ['rows' => $r['rows'], 'total' => $r['total'], 'totalValue' => $r['total_value'], 'withheld' => $r['withheld'], 'page' => $page, 'filters' => $filters, 'areas' => area_options($pdo, $site),
    'categories' => categories($pdo), 'may' => ['receive' => has_right('stock.receive'), 'waste' => has_right('stock.waste'), 'produce' => has_right('stock.produce')], 'notice' => null]),
    ['activeNav' => 'stock-levels', 'screen' => 'stock-levels', 'entity' => 'ingredient']);
