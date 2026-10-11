<?php
declare(strict_types=1);
/** Action `par_set` (log ingredient.par_set with scope_id; agent approval: other): ingredients.write AT THE SITE. par + par_unit and reorder_min + reorder_min_unit land in base through ri_to_base(). Location /ingredients/{id}/sites#site-settings-row-{site}. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
$pdo = db();
$i = ingredient_param_or_404($pdo);
$site = site_param_or_current();
require_right('ingredients.write', $site);
$st = $pdo->prepare('SELECT * FROM mcp_ingredient_site_settings WHERE ingredient_id = :i AND scope_id = :s');
$st->execute(['i' => $i['ingredient_id'], 's' => $site]);
$cur = $st->fetch() ?: null;
$errors = [];
$f = ['par_base' => $cur['par_base'] ?? null, 'reorder_min_base' => $cur['reorder_min_base'] ?? null];
$par = qty_with_unit($pdo, $i['ingredient_id'], 'par', 'The par', $errors, true, $i['base_unit_code']);
if ($par !== null) { $f['par_base'] = $par['qty_base']; } elseif (req_has('par') && (string) req_val('par') === '') { $f['par_base'] = null; }
$min = qty_with_unit($pdo, $i['ingredient_id'], 'reorder_min', 'The reorder minimum', $errors, true, $i['base_unit_code']);
if ($min !== null) { $f['reorder_min_base'] = $min['qty_base']; } elseif (req_has('reorder_min') && (string) req_val('reorder_min') === '') { $f['reorder_min_base'] = null; }
$f['par_mode'] = ri_choice('par_mode', $cur['par_mode'] ?? 'fixed', ['fixed', 'dynamic'], 'The par mode', $errors, false) ?? 'fixed';
$f['service_level'] = ri_num('service_level', $cur['service_level'] ?? '0.950', 'The service level', $errors, 0.51, 0.999, false, 3) ?? '0.950';
$f['preferred_supplier_item_id'] = isset($cur['preferred_supplier_item_id']) ? (int) $cur['preferred_supplier_item_id'] : null;
if (req_has('preferred_supplier_item')) {
    $v = (string) req_val('preferred_supplier_item');
    if ($v === '') { $f['preferred_supplier_item_id'] = null; }
    else {
        $si = ctype_digit($v) ? find_supplier_item($pdo, (int) $v) : null;
        if ($si === null || $si['ingredient_id'] !== $i['ingredient_id']) { $errors['preferred_supplier_item'] = 'That supplier item does not sell ' . $i['name'] . '.'; } else { $f['preferred_supplier_item_id'] = $si['supplier_item_id']; }
    }
}
$f['default_area_id'] = isset($cur['default_area_id']) ? (int) $cur['default_area_id'] : null;
if (req_has('default_area')) {
    $v = (string) req_val('default_area');
    if ($v === '') { $f['default_area_id'] = null; }
    else {
        $a = ctype_digit($v) ? find_storage_area($pdo, (int) $v) : null;
        if ($a === null || $a['scope_id'] !== $site || !$a['active']) { $errors['default_area'] = 'Choose an active storage area of that site.'; } else { $f['default_area_id'] = $a['storage_area_id']; }
    }
}
$f['active'] = ri_yes('active', isset($cur['active']) ? (bool) $cur['active'] : true);
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static function () use ($pdo, $i, $site, $f): array {
    $pdo->beginTransaction();
    $r = save_site_settings($pdo, $i['ingredient_id'], $site, $f);
    $d = ri_diff($r['before'], $r['after']);
    catalog_log($pdo, 'ingredient.par_set', 'ingredient', $i['ingredient_id'], ['scope_id' => $site, 'before' => $d['before'], 'after' => $d['after'] + ['scope_id' => $site]]);
    $pdo->commit();
    return $r;
});
$words = $f['par_base'] === null ? 'no par' : 'par ' . format_qty($pdo, $i['ingredient_id'], $f['par_base'], $par['unit'] ?? null) . ' (' . $f['par_mode'] . ')';
ri_done('Saved ' . $i['name'] . ' at this site: ' . $words, $i['ingredient_id'], ri_land('/ingredients/' . $i['ingredient_id'] . '/sites', 'par_saved', 'site-settings-row-' . $site), 'ingredientChanged', ['scope_id' => $site, 'par_base' => $f['par_base'], 'par_mode' => $f['par_mode']]);
