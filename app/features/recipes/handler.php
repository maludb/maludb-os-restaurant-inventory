<?php
declare(strict_types=1);

/**
 * The handlers' shared prelude for slice 2 (recipes.md "Handlers"): the request readers (recipe_from_request(), line_from_request()), the
 * gates (recipes_write_begin()), the 404s and recipes_log() — entity_type recipe / recipe_version / recipe_line / menu_item / modifier /
 * menu_category, with recipe_id on every recipe-side row. Every handler: ri_handler_begin() → the right at the site → ri_guard() → the
 * verb → log → ri_done().
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/present.php';

function recipes_write_begin(string $right = 'recipes.write'): void
{
    ri_handler_begin();
    require_right($right);
}
function recipe_or_404(PDO $pdo, ?int $id): array
{
    $r = $id === null ? null : find_recipe($pdo, $id);
    if ($r === null) { refuse(404, 'That recipe is not here.'); }
    return $r;
}
/** The version — a draft when $draft — of a recipe the caller may see; 404 / 422 in words. */
function version_or_404(PDO $pdo, ?int $id, bool $draft = false): array
{
    $v = $id === null ? null : recipe_version($pdo, $id);
    if ($v === null) { refuse(404, 'That version is not here.'); }
    if ($draft && $v['status'] !== 'draft') { refuse(422, 'Version ' . $v['version_no'] . ' is ' . $v['status'] . ' — the lines of a version change only while it is a draft.'); }
    return $v;
}
function menu_item_or_404(PDO $pdo, ?int $id): array
{
    $m = $id === null ? null : find_menu_item($pdo, $id);
    if ($m === null) { refuse(404, 'That menu item is not here.'); }
    return $m;
}
function modifier_or_404(PDO $pdo, ?int $id): array
{
    $m = $id === null ? null : find_modifier($pdo, $id);
    if ($m === null) { refuse(404, 'That modifier is not here.'); }
    return $m;
}
/** log_activity() with the recipe's audit key. */
function recipes_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = [], ?int $recipeId = null): void
{
    if ($recipeId !== null) { $opts['recipe_id'] = $recipeId; }
    log_activity($pdo, $action, $entityType, $entityId, $opts);
}
/** A record param that accepts an id or a name/code, resolved through the feature's own query. */
function ri_resolve_recipe(PDO $pdo, string $value): ?int
{
    if (ctype_digit($value)) { return (int) $value; }
    $st = $pdo->prepare('SELECT recipe_id FROM mcp_recipes WHERE lower(code) = lower(:v) OR lower(name) = lower(:v2) ORDER BY active DESC LIMIT 1');
    $st->execute(['v' => $value, 'v2' => $value]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}
function ri_resolve_ingredient(PDO $pdo, string $value): ?int
{
    if (ctype_digit($value)) { return (int) $value; }
    $st = $pdo->prepare('SELECT ingredient_id FROM mcp_ingredients WHERE lower(code) = lower(:v) OR lower(name) = lower(:v2) ORDER BY active DESC LIMIT 1');
    $st->execute(['v' => $value, 'v2' => $value]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}
/** A menu item by id, else by PLU (a PLU may be all digits: the id wins when one exists). */
function ri_resolve_menu_item(PDO $pdo, string $value): ?int
{
    if (ctype_digit($value) && find_menu_item($pdo, (int) $value) !== null) { return (int) $value; }
    $st = $pdo->prepare('SELECT menu_item_id FROM mcp_menu_items WHERE plu = :p ORDER BY active DESC, menu_item_id LIMIT 1');
    $st->execute(['p' => $value]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}
function ri_resolve_category(PDO $pdo, string $value): ?int
{
    if (ctype_digit($value)) { return (int) $value; }
    $st = $pdo->prepare('SELECT category_id FROM mcp_menu_categories WHERE lower(key) = lower(:v) OR lower(name) = lower(:v2) LIMIT 1');
    $st->execute(['v' => $value, 'v2' => $value]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}
function ri_decimal(string $name, ?string $keep, string $label, array &$errors, float $min = 0, bool $strictMin = true, ?float $max = null, bool $required = false): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { if ($required) { $errors[$name] = $label . ' is required.'; } return $required ? $keep : null; }
    if (!is_numeric($v) || ($strictMin ? (float) $v <= $min : (float) $v < $min) || ($max !== null && (float) $v > $max)) { $errors[$name] = $label . ' is a number' . ($strictMin ? ' above ' : ' from ') . ri_fmt($min) . ($max !== null ? ' to ' . ri_fmt($max) : '') . '.'; return $keep; }
    return $v;
}

/** The recipe form → fields (a field left out stays as it was); $cur = the current row on update. */
function recipe_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = ri_text('name', $cur['name'] ?? null, 120, 'The name', $errors, $cur === null);
    if (req_has('code')) { $c = ri_text('code', $cur['code'] ?? null, 40, 'The code', $errors); $f['code'] = $c; }
    if (req_has('kind') || $cur === null) { $f['kind'] = ri_choice('kind', $cur['kind'] ?? null, array_keys(RECIPE_KINDS), 'The kind', $errors, false); if (($f['kind'] ?? null) === null && $cur === null) { $errors['kind'] = 'The kind is one of: menu_item, prep, modifier.'; } }
    if (req_has('menu_category')) { $v = (string) req_val('menu_category'); $f['menu_category_id'] = $v === '' ? null : ri_resolve_category($pdo, $v); if ($v !== '' && $f['menu_category_id'] === null) { $errors['menu_category'] = 'Choose a menu category from the list.'; } }
    if (req_has('output_ingredient')) { $v = (string) req_val('output_ingredient'); $f['output_ingredient_id'] = $v === '' ? null : ri_resolve_ingredient($pdo, $v); if ($v !== '' && $f['output_ingredient_id'] === null) { $errors['output_ingredient'] = 'Choose the ingredient the prep makes from the list.'; } }
    if (req_has('portion_qty')) { $f['portion_qty'] = ri_decimal('portion_qty', $cur['portion_qty'] ?? null, 'The portion', $errors); }
    if (req_has('portion_unit')) { $f['portion_unit_code'] = ri_text('portion_unit', $cur['portion_unit_code'] ?? null, 16, 'The portion unit', $errors); }
    if (req_has('method')) { $f['method'] = ri_text('method', $cur['method'] ?? null, 20000, 'The method', $errors); }
    if ($cur === null) {
        $kind = $f['kind'] ?? 'menu_item';
        if (($f['menu_category_id'] ?? null) === null && !req_has('menu_category')) { $f['menu_category_id'] = $kind === 'prep' ? ri_resolve_category($pdo, 'prep') : ($kind === 'modifier' ? ri_resolve_category($pdo, 'modifiers') : null); }
        $f['batch_yield_qty'] = ri_decimal('batch_yield_qty', '1', 'The batch yield', $errors) ?? '1';
        if (req_has('batch_yield_unit')) { $u = (string) req_val('batch_yield_unit'); $f['batch_yield_unit_code'] = $u === '' || $u === 'portion' || $u === 'portions' ? null : $u; }
        $f['expected_loss_pct'] = ri_decimal('expected_loss_pct', '0', 'The expected loss', $errors, 0, false, 99.99) ?? '0';
        if (req_has('lines')) {
            $raw = (string) req_val('lines');
            $list = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($list)) { $errors['lines'] = 'lines is a JSON list of {ingredient or sub_recipe, qty, unit, line_yield_pct, group_name, optional, notes}.'; }
            else { $f['lines'] = []; foreach ($list as $n => $line) { $le = []; $f['lines'][] = line_from_array($pdo, is_array($line) ? $line : [], $le); foreach ($le as $k => $msg) { $errors['lines'] = 'Line ' . ($n + 1) . ': ' . $msg; break; } } }
        }
    } else {
        if (req_has('lines')) { $errors['lines'] = 'Lines are changed on the draft version (recipe_line_set).'; }
        if (req_has('status')) { $s = ri_choice('status', null, ['active', 'archived'], 'The status', $errors); if ($s !== null) { $f['active'] = $s === 'active'; } }
    }
    // the CHECKs of `recipes` carry no sentence of their own: said here in the schema's words
    $kind = $f['kind'] ?? $cur['kind'] ?? null;
    $out = array_key_exists('output_ingredient_id', $f) ? $f['output_ingredient_id'] : ($cur['output_ingredient_id'] ?? null);
    if ($kind === 'prep' && $out === null && !isset($errors['output_ingredient'])) { $errors['output_ingredient'] = 'A prep recipe names the ingredient it makes.'; }
    if ($kind !== null && $kind !== 'prep' && $out !== null && !isset($errors['output_ingredient'])) { $errors['output_ingredient'] = 'Only a prep recipe makes an ingredient; a ' . str_replace('_', ' ', $kind) . ' has no output.'; }
    return $f;
}
/** A line from the request (the add row, a row's Pattern C save, the agent's fields). */
function line_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    return line_from_array($pdo, $_POST, $errors, $cur);
}
function line_from_array(PDO $pdo, array $in, array &$errors, ?array $cur = null): array
{
    $f = [];
    $has = static fn (string $k): bool => array_key_exists($k, $in);
    $val = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
    if ($has('ingredient') || $has('sub_recipe') || $cur === null) {
        $ing = $has('ingredient') ? $val('ingredient') : '';
        $sub = $has('sub_recipe') ? $val('sub_recipe') : '';
        if ($ing !== '' && $sub !== '') { $errors['ingredient'] = 'A line names an ingredient or a recipe, not both.'; }
        elseif ($ing !== '') { $f['ingredient_id'] = ri_resolve_ingredient($pdo, $ing); $f['sub_recipe_id'] = null; if ($f['ingredient_id'] === null) { $errors['ingredient'] = 'Choose an ingredient from the list.'; } }
        elseif ($sub !== '') { $f['sub_recipe_id'] = ri_resolve_recipe($pdo, $sub); $f['ingredient_id'] = null; if ($f['sub_recipe_id'] === null) { $errors['sub_recipe'] = 'Choose a recipe from the list.'; } }
        elseif ($cur === null) { $errors['ingredient'] = 'Pick an ingredient or a recipe.'; }
    }
    if ($has('qty') || $cur === null) { $q = $val('qty'); if ($q === '' || !is_numeric($q) || (float) $q <= 0) { $errors['qty'] = 'The quantity is a number above zero.'; } else { $f['qty'] = $q; } }
    if ($has('unit') || $cur === null) { $u = $val('unit'); if ($u === '' || !preg_match('/^[a-z][a-z0-9_]{0,23}$/', $u)) { $errors['unit'] = 'Choose a unit.'; } else { $f['unit_code'] = $u; } }
    if ($has('line_yield_pct')) { $y = $val('line_yield_pct'); if ($y === '') { $f['line_yield_pct'] = 100; } elseif (!is_numeric($y) || (float) $y <= 0 || (float) $y > 100) { $errors['line_yield_pct'] = 'The line yield is a percentage above 0 up to 100.'; } else { $f['line_yield_pct'] = $y; } }
    if ($has('group_name')) { $g = $val('group_name'); if (mb_strlen($g) > 40) { $errors['group_name'] = 'The group is up to 40 characters.'; } else { $f['group_name'] = $g === '' ? null : $g; } }
    if ($has('optional')) { $f['optional'] = in_array(strtolower($val('optional')), ['1', 'true', 'on', 'yes'], true); }
    if ($has('notes')) { $n = $val('notes'); if (mb_strlen($n) > 200) { $errors['notes'] = 'The notes are up to 200 characters.'; } else { $f['notes'] = $n === '' ? null : $n; } }
    if ($has('seq')) { $s = $val('seq'); if ($s !== '' && ctype_digit($s)) { $f['seq'] = (int) $s; } }
    return $f;
}
/** The loggable fields of a recipe row (never the method's words — its length). */
function recipe_loggable(?array $r): array
{
    if ($r === null) { return []; }
    return ['name' => $r['name'], 'code' => $r['code'], 'kind' => $r['kind'], 'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'], 'output_ingredient_id' => $r['output_ingredient_id'] === null ? null : (int) $r['output_ingredient_id'],
            'portion_qty' => $r['portion_qty'], 'portion_unit_code' => $r['portion_unit_code'], 'method_length' => mb_strlen((string) ($r['method'] ?? '')), 'active' => (bool) $r['active']];
}
