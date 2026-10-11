<?php
declare(strict_types=1);
/** GET /stock/unit-options?ingredient=&supplier_item=&purpose= — the units a form offers for an ingredient (receive | count | waste | recipe), the
 *  catch-weight and temperature facts, and a supplier item's unit and price (walled). JSON for stock-forms.js; <option>s for HTMX. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$purpose = in_array(request_string('purpose'), ['receive', 'count', 'waste', 'recipe', 'transfer', 'adjust'], true) ? request_string('purpose') : 'receive';
$si = request_integer('supplier_item');
$item = $si === null ? null : find_supplier_item($pdo, $si);
$iid = $item['ingredient_id'] ?? request_integer('ingredient');
$i = $iid === null ? null : find_ingredient($pdo, (int) $iid);
if ($i === null) { respond_screen(['options' => array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo)), 'catch_weight' => false, 'temperature' => false]); }
$opts = array_map(static fn (array $o): array => ['code' => $o['code'], 'label' => $o['label']], unit_options_for($pdo, (int) $i['ingredient_id'], $purpose === 'receive' ? 'receive' : 'count'));
$facts = ingredient_receiving_facts($pdo, (int) $i['ingredient_id'], $site, $item['supplier_item_id'] ?? null);
$default = $item['purchase_unit_code'] ?? ($purpose === 'receive' ? ($opts[0]['code'] ?? null) : (default_count_unit($pdo, (int) $i['ingredient_id']) ?? $i['base_unit_code']));
$price = $item !== null && sees_cost($site) ? ($item['contract_price'] ?? $item['last_price']) : null;
if (is_htmx_request() && !wants_json()) {
    foreach ($opts as $o) { echo '<option value="' . e($o['code']) . '"' . ($o['code'] === $default ? ' selected' : '') . '>' . e($o['label']) . '</option>'; }
    exit;
}
respond_screen(['ingredient_id' => (int) $i['ingredient_id'], 'ingredient' => $i['name'] . ' · ' . $i['code'], 'measure_class' => $i['measure_class'], 'options' => $opts, 'default_unit' => $default,
    'catch_weight' => $facts['catch_weight'], 'temperature' => $purpose === 'receive' && $facts['temperature'], 'unit_cost' => $price === null ? null : (string) $price]);
