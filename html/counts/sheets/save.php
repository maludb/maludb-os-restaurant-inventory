<?php
declare(strict_types=1);
/** Action `count_sheet_save` (log count_sheet.save): ingredients.write; name (unique per site), kind, active, `fill` (every ingredient with a balance in every area, in walk order); `sheet` changes an existing one. Location /counts/sheets/{id}. */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
counts_begin('ingredients.write');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$cur = req_has('sheet') && (string) req_val('sheet') !== '' ? sheet_or_404($pdo, request_integer('sheet')) : null;
$errors = [];
$f = [];
$f['name'] = ri_text('name', $cur['name'] ?? null, 80, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? '');
$f['kind'] = ri_choice('kind', $cur['kind'] ?? 'full', array_keys(COUNT_KINDS), 'The kind', $errors, false) ?? 'full';
$f['active'] = ri_yes('active', $cur['active'] ?? true);
$fill = ri_yes('fill', false);
if ($errors !== []) { ri_refuse_fields($errors); }
$res = ri_guard($pdo, static function () use ($pdo, $site, $me, $cur, $f, $fill): array {
    $pdo->beginTransaction();
    try { $id = save_count_sheet($pdo, $site, $cur['count_sheet_id'] ?? null, $f, $fill, $me); }
    catch (DomainException $e) { throw new DomainException(str_starts_with($e->getMessage(), 'A sheet with that name') ? 'A sheet named ' . $f['name'] . ' exists at this site.' : $e->getMessage()); }
    $s = find_count_sheet($pdo, $id);
    $after = ['name' => $f['name'], 'kind' => $f['kind'], 'active' => $f['active'], 'fill' => $fill, 'lines' => $s['lines']];
    if ($cur === null) { count_log($pdo, 'count_sheet.save', 'count_sheet', $id, null, ['after' => $after]); }
    else { $d = ri_diff(['name' => $cur['name'], 'kind' => $cur['kind'], 'active' => $cur['active']], $after); count_log($pdo, 'count_sheet.save', 'count_sheet', $id, null, ['before' => $d['before'], 'after' => $d['after'] + ['fill' => $fill, 'lines' => $s['lines']]]); }
    $pdo->commit();
    return ['id' => $id, 'lines' => (int) $s['lines']];
});
ri_done(($cur === null ? 'Made the sheet ' : 'Saved the sheet ') . $f['name'] . ($fill ? ' — ' . $res['lines'] . ' lines from the shelves' : ''), $res['id'], ri_land('/counts/sheets/' . $res['id'], $cur === null ? 'created' : ($fill ? 'filled' : 'saved')), 'countSheetChanged', ['sheet_id' => $res['id'], 'lines' => $res['lines']]);
