<?php
declare(strict_types=1);
/** /ingredients/{id}/units — the ingredient's own units and the class's global ones (screen `ingredient-units`); the add/edit form at the top. ingredients.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
$pdo = db();
$i = catalog_ingredient_or_404($pdo, request_integer('id') ?? request_integer('ingredient'));
$units = ingredient_units($pdo, $i['ingredient_id'], false);
$edit = request_integer('unit');
$cur = null;
foreach ($units as $u) { if ($edit !== null && (int) ($u['ingredient_unit_id'] ?? 0) === $edit) { $cur = $u; } }
log_screen_view($pdo, 'ingredient-units', ['ingredient_id' => $i['ingredient_id']]);
if (wants_json()) {
    respond_screen(['ingredient' => present_ingredient($i), 'units' => $units, 'options' => ri_unit_options($pdo, $i['ingredient_id'])]);
}
$notices = ['unit_saved' => ['success', 'The unit is saved.'], 'unit_retired' => ['success', 'The unit is retired.'], 'unit_restored' => ['success', 'The unit is active again.']];
render_screen($i['name'] . ' · units', view('catalog/ingredient-units.php', ['i' => $i, 'units' => $units, 'cur' => $cur, 'options' => ri_unit_options($pdo, $i['ingredient_id']), 'here' => here_url(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'ingredient-list', 'screen' => 'ingredient-units', 'entity' => 'ingredient', 'recordId' => (string) $i['ingredient_id']]);
