<?php
declare(strict_types=1);
/** Actions recipe_create (no recipe) / recipe_update (with): recipes.write. On create the recipe AND its first draft version are made (lines JSON through the same validation as recipe_line_set); the kind and the output ingredient are locked once activated or mapped; lines on an update point at recipe_line_set. Log recipe.create / recipe.update. Location /recipes/{id}/versions/{version} on create, /recipes/{id} on update; refresh recipeChanged. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$me = (int) current_member_id();
$idRaw = req_val('recipe');
$id = $idRaw === null || $idRaw === '' ? null : ri_resolve_recipe($pdo, $idRaw);
if ($idRaw !== null && $idRaw !== '' && $id === null) { refuse(404, 'That recipe is not here.'); }
$cur = $id === null ? null : recipe_or_404($pdo, $id);
$errors = [];
$f = recipe_from_request($pdo, $cur, $errors);
if ($cur !== null && recipe_locked($pdo, $id)) {
    if (array_key_exists('kind', $f) && $f['kind'] !== $cur['kind']) { $errors['kind'] = 'The kind is locked: a version was activated or a menu item maps to this recipe.'; }
    if (array_key_exists('output_ingredient_id', $f) && $f['output_ingredient_id'] !== ($cur['output_ingredient_id'] === null ? null : (int) $cur['output_ingredient_id'])) { $errors['output_ingredient'] = 'The output ingredient is locked: a version was activated or a menu item maps to this recipe.'; }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): array {
    $pdo->beginTransaction();
    $r = save_recipe($pdo, $id, $f, $me);
    if (!empty($_FILES['photo']['name'])) {
        $aid = attachment_store($pdo, 'recipe', $r['id'], $_FILES['photo'], $me);
        $pdo->prepare('UPDATE recipes SET photo_attachment_id = :a WHERE id = :id')->execute(['a' => $aid, 'id' => $r['id']]);
    }
    $after = recipe_loggable(find_recipe($pdo, $r['id']));
    if ($cur === null) {
        recipes_log($pdo, 'recipe.create', 'recipe', $r['id'], ['after' => $after + ['version_id' => $r['version_id'], 'lines' => count($f['lines'] ?? [])]]);
    } else {
        $d = ri_diff(recipe_loggable($cur), $after);
        if ($d['after'] !== []) { recipes_log($pdo, 'recipe.update', 'recipe', $r['id'], $d); }
    }
    $pdo->commit();
    return $r;
});
$name = (string) ($f['name'] ?? $cur['name'] ?? 'the recipe');
if ($cur === null) {
    ri_done('Made ' . $name . ' with its first draft', $r['id'], ri_land(return_path('/recipes/versions/' . $r['version_id']), 'created'), 'recipeChanged', ['recipe_id' => $r['id'], 'version_id' => $r['version_id'], 'location_editor' => '/recipes/versions/' . $r['version_id']]);
}
ri_done('Saved ' . $name, $r['id'], ri_land(return_path('/recipes/' . $r['id']), 'saved'), 'recipeChanged', ['recipe_id' => $r['id']]);
