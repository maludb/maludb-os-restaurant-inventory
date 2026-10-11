<?php
declare(strict_types=1);
/** /recipes/{recipe}/what-if?ingredient=&price=&unit= — the what-if scratchpad (screen recipe-what-if; walled whole: 403 without sees_cost()). POST (Recalculate) answers the result partial; nothing is saved. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
if (!sees_cost($site)) { refuse(403, 'You may not see cost.'); }
$id = request_integer('id');
$cur = recipe_or_404($pdo, $id);
$vid = version_in_force($pdo, $id);
$leaves = $vid === null ? [] : recipe_leaves($pdo, $vid, date('Y-m-d'));
$overrides = [];
$prefill = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    foreach ($_POST['price'] ?? [] as $iid => $price) { $price = trim((string) $price); if ($price !== '' && is_numeric($price) && ctype_digit((string) $iid)) { $overrides[] = ['ingredient' => (int) $iid, 'cost' => $price, 'unit' => trim((string) ($_POST['unit'][$iid] ?? ''))]; } }
} elseif (request_string('ingredient') !== '' && request_string('price') !== '') {
    $iid = ri_resolve_ingredient($pdo, request_string('ingredient'));
    if ($iid !== null && is_numeric(request_string('price'))) { $prefill = [$iid => ['price' => request_string('price'), 'unit' => request_string('unit')]]; $overrides[] = ['ingredient' => $iid, 'cost' => request_string('price'), 'unit' => request_string('unit')]; }
}
foreach ($overrides as &$o) { if ($o['unit'] === '') { $o['unit'] = one_value($pdo, 'SELECT recipe_unit_code FROM mcp_ingredients WHERE ingredient_id = :i', ['i' => $o['ingredient']]); } }
unset($o);
$result = $vid !== null && $overrides !== [] ? what_if_cost($pdo, $vid, $site, $overrides) : null;
$mi = one_value($pdo, 'SELECT menu_item_id FROM mcp_menu_items WHERE recipe_id = :r AND active ORDER BY menu_item_id LIMIT 1', ['r' => $id]);
$price = $mi ? menu_price($pdo, (int) $mi, $site) : null;
log_screen_view($pdo, 'recipe-what-if', ['recipe_id' => $id]);
if (wants_json()) { respond_screen(['recipe_id' => $id, 'version_id' => $vid, 'leaves' => $leaves, 'overrides' => $overrides, 'result' => $result, 'menu_price' => $price]); }
foreach ($leaves as &$l) { $l['cost_per_recipe_unit'] = null; $per = ri_from_base_db($pdo, (int) $l['ingredient_id'], '1', $l['recipe_unit_code']); $c = one_value($pdo, 'SELECT ri_cost_as_of(:i, :s)', ['i' => $l['ingredient_id'], 's' => $site]); if ($per !== null && (float) $per > 0 && $c !== null) { $l['cost_per_recipe_unit'] = (float) $c / (float) $per; } $l['prefill'] = $prefill[(int) $l['ingredient_id']] ?? null; $l['units'] = ri_unit_options($pdo, (int) $l['ingredient_id']); }
unset($l);
$html = view('recipes/what-if.php', ['recipe' => $cur, 'versionId' => $vid, 'leaves' => $leaves, 'result' => $result, 'menuPrice' => $price, 'overrides' => $overrides, 'back' => back_link()]);
if (is_htmx_request() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { echo view('recipes/partials/what-if-result.php', ['result' => $result, 'menuPrice' => $price, 'recipe' => $cur]); exit; }
render_screen('What if — ' . $cur['name'], $html, ['activeNav' => 'recipe-list', 'screen' => 'recipe-what-if', 'entity' => 'recipe', 'recordId' => (string) $id]);
