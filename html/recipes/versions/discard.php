<?php
declare(strict_types=1);
/** Action recipe_version_discard: recipes.write; a draft only — its lines go with it. Log recipe.version_discard. Location /recipes/{recipe}. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$v = version_or_404($pdo, request_integer('version'));
$vid = (int) $v['recipe_version_id'];
ri_guard($pdo, static function () use ($pdo, $vid, $v): void {
    discard_version($pdo, $vid);
    recipes_log($pdo, 'recipe.version_discard', 'recipe_version', $vid, ['before' => ['version_no' => (int) $v['version_no']]], (int) $v['recipe_id']);
});
ri_done('Discarded draft v' . $v['version_no'], $vid, ri_land('/recipes/' . $v['recipe_id'], 'discarded'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id']]);
