<?php
declare(strict_types=1);
/** Action recipe_version_update: recipes.write; a draft's batch yield, its unit, the expected loss, the change note (Pattern C — answers the batch form partial). Log recipe.version_update (the diff). */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$v = version_or_404($pdo, request_integer('version'));   // an active version's change is refused by the trigger in its own words
$errors = [];
$f = [];
if (req_has('batch_yield_qty')) { $f['batch_yield_qty'] = ri_decimal('batch_yield_qty', $v['batch_yield_qty'], 'The batch yield', $errors); }
if (req_has('batch_yield_unit')) { $u = (string) req_val('batch_yield_unit'); $f['batch_yield_unit_code'] = $u === '' || $u === 'portion' || $u === 'portions' ? null : $u; }
if (req_has('expected_loss_pct')) { $f['expected_loss_pct'] = ri_decimal('expected_loss_pct', $v['expected_loss_pct'], 'The expected loss', $errors, 0, false, 99.99) ?? '0'; }
if (req_has('change_note')) { $f['change_note'] = ri_text('change_note', $v['change_note'], 500, 'The change note', $errors); }
if ($errors !== []) { ri_refuse_fields($errors); }
$vid = (int) $v['recipe_version_id'];
$d = ri_guard($pdo, static function () use ($pdo, $vid, $f, $v): array {
    $d = save_version($pdo, $vid, $f);
    if ($d['after'] !== []) { recipes_log($pdo, 'recipe.version_update', 'recipe_version', $vid, $d, (int) $v['recipe_id']); }
    return $d;
});
if (is_htmx_request() && !wants_json()) {
    hx_trigger('recipeChanged');
    $recipe = find_recipe($pdo, (int) $v['recipe_id']);
    echo view('recipes/partials/version-form.php', ['version' => recipe_version($pdo, $vid), 'recipe' => $recipe, 'units' => $recipe['output_ingredient_id'] !== null ? ri_unit_options($pdo, (int) $recipe['output_ingredient_id']) : [], 'saved' => true]);
    exit;
}
ri_done('Saved the batch of v' . $v['version_no'], $vid, ri_land('/recipes/versions/' . $vid, 'saved'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id']]);
