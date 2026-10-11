<?php
declare(strict_types=1);
/** Action recipe_delete: records.delete; only a recipe never activated, not mapped, named by no line and never produced (recipe_deletable()). Agent approval: deletion. Log recipe.delete. Location /recipes/. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin('records.delete');
$pdo = db();
$id = ri_resolve_recipe($pdo, (string) req_val('recipe'));
$cur = recipe_or_404($pdo, $id);
$why = recipe_deletable($pdo, $id);
if ($why !== null) { emit_action_status(false, ['error' => $why]); refuse(422, $cur['name'] . ' cannot be deleted: ' . $why . ' Archive it instead.'); }
ri_guard($pdo, static function () use ($pdo, $id, $cur): void {
    delete_recipe($pdo, $id);
    recipes_log($pdo, 'recipe.delete', 'recipe', $id, ['after' => ['name' => $cur['name'], 'code' => $cur['code'], 'kind' => $cur['kind']]]);
});
ri_done('Deleted ' . $cur['name'], $id, ri_land('/recipes/', 'deleted'), 'recipeChanged');
