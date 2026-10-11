<?php
declare(strict_types=1);
/** Action `area_reorder` (log area.reorder with scope_id): ingredients.write; areas[] ids of this site in walk order → walk_order 10, 20, 30… */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$site = (int) current_site_id();
$ids = array_map('intval', request_list('areas') ?? []);
if ($ids === []) { ri_refuse_fields(['areas' => 'Say the areas in walk order.']); }
$own = array_column(find_storage_areas($pdo, $site, false), 'storage_area_id');
foreach ($ids as $id) { if (!in_array($id, $own, true)) { ri_refuse_fields(['areas' => 'Area #' . $id . ' is not at this site.']); } }
ri_guard($pdo, static function () use ($pdo, $site, $ids): void {
    $pdo->beginTransaction();
    reorder_areas($pdo, $site, $ids);
    catalog_log($pdo, 'area.reorder', 'site', $site, ['scope_id' => $site, 'after' => ['order' => $ids]]);
    $pdo->commit();
});
ri_done('Saved the walk order of ' . count($ids) . ' areas', $site, ri_land('/areas/', 'reordered'), 'areaChanged', ['order' => $ids]);
