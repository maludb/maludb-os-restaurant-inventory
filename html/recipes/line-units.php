<?php
declare(strict_types=1);
/** GET /recipes/line-units?ingredient=&sub_recipe=&selected= — the unit <select>'s options for a line (Pattern A fragment). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$ing = request_string('ingredient'); $sub = request_string('sub_recipe');
$opts = line_unit_options($pdo, $ing === '' ? null : ri_resolve_ingredient($pdo, $ing), $sub === '' ? null : ri_resolve_recipe($pdo, $sub));
if (wants_json()) { json_response(['ok' => true, 'units' => $opts]); }
header('Cache-Control: no-store');
$selected = request_string('selected');
foreach ($opts as $o) { echo '<option value="' . e($o['code']) . '"' . ($o['code'] === $selected ? ' selected' : '') . '>' . e($o['label']) . '</option>'; }
if ($opts === []) { echo '<option value="">pick first</option>'; }
