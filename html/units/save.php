<?php
declare(strict_types=1);
/** Action `global_unit_save` (log unit.save): settings.manage; a base unit's class and factor never change (the handler's sentence); the trigger keeps one base per class. Location /units/#unit-row-{code}. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('settings.manage');
$pdo = db();
$code = strtolower(trim((string) (req_val('unit') ?? req_val('code') ?? '')));
$cur = $code === '' || !req_has('unit') ? null : global_unit($pdo, $code);   // `unit` names an existing row; `code` makes one (a taken code is refused)
$errors = [];
$f = [];
if ($cur === null) {
    if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $code)) { $errors['code'] = 'A unit code is a short word: letters, digits and _, starting with a letter.'; }
    $f['code'] = $code;
    $f['measure_class'] = ri_choice('measure_class', null, array_keys(MEASURE_CLASSES), 'The measure class', $errors, false);
    if ($f['measure_class'] === null) { $errors['measure_class'] = 'Choose weight, volume or each.'; }
    $f['to_base_factor'] = ri_num('to_base_factor', null, 'The factor', $errors, 0.00000001, null, false, 8);
    if ($f['to_base_factor'] === null) { $errors['to_base_factor'] = 'Say how many base units one of it holds.'; }
} else {
    if (req_has('to_base_factor') && (string) req_val('to_base_factor') !== '' && number_format((float) req_val('to_base_factor'), 8, '.', '') !== number_format((float) $cur['to_base_factor'], 8, '.', '')) {
        if ($cur['is_base']) { $errors['to_base_factor'] = 'The base unit of a class does not change.'; } else { $f['to_base_factor'] = ri_num('to_base_factor', null, 'The factor', $errors, 0.00000001, null, false, 8); }
    }
    if (req_has('measure_class') && (string) req_val('measure_class') !== $cur['measure_class']) { $errors['measure_class'] = $cur['is_base'] ? 'The base unit of a class does not change.' : 'A unit\'s class does not change — retire it and make another.'; }
}
$f['name'] = ri_text('name', $cur['name'] ?? null, 60, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? $code);
$f['display_order'] = ri_int('display_order', isset($cur['display_order']) ? (int) $cur['display_order'] : 100, 0, 10000, 'The order', $errors, false) ?? 100;
$f['active'] = ri_yes('active', $cur === null ? true : (bool) $cur['active']);
if ($cur !== null && $cur['is_base'] && !$f['active']) { $errors['active'] = 'The base unit of a class is never retired.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$saved = ri_guard($pdo, static function () use ($pdo, $cur, $f): string {
    $pdo->beginTransaction();
    try { $c = save_global_unit($pdo, $cur['unit_code'] ?? null, $f); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['code' => 'That unit code exists already.']); } throw $e; }
    catalog_log($pdo, 'unit.save', 'unit', 0, ['after' => $f + ['code' => $c]]);
    $pdo->commit();
    return $c;
});
ri_done(($cur === null ? 'Added the unit ' : 'Saved the unit ') . $saved, $saved, ri_land('/units/', 'unit_saved', 'unit-row-' . $saved), 'unitChanged');
