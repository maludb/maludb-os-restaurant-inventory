<?php
declare(strict_types=1);
/**
 * Action `waste_log`: stock.waste. stage (ingredient | prep | plate), reason (a waste reason — the schema's sentence otherwise), ingredient XOR recipe,
 * qty + unit (a recipe: portion | batch) or gross_weight_g + tare_g (an ingredient weighed), station, day_part, area, disposition, photo, notes,
 * business_date. The row is inserted and ri_post_waste() posts it in the same transaction (a recipe explodes through its version in force). The
 * photo is an attachment, never in a log payload. Log waste.log (ingredient_id / recipe_id; cost only for a cost-seer). Lands on the list, the row highlighted.
 */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.waste');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$stage = ri_choice('stage', 'ingredient', array_keys(WASTE_STAGES), 'The stage', $errors, false) ?? 'ingredient';
$reasonRaw = (string) (req_val('reason') ?? '');
$reason = $reasonRaw === '' ? null : reason_by_ref($pdo, $reasonRaw);
if ($reason === null) { $errors['reason'] = $reasonRaw === '' ? 'Say why — the reason is required.' : 'That reason is not here.'; }
elseif (!in_array('waste', pg_text_array((string) $reason['applies_to']), true)) { $errors['reason'] = 'The reason ' . $reason['name'] . ' is not a waste reason'; }
$ing = (string) (req_val('ingredient') ?? '') === '' ? null : ingredient_by_ref($pdo, (string) req_val('ingredient'));
$rec = (string) (req_val('recipe') ?? '') === '' ? null : stock_recipe_by_ref($pdo, (string) req_val('recipe'));
if ((string) (req_val('ingredient') ?? '') !== '' && $ing === null) { $errors['ingredient'] = 'That ingredient is not here.'; }
if ((string) (req_val('recipe') ?? '') !== '' && $rec === null) { $errors['recipe'] = 'That recipe is not here.'; }
if ($ing !== null && $rec !== null) { $errors['recipe'] = 'An ingredient or a recipe — not both.'; }
if ($ing === null && $rec === null && !isset($errors['ingredient']) && !isset($errors['recipe'])) { $errors[$stage === 'plate' ? 'recipe' : 'ingredient'] = $stage === 'plate' ? 'Choose the plate (a recipe).' : 'Choose the ingredient.'; }
if ($stage === 'ingredient' && $rec !== null) { $errors['recipe'] = 'Ingredient-stage waste names an ingredient — a prep or a plate names a recipe.'; }
$qty = null; $unit = null; $gross = null; $tare = null;
$gRaw = (string) (req_val('gross_weight_g') ?? '');
if ($gRaw !== '' && $ing !== null) {
    $gross = stock_number($gRaw, 'gross_weight_g', 'The gross weight', $errors, true);
    $tare = stock_number(req_val('tare_g') ?? '', 'tare_g', 'The tare', $errors, false, 0) ?? '0';
    if ($gross !== null && (float) $tare >= (float) $gross) { $errors['tare_g'] = 'The tare is less than the gross weight.'; }
    if (!isset($errors['gross_weight_g']) && !isset($errors['tare_g']) && $gross !== null) {
        $qty = number_format((float) $gross - (float) $tare, 4, '.', ''); $unit = 'g';
        try { qty_to_base($pdo, (int) $ing['ingredient_id'], $qty, 'g'); } catch (PDOException $e) { $errors['gross_weight_g'] = db_message($e, 'That ingredient is not weighed.'); }
    }
} elseif ($ing !== null) {
    $q = stock_qty_in($pdo, (int) $ing['ingredient_id'], req_val('qty'), req_val('unit') ?: (default_count_unit($pdo, (int) $ing['ingredient_id']) ?? $ing['base_unit_code']), 'qty', 'The quantity', $errors);
    if ($q !== null) { $qty = $q['qty']; $unit = $q['unit']; }
} elseif ($rec !== null) {
    $qty = stock_number(req_val('qty'), 'qty', 'The quantity', $errors, true);
    if ($qty === null && !isset($errors['qty'])) { $errors['qty'] = 'The quantity is required.'; }
    $unit = (string) (req_val('unit') ?? '') ?: 'portion';
    if (!in_array($unit, ['portion', 'batch'], true)) { $errors['qty_unit'] = 'A recipe is wasted in portions or batches.'; $errors['unit'] = $errors['qty_unit']; }
}
$station = ri_text('station', null, 60, 'The station', $errors);
$dayPart = null;
if ((string) (req_val('day_part') ?? '') !== '') {
    $v = (string) req_val('day_part');
    $dayPart = one_value($pdo, 'SELECT day_part_id FROM mcp_day_parts WHERE scope_id = :s AND (day_part_id::text = :v OR key = :v2)', ['s' => $site, 'v' => $v, 'v2' => $v]);
    if ($dayPart === null || $dayPart === false) { $errors['day_part'] = 'That day part is not this site\'s.'; $dayPart = null; }
} elseif (!req_has('day_part')) { $dayPart = current_day_part($pdo, $site); }
$area = (string) (req_val('area') ?? '') === '' ? null : stock_area_ref($pdo, req_val('area'), $site, 'area', 'The area', $errors);
$disposition = ri_choice('disposition', 'trash', array_keys(WASTE_DISPOSITIONS), 'The disposition', $errors, false) ?? 'trash';
$notes = ri_text('notes', null, 2000, 'Notes', $errors);
$bdate = (string) (req_val('business_date') ?? '') ?: site_today($site);
if (DateTimeImmutable::createFromFormat('!Y-m-d', $bdate) === false || $bdate > site_today($site)) { $errors['business_date'] = 'The day is a date, not in the future.'; }
$photo = isset($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['photo'] : null;
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $site, $me, $stage, $reason, $ing, $rec, $qty, $unit, $gross, $tare, $station, $dayPart, $area, $disposition, $notes, $bdate, $photo): int {
    $pdo->beginTransaction();
    $st = $pdo->prepare('INSERT INTO waste_logs (scope_id, logged_by, business_date, station, day_part_id, stage, reason_code_id, ingredient_id, recipe_id, qty, unit_code, gross_weight_g, tare_g, storage_area_id, disposition, notes)
                         VALUES (:s, :by, :d, :st, :dp, :sg, :r, :i, :rc, :q, :u, :g, :t, :a, :disp, :n) RETURNING id');
    $st->execute(['s' => $site, 'by' => $me, 'd' => $bdate, 'st' => $station, 'dp' => $dayPart, 'sg' => $stage, 'r' => $reason['reason_code_id'], 'i' => $ing['ingredient_id'] ?? null, 'rc' => $rec['recipe_id'] ?? null,
                  'q' => $qty, 'u' => $unit, 'g' => $gross, 't' => $gross === null ? null : $tare, 'a' => $area, 'disp' => $disposition, 'n' => $notes]);
    $id = (int) $st->fetchColumn();
    $pdo->prepare('SELECT ri_post_waste(:w, :by)')->execute(['w' => $id, 'by' => $me]);
    if ($photo !== null) {
        $att = attachment_store($pdo, 'waste_log', $id, $photo, $me);
        $pdo->prepare('UPDATE waste_logs SET photo_attachment_id = :a WHERE id = :id')->execute(['a' => $att, 'id' => $id]);
    }
    $w = waste_for($pdo, $id);
    $after = ['stage' => $stage, 'reason' => $reason['code'], 'qty' => $qty, 'unit' => $unit, 'qty_base' => $w['qty_base'], 'disposition' => $disposition, 'photo' => $photo !== null];
    if (sees_cost()) { $after['cost'] = $w['cost']; }
    stock_log($pdo, 'waste.log', 'waste_log', $id, ['ingredient_id' => $ing['ingredient_id'] ?? null, 'recipe_id' => $rec['recipe_id'] ?? null, 'after' => $after]);
    $pdo->commit();
    return $id;
});
$w = waste_for($pdo, $id);
$extra = ['waste_id' => $id, 'number' => $w['number'], 'qty_base' => $w['qty_base'], 'transactions' => stock_doc_txns($pdo, 'waste', $id)];
if (sees_cost()) { $extra['cost'] = $w['cost']; }
ri_done('Logged ' . WASTE_STAGES[$stage] . ' waste: ' . fmt_num($qty, 4) . ' ' . $unit . ' of ' . ($ing['name'] ?? $rec['name']) . ' (' . $reason['name'] . ')', $id, ri_land('/waste/?highlight=' . $id, 'logged', 'waste-row-' . $id), 'wasteChanged', $extra);
