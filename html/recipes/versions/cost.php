<?php
declare(strict_types=1);
/** GET /recipes/versions/{version}/cost — the editor's cost panel (Pattern A fragment; walled: a caller without sees_cost() gets "cost withheld" and the function is never called). */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$v = version_or_404($pdo, request_integer('id') ?? request_integer('version'));
$site = (int) current_site_id();
$recipe = find_recipe($pdo, (int) $v['recipe_id']);
$cost = sees_cost($site) ? recipe_cost($pdo, (int) $v['recipe_version_id'], $site) : null;
if (wants_json()) { json_response(['ok' => true, 'cost' => $cost === null ? null : ['batch_cost' => $cost['batch_cost'], 'portion_cost' => $cost['portion_cost'], 'uncosted' => (int) $cost['uncosted'], 'lines' => $cost['lines']], 'withheld' => !sees_cost($site)]); }
header('Cache-Control: no-store');
echo view('recipes/partials/cost-panel.php', ['cost' => $cost, 'seesCost' => sees_cost($site), 'lines' => recipe_lines($pdo, (int) $v['recipe_version_id']), 'recipe' => $recipe, 'version' => $v]);
