<?php
declare(strict_types=1);
/** /ingredients/{id}?tab= — one ingredient at this site (screen `ingredient-view`). JSON: ingredient_full(). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$i = catalog_ingredient_or_404($pdo, request_integer('id') ?? request_integer('ingredient'));
$full = ingredient_full($pdo, $i['ingredient_id'], $site);
$tab = request_string('tab') ?: 'on_hand';
log_activity($pdo, 'screen.view', 'ingredient', $i['ingredient_id'], ['screen' => 'ingredient-view', 'after' => ['ingredient_id' => $i['ingredient_id'], 'tab' => $tab]]);
if (wants_json()) {
    respond_screen(['ingredient' => present_ingredient($full['ingredient']), 'units' => $full['units'], 'on_hand' => $full['on_hand'], 'site_settings' => $full['site_settings'], 'cost' => $full['cost'], 'supplier_items' => array_map('present_supplier_item', $full['supplier_items']),
        'recipes_using' => $full['recipes_using'], 'yield_tests' => $full['yield_tests'], 'containers' => $full['containers'], 'notes' => $full['notes'], 'attachments' => $full['attachments'],
        'deletable' => has_right('records.delete') ? ingredient_deletable($pdo, $i['ingredient_id']) : null, 'may' => ['write' => has_right('ingredients.write'), 'delete' => has_right('records.delete'), 'cost' => sees_cost($site)]]);
}
$notices = ['created' => ['success', 'The ingredient is made. Give it the units it is bought and counted in.'], 'saved' => ['success', 'Saved.'], 'archived' => ['success', 'The ingredient is archived.'], 'restored' => ['success', 'The ingredient is active again.'],
    'yield_saved' => ['success', 'The yield test is recorded.'], 'yield_applied' => ['success', 'The yield test is recorded and applied to the ingredient.']];
render_screen($i['name'], view('catalog/ingredient.php', ['full' => $full, 'tab' => $tab, 'site' => $site, 'may' => ['write' => has_right('ingredients.write'), 'delete' => has_right('records.delete'), 'prices' => has_right('prices.write'), 'notes' => has_right('notes.write')],
    'deletable' => has_right('records.delete') ? ingredient_deletable($pdo, $i['ingredient_id']) : 'n/a', 'seesCost' => sees_cost($site), 'tz' => member_timezone(), 'here' => here_url(), 'me' => (int) current_member_id(), 'isAdmin' => is_ri_admin(),
    'cost_history' => $tab === 'cost' && sees_cost($site) ? cost_history($pdo, $i['ingredient_id'], $site) : [], 'ledger' => $tab === 'ledger' ? ingredient_ledger($pdo, $i['ingredient_id'], $site) : [],
    'trail' => $tab === 'trail' ? array_merge(find_record_activity($pdo, 'ingredient', $i['ingredient_id']), []) : [], 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'ingredient-list', 'screen' => 'ingredient-view', 'entity' => 'ingredient', 'recordId' => (string) $i['ingredient_id']]);
