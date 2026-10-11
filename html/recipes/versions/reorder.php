<?php
declare(strict_types=1);
/** The drag order of a draft's lines: lines[] ids in order → seq per row (the manifest's recipe_line_set with `seq`, posted for the rows at once). recipes.write. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$v = version_or_404($pdo, request_integer('version'), true);
$ids = array_values(array_filter(array_map('intval', (array) ($_POST['lines'] ?? [])), static fn (int $i): bool => $i > 0));
$vid = (int) $v['recipe_version_id'];
ri_guard($pdo, static function () use ($pdo, $vid, $ids, $v): void {
    reorder_lines($pdo, $vid, $ids);
    recipes_log($pdo, 'recipe.line_set', 'recipe_version', $vid, ['after' => ['reordered' => $ids]], (int) $v['recipe_id']);
});
if (is_htmx_request() && !wants_json()) { http_response_code(204); exit; }
ri_done('Reordered the lines', $vid, ri_land('/recipes/versions/' . $vid), 'recipeChanged');
