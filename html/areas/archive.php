<?php
declare(strict_types=1);
/** Action `area_archive` (log area.archive): ingredients.write; refused in words while anything is on hand there. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$a = catalog_area_or_404($pdo, request_integer('area') ?? (ctype_digit((string) req_val('area')) ? (int) req_val('area') : null));
$archived = ri_yes('archived', true);
if ($archived) { $why = area_archivable($pdo, $a['storage_area_id']); if ($why !== null) { emit_action_status(false, ['error' => $why]); refuse(422, $why); } }
ri_guard($pdo, static function () use ($pdo, $a, $archived): void {
    archive_area($pdo, $a['storage_area_id'], $archived);
    catalog_log($pdo, 'area.archive', 'storage_area', $a['storage_area_id'], ['scope_id' => $a['scope_id'], 'before' => ['active' => $a['active']], 'after' => ['archived' => $archived, 'active' => !$archived]]);
});
ri_done(($archived ? 'Archived ' : 'Restored ') . $a['name'], $a['storage_area_id'], ri_land('/areas/', $archived ? 'archived' : 'restored', 'area-row-' . $a['storage_area_id']), 'areaChanged');
