<?php
declare(strict_types=1);
/** Action recipe_version_retire: recipes.write; the version in force with no later version leaves the menu from effective_to. Log recipe.version_retire. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$v = version_or_404($pdo, request_integer('version'));
$to = req_has('effective_to') ? (string) req_val('effective_to') : date('Y-m-d');
if ($to === '') { $to = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { ri_refuse_fields(['effective_to' => 'The date is YYYY-MM-DD.']); }
$me = (int) current_member_id();
$vid = (int) $v['recipe_version_id'];
ri_guard($pdo, static function () use ($pdo, $vid, $to, $me, $v): void {
    retire_version($pdo, $vid, $to, $me);
    recipes_log($pdo, 'recipe.version_retire', 'recipe_version', $vid, ['after' => ['version_no' => (int) $v['version_no'], 'effective_to' => $to]], (int) $v['recipe_id']);
});
ri_done('Retired v' . $v['version_no'] . ' after ' . format_date($to), $vid, ri_land('/recipes/' . $v['recipe_id'], 'retired'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id']]);
