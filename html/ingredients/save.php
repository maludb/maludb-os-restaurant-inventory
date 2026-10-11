<?php
declare(strict_types=1);
/** Actions `ingredient_create` (no `ingredient`) / `ingredient_update` (with): ingredients.write; log ingredient.create / ingredient.update. A cost or a par here is refused pointing at the paused actions. Location /ingredients/{id}; refresh ingredientChanged. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('ingredients.write');
$pdo = db();
$me = (int) current_member_id();
$raw = (string) (req_val('ingredient') ?? req_val('ingredient_id') ?? '');
$cur = $raw === '' ? null : (ingredient_by_ref($pdo, $raw) ?? respond_not_found('That ingredient is not here.'));
$id = $cur['ingredient_id'] ?? null;
$errors = [];
$f = ingredient_from_request($pdo, $cur, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
if ($id === null && $f['code'] === '') {
    $base = code_from_name($f['name']);
    $f['code'] = $base;
    for ($n = 2; $n < 100; $n++) {
        $st = $pdo->prepare('SELECT 1 FROM mcp_ingredients WHERE lower(code) = lower(:c)');
        $st->execute(['c' => $f['code']]);
        if ($st->fetchColumn() === false) { break; }
        $f['code'] = $base . '_' . $n;
    }
}
$newId = ri_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    try {
        $newId = save_ingredient($pdo, $id, $f, $me);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $m = db_message($e, '');
        if ((string) $e->getCode() === '23505') { ri_refuse_fields(['code' => 'That code is already taken.']); }
        if ((string) $e->getCode() === '23514' && (str_contains($m, 'density') || str_contains($m, 'each-weight') || str_contains($m, 'each-volume') || str_contains($m, 'base unit'))) { ri_refuse_fields(['recipe_unit' => $m]); }
        throw $e;
    }
    $after = ingredient_loggable(find_ingredient($pdo, $newId));
    if ($cur === null) {
        catalog_log($pdo, 'ingredient.create', 'ingredient', $newId, ['after' => $after]);
    } else {
        $d = ri_diff(ingredient_loggable($cur), $after);
        if ($d['after'] !== []) { catalog_log($pdo, 'ingredient.update', 'ingredient', $newId, ['before' => $d['before'], 'after' => $d['after']]); }
    }
    $pdo->commit();
    return $newId;
});
ri_done(($id === null ? 'Made ' : 'Saved ') . $f['name'], $newId, ri_land(return_path('/ingredients/' . $newId), $id === null ? 'created' : 'saved'), 'ingredientChanged', ['ingredient_id' => $newId]);
