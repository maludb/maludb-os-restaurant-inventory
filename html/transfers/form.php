<?php
declare(strict_types=1);
/** /transfers/new — start a transfer (screen `transfer-add`; params to_site, from_area, to_area, ingredient). stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.transfer');
$pdo = db();
$site = (int) current_site_id();
$prefill = ['to_site' => request_string('to_site') ?: (string) $site, 'from_area' => request_string('from_area'), 'to_area' => request_string('to_area'), 'ingredient' => request_string('ingredient')];
$toSite = ctype_digit($prefill['to_site']) && in_array((int) $prefill['to_site'], array_column(held_sites(), 'scope_id'), true) ? (int) $prefill['to_site'] : $site;
$unitOptions = [];
if ($prefill['ingredient'] !== '') { $i = ingredient_by_ref($pdo, $prefill['ingredient']); if ($i !== null) { $prefill['ingredient'] = (string) $i['ingredient_id']; $prefill['ingredient_name'] = $i['name']; $unitOptions = unit_options_for($pdo, (int) $i['ingredient_id'], 'count'); } else { $prefill['ingredient'] = ''; } }
log_screen_view($pdo, 'transfer-add');
if (wants_json()) { respond_screen(['sites' => array_map(static fn (array $s): array => ['site' => $s['scope_id'], 'name' => $s['name']], held_sites()), 'from_areas' => array_map('present_area', area_options($pdo, $site))]); }
render_screen('New transfer', view('transfers/form.php', ['cur' => null, 'sites' => held_sites_this_first($site), 'fromAreas' => area_options($pdo, $site), 'toAreas' => find_storage_areas($pdo, $toSite), 'prefill' => $prefill, 'unitOptions' => $unitOptions]),
    ['activeNav' => 'transfer-list', 'screen' => 'transfer-add', 'entity' => 'inventory_transfer']);
