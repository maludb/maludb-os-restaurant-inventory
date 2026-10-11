<?php
declare(strict_types=1);
/** Action recipe_line_set: recipes.write; a draft's line — INSERT (no line) or UPDATE (with); qty_base is the trigger's. Pattern C answers the row partial + HX-Trigger recipeChanged (the cost panel refreshes). Log recipe.line_set with recipe_id. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$lineId = request_integer('line');
$cur = null;
if ($lineId !== null) {
    $st = $pdo->prepare('SELECT * FROM mcp_recipe_lines WHERE recipe_line_id = :id'); $st->execute(['id' => $lineId]); $cur = $st->fetch() ?: null;
    if ($cur === null) { refuse(404, 'That line is not here.'); }
}
$v = version_or_404($pdo, $cur !== null ? (int) $cur['version_id'] : request_integer('version'), true);
$vid = (int) $v['recipe_version_id'];
$errors = [];
$f = line_from_request($pdo, $cur, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $vid, $lineId, $f, $v): int {
    $pdo->beginTransaction();
    $id = save_recipe_line($pdo, $vid, $lineId, $f);
    $st = $pdo->prepare('SELECT ingredient_id, sub_recipe_id, qty, unit_code, qty_base, line_yield_pct, seq FROM recipe_lines WHERE id = :id'); $st->execute(['id' => $id]); $after = $st->fetch();
    recipes_log($pdo, 'recipe.line_set', 'recipe_line', $id, ['after' => $after + ['version_id' => $vid]], (int) $v['recipe_id']);
    $pdo->commit();
    return $id;
});
if (is_htmx_request() && !wants_json()) {
    hx_trigger('recipeChanged');
    $line = null;
    foreach (recipe_lines($pdo, $vid) as $l) { if ((int) $l['recipe_line_id'] === $id) { $line = $l; } }
    if ($lineId === null) {
        // the add row answered: the new row goes before the add row (hx-swap beforebegin on the add row); the add row re-renders empty through the trigger
        echo view('recipes/partials/editor-row.php', ['l' => $line, 'version' => $v]);
        exit;
    }
    echo view('recipes/partials/editor-row.php', ['l' => $line, 'version' => $v]);
    exit;
}
ri_done(($lineId === null ? 'Added ' : 'Saved ') . 'the line', $id, ri_land('/recipes/versions/' . $vid, 'line_saved'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id'], 'version_id' => $vid]);
