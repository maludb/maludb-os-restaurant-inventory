<?php
declare(strict_types=1);
/** Action recipe_version_activate: recipes.write; ri_recipe_version_activate(version, by, from) — the verb's refusals in its words. Agent approval: other. Log recipe.version_activate with ended_version_no. Location /recipes/{recipe}?version=. */
require_once dirname(__DIR__, 3) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$v = version_or_404($pdo, request_integer('version'), true);
$from = req_has('effective_from') ? req_val('effective_from') : date('Y-m-d');
if ($from === '' || $from === null) { $from = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))) { ri_refuse_fields(['effective_from' => 'The date is YYYY-MM-DD.']); }
$me = (int) current_member_id();
$vid = (int) $v['recipe_version_id'];
$r = ri_guard($pdo, static function () use ($pdo, $vid, $from, $me, $v): array {
    $pdo->beginTransaction();
    $r = activate_version($pdo, $vid, $from, $me);
    recipes_log($pdo, 'recipe.version_activate', 'recipe_version', $vid, ['after' => ['version_no' => (int) $v['version_no'], 'effective_from' => $from, 'ended_version_no' => $r['ended'], 'ended_at' => $r['ended'] === null ? null : $from]], (int) $v['recipe_id']);
    $pdo->commit();
    return $r;
});
$recipe = find_recipe($pdo, (int) $v['recipe_id']);
ri_done('Activated v' . $v['version_no'] . ' of ' . $recipe['name'] . ' from ' . format_date($from) . ($r['ended'] !== null ? ' — v' . $r['ended'] . ' ends then' : ''), $vid, ri_land('/recipes/' . $v['recipe_id'] . '?version=' . $vid, 'activated'), 'recipeChanged', ['recipe_id' => (int) $v['recipe_id'], 'ended_version_no' => $r['ended']]);
