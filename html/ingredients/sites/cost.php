<?php
declare(strict_types=1);
/** Action `cost_override_set` (log ingredient.cost_override with scope_id; agent approval: other): prices.write AND sees_cost at the site. cost + unit → per base; blank clears. Location /ingredients/{id}/sites. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
$pdo = db();
$i = ingredient_param_or_404($pdo);
$site = site_param_or_current();
require_right('prices.write', $site);
if (!sees_cost($site)) { emit_action_status(false, ['error' => 'You may not see cost.']); refuse(403, 'You may not see cost.'); }
$errors = [];
$cost = str_replace(',', '', (string) (req_val('cost') ?? ''));
$unit = (string) (req_val('unit') ?? $i['base_unit_code']);
$reason = ri_text('reason', null, 200, 'The reason', $errors);
$perBase = null;
$perUnit = null;
if ($cost !== '') {
    if (!is_numeric($cost) || (float) $cost < 0) { $errors['cost'] = 'The cost is an amount of 0 or more.'; }
    else {
        try { $per = qty_to_base($pdo, $i['ingredient_id'], '1', $unit === '' ? $i['base_unit_code'] : $unit); }
        catch (PDOException $e) { $errors['unit'] = db_message($e, 'That unit cannot be converted.'); $per = null; }
        if ($per !== null) { $perBase = number_format((float) $cost / (float) $per, 6, '.', ''); $perUnit = number_format((float) $cost, 4, '.', ''); }
    }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static function () use ($pdo, $i, $site, $perBase, $unit, $perUnit, $reason): array {
    $pdo->beginTransaction();
    $r = set_cost_override($pdo, $i['ingredient_id'], $site, $perBase, $perBase === null ? null : $unit, $perUnit, $reason, (int) current_member_id());
    catalog_log($pdo, 'ingredient.cost_override', 'ingredient', $i['ingredient_id'], ['scope_id' => $site, 'before' => $r['before'], 'after' => $r['after'] + ['reason' => $reason, 'scope_id' => $site]]);
    $pdo->commit();
    return $r;
});
ri_done($perBase === null ? 'Cleared the cost override on ' . $i['name'] : 'Set ' . $i['name'] . ' at $' . fmt_num($perUnit, 4) . ' / ' . $unit, $i['ingredient_id'], ri_land('/ingredients/' . $i['ingredient_id'] . '/sites', $perBase === null ? 'cost_cleared' : 'cost_saved', 'cost-override-form-' . $site), 'ingredientChanged', ['scope_id' => $site, 'cost_override_per_base' => $perBase]);
