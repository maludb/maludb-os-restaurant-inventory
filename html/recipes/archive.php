<?php
declare(strict_types=1);
/** Action recipe_archive: recipes.write; archived yes/no (no restores). Log recipe.archive. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$id = ri_resolve_recipe($pdo, (string) req_val('recipe'));
$cur = recipe_or_404($pdo, $id);
$archived = ri_yes('archived', true);
ri_guard($pdo, static function () use ($pdo, $id, $archived, $cur): void {
    archive_recipe($pdo, $id, $archived);
    recipes_log($pdo, 'recipe.archive', 'recipe', $id, ['before' => ['active' => (bool) $cur['active']], 'after' => ['archived' => $archived, 'active' => !$archived]]);
});
ri_done(($archived ? 'Archived ' : 'Restored ') . $cur['name'], $id, ri_land('/recipes/' . $id, $archived ? 'archived' : 'restored'), 'recipeChanged');
