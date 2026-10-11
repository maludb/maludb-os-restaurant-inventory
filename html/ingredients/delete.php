<?php
declare(strict_types=1);
/** Action `ingredient_delete` (log ingredient.delete; agent approval: deletion): records.delete; only one with no ledger row, recipe line, supplier item or count line. Location /ingredients/. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('records.delete');
$pdo = db();
$i = ingredient_param_or_404($pdo);
$why = ingredient_deletable($pdo, $i['ingredient_id']);
if ($why !== null) { emit_action_status(false, ['error' => $why]); refuse(422, $why); }
ri_guard($pdo, static function () use ($pdo, $i): void {
    delete_ingredient($pdo, $i['ingredient_id']);
    catalog_log($pdo, 'ingredient.delete', 'ingredient', $i['ingredient_id'], ['after' => ['name' => $i['name'], 'code' => $i['code']]]);
});
ri_done('Deleted ' . $i['name'], $i['ingredient_id'], ri_land('/ingredients/', 'deleted'), 'ingredientChanged');
