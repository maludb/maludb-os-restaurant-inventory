<?php
declare(strict_types=1);
/** Action `unit_set` (log ingredient.unit_set): ingredients.write. The factor is entered as holds_qty + holds_unit (DECISION) and computed by ri_to_base(); the trigger refuses a global code and a case under one base unit in its words. Location /ingredients/{id}/units#ingredient-unit-row-{unit}. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$i = ingredient_param_or_404($pdo);
$unitId = request_integer('unit') ?? (req_val('unit') !== null && ctype_digit((string) req_val('unit')) ? (int) req_val('unit') : null);
$cur = null;
if ($unitId !== null) {
    $st = $pdo->prepare('SELECT * FROM mcp_ingredient_units WHERE ingredient_unit_id = :u AND ingredient_id = :i');
    $st->execute(['u' => $unitId, 'i' => $i['ingredient_id']]);
    $cur = $st->fetch() ?: null;
    if ($cur === null) { respond_not_found('That unit is not here.'); }
}
$errors = [];
$f = [];
$f['unit_code'] = strtolower((string) (ri_text('unit_code', $cur['unit_code'] ?? null, 24, 'The code', $errors, $cur === null) ?? ''));
if ($f['unit_code'] === '' || !preg_match('/^[a-z][a-z0-9_]{0,23}$/', $f['unit_code'])) { $errors['unit_code'] = 'A unit code is a short word: letters, digits and _, starting with a letter.'; }
$f['name'] = ri_text('name', $cur['name'] ?? null, 80, 'The name', $errors, $cur === null) ?? $f['unit_code'];
$f['kind'] = ri_choice('kind', $cur['kind'] ?? 'count', ['purchase', 'count', 'recipe'], 'The kind', $errors, false) ?? 'count';
$f['is_default_count'] = ri_yes('is_default_count', (bool) ($cur['is_default_count'] ?? false));
$f['active'] = ri_yes('active', (bool) ($cur['active'] ?? true));
$f['to_base_factor'] = $cur['to_base_factor'] ?? null;
if (req_has('to_base_factor')) {
    $f['to_base_factor'] = ri_num('to_base_factor', $f['to_base_factor'], 'The factor', $errors, 0.000001, null, false, 6);
} elseif (req_has('holds_qty') || $cur === null) {
    $q = str_replace(',', '', (string) (req_val('holds_qty') ?? ''));
    $u = (string) (req_val('holds_unit') ?? '');
    if ($q === '' || !is_numeric($q) || (float) $q <= 0) { $errors['holds_qty'] = 'Say how much one ' . ($f['unit_code'] ?: 'unit') . ' holds.'; }
    if ($u === '') { $errors['holds_unit'] = 'Say the unit it holds.'; }
    if (!isset($errors['holds_qty'], $errors['holds_unit']) && $q !== '' && $u !== '') {
        try { $f['to_base_factor'] = qty_to_base($pdo, $i['ingredient_id'], number_format((float) $q, 6, '.', ''), $u); }
        catch (PDOException $e) { $errors['holds_unit'] = db_message($e, 'That unit cannot be converted for this ingredient.'); }
    }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$savedId = ri_guard($pdo, static function () use ($pdo, $i, $unitId, $cur, $f): int {
    $pdo->beginTransaction();
    try {
        $sid = save_ingredient_unit($pdo, $i['ingredient_id'], $unitId, $f);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $m = db_message($e, '');
        if ((string) $e->getCode() === '23505') { ri_refuse_fields(['unit_code' => 'The ingredient already has a unit with that code.']); }
        if ((string) $e->getCode() === '23514') { ri_refuse_fields([str_contains($m, 'global unit') ? 'unit_code' : 'holds_qty' => $m]); }
        throw $e;
    }
    catalog_log($pdo, 'ingredient.unit_set', 'ingredient_unit', $sid, ['ingredient_id' => $i['ingredient_id'], 'before' => $cur === null ? null : ['unit_code' => $cur['unit_code'], 'to_base_factor' => $cur['to_base_factor'], 'kind' => $cur['kind'], 'is_default_count' => (bool) $cur['is_default_count'], 'active' => (bool) $cur['active']],
        'after' => ['unit_code' => $f['unit_code'], 'name' => $f['name'], 'kind' => $f['kind'], 'to_base_factor' => $f['to_base_factor'], 'is_default_count' => $f['is_default_count'], 'active' => $f['active']]]);
    $pdo->commit();
    return $sid;
});
$sentence = format_factor_sentence($f['unit_code'], (string) $f['to_base_factor'], $i['base_unit_code']);
ri_done(($unitId === null ? 'Added ' : 'Saved ') . $sentence, $savedId, ri_land('/ingredients/' . $i['ingredient_id'] . '/units', 'unit_saved', 'ingredient-unit-row-' . $savedId), 'ingredientChanged', ['ingredient_id' => $i['ingredient_id'], 'to_base_factor' => $f['to_base_factor'], 'sentence' => $sentence]);
