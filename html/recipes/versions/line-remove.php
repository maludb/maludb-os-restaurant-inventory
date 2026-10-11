<?php
declare(strict_types=1);
/** Action recipe_line_remove: recipes.write; a draft's line. Log recipe.line_remove (before the line). Pattern C answers an empty swap. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$lineId = request_integer('line');
$st = $pdo->prepare('SELECT version_id FROM mcp_recipe_lines WHERE recipe_line_id = :id'); $st->execute(['id' => $lineId]);
$vid = $st->fetchColumn();
if ($vid === false) { refuse(404, 'That line is not here.'); }
$v = version_or_404($pdo, (int) $vid, true);
ri_guard($pdo, static function () use ($pdo, $lineId, $v): void {
    $before = remove_recipe_line($pdo, $lineId);
    recipes_log($pdo, 'recipe.line_remove', 'recipe_line', $lineId, ['before' => $before], (int) $v['recipe_id']);
});
if (is_htmx_request() && !wants_json()) { hx_trigger('recipeChanged'); http_response_code(200); echo ''; exit; }
ri_done('Removed the line', $lineId, ri_land('/recipes/versions/' . $v['recipe_version_id'], 'line_removed'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id']]);
