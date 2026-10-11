<?php
declare(strict_types=1);
/** /stock/value?as_of= — stock value by category at this site as of a day's close (screen `stock-value`). The whole screen is cost: withheld to one who may not see cost here (ri.read opens it). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$asOf = request_string('as_of');
if ($asOf === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) === false) { $asOf = site_today($site); }
$v = stock_value_rows($pdo, $site, $asOf);
log_screen_view($pdo, 'stock-value');
if (wants_json()) {
    respond_screen($v['withheld'] ? ['as_of' => $asOf, 'withheld' => true, 'cost_withheld' => true, 'categories' => array_map(static fn (array $c): array => ['category' => $c['category_key'], 'ingredients' => $c['ingredients']], $v['rows'])]
        : ['as_of' => $asOf, 'withheld' => false, 'total' => $v['total'], 'categories' => array_map(static fn (array $c): array => ['category' => $c['category_key'], 'ingredients' => $c['ingredients'], 'value' => $c['value']], $v['rows'])]);
}
render_screen('Stock value', view('stock/value.php', ['value' => $v, 'asOf' => $asOf]), ['activeNav' => 'stock-value', 'screen' => 'stock-value']);
