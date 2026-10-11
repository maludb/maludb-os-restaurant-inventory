<?php
declare(strict_types=1);
/** Action `production_post`: stock.produce. A new batch (recipe — a prep, batches, area, consume_area, output_qty + output_unit, made_at, notes, draft) is inserted then posted through ri_post_production() unless draft = yes; `batch` names a draft to post. Log production.post (recipe_id; after.status draft|posted; cost_total only for a cost-seer). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.produce');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$sees = sees_cost($site);
$existing = req_has('batch') && (string) req_val('batch') !== '' ? batch_or_404($pdo, (int) req_val('batch')) : null;
$draft = false;
if ($existing === null) {
    $errors = [];
    $recipe = stock_recipe_by_ref($pdo, (string) (req_val('recipe') ?? ''));
    if ($recipe === null) { $errors['recipe'] = (string) (req_val('recipe') ?? '') === '' ? 'Choose the prep recipe.' : 'That recipe is not here.'; }
    elseif ($recipe['kind'] !== 'prep') { $errors['recipe'] = 'Only a prep recipe is produced into stock — ' . $recipe['name'] . ' is a ' . str_replace('_', ' ', $recipe['kind']) . ' recipe.'; }
    $batches = stock_number(req_val('batches') ?? '1', 'batches', 'Batches', $errors, true) ?? (isset($errors['batches']) ? null : '1');
    $area = stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area the output lands in', $errors);
    $consume = (string) (req_val('consume_area') ?? '') === '' ? null : stock_area_ref($pdo, req_val('consume_area'), $site, 'consume_area', 'The area the inputs come from', $errors);
    $out = null;
    if ($recipe !== null && $recipe['kind'] === 'prep' && (string) (req_val('output_qty') ?? '') !== '') {
        $q = stock_qty_in($pdo, (int) $recipe['output_ingredient_id'], req_val('output_qty'), req_val('output_unit'), 'output_qty', 'The actual output', $errors);
        $out = $q['qty_base'] ?? null;
    }
    $madeAt = null;
    $raw = (string) (req_val('made_at') ?? '');
    if ($raw !== '') {
        try { $madeAt = (new DateTimeImmutable($raw, new DateTimeZone(site_timezone($site))))->format(DATE_ATOM); } catch (Exception) { $errors['made_at'] = 'Made at is a date and a time.'; }
        if ($madeAt !== null && strtotime($madeAt) > time() + 300) { $errors['made_at'] = 'A batch is not made in the future.'; }
    }
    $notes = ri_text('notes', null, 2000, 'Notes', $errors);
    $draft = ri_yes('draft');
    if ($errors !== []) { ri_refuse_fields($errors); }
}
$id = ri_guard($pdo, static function () use ($pdo, $existing, $site, $me, $draft, &$recipe, &$batches, &$area, &$consume, &$out, &$madeAt, &$notes): int {
    $pdo->beginTransaction();
    if ($existing !== null) {
        $id = (int) $existing['production_batch_id'];
    } else {
        $st = $pdo->prepare('INSERT INTO production_batches (scope_id, recipe_id, batches, output_qty_base, storage_area_id, consume_area_id, made_by, made_at, notes, created_by) VALUES (:s, :r, :b, :o, :a, :c, :by, COALESCE(:m::timestamptz, now()), :n, :by2) RETURNING id');
        $st->execute(['s' => $site, 'r' => $recipe['recipe_id'], 'b' => $batches, 'o' => $out, 'a' => $area, 'c' => $consume, 'by' => $me, 'm' => $madeAt, 'n' => $notes, 'by2' => $me]);
        $id = (int) $st->fetchColumn();
    }
    if (!$draft) { $pdo->prepare('SELECT ri_post_production(:b, :by)')->execute(['b' => $id, 'by' => $me]); }
    $b = batch_for($pdo, $id);
    $after = ['status' => $b['status'], 'recipe_id' => $b['recipe_id'], 'batches' => $b['batches'], 'output_qty_base' => $b['output_qty_base']];
    if (sees_cost() && $b['cost_total'] !== null) { $after['cost_total'] = $b['cost_total']; }
    stock_log($pdo, 'production.post', 'production_batch', $id, ['recipe_id' => $b['recipe_id'], 'ingredient_id' => $b['output_ingredient_id'], 'after' => $after]);
    $pdo->commit();
    return $id;
});
$b = batch_for($pdo, $id);
$extra = ['batch_id' => $id, 'status' => $b['status'], 'output_qty_base' => $b['output_qty_base'], 'output_ingredient_id' => $b['output_ingredient_id']];
if ($sees) { $extra['cost_total'] = $b['cost_total']; }
if ($b['status'] === 'posted') { $extra['transactions'] = stock_doc_txns($pdo, 'production', $id); }
ri_done(($b['status'] === 'posted' ? 'Posted ' : 'Saved as draft ') . $b['number'] . ': ' . fmt_num($b['batches'], 4) . ' × ' . $b['recipe'] . ($b['status'] === 'posted' && $b['output_ingredient_id'] ? ' — made ' . format_qty($pdo, $b['output_ingredient_id'], $b['output_qty_base']) : ''),
    $id, ri_land('/production/' . $id, $b['status'] === 'posted' ? 'posted' : 'drafted'), 'productionChanged', $extra);
