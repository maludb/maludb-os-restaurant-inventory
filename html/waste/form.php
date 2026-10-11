<?php
declare(strict_types=1);
/** /waste/new — the waste log at 375 px (screen `waste-add`; params stage, ingredient, recipe). stock.waste. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.waste');
$pdo = db();
$site = (int) current_site_id();
$prefill = ['stage' => in_array(request_string('stage'), array_keys(WASTE_STAGES), true) ? request_string('stage') : '', 'ingredient' => request_string('ingredient'), 'recipe' => request_string('recipe')];
$unitOptions = array_merge([['code' => 'portion', 'label' => 'portion'], ['code' => 'batch', 'label' => 'batch']], array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo)));
if ($prefill['ingredient'] !== '') { $i = ingredient_by_ref($pdo, $prefill['ingredient']); if ($i !== null) { $prefill['ingredient'] = (string) $i['ingredient_id']; $unitOptions = array_map(static fn (array $o): array => ['code' => $o['code'], 'label' => $o['label']], unit_options_for($pdo, (int) $i['ingredient_id'], 'waste')); } }
log_screen_view($pdo, 'waste-add');
if (wants_json()) { respond_screen(['reasons' => reasons_for($pdo, 'waste'), 'day_parts' => day_parts_for($pdo, $site), 'stations' => recent_stations($pdo, $site)]); }
render_screen('Log waste', view('waste/form.php', ['prefill' => $prefill, 'reasons' => reasons_for($pdo, 'waste', $prefill['stage'] ?: null), 'dayParts' => day_parts_for($pdo, $site), 'currentDayPart' => current_day_part($pdo, $site),
    'areas' => area_options($pdo, $site), 'stations' => recent_stations($pdo, $site), 'unitOptions' => $unitOptions]), ['activeNav' => 'waste-list', 'screen' => 'waste-add', 'entity' => 'waste_log']);
