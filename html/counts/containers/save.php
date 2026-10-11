<?php
declare(strict_types=1);
/** Action `container_save` (log container.save, ingredient_id): ingredients.write; a bottle or keg the bar weighs — ingredient (a volume ingredient), name, full_weight_g, empty_weight_g (less than full), pourer_tare_g, volume_ml, is_default (one per ingredient), active; `container` changes one. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
counts_begin('ingredients.write');
$pdo = db();
$cur = req_has('container') && (string) req_val('container') !== '' ? container_or_404($pdo, request_integer('container')) : null;
$errors = [];
$ing = $cur !== null && !req_has('ingredient') ? find_ingredient($pdo, (int) $cur['ingredient_id']) : ((string) (req_val('ingredient') ?? '') === '' ? null : ingredient_by_ref($pdo, (string) req_val('ingredient')));
if ($ing === null) { $errors['ingredient'] = (string) (req_val('ingredient') ?? '') === '' ? 'Choose the ingredient.' : 'That ingredient is not here.'; }
elseif ($ing['measure_class'] !== 'volume') { $errors['ingredient'] = $ing['name'] . ' is not measured by volume — only liquor, beer, wine and the like are weighed in a bottle or keg.'; }
$f = ['ingredient_id' => (int) ($ing['ingredient_id'] ?? 0)];
$f['name'] = ri_text('name', $cur['name'] ?? null, 60, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? '');
$num = static function (string $k, ?string $keep, string $label, bool $required, bool $positive = true) use (&$errors): ?string {
    if (!req_has($k)) { if ($keep === null && $required) { $errors[$k] = $label . ' is required.'; } return $keep; }
    $v = str_replace([',', ' '], '', (string) req_val($k));
    if ($v === '') { if ($required) { $errors[$k] = $label . ' is required.'; } return $keep; }
    if (!is_numeric($v) || (float) $v < 0 || ($positive && (float) $v <= 0) || (float) $v > 10000000) { $errors[$k] = $label . ' is a number' . ($positive ? ' over zero.' : ' of zero or more.'); return $keep; }
    return number_format((float) $v, 2, '.', '');
};
$f['full_weight_g'] = $num('full_weight_g', $cur['full_weight_g'] ?? null, 'The full weight', true);
$f['empty_weight_g'] = $num('empty_weight_g', $cur['empty_weight_g'] ?? null, 'The empty weight', true, false);
$f['pourer_tare_g'] = $num('pourer_tare_g', $cur['pourer_tare_g'] ?? '0.00', 'The pourer weight', false, false) ?? '0.00';
$f['volume_ml'] = $num('volume_ml', $cur['volume_ml'] ?? null, 'The volume', true);
$hasDefault = $ing !== null && container_for($pdo, (int) $ing['ingredient_id']) !== null;
$f['is_default'] = ri_yes('is_default', $cur['is_default'] ?? !$hasDefault);
$f['active'] = ri_yes('active', $cur['active'] ?? true);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f, $ing): int {
    $pdo->beginTransaction();
    $id = save_container($pdo, $cur['container_id'] ?? null, $f);
    count_log($pdo, 'container.save', 'container', $id, null, ['ingredient_id' => (int) $f['ingredient_id'], 'after' => ['container_id' => $id, 'ingredient_id' => (int) $f['ingredient_id'], 'name' => $f['name'], 'full_weight_g' => $f['full_weight_g'],
        'empty_weight_g' => $f['empty_weight_g'], 'volume_ml' => $f['volume_ml'], 'is_default' => $f['is_default']]]);
    $pdo->commit();
    return $id;
});
ri_done(($cur === null ? 'Added ' : 'Saved ') . $f['name'] . ' for ' . $ing['name'], $id, ri_land('/counts/containers/?ingredient=' . $f['ingredient_id'], $cur === null ? 'created' : 'saved', 'container-row-' . $id), 'containerChanged', ['container_id' => $id]);
