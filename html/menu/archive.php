<?php
declare(strict_types=1);
/** Action menu_item_archive: recipes.write. Log menu_item.archive. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
recipes_write_begin();
$pdo = db();
$m = menu_item_or_404($pdo, request_integer('menu_item'));
$archived = ri_yes('archived', true);
$id = (int) $m['menu_item_id'];
ri_guard($pdo, static function () use ($pdo, $id, $archived, $m): void {
    archive_menu_item($pdo, $id, $archived);
    recipes_log($pdo, 'menu_item.archive', 'menu_item', $id, ['before' => ['active' => (bool) $m['active']], 'after' => ['archived' => $archived]]);
});
ri_done(($archived ? 'Archived ' : 'Restored ') . $m['name'], $id, ri_land('/menu/' . $id, $archived ? 'archived' : 'restored'), 'menuItemChanged');
