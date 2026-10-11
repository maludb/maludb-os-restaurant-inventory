<?php
declare(strict_types=1);

/**
 * Recipes, the menu map and costing — the reads (docs/build-specs/recipes.md "Query functions", signatures fixed). Every read is an mcp_*
 * view or a db/008–db/018 function AS THE MEMBER; PHP never sums a cost, converts a unit, walks the tree or decides a version — the
 * what-if is the one named exception and it goes through ri_recipe_what_if() (db/018), so even there the database re-sums.
 */
require_once dirname(__DIR__, 2) . '/recipes_units.php';
if (is_file(__DIR__ . '/../catalog/queries.php')) { require_once __DIR__ . '/../catalog/queries.php'; }   // slice 1's shared reads: recipes_using(), record_notes(), record_attachments()
if (!function_exists('recipes_using')) {
    function recipes_using(PDO $pdo, int $ingredientId, int $limit = 50): array
    { $st = $pdo->prepare('SELECT * FROM ri_recipes_using(:i) LIMIT :l'); $st->bindValue('i', $ingredientId, PDO::PARAM_INT); $st->bindValue('l', $limit, PDO::PARAM_INT); $st->execute(); return $st->fetchAll(); }
    function record_notes(PDO $pdo, string $type, int $id): array
    { $st = $pdo->prepare('SELECT n.note_id, n.body, n.member_id, m.display_name, n.created_at FROM mcp_notes n LEFT JOIN mcp_members m ON m.member_id = n.member_id WHERE n.record_type = :t AND n.record_id = :id ORDER BY n.created_at DESC'); $st->execute(['t' => $type, 'id' => $id]); return $st->fetchAll(); }
    function record_attachments(PDO $pdo, string $type, int $id): array
    { $st = $pdo->prepare('SELECT * FROM mcp_attachments WHERE record_type = :t AND record_id = :id ORDER BY created_at DESC'); $st->execute(['t' => $type, 'id' => $id]); return $st->fetchAll(); }
}

const RECIPE_PAGE = 48;
const RECIPE_KINDS = ['menu_item' => 'Menu item', 'prep' => 'Prep', 'modifier' => 'Modifier'];
const RECIPE_KIND_COLOR = ['menu_item' => 'primary', 'prep' => 'info', 'modifier' => 'secondary'];
const MODIFIER_ACTIONS = ['add' => 'Add', 'remove' => 'Remove', 'swap' => 'Swap', 'none' => 'None'];
const MODIFIER_ACTION_COLOR = ['add' => 'success', 'remove' => 'danger', 'swap' => 'info', 'none' => 'secondary'];
const QUADRANT_LABEL = ['star' => '★ Star', 'plowhorse' => 'Plowhorse', 'puzzle' => 'Puzzle', 'dog' => 'Dog'];
const QUADRANT_COLOR = ['star' => 'success', 'plowhorse' => 'warning', 'puzzle' => 'info', 'dog' => 'danger'];
const QUADRANT_ADVICE = ['star' => 'Keep it, feature it, hold the price.', 'plowhorse' => 'Popular but thin: raise the price a little, cut the cost, or reposition.', 'puzzle' => 'Profitable but unsold: feature it, rename it, or lower the price.', 'dog' => 'Neither: remove it or re-engineer it.'];

function menu_categories(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT category_id, key, name, kind, sort_order, active FROM mcp_menu_categories' . ($activeOnly ? ' WHERE active' : '') . ' ORDER BY sort_order, name')->fetchAll();
}
function menu_category_counts(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT c.category_id, (SELECT count(*) FROM mcp_recipes r WHERE r.category_id = c.category_id AND r.active) AS recipes, (SELECT count(*) FROM mcp_menu_items m WHERE m.category_id = c.category_id AND m.active) AS menu_items FROM mcp_menu_categories c')->fetchAll() as $r) {
        $out[(int) $r['category_id']] = ['recipes' => (int) $r['recipes'], 'menu_items' => (int) $r['menu_items']];
    }
    return $out;
}

