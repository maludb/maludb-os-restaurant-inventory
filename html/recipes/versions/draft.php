<?php
declare(strict_types=1);
/** Action recipe_version_draft: recipes.write; a new draft copied from the version in force (the verb refuses a second draft). Log recipe.version_draft. Location the editor. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$id = ri_resolve_recipe($pdo, (string) req_val('recipe'));
$cur = recipe_or_404($pdo, $id);
$me = (int) current_member_id();
$note = req_val('change_note');
$vid = ri_guard($pdo, static function () use ($pdo, $id, $me, $note): int {
    $copied = version_in_force($pdo, $id);
    $vid = new_draft($pdo, $id, $note === '' ? null : $note, $me);
    $v = recipe_version($pdo, $vid);
    recipes_log($pdo, 'recipe.version_draft', 'recipe_version', $vid, ['after' => ['version_no' => (int) $v['version_no'], 'copied_from' => $copied, 'change_note' => $note]], $id);
    return $vid;
});
ri_done('Started a new draft of ' . $cur['name'], $vid, ri_land('/recipes/versions/' . $vid, 'drafted'), 'recipeChanged', ['recipe_id' => $id, 'version_id' => $vid]);
