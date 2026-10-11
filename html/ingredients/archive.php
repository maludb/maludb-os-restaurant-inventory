<?php
declare(strict_types=1);
/** Action `ingredient_archive` (log ingredient.archive): ingredients.write; archived yes|no (no restores). Location /ingredients/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$i = ingredient_param_or_404($pdo);
$archived = ri_yes('archived', true);
ri_guard($pdo, static function () use ($pdo, $i, $archived): void {
    archive_ingredient($pdo, $i['ingredient_id'], $archived);
    catalog_log($pdo, 'ingredient.archive', 'ingredient', $i['ingredient_id'], ['before' => ['active' => $i['active']], 'after' => ['archived' => $archived, 'active' => !$archived]]);
});
ri_done(($archived ? 'Archived ' : 'Restored ') . $i['name'], $i['ingredient_id'], ri_land('/ingredients/' . $i['ingredient_id'], $archived ? 'archived' : 'restored'), 'ingredientChanged');
