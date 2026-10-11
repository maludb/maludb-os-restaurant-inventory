<?php
declare(strict_types=1);
/** /production/new — the batch form at 375 px (screen `production-add`; param recipe). stock.produce. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.produce');
$pdo = db();
$site = (int) current_site_id();
$recipe = request_string('recipe') !== '' ? stock_recipe_by_ref($pdo, request_string('recipe')) : null;
if ($recipe !== null && $recipe['kind'] !== 'prep') { $recipe = null; }
$preview = $recipe === null ? null : batch_preview($pdo, (int) $recipe['recipe_id'], 1.0, $site);
$outputUnits = $recipe !== null && !empty($recipe['output_ingredient_id']) ? array_map(static fn (array $o): array => ['code' => $o['code'], 'label' => $o['label']], unit_options_for($pdo, (int) $recipe['output_ingredient_id'], 'count'))
    : array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo));
log_screen_view($pdo, 'production-add');
if (wants_json()) { respond_screen(['recipe' => $recipe === null ? null : ['recipe_id' => (int) $recipe['recipe_id'], 'name' => $recipe['name']], 'preview' => $preview]); }
render_screen('Record a prep batch', view('production/form.php', ['areas' => area_options($pdo, $site), 'prefill' => ['recipe' => $recipe === null ? '' : (string) $recipe['recipe_id']], 'preview' => $preview, 'recipe' => $recipe, 'outputUnits' => $outputUnits]),
    ['activeNav' => 'production-list', 'screen' => 'production-add', 'entity' => 'production_batch']);
