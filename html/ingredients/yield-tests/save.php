<?php
declare(strict_types=1);
/** Action `yield_test_add` (log ingredient.yield_test): ingredients.write. With preview=1 answers the computed yield as a fragment (Pattern A, no save). Location /ingredients/{id}?tab=yield. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$i = ingredient_param_or_404($pdo);
$errors = [];
$ap = qty_with_unit($pdo, $i['ingredient_id'], 'ap_qty', 'The as-purchased quantity', $errors, false, $i['base_unit_code']);
$ep = qty_with_unit($pdo, $i['ingredient_id'], 'ep_qty', 'The edible-portion quantity', $errors, false, $i['base_unit_code']);
$preview = (string) (req_val('preview') ?? '') === '1';
if ($ap !== null && $ep !== null && (float) $ep['qty_base'] > (float) $ap['qty_base']) { $errors['ep_qty'] = 'The edible portion cannot exceed what was bought.'; }
if ($preview) {
    header('Cache-Control: no-store');
    if ($errors !== [] || $ap === null || $ep === null || (float) $ap['qty_base'] <= 0) { echo '<div class="text-muted fs-12" id="yield-test-preview">' . e($errors[array_key_first($errors)] ?? 'Enter both quantities.') . '</div>'; exit; }
    $pct = round((float) $ep['qty_base'] / (float) $ap['qty_base'] * 100, 2);
    echo '<div class="fw-semibold" id="yield-test-preview">Yield <span id="yield-test-preview-pct">' . e(fmt_num($pct, 2)) . ' %</span> <span class="text-muted fs-12">— ' . e(fmt_num($ep['qty_base'], 2)) . ' ' . e($i['base_unit_code']) . ' of ' . e(fmt_num($ap['qty_base'], 2)) . ' ' . e($i['base_unit_code']) . '; cost factor ' . e(fmt_num(100 / $pct, 4)) . '</span></div>';
    exit;
}
$f = ['ap_qty_base' => $ap['qty_base'] ?? null, 'ep_qty_base' => $ep['qty_base'] ?? null];
$f['trim_loss_pct'] = ri_num('trim_loss_pct', null, 'The trim loss', $errors, 0, 100, true, 2);
$f['cook_loss_pct'] = ri_num('cook_loss_pct', null, 'The cook loss', $errors, 0, 100, true, 2);
$f['notes'] = ri_text('notes', null, 1000, 'The notes', $errors);
$bp = [];
if (req_has('byproducts')) {
    $raw = (string) req_val('byproducts');
    $dec = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($dec)) { $errors['byproducts'] = 'The by-products are a JSON list of name, quantity and value.'; } else { $bp = $dec; }
} elseif (isset($_POST['bp']) && is_array($_POST['bp'])) {
    foreach ($_POST['bp'] as $row) { if (!is_array($row) || trim((string) ($row['name'] ?? '')) === '') { continue; } $bp[] = ['name' => mb_substr(trim((string) $row['name']), 0, 80), 'qty' => (string) ($row['qty'] ?? ''), 'unit' => (string) ($row['unit'] ?? ''), 'unit_value' => (string) ($row['unit_value'] ?? '')]; }
}
$f['byproducts'] = $bp;
$apply = ri_yes('apply', false);
if ($errors !== []) { ri_refuse_fields($errors); }
$tid = ri_guard($pdo, static function () use ($pdo, $i, $f, $apply): int {
    $pdo->beginTransaction();
    try { $tid = add_yield_test($pdo, $i['ingredient_id'], $f, $apply, (int) current_member_id()); }
    catch (PDOException $e) { $pdo->rollBack(); if ((string) $e->getCode() === '23514') { ri_refuse_fields(['ep_qty' => 'The edible portion cannot exceed what was bought.']); } throw $e; }
    $st = $pdo->prepare('SELECT yield_pct FROM mcp_yield_tests WHERE yield_test_id = :id'); $st->execute(['id' => $tid]);
    catalog_log($pdo, 'ingredient.yield_test', 'yield_test', $tid, ['ingredient_id' => $i['ingredient_id'], 'after' => ['ap_qty_base' => $f['ap_qty_base'], 'ep_qty_base' => $f['ep_qty_base'], 'yield_pct' => $st->fetchColumn(), 'applied' => $apply]]);
    $pdo->commit();
    return $tid;
});
$pct = round((float) $f['ep_qty_base'] / (float) $f['ap_qty_base'] * 100, 2);
ri_done('Recorded a yield of ' . fmt_num($pct, 2) . ' % on ' . $i['name'] . ($apply ? ' and applied it' : ''), $tid, ri_land('/ingredients/' . $i['ingredient_id'] . '?tab=yield', $apply ? 'yield_applied' : 'yield_saved'), 'ingredientChanged', ['ingredient_id' => $i['ingredient_id'], 'yield_pct' => $pct]);
