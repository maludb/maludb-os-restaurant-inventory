<?php
declare(strict_types=1);
/** /adjustments/new — start an adjustment (screen `adjustment-add`; params area, reason, ingredient). counts.approve. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('counts.approve');
$pdo = db();
$site = (int) current_site_id();
$prefill = ['area' => request_string('area'), 'reason' => request_string('reason'), 'ingredient' => request_string('ingredient'), 'ingredient_name' => ''];
$unitOptions = [];
if ($prefill['ingredient'] !== '') { $i = ingredient_by_ref($pdo, $prefill['ingredient']); if ($i !== null) { $prefill['ingredient'] = (string) $i['ingredient_id']; $prefill['ingredient_name'] = $i['name']; $unitOptions = unit_options_for($pdo, (int) $i['ingredient_id'], 'count'); } else { $prefill['ingredient'] = ''; } }
log_screen_view($pdo, 'adjustment-add');
if (wants_json()) { respond_screen(['reasons' => reasons_for($pdo, 'adjustment'), 'areas' => array_map('present_area', area_options($pdo, $site))]); }
render_screen('New adjustment', view('adjustments/form.php', ['areas' => area_options($pdo, $site), 'reasons' => reasons_for($pdo, 'adjustment'), 'prefill' => $prefill, 'unitOptions' => $unitOptions, 'seesCost' => sees_cost($site)]),
    ['activeNav' => 'adjustment-list', 'screen' => 'adjustment-add', 'entity' => 'inventory_adjustment']);