/** The recipes as cards: the version in force, the cached portion cost at the site (null when the caller may not see cost — the caller walls), the first mapped item's price. */
function find_recipes(PDO $pdo, int $scopeId, array $filters, int $limit = RECIPE_PAGE, int $offset = 0): array
{
    $where = [];
    $args = [];
    if (($filters['q'] ?? '') !== '') { $where[] = '(lower(r.name) LIKE :q OR lower(r.code) LIKE :q)'; $args['q'] = '%' . mb_strtolower(trim((string) $filters['q'])) . '%'; }
    if (($filters['kind'] ?? '') !== '' && isset(RECIPE_KINDS[$filters['kind']])) { $where[] = 'r.kind = :k'; $args['k'] = $filters['kind']; }
    if (($filters['category'] ?? '') !== '' && ctype_digit((string) $filters['category'])) { $where[] = 'r.category_id = :c'; $args['c'] = (int) $filters['category']; }
    $status = $filters['status'] ?? 'active';
    if ($status === 'active') { $where[] = 'r.active'; } elseif ($status === 'archived') { $where[] = 'NOT r.active'; }
    $sql = 'SELECT r.recipe_id, r.code, r.name, r.kind, r.category_id, mc.name AS category, r.output_ingredient_id, oi.name AS output_ingredient, r.portion_qty, r.portion_unit_code, r.allergens_rollup, r.active, r.archived_at, r.photo_attachment_id,
                   r.current_version_id, v.version_no, v.effective_from, v.effective_to,
                   (SELECT count(*) FROM mcp_recipe_versions d WHERE d.recipe_id = r.recipe_id AND d.status = \'draft\') AS drafts,
                   (SELECT count(*) FROM mcp_recipe_lines l WHERE l.version_id = r.current_version_id AND l.sub_recipe_id IS NOT NULL) AS sub_recipes,
                   (SELECT count(*) FROM mcp_recipe_lines l WHERE l.version_id = r.current_version_id) AS lines,
                   (SELECT m.menu_item_id FROM mcp_menu_items m WHERE m.recipe_id = r.recipe_id AND m.active ORDER BY m.menu_item_id LIMIT 1) AS first_menu_item_id,
                   count(*) OVER () AS total
              FROM mcp_recipes r LEFT JOIN mcp_menu_categories mc ON mc.category_id = r.category_id LEFT JOIN mcp_ingredients oi ON oi.ingredient_id = r.output_ingredient_id
              LEFT JOIN mcp_recipe_versions v ON v.recipe_version_id = r.current_version_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.kind = \'menu_item\' DESC, r.kind, mc.sort_order NULLS LAST, r.name LIMIT :l OFFSET :o';
    $st = $pdo->prepare($sql);
    foreach ($args as $k => $v) { $st->bindValue($k, $v); }
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->bindValue('o', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $sees = sees_cost($scopeId);
    foreach ($rows as &$r) {
        $r['allergens'] = pg_text_array((string) $r['allergens_rollup']);
        $r['portion_cost'] = null;
        $r['menu_price'] = null;
        $r['food_cost_pct'] = null;
        if ($r['current_version_id'] !== null && $sees) {
            $r['portion_cost'] = portion_cost_cached($pdo, (int) $r['current_version_id'], $scopeId, date('Y-m-d'));
            if ($r['first_menu_item_id'] !== null) {
                $r['menu_price'] = menu_price($pdo, (int) $r['first_menu_item_id'], $scopeId);
                if ($r['menu_price'] !== null && (float) $r['menu_price'] > 0 && $r['portion_cost'] !== null) { $r['food_cost_pct'] = round((float) $r['portion_cost'] / (float) $r['menu_price'] * 100, 1); }
            }
        }
    }
    unset($r);
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** The picker's rows: active recipes by name or code, a kind, a recipe left out. */
function recipes_for_pick(PDO $pdo, string $q, ?string $kind, ?int $exclude): array
{
    $sql = 'SELECT r.recipe_id, r.code, r.name, r.kind, mc.name AS category, v.version_no FROM mcp_recipes r LEFT JOIN mcp_menu_categories mc ON mc.category_id = r.category_id LEFT JOIN mcp_recipe_versions v ON v.recipe_version_id = r.current_version_id
            WHERE r.active' . ($q !== '' ? ' AND (lower(r.name) LIKE :q OR lower(r.code) LIKE :q)' : '') . ($kind !== null ? ' AND r.kind = :k' : '') . ($exclude !== null ? ' AND r.recipe_id <> :x' : '') . ' ORDER BY r.name LIMIT 500';
    $st = $pdo->prepare($sql);
    $args = [];
    if ($q !== '') { $args['q'] = '%' . mb_strtolower($q) . '%'; }
    if ($kind !== null) { $args['k'] = $kind; }
    if ($exclude !== null) { $args['x'] = $exclude; }
    $st->execute($args);
    return $st->fetchAll();
}

function find_recipe(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.*, mc.name AS category, oi.name AS output_ingredient, oi.base_unit_code AS output_base_unit FROM mcp_recipes r LEFT JOIN mcp_menu_categories mc ON mc.category_id = r.category_id LEFT JOIN mcp_ingredients oi ON oi.ingredient_id = r.output_ingredient_id WHERE r.recipe_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['allergens'] = pg_text_array((string) $r['allergens_rollup']);
    return $r;
}

function recipe_versions(PDO $pdo, int $recipeId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_recipe_versions WHERE recipe_id = :r ORDER BY version_no');
    $st->execute(['r' => $recipeId]);
    return $st->fetchAll();
}
function recipe_version(PDO $pdo, int $versionId): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_recipe_versions WHERE recipe_version_id = :id');
    $st->execute(['id' => $versionId]);
    $v = $st->fetch();
    return $v === false ? null : $v;
}
/** ri_recipe_version_on(): the version in force on a date (today by default), or null. */
function version_in_force(PDO $pdo, int $recipeId, ?string $on = null): ?int
{
    $st = $pdo->prepare('SELECT ri_recipe_version_on(:r, CAST(:d AS date))');
    $st->execute(['r' => $recipeId, 'd' => $on ?? date('Y-m-d')]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int) $v;
}

/** The lines of a version with the ingredient's or the sub-recipe's name and the quantity shown through format_qty(). */
function recipe_lines(PDO $pdo, int $versionId): array
{
    $st = $pdo->prepare('SELECT l.*, i.name AS ingredient_name, i.code AS ingredient_code, i.base_unit_code, i.recipe_unit_code, sr.name AS sub_recipe_name, sr.code AS sub_recipe_code, sr.kind AS sub_recipe_kind, sr.output_ingredient_id AS sub_output_ingredient_id, so.base_unit_code AS sub_base_unit
                           FROM mcp_recipe_lines l LEFT JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id LEFT JOIN mcp_recipes sr ON sr.recipe_id = l.sub_recipe_id LEFT JOIN mcp_ingredients so ON so.ingredient_id = sr.output_ingredient_id
                          WHERE l.version_id = :v ORDER BY l.seq, l.recipe_line_id');
    $st->execute(['v' => $versionId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$l) {
        $l['qty_display'] = ri_fmt($l['qty'], 4) . ' ' . $l['unit_code'];
        if ($l['ingredient_id'] !== null) { $l['base_display'] = ri_fmt($l['qty_base'], $l['base_unit_code'] === 'each' ? 0 : 2) . ' ' . $l['base_unit_code']; }
        elseif ($l['sub_recipe_kind'] === 'prep') { $l['base_display'] = ri_fmt($l['qty_base'], 2) . ' ' . $l['sub_base_unit']; }
        else { $l['base_display'] = ri_fmt($l['qty_base'], 2) . ' portion' . ((float) $l['qty_base'] === 1.0 ? '' : 's'); }
    }
    unset($l);
    return $rows;
}

/** The nested view through the sub-recipes' versions in force on $on (≤ 12 deep). Each node: the line + children[]. */
function recipe_tree(PDO $pdo, int $versionId, string $on, int $depth = 0): array
{
    $out = [];
    foreach (recipe_lines($pdo, $versionId) as $l) {
        $node = $l;
        $node['children'] = [];
        $node['sub_version_id'] = null;
        if ($l['sub_recipe_id'] !== null && $depth < 12) {
            $sv = version_in_force($pdo, (int) $l['sub_recipe_id'], $on);
            $node['sub_version_id'] = $sv;
            if ($sv !== null) { $node['children'] = recipe_tree($pdo, $sv, $on, $depth + 1); }
        }
        $out[] = $node;
    }
    return $out;
}
/** ri_explode(version, on, true): the leaf ingredients of a batch, in base units, with their names. */
function recipe_leaves(PDO $pdo, int $versionId, string $on): array
{
    $st = $pdo->prepare('SELECT e.ingredient_id, e.qty_base, i.name, i.code, i.base_unit_code, i.recipe_unit_code FROM ri_explode(:v, CAST(:d AS date), true) e JOIN mcp_ingredients i ON i.ingredient_id = e.ingredient_id ORDER BY i.name');
    $st->execute(['v' => $versionId, 'd' => $on]);
    return $st->fetchAll();
}

/** ri_recipe_cost()'s row — batch_cost, portion_cost, batch_yield_qty, lines (decoded), uncosted; NULL for a caller without sees_cost($scopeId). */
function recipe_cost(PDO $pdo, int $versionId, int $scopeId, ?string $asOf = null, ?string $basis = null): ?array
{
    if (!sees_cost($scopeId)) { return null; }
    $st = $pdo->prepare('SELECT batch_cost, portion_cost, batch_yield_qty, lines, uncosted FROM ri_recipe_cost(:v, :s, CAST(:t AS timestamptz), :b)');
    $st->execute(['v' => $versionId, 's' => $scopeId, 't' => $asOf ?? date('c'), 'b' => $basis]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['lines'] = json_decode((string) $r['lines'], true) ?: [];
    return $r;
}
function portion_cost_cached(PDO $pdo, int $versionId, int $scopeId, string $on): ?float
{
    $st = $pdo->prepare('SELECT ri_portion_cost_cached(:v, :s, CAST(:d AS date))');
    $st->execute(['v' => $versionId, 's' => $scopeId, 'd' => $on]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (float) $v;
}
/** The what-if through ri_recipe_what_if() (db/018): $overrides = [[ingredient, cost, unit]]; walled. */
function what_if_cost(PDO $pdo, int $versionId, int $scopeId, array $overrides): ?array
{
    if (!sees_cost($scopeId)) { return null; }
    $st = $pdo->prepare('SELECT batch_cost, portion_cost, batch_yield_qty, lines, uncosted, base_portion_cost FROM ri_recipe_what_if(:v, :s, CAST(:o AS jsonb))');
    $st->execute(['v' => $versionId, 's' => $scopeId, 'o' => json_encode(array_values($overrides))]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['lines'] = json_decode((string) $r['lines'], true) ?: [];
    return $r;
}
/** The cost per portion by week at the site, with the version in force each Monday; walled. */
function cost_history_weeks(PDO $pdo, int $recipeId, int $scopeId, int $weeks = 52): array
{
    if (!sees_cost($scopeId)) { return []; }
    $out = [];
    $monday = new DateTimeImmutable('monday this week');
    $firstItem = $pdo->prepare('SELECT m.menu_item_id FROM mcp_menu_items m WHERE m.recipe_id = :r AND m.active ORDER BY m.menu_item_id LIMIT 1');
    $firstItem->execute(['r' => $recipeId]);
    $mi = $firstItem->fetchColumn();
    for ($k = $weeks - 1; $k >= 0; $k--) {
        $d = $monday->modify("-{$k} weeks")->format('Y-m-d');
        $v = version_in_force($pdo, $recipeId, $d);
        $cost = $v === null ? null : portion_cost_cached($pdo, $v, $scopeId, $d);
        $price = $mi ? menu_price($pdo, (int) $mi, $scopeId, $d) : null;
        $vno = $v === null ? null : (int) (recipe_version($pdo, $v)['version_no'] ?? 0);
        $out[] = ['week' => $d, 'version_id' => $v, 'version_no' => $vno, 'portion_cost' => $cost, 'menu_price' => $price, 'food_cost_pct' => $cost !== null && $price !== null && (float) $price > 0 ? round($cost / (float) $price * 100, 1) : null];
    }
    return $out;
}
/** The parents a recipe is a sub-recipe of (the versions in force, drafts too). */
function used_in(PDO $pdo, int $recipeId): array
{
    $st = $pdo->prepare('SELECT DISTINCT r.recipe_id, r.name, r.code, r.kind, v.version_no, v.status FROM mcp_recipe_lines l JOIN mcp_recipe_versions v ON v.recipe_version_id = l.version_id JOIN mcp_recipes r ON r.recipe_id = v.recipe_id WHERE l.sub_recipe_id = :r AND v.status <> \'retired\' ORDER BY r.name, v.version_no');
    $st->execute(['r' => $recipeId]);
    return $st->fetchAll();
}
function recipe_allergens(PDO $pdo, int $recipeId): array
{
    $st = $pdo->prepare('SELECT allergens_rollup FROM mcp_recipes WHERE recipe_id = :r');
    $st->execute(['r' => $recipeId]);
    return pg_text_array((string) $st->fetchColumn());
}
/** The unit <select> for a line: an ingredient's units (ri_unit_options), a prep sub-recipe's output units, or `portion` alone. [{code, label}] */
function line_unit_options(PDO $pdo, ?int $ingredientId, ?int $subRecipeId): array
{
    if ($ingredientId !== null) { return array_map(static fn (array $u): array => ['code' => $u['code'], 'label' => $u['label']], ri_unit_options($pdo, $ingredientId)); }
    if ($subRecipeId !== null) {
        $r = find_recipe($pdo, $subRecipeId);
        if ($r !== null && $r['kind'] === 'prep' && $r['output_ingredient_id'] !== null) { return array_map(static fn (array $u): array => ['code' => $u['code'], 'label' => $u['label']], ri_unit_options($pdo, (int) $r['output_ingredient_id'])); }
        return [['code' => 'portion', 'label' => 'portion']];
    }
    return [];
}
/** "2 kg = 2 000 g" through ri_to_base(); ['ok', 'text', 'qty_base']. A prep sub-recipe converts through its output; a portion sub-recipe says "N portion(s)". */
function convert_sentence(PDO $pdo, ?int $ingredientId, ?int $subRecipeId, string $qty, string $unit): array
{
    if (!is_numeric($qty) || (float) $qty <= 0) { return ['ok' => false, 'text' => 'Type a quantity above zero.', 'qty_base' => null]; }
    $iid = $ingredientId;
    if ($iid === null && $subRecipeId !== null) {
        $r = find_recipe($pdo, $subRecipeId);
        if ($r === null) { return ['ok' => false, 'text' => 'That recipe is not here.', 'qty_base' => null]; }
        if ($r['kind'] !== 'prep') { return $unit === 'portion' ? ['ok' => true, 'text' => ri_fmt($qty, 4) . ' portion' . ((float) $qty === 1.0 ? '' : 's') . ' of ' . $r['name'], 'qty_base' => $qty] : ['ok' => false, 'text' => 'A menu-item or modifier sub-recipe is taken in portions.', 'qty_base' => null]; }
        $iid = (int) $r['output_ingredient_id'];
    }
    if ($iid === null) { return ['ok' => false, 'text' => 'Pick an ingredient or a recipe first.', 'qty_base' => null]; }
    try {
        $st = $pdo->prepare('SELECT ri_to_base(:i, :q, :u), (SELECT base_unit_code FROM mcp_ingredients WHERE ingredient_id = :i2)');
        $st->execute(['i' => $iid, 'q' => $qty, 'u' => $unit, 'i2' => $iid]);
        [$base, $bu] = $st->fetch(PDO::FETCH_NUM);
        return ['ok' => true, 'text' => ri_fmt($qty, 4) . ' ' . $unit . ' = ' . ri_fmt($base, $bu === 'each' ? 0 : 2) . ' ' . $bu, 'qty_base' => (string) $base];
    } catch (PDOException $e) {
        return ['ok' => false, 'text' => 'cannot: ' . db_message($e, 'that unit does not convert'), 'qty_base' => null];
    }
}
/** What activation from a date does, without writing: ['ok', 'text', 'ends_version_no']. */
function activation_preview(PDO $pdo, int $versionId, string $from): array
{
    $v = recipe_version($pdo, $versionId);
    if ($v === null) { return ['ok' => false, 'text' => 'That version is not here.', 'ends_version_no' => null]; }
    if ($v['status'] !== 'draft') { return ['ok' => false, 'text' => 'Version ' . $v['version_no'] . ' is ' . $v['status'] . '.', 'ends_version_no' => null]; }
    $lines = (int) one_value($pdo, 'SELECT count(*) FROM mcp_recipe_lines WHERE version_id = :v', ['v' => $versionId]);
    if ($lines === 0) { return ['ok' => false, 'text' => 'The draft has no lines yet.', 'ends_version_no' => null]; }
    $st = $pdo->prepare('SELECT version_no, effective_from FROM mcp_recipe_versions WHERE recipe_id = :r AND status = \'active\' AND effective_to IS NULL');
    $st->execute(['r' => $v['recipe_id']]);
    $open = $st->fetch();
    $later = one_value($pdo, 'SELECT count(*) FROM mcp_recipe_versions WHERE recipe_id = :r AND status = \'active\' AND effective_from >= CAST(:d AS date)', ['r' => $v['recipe_id'], 'd' => $from]);
    if ((int) $later > 0) { return ['ok' => false, 'text' => 'A version already in force from ' . format_date($from) . ' or later exists — activate from a later date.', 'ends_version_no' => null]; }
    if ($open) { return ['ok' => true, 'text' => 'v' . $open['version_no'] . ' ends ' . format_date($from) . '; v' . $v['version_no'] . ' is in force from ' . format_date($from) . '.', 'ends_version_no' => (int) $open['version_no']]; }
    return ['ok' => true, 'text' => 'v' . $v['version_no'] . ' is the first version, in force from ' . format_date($from) . '.', 'ends_version_no' => null];
}
/** null when deletable, else the reason. */
function recipe_deletable(PDO $pdo, int $id): ?string
{
    if ((int) one_value($pdo, 'SELECT count(*) FROM mcp_recipe_versions WHERE recipe_id = :r AND status <> \'draft\'', ['r' => $id]) > 0) { return 'A version of it was activated.'; }
    if ((int) one_value($pdo, 'SELECT count(*) FROM mcp_menu_items WHERE recipe_id = :r', ['r' => $id]) > 0) { return 'A menu item maps to it.'; }
    if ((int) one_value($pdo, 'SELECT count(*) FROM mcp_recipe_lines WHERE sub_recipe_id = :r', ['r' => $id]) > 0) { return 'A line of another recipe names it.'; }
    if ((int) one_value($pdo, 'SELECT count(*) FROM mcp_production_batches WHERE recipe_id = :r', ['r' => $id]) > 0) { return 'A prep batch was made from it.'; }
    return null;
}
/** Activated or mapped: its kind and output ingredient are locked. */
function recipe_locked(PDO $pdo, int $id): bool
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_recipe_versions WHERE recipe_id = :r AND status <> \'draft\'', ['r' => $id]) > 0
        || (int) one_value($pdo, 'SELECT count(*) FROM mcp_menu_items WHERE recipe_id = :r', ['r' => $id]) > 0;
}
/** Everything a recipe's page shows. */
function recipe_full(PDO $pdo, int $id, int $scopeId, ?int $versionId = null, ?string $asOf = null): ?array
{
    $r = find_recipe($pdo, $id);
    if ($r === null) { return null; }
    $versions = recipe_versions($pdo, $id);
    $on = $asOf ?? date('Y-m-d');
    $chosen = null;
    if ($versionId !== null) { foreach ($versions as $v) { if ((int) $v['recipe_version_id'] === $versionId) { $chosen = $v; } } }
    if ($chosen === null) { $inForce = version_in_force($pdo, $id, $on); foreach ($versions as $v) { if ($inForce !== null && (int) $v['recipe_version_id'] === $inForce) { $chosen = $v; } } }
    if ($chosen === null) { foreach ($versions as $v) { if ($v['status'] === 'draft') { $chosen = $v; } } }
    if ($chosen === null && $versions !== []) { $chosen = end($versions); }
    $lines = $chosen ? recipe_lines($pdo, (int) $chosen['recipe_version_id']) : [];
    $cost = $chosen ? recipe_cost($pdo, (int) $chosen['recipe_version_id'], $scopeId, $on . 'T23:59:59') : null;
    if ($cost !== null) {
        $byLine = [];
        foreach ($cost['lines'] as $cl) { $byLine[(int) $cl['line_id']] = $cl; }
        foreach ($lines as &$l) {
            $cl = $byLine[(int) $l['recipe_line_id']] ?? null;
            $l['line_cost'] = $cl['line_cost'] ?? null;
            $l['cost_per_base'] = $cl['cost_per_base'] ?? null;
            $l['share_pct'] = $cl !== null && (float) $cost['batch_cost'] > 0 ? round((float) $cl['line_cost'] / (float) $cost['batch_cost'] * 100, 1) : null;
        }
        unset($l);
    }
    $mi = $pdo->prepare('SELECT m.*, s.name AS source_name FROM mcp_menu_items m LEFT JOIN mcp_sources s ON s.source_id = m.pos_source_id WHERE m.recipe_id = :r ORDER BY m.plu');
    $mi->execute(['r' => $id]);
    $menuItems = $mi->fetchAll();
    foreach ($menuItems as &$m) {
        $m['price'] = menu_price($pdo, (int) $m['menu_item_id'], $scopeId);
        $m['plate_cost'] = $cost !== null ? round((float) $cost['portion_cost'] * (float) $m['portions'], 4) : null;
        $m['food_cost_pct'] = $m['plate_cost'] !== null && $m['price'] !== null && (float) $m['price'] > 0 ? round($m['plate_cost'] / (float) $m['price'] * 100, 1) : null;
    }
    unset($m);
    return ['recipe' => $r, 'versions' => $versions, 'version' => $chosen, 'lines' => $lines, 'cost' => $cost, 'as_of' => $on,
            'tree' => $chosen ? recipe_tree($pdo, (int) $chosen['recipe_version_id'], $on) : [], 'leaves' => $chosen ? recipe_leaves($pdo, (int) $chosen['recipe_version_id'], $on) : [],
            'menu_items' => $menuItems, 'used_in' => used_in($pdo, $id), 'notes' => record_notes($pdo, 'recipe', $id), 'attachments' => record_attachments($pdo, 'recipe', $id)];
}
function recipe_activity(PDO $pdo, string $type, int $id, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_activity_log WHERE (entity_type = :t AND entity_id = :id) OR recipe_id = :id2 ORDER BY occurred_at DESC LIMIT :l');
    $st->bindValue('t', $type); $st->bindValue('id', $id, PDO::PARAM_INT); $st->bindValue('id2', $id, PDO::PARAM_INT); $st->bindValue('l', $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}

// ---- menu items ----
function menu_price(PDO $pdo, int $menuItemId, int $scopeId, ?string $on = null): ?string
{
    $st = $pdo->prepare('SELECT ri_menu_price(:m, :s, CAST(:d AS date))');
    $st->execute(['m' => $menuItemId, 's' => $scopeId, 'd' => $on ?? date('Y-m-d')]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string) $v;
}
/** The menu items of this site's sources + the typed ones; the unmapped queue flagged. */
function find_menu_items(PDO $pdo, int $scopeId, array $filters, int $limit = 100, int $offset = 0): array
{
    $where = ['(m.pos_source_id IS NULL OR s.scope_id = :s)'];
    $args = ['s' => $scopeId];
    if (($filters['q'] ?? '') !== '') { $where[] = '(lower(m.name) LIKE :q OR lower(m.plu) LIKE :q)'; $args['q'] = '%' . mb_strtolower(trim((string) $filters['q'])) . '%'; }
    if (!empty($filters['source'])) { $where[] = 'm.pos_source_id = :src'; $args['src'] = (int) $filters['source']; }
    if (!empty($filters['category'])) { $where[] = 'm.category_id = :c'; $args['c'] = (int) $filters['category']; }
    if (!empty($filters['unmapped'])) { $where[] = 'm.recipe_id IS NULL AND NOT m.no_depletion'; }
    if (empty($filters['archived'])) { $where[] = 'm.active'; }
    $st = $pdo->prepare('SELECT m.*, s.name AS source_name, r.name AS recipe_name, r.code AS recipe_code, mc.name AS category, count(*) OVER () AS total
                           FROM mcp_menu_items m LEFT JOIN mcp_sources s ON s.source_id = m.pos_source_id LEFT JOIN mcp_recipes r ON r.recipe_id = m.recipe_id LEFT JOIN mcp_menu_categories mc ON mc.category_id = m.category_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY (m.recipe_id IS NULL AND NOT m.no_depletion) DESC, m.plu LIMIT :l OFFSET :o');
    foreach ($args as $k => $v) { $st->bindValue($k, $v); }
    $st->bindValue('l', $limit, PDO::PARAM_INT); $st->bindValue('o', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$m) { $m['price'] = menu_price($pdo, (int) $m['menu_item_id'], $scopeId); }
    unset($m);
    return $rows;
}
function find_menu_item(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT m.*, s.name AS source_name, s.scope_id AS source_scope_id, r.name AS recipe_name, r.code AS recipe_code, mc.name AS category, mb.display_name AS mapped_by_name FROM mcp_menu_items m LEFT JOIN mcp_sources s ON s.source_id = m.pos_source_id LEFT JOIN mcp_recipes r ON r.recipe_id = m.recipe_id LEFT JOIN mcp_menu_categories mc ON mc.category_id = m.category_id LEFT JOIN mcp_members mb ON mb.member_id = m.mapped_by WHERE m.menu_item_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
function menu_item_full(PDO $pdo, int $id, int $scopeId): ?array
{
    $m = find_menu_item($pdo, $id);
    if ($m === null) { return null; }
    $m['price'] = menu_price($pdo, $id, $scopeId);
    $prices = $pdo->prepare('SELECT p.*, s.name AS site FROM mcp_menu_item_prices p JOIN mcp_sites s ON s.scope_id = p.scope_id WHERE p.menu_item_id = :m ORDER BY p.effective_from DESC');
    $prices->execute(['m' => $id]);
    $mods = $pdo->prepare('SELECT md.*, mm.qty_factor AS item_factor, mm.recipe_id AS item_recipe_id, r.name AS recipe_name, r2.name AS item_recipe_name FROM mcp_modifiers md LEFT JOIN mcp_menu_item_modifiers mm ON mm.modifier_id = md.modifier_id AND mm.menu_item_id = :m LEFT JOIN mcp_recipes r ON r.recipe_id = md.recipe_id LEFT JOIN mcp_recipes r2 ON r2.recipe_id = mm.recipe_id
                           WHERE md.active AND (mm.menu_item_id IS NOT NULL OR (md.pos_source_id = :src AND md.modifier_id IN (SELECT md2.modifier_id FROM mcp_modifiers md2 JOIN mcp_pos_sale_lines l ON l.menu_item_id = :m2 JOIN mcp_modifiers md3 ON md3.pos_source_id = md2.pos_source_id, jsonb_array_elements(l.modifiers) e WHERE md2.code = e->>\'code\'))) ORDER BY md.code');
    $mods->execute(['m' => $id, 'src' => $m['pos_source_id'], 'm2' => $id]);
    $sales = $pdo->prepare('SELECT date_trunc(\'week\', business_date)::date AS week, sum(qty) AS qty, sum(net) AS net FROM mcp_sales_daily WHERE menu_item_id = :m AND scope_id = :s AND day_part_id IS NULL AND business_date >= current_date - 84 GROUP BY 1 ORDER BY 1 DESC');
    $sales->execute(['m' => $id, 's' => $scopeId]);
    $cost = null;
    if ($m['recipe_id'] !== null && sees_cost($scopeId)) {
        $v = version_in_force($pdo, (int) $m['recipe_id']);
        if ($v !== null) {
            $pc = portion_cost_cached($pdo, $v, $scopeId, date('Y-m-d'));
            $plate = $pc === null ? null : round($pc * (float) $m['portions'], 4);
            $cost = ['version_id' => $v, 'portion_cost' => $pc, 'plate_cost' => $plate, 'food_cost_pct' => $plate !== null && $m['price'] !== null && (float) $m['price'] > 0 ? round($plate / (float) $m['price'] * 100, 1) : null, 'lines' => recipe_lines($pdo, $v)];
        }
    }
    return ['menu_item' => $m, 'prices' => $prices->fetchAll(), 'modifiers' => $mods->fetchAll(), 'sales_by_week' => $sales->fetchAll(), 'cost' => $cost];
}
function unmapped_plus(PDO $pdo, int $scopeId, int $days = 28): array
{
    $st = $pdo->prepare('SELECT * FROM ri_unmapped_plus(:s, :d)');
    $st->execute(['s' => $scopeId, 'd' => $days]);
    return $st->fetchAll();
}
function find_modifiers(PDO $pdo, int $scopeId, array $filters = []): array
{
    $where = ['(md.pos_source_id IS NULL OR s.scope_id = :s)', 'md.active'];
    $args = ['s' => $scopeId];
    if (!empty($filters['source'])) { $where[] = 'md.pos_source_id = :src'; $args['src'] = (int) $filters['source']; }
    if (!empty($filters['unmapped'])) { $where[] = '(md.recipe_id IS NULL)'; }
    $st = $pdo->prepare('SELECT md.*, s.name AS source_name, r.name AS recipe_name, r.kind AS recipe_kind, (SELECT count(*) FROM mcp_menu_item_modifiers mm WHERE mm.modifier_id = md.modifier_id) AS per_item
                           FROM mcp_modifiers md LEFT JOIN mcp_sources s ON s.source_id = md.pos_source_id LEFT JOIN mcp_recipes r ON r.recipe_id = md.recipe_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (md.recipe_id IS NULL) DESC, md.code');
    $st->execute($args);
    return $st->fetchAll();
}
function find_modifier(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT md.*, s.name AS source_name, s.scope_id AS source_scope_id, r.name AS recipe_name FROM mcp_modifiers md LEFT JOIN mcp_sources s ON s.source_id = md.pos_source_id LEFT JOIN mcp_recipes r ON r.recipe_id = md.recipe_id WHERE md.modifier_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
/** The menu items a modifier was seen on (pos_sale_lines, slice 5) and the per-item overrides. */
function modifier_parents(PDO $pdo, int $modifierId): array
{
    $st = $pdo->prepare('SELECT m.menu_item_id, m.plu, m.name, mm.qty_factor, mm.recipe_id, r.name AS recipe_name,
                                (SELECT count(*) FROM mcp_pos_sale_lines l, jsonb_array_elements(l.modifiers) e WHERE l.menu_item_id = m.menu_item_id AND e->>\'code\' = md.code) AS seen
                           FROM mcp_modifiers md JOIN mcp_menu_items m ON m.pos_source_id = md.pos_source_id LEFT JOIN mcp_menu_item_modifiers mm ON mm.menu_item_id = m.menu_item_id AND mm.modifier_id = md.modifier_id LEFT JOIN mcp_recipes r ON r.recipe_id = mm.recipe_id
                          WHERE md.modifier_id = :id AND (mm.menu_item_id IS NOT NULL OR EXISTS (SELECT 1 FROM mcp_pos_sale_lines l, jsonb_array_elements(l.modifiers) e WHERE l.menu_item_id = m.menu_item_id AND e->>\'code\' = md.code)) ORDER BY m.plu');
    $st->execute(['id' => $modifierId]);
    return $st->fetchAll();
}
/** ri_menu_engineering() grouped by category with the category's cm_avg and mix_threshold; walled (empty for a caller without cost). */
function menu_engineering(PDO $pdo, int $scopeId, string $from, string $to, ?int $categoryId = null, ?string $basis = null): array
{
    if (!sees_cost($scopeId)) { return []; }
    $st = $pdo->prepare('SELECT e.*, m.plu, m.name, mc.name AS category_name, mc.sort_order FROM ri_menu_engineering(:s, CAST(:f AS date), CAST(:t AS date), :b) e JOIN mcp_menu_items m ON m.menu_item_id = e.menu_item_id LEFT JOIN mcp_menu_categories mc ON mc.category_id = e.category_id' . ($categoryId !== null ? ' WHERE e.category_id = :c' : '') . ' ORDER BY mc.sort_order NULLS LAST, e.cm_total DESC');
    $args = ['s' => $scopeId, 'f' => $from, 't' => $to, 'b' => $basis];
    if ($categoryId !== null) { $args['c'] = $categoryId; }
    $st->execute($args);
    $groups = [];
    foreach ($st->fetchAll() as $r) {
        $k = (int) ($r['category_id'] ?? 0);
        $groups[$k] ??= ['category_id' => $k ?: null, 'name' => $r['category_name'] ?? 'Uncategorised', 'cm_avg' => $r['cm_avg'], 'mix_threshold' => $r['mix_threshold'], 'rows' => []];
        $groups[$k]['rows'][] = $r;
    }
    return array_values($groups);
}
