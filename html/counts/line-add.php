<?php
declare(strict_types=1);
/** Action `count_line_add` (log count.line_add, ingredient_id): stock.count; an ingredient found on the shelf that is not on the sheet — count, area, ingredient, and entries (JSON, or qty + unit). ri_count_line_add() (db/018) takes the book figure as of the count's moment. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('stock.count');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$count = count_or_404($pdo, request_integer('count'));
$errors = [];
$areaId = stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area', $errors);
$ing = (string) (req_val('ingredient') ?? '') === '' ? null : ingredient_by_ref($pdo, (string) req_val('ingredient'));
if ($ing === null) { $errors['ingredient'] = (string) (req_val('ingredient') ?? '') === '' ? 'Choose the ingredient.' : 'That ingredient is not here.'; }
$entries = null;
if ($ing !== null) {
    $probe = ['count_unit' => default_count_unit($pdo, (int) $ing['ingredient_id']) ?? $ing['base_unit_code'], 'base_unit_code' => $ing['base_unit_code']];
    if (req_has('qty') && (string) req_val('qty') !== '' && !req_has('whole')) { $_POST['whole'] = $_POST['qty']; $_POST['whole_unit'] = req_val('unit') ?: $probe['count_unit']; }
    $entries = count_entries_from_request($probe, $errors);
}
if ($errors !== []) { ri_refuse_fields($errors); }
$line = ri_guard($pdo, static function () use ($pdo, $count, $areaId, $ing, $entries, $me): array {
    count_require_changeable($count);
    $pdo->beginTransaction();
    $l = add_count_line($pdo, (int) $count['count_id'], $areaId, (int) $ing['ingredient_id'], $entries, $me);
    count_log($pdo, 'count.line_add', 'inventory_count_line', (int) $l['count_line_id'], (int) $count['count_id'], ['ingredient_id' => (int) $ing['ingredient_id'],
        'after' => ['count_line_id' => (int) $l['count_line_id'], 'ingredient_id' => (int) $ing['ingredient_id'], 'storage_area_id' => $areaId, 'state' => $l['state'], 'counted_qty_base' => $l['counted_qty_base'], 'entries' => $entries]]);
    $pdo->commit();
    return $l;
});
ri_done('Added ' . $ing['name'] . ' to ' . $count['number'], (int) $line['count_line_id'], ri_land('/counts/' . $count['count_id'] . '?area=' . $areaId, 'line_added', 'count-line-' . $line['count_line_id']), 'countChanged',
    ['count_id' => (int) $count['count_id'], 'line' => present_count_line($line)]);
