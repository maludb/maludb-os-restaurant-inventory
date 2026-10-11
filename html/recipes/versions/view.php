<?php
declare(strict_types=1);
/** /recipes/versions/{version} — THE RECIPE EDITOR (screen recipe-version-edit, manifest URL /recipes/{recipe}/versions/{version}): a draft; an active or retired version → 303 to the recipe's page at that version. recipes.write. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
require_login();
require_right('recipes.write');
$pdo = db();
$v = version_or_404($pdo, request_integer('id') ?? request_integer('version'));
$vid = (int) $v['recipe_version_id'];
if ($v['status'] !== 'draft') { redirect('/recipes/' . $v['recipe_id'] . '?version=' . $vid); }
$recipe = find_recipe($pdo, (int) $v['recipe_id']);
$site = (int) current_site_id();
$lines = recipe_lines($pdo, $vid);
log_screen_view($pdo, 'recipe-version-edit', ['recipe_id' => (int) $v['recipe_id']]);
if (wants_json()) { respond_screen(['recipe' => recipe_loggable($recipe) + ['recipe_id' => (int) $recipe['recipe_id']], 'version' => present_version($v), 'lines' => array_map('present_line', $lines), 'cost' => sees_cost($site) ? recipe_cost($pdo, $vid, $site) : null, 'sees_cost' => sees_cost($site)]); }
$notices = ['created' => ['success', 'The recipe was made — add its lines.'], 'drafted' => ['success', 'A new draft copied from the version in force.'], 'saved' => ['success', 'Saved.'], 'line_saved' => ['success', 'The line was saved.'], 'line_removed' => ['warning', 'The line was removed.']];
render_screen('Edit v' . $v['version_no'] . ' — ' . $recipe['name'], view('recipes/editor.php', ['recipe' => $recipe, 'version' => $v, 'lines' => $lines, 'cost' => sees_cost($site) ? recipe_cost($pdo, $vid, $site) : null, 'seesCost' => sees_cost($site),
    'units' => $recipe['output_ingredient_id'] !== null ? ri_unit_options($pdo, (int) $recipe['output_ingredient_id']) : [], 'preview' => activation_preview($pdo, $vid, date('Y-m-d')), 'back' => back_link(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'recipe-list', 'screen' => 'recipe-version-edit', 'entity' => 'recipe', 'recordId' => (string) $v['recipe_id']]);
