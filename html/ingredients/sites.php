<?php
declare(strict_types=1);
/** /ingredients/{id}/sites — the per-site rows: par, mode, reorder minimum, service level, the preferred supplier item, the depletion area; a cost override apart (screen `ingredient-site-settings`). ingredients.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
$pdo = db();
$i = catalog_ingredient_or_404($pdo, request_integer('id') ?? request_integer('ingredient'));
$rows = site_settings_rows($pdo, $i['ingredient_id'], array_column(held_sites(), 'scope_id'));
$areasBySite = [];
foreach ($rows as $r) { $areasBySite[$r['scope_id']] = find_storage_areas($pdo, $r['scope_id']); }
log_screen_view($pdo, 'ingredient-site-settings', ['ingredient_id' => $i['ingredient_id']]);
if (wants_json()) {
    respond_screen(['ingredient' => present_ingredient($i), 'sites' => array_map(static fn (array $r): array => $r + ['cost_override_per_base' => sees_cost($r['scope_id']) ? $r['cost_override_per_base'] : null], $rows), 'options' => ri_unit_options($pdo, $i['ingredient_id'])]);
}
$notices = ['par_saved' => ['success', 'The site\'s settings are saved.'], 'cost_saved' => ['success', 'The cost override is set.'], 'cost_cleared' => ['success', 'The cost override is cleared.']];
render_screen($i['name'] . ' · per site', view('catalog/ingredient-sites.php', ['i' => $i, 'rows' => $rows, 'areasBySite' => $areasBySite, 'options' => ri_unit_options($pdo, $i['ingredient_id']), 'mayPrice' => has_right_anywhere('prices.write'), 'here' => here_url(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'ingredient-list', 'screen' => 'ingredient-site-settings', 'entity' => 'ingredient', 'recordId' => (string) $i['ingredient_id']]);
