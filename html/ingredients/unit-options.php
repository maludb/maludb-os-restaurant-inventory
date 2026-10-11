<?php
declare(strict_types=1);
/** GET /ingredients/unit-options?ingredient=&kind=  or  ?class=&density=&each= — the <option>s of a unit select (Pattern A fragment). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
header('Cache-Control: no-store');
$selected = request_string('selected');
$ing = request_integer('ingredient') ?? request_integer('id');
if ($ing !== null) {
    $opts = ri_unit_options($pdo, $ing, request_string('kind') ?: null);
} else {
    $class = request_string('class');
    if (!isset(MEASURE_CLASSES[$class])) { $class = 'weight'; }
    $classes = [$class];
    $density = request_string('density');
    $each = request_string('each');
    if ($density !== '' && is_numeric($density) && (float) $density > 0 && $class !== 'each') { $classes[] = $class === 'weight' ? 'volume' : 'weight'; }
    if ($each !== '' && is_numeric($each) && (float) $each > 0) { $classes[] = $class === 'each' ? 'weight' : 'each'; }
    $opts = [];
    foreach (global_units($pdo) as $g) { if (in_array($g['measure_class'], $classes, true)) { $opts[] = ['code' => $g['unit_code'], 'label' => $g['unit_code'] . ' — ' . $g['name'], 'measure_class' => $g['measure_class'], 'kind' => 'global']; } }
}
if (wants_json()) { respond_screen(['options' => $opts]); }
foreach ($opts as $o) {
    echo '<option value="' . e($o['code']) . '"' . ($o['code'] === $selected ? ' selected' : '') . '>' . e($o['label']) . ($o['kind'] !== 'global' ? ' (' . e($o['kind']) . ')' : '') . '</option>';
}
