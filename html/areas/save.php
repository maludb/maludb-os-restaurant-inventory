<?php
declare(strict_types=1);
/** Actions `area_create` / `area_update` (log area.create / area.update with scope_id): ingredients.write at the current site. Location /areas/. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$site = (int) current_site_id();
$raw = (string) (req_val('area') ?? req_val('storage_area') ?? '');
$cur = null;
if ($raw !== '') {
    $cur = ctype_digit($raw) ? find_storage_area($pdo, (int) $raw) : null;
    if ($cur === null) { $st = $pdo->prepare('SELECT storage_area_id FROM mcp_storage_areas WHERE scope_id = :s AND lower(name) = lower(:n)'); $st->execute(['s' => $site, 'n' => $raw]); $x = $st->fetchColumn(); $cur = $x === false ? null : find_storage_area($pdo, (int) $x); }
    if ($cur === null || $cur['scope_id'] !== $site) { respond_not_found('That storage area is not here.'); }
}
$errors = [];
$f = [];
$f['name'] = ri_text('name', $cur['name'] ?? null, 80, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? '');
$f['kind'] = ri_choice('kind', $cur['kind'] ?? 'dry', array_keys(AREA_KINDS), 'The kind', $errors, false) ?? 'dry';
$f['walk_order'] = ri_int('walk_order', isset($cur['walk_order']) ? (int) $cur['walk_order'] : ((int) one_value($pdo, 'SELECT COALESCE(max(walk_order), 0) FROM mcp_storage_areas WHERE scope_id = :s', ['s' => $site])) + 10, 0, 100000, 'The walk order', $errors, false);
$f['allow_negative'] = ri_yes('allow_negative', $cur['allow_negative'] ?? true);
$f['active'] = ri_yes('active', $cur['active'] ?? true);
if ($errors !== []) { ri_refuse_fields($errors); }
$newId = ri_guard($pdo, static function () use ($pdo, $site, $cur, $f): int {
    $pdo->beginTransaction();
    try { $newId = save_area($pdo, $site, $cur['storage_area_id'] ?? null, $f); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23505') { ri_refuse_fields(['name' => 'There is already an area called ' . $f['name'] . ' at this site.']); } throw $e; }
    if ($cur === null) { catalog_log($pdo, 'area.create', 'storage_area', $newId, ['scope_id' => $site, 'after' => $f + ['scope_id' => $site]]); }
    else { $d = ri_diff(['name' => $cur['name'], 'kind' => $cur['kind'], 'walk_order' => (int) $cur['walk_order'], 'allow_negative' => $cur['allow_negative'], 'active' => $cur['active']], $f); if ($d['after'] !== []) { catalog_log($pdo, 'area.update', 'storage_area', $newId, ['scope_id' => $site, 'before' => $d['before'], 'after' => $d['after']]); } }
    $pdo->commit();
    return $newId;
});
ri_done(($cur === null ? 'Made ' : 'Saved ') . $f['name'], $newId, ri_land(return_path('/areas/'), $cur === null ? 'created' : 'saved', 'area-row-' . $newId), 'areaChanged', ['scope_id' => $site]);
