<?php
declare(strict_types=1);

/**
 * The usage reads (periods.md "Query functions"; signatures fixed — Phase 4's tools `usage_report`, `usage_by_category`, `variance_top` and
 * `food_cost_pct` call these). ri_usage() is the referee: it answers every bucket in base units and at cost, with the identity in the function —
 * actual = opening + purchases + transfers in − transfers out + production in − production out + adjustments − closing — and PHP re-derives nothing.
 * What PHP adds is the display quantity in the ingredient's count unit (ri_from_base(), in the same statement), the category subtotals and the
 * wall: the functions answer cost unnulled (db/018), so every value column is nulled here unless the caller sees cost at the site.
 */
require_once dirname(__DIR__) . '/periods/queries.php';

const USAGE_MAX_DAYS = 400;
const USAGE_BUCKETS = ['opening', 'purchases', 'transfers_in', 'transfers_out', 'production_in', 'production_out', 'adjustments', 'closing', 'actual', 'theoretical', 'variance', 'waste', 'comps', 'unexplained'];
const USAGE_VALUE_COLS = ['opening_value', 'purchases_value', 'closing_value', 'actual_value', 'theoretical_value', 'variance_value', 'waste_value', 'unexplained_value'];
const USAGE_SORTS = ['unexplained' => 'Unexplained', 'variance' => 'Variance', 'actual' => 'Actual', 'name' => 'Name'];
const USAGE_BASES = ['avg' => 'Average', 'last' => 'Last price'];
const USAGE_PICKS = ['this' => 'This period', 'last' => 'Last period', '4weeks' => 'Last 4 weeks', 'month' => 'Last month', 'custom' => 'Custom'];

/** The cost basis the site's setting names (avg or last). */
function usage_default_basis(PDO $pdo): string
{
    $b = one_value($pdo, 'SELECT cost_basis FROM ri_settings WHERE id = 1');
    return $b === 'last' ? 'last' : 'avg';
}

/**
 * The window a request asks for at a site: {from, to, pick, error}. Two dates (`from`, `to`) are a custom window; else a quick pick —
 * this period (the one holding today; the last seven days when the site has none), last period, last 4 weeks (today − 27 .. today), last month
 * (the calendar month before this one). `error` holds a sentence when the window is not one (a date that is not, to before from, over 400 days).
 */
function window_from_request(PDO $pdo, int $scopeId, ?array $in = null): array
{
    $in ??= ['from' => request_string('from'), 'to' => request_string('to'), 'pick' => request_string('pick')];
    $today = site_today($scopeId);
    $day = static fn (string $d): ?DateTimeImmutable => ($x = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) !== false && $x->format('Y-m-d') === $d ? $x : null;
    $fromIn = (string) ($in['from'] ?? '');
    $toIn = (string) ($in['to'] ?? '');
    $pick = (string) ($in['pick'] ?? '');
    if ($fromIn !== '' || $toIn !== '') {
        $f = $day($fromIn);
        $t = $day($toIn);
        if ($f === null || $t === null) { return ['from' => $fromIn, 'to' => $toIn, 'pick' => 'custom', 'error' => 'The window is two dates (YYYY-MM-DD).']; }
        return usage_window_checked($f->format('Y-m-d'), $t->format('Y-m-d'), 'custom');
    }
    $now = new DateTimeImmutable($today);
    $current = period_on($pdo, $scopeId, $today);
    switch ($pick) {
        case 'last':
            $row = $current === null ? null : one_row($pdo, 'SELECT starts_on, ends_on FROM mcp_periods WHERE scope_id = :s AND calendar_id = :c AND ends_on < :d ORDER BY ends_on DESC LIMIT 1', ['s' => $scopeId, 'c' => $current['calendar_id'], 'd' => $current['starts_on']]);
            if ($row === null) { $row = one_row($pdo, 'SELECT starts_on, ends_on FROM mcp_periods WHERE scope_id = :s AND ends_on < :d ORDER BY ends_on DESC LIMIT 1', ['s' => $scopeId, 'd' => $today]); }
            if ($row === null) { return ['from' => $now->modify('-13 days')->format('Y-m-d'), 'to' => $now->modify('-7 days')->format('Y-m-d'), 'pick' => 'last', 'error' => 'There is no earlier period to show — make the year\'s periods first.']; }
            return usage_window_checked($row['starts_on'], $row['ends_on'], 'last');
        case '4weeks':
            return usage_window_checked($now->modify('-27 days')->format('Y-m-d'), $today, '4weeks');
        case 'month':
            return usage_window_checked($now->modify('first day of last month')->format('Y-m-d'), $now->modify('last day of last month')->format('Y-m-d'), 'month');
        default:
            if ($current !== null) { return usage_window_checked($current['starts_on'], $current['ends_on'], 'this'); }
            return usage_window_checked($now->modify('-6 days')->format('Y-m-d'), $today, 'this');
    }
}

function usage_window_checked(string $from, string $to, string $pick): array
{
    $w = ['from' => $from, 'to' => $to, 'pick' => $pick, 'error' => null];
    $days = (int) ((new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days) + 1;
    if ($to < $from) { $w['error'] = 'The window ends before it starts.'; }
    elseif ($days > USAGE_MAX_DAYS) { $w['error'] = 'A window is up to ' . USAGE_MAX_DAYS . ' days (this one is ' . $days . ') — narrow it.'; }
    return $w;
}

/** A one-row read, or null. */
function one_row(PDO $pdo, string $sql, array $args = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The sum of money strings in whole cents ("12.30" + "0.05" → "12.35"); null values are skipped. */
function usage_sum(array $vals): string
{
    $c = 0;
    foreach ($vals as $v) { if ($v !== null && $v !== '') { $c += (int) round((float) $v * 100); } }
    return number_format($c / 100, 2, '.', '');
}

function usage_row_decode(array $r, bool $sees): array
{
    $r = stock_ints($r, ['ingredient_id', 'category_id']);
    if (!$sees) {
        foreach (USAGE_VALUE_COLS as $k) { $r[$k] = null; }
        $r['unit_cost'] = null;
        $r['unit_cost_count'] = null;
    }
    return $r;
}

/**
 * Actual vs theoretical for a window at a site: ['rows', 'categories' (subtotals, in order), 'totals', 'from', 'to', 'basis', 'withheld'].
 * $filters: category (a key), ingredient (an id), basis (avg|last; the setting's by default), sort (unexplained|variance|actual|name; unexplained by default,
 * by |value| — or by |units| for a caller who sees no cost). Each row carries `*_base` (the function's) and `*_count` (in `count_unit`, ri_from_base()).
 */
function usage(PDO $pdo, int $scopeId, string $from, string $to, array $filters = []): array
{
    $sees = sees_cost($scopeId);
    $basis = in_array($filters['basis'] ?? '', array_keys(USAGE_BASES), true) ? $filters['basis'] : usage_default_basis($pdo);
    $sort = isset(USAGE_SORTS[$filters['sort'] ?? '']) ? $filters['sort'] : 'unexplained';
    $conv = implode(', ', array_map(static fn (string $b): string => "ri_from_base(u.ingredient_id, u.{$b}_base, du.unit) AS {$b}_count", USAGE_BUCKETS));
    $where = ['true'];
    $args = ['s' => $scopeId, 'f' => $from, 't' => $to, 'b' => $basis, 'ls' => $scopeId, 'lf' => $from, 'lt' => $to, 'pb' => $basis, 'ps' => $scopeId, 'ps2' => $scopeId, 'pt' => $to];
    if (($filters['category'] ?? '') !== '') { $where[] = 'c.key = :cat'; $args['cat'] = (string) $filters['category']; }
    if (($filters['ingredient'] ?? '') !== '' && ctype_digit((string) $filters['ingredient'])) { $where[] = 'u.ingredient_id = :ing'; $args['ing'] = (int) $filters['ingredient']; }
    $st = $pdo->prepare("SELECT u.*, i.code, i.name, i.base_unit_code, c.key AS category_key, c.name AS category, c.sort_order, du.unit AS count_unit, CASE WHEN :pb = 'last' AND lp.per_unit_code = du.unit AND lp.per_unit_price IS NOT NULL THEN lp.per_unit_price ELSE u.unit_cost * ri_to_base(u.ingredient_id, 1, du.unit) END AS unit_cost_count, {$conv},
          EXISTS (SELECT 1 FROM mcp_inventory_transactions lt WHERE lt.scope_id = :ls AND lt.ingredient_id = u.ingredient_id AND lt.business_date BETWEEN :lf AND :lt AND lt.note LIKE '%late:%') AS has_late
          FROM ri_usage(:s, :f::date, :t::date, :b) u
          JOIN mcp_ingredients i ON i.ingredient_id = u.ingredient_id JOIN mcp_ingredient_categories c ON c.category_id = u.category_id
          LEFT JOIN LATERAL (SELECT h.per_unit_code, h.per_unit_price FROM mcp_price_history h WHERE h.ingredient_id = u.ingredient_id AND h.scope_id = :ps AND h.changed_at <= ri_site_moment(:ps2, :pt::date, true)
                              ORDER BY h.changed_at DESC, h.price_history_id DESC LIMIT 1) lp ON true
          CROSS JOIN LATERAL (SELECT COALESCE((SELECT iu.unit_code FROM mcp_ingredient_units iu WHERE iu.ingredient_id = u.ingredient_id AND iu.is_default_count AND iu.active LIMIT 1), i.base_unit_code) AS unit) du
         WHERE " . implode(' AND ', $where));
    $st->execute($args);
    $rows = array_map(static fn (array $r): array => usage_row_decode($r, $sees), $st->fetchAll());
    $key = static fn (array $r): float => match (true) {
        $sort === 'name' => 0.0,
        $sort === 'variance' => abs((float) ($sees ? $r['variance_value'] : $r['variance_base'])),
        $sort === 'actual' => abs((float) ($sees ? $r['actual_value'] : $r['actual_base'])),
        default => abs((float) ($sees ? $r['unexplained_value'] : $r['unexplained_base'])),
    };
    usort($rows, static fn (array $a, array $b): int => $sort === 'name' ? strcasecmp($a['name'], $b['name']) : ($key($b) <=> $key($a) ?: strcasecmp($a['name'], $b['name'])));
    $cats = [];
    foreach ($rows as $r) {
        $k = $r['category_key'];
        $cats[$k] ??= ['category_key' => $k, 'category' => $r['category'], 'category_id' => $r['category_id'], 'sort_order' => (int) $r['sort_order'], 'ingredients' => 0, 'rows' => []];
        $cats[$k]['ingredients']++;
        $cats[$k]['rows'][] = $r;
    }
    uasort($cats, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'] ?: strcasecmp($a['category'], $b['category']));
    foreach ($cats as &$c) {
        foreach (USAGE_VALUE_COLS as $v) { $c[$v] = $sees ? usage_sum(array_column($c['rows'], $v)) : null; }
        unset($c['rows']);
    }
    unset($c);
    $totals = ['ingredients' => count($rows)];
    foreach (USAGE_VALUE_COLS as $v) { $totals[$v] = $sees ? usage_sum(array_column($rows, $v)) : null; }
    return ['rows' => $rows, 'categories' => array_values($cats), 'totals' => $totals, 'from' => $from, 'to' => $to, 'basis' => $basis, 'sort' => $sort, 'withheld' => !$sees];
}

/** The same by category — ri_usage_by_category(), with the category's name; the values null for a caller who sees no cost. */
function usage_by_category(PDO $pdo, int $scopeId, string $from, string $to, ?string $basis = null): array
{
    $sees = sees_cost($scopeId);
    $basis = in_array($basis, array_keys(USAGE_BASES), true) ? $basis : usage_default_basis($pdo);
    $st = $pdo->prepare('SELECT u.*, c.key, c.name, c.kind FROM ri_usage_by_category(:s, :f::date, :t::date, :b) u JOIN mcp_ingredient_categories c ON c.category_id = u.category_id ORDER BY c.sort_order, c.name');
    $st->execute(['s' => $scopeId, 'f' => $from, 't' => $to, 'b' => $basis]);
    return array_map(static function (array $r) use ($sees): array {
        $r = stock_ints($r, ['category_id', 'ingredients']);
        if (!$sees) { foreach (USAGE_VALUE_COLS as $k) { $r[$k] = null; } }
        return $r;
    }, $st->fetchAll());
}

/** The ingredients with the largest |unexplained| at cost in the window (ri_variance_top()); a caller who sees no cost gets the largest by units. */
function variance_top(PDO $pdo, int $scopeId, string $from, string $to, int $limit = 10): array
{
    $u = usage($pdo, $scopeId, $from, $to, ['sort' => 'unexplained']);
    return array_slice($u['rows'], 0, max(1, $limit));
}

/** {food_sales, cogs_actual, pct_actual, cogs_theoretical, pct_theoretical, waste_value} for a window — all null for a caller who sees no cost. */
function food_cost_pct(PDO $pdo, int $scopeId, string $from, string $to): array
{
    if (!sees_cost($scopeId)) { return ['food_sales' => null, 'cogs_actual' => null, 'pct_actual' => null, 'cogs_theoretical' => null, 'pct_theoretical' => null, 'waste_value' => null]; }
    $u = usage($pdo, $scopeId, $from, $to);
    $sales = number_format((float) one_value($pdo, 'SELECT ri_food_sales(:s, :f::date, :t::date, NULL)', ['s' => $scopeId, 'f' => $from, 't' => $to]), 2, '.', '');
    return ['food_sales' => $sales, 'cogs_actual' => $u['totals']['actual_value'], 'pct_actual' => period_pct($u['totals']['actual_value'], $sales),
        'cogs_theoretical' => $u['totals']['theoretical_value'], 'pct_theoretical' => period_pct($u['totals']['theoretical_value'], $sales), 'waste_value' => $u['totals']['waste_value']];
}

// ------------------------------------------------------------------------------------------------ one ingredient's window
/**
 * One ingredient's window: its bucket row (null when nothing moved), the recipes that consumed it, the waste logged, the count lines of approved counts
 * inside it, its cost history and its movements (the ledger's, first page). {row, consumers, waste, count_lines, cost_history, movements, ingredient}.
 */
function usage_ingredient(PDO $pdo, int $scopeId, int $ingredientId, string $from, string $to, ?string $basis = null): ?array
{
    $ing = find_ingredient($pdo, $ingredientId);
    if ($ing === null) { return null; }
    $u = usage($pdo, $scopeId, $from, $to, ['ingredient' => (string) $ingredientId, 'basis' => $basis]);
    $sees = sees_cost($scopeId);
    $st = $pdo->prepare('SELECT w.waste_log_id, w.number, w.business_date, w.logged_at, w.qty_base, w.cost, r.name AS reason, w.stage FROM mcp_waste_logs w JOIN mcp_reason_codes r ON r.reason_code_id = w.reason_code_id
                          WHERE w.scope_id = :s AND w.ingredient_id = :i AND w.business_date BETWEEN :f AND :t ORDER BY w.logged_at DESC LIMIT 100');
    $st->execute(['s' => $scopeId, 'i' => $ingredientId, 'f' => $from, 't' => $to]);
    $waste = array_map(static fn (array $w): array => stock_ints($w, ['waste_log_id']) + ['cost_walled' => !$sees], $st->fetchAll());
    $st = $pdo->prepare("SELECT l.count_line_id, l.count_id, c.number, c.as_of, l.state, l.system_qty_base, l.counted_qty_base, l.recount_qty_base, l.variance_base, l.variance_value
                          FROM mcp_inventory_count_lines l JOIN mcp_inventory_counts c ON c.count_id = l.count_id
                         WHERE c.scope_id = :s AND l.ingredient_id = :i AND c.status = 'approved' AND c.as_of > ri_site_moment(:s2, :f::date, false) AND c.as_of <= ri_site_moment(:s3, :t::date, true) AND l.state IN ('counted', 'zero')
                         ORDER BY c.as_of DESC, l.count_line_id");
    $st->execute(['s' => $scopeId, 'i' => $ingredientId, 's2' => $scopeId, 's3' => $scopeId, 'f' => $from, 't' => $to]);
    $lines = array_map(static fn (array $l): array => stock_ints($l, ['count_line_id', 'count_id']), $st->fetchAll());
    $hist = array_values(array_filter(cost_history($pdo, $ingredientId, $scopeId, 200), static fn (array $h): bool => substr((string) $h['changed_at'], 0, 10) >= $from && substr((string) $h['changed_at'], 0, 10) <= $to));
    return ['ingredient' => $ing, 'row' => $u['rows'][0] ?? null, 'consumers' => consumers($pdo, $scopeId, $ingredientId, $from, $to), 'waste' => $waste, 'count_lines' => $lines,
        'cost_history' => $sees ? $hist : [], 'movements' => stock_movements($pdo, $scopeId, ['ingredient' => (string) $ingredientId, 'from' => $from, 'to' => $to], 1), 'withheld' => !$sees];
}

/**
 * The recipes that consumed an ingredient in the window and how much each took, in base units, with its share of the total: the menu items sold
 * (mcp_sales_daily × the version in force that day × ri_explode — the way ri_usage() reads comps) and the prep batches that took it as an input
 * (the ledger's production_out rows, reversals netted). [{recipe_id, recipe, code, kind, qty_base, share_pct}] largest first; shares add to 100.
 */
function consumers(PDO $pdo, int $scopeId, int $ingredientId, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT x.recipe_id, r.name AS recipe, r.code, r.kind, x.qty FROM (
            SELECT m.recipe_id, sum(e.qty_base * sd.qty / v.batch_yield_qty) AS qty
              FROM mcp_sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id JOIN recipe_versions v ON v.id = ri_recipe_version_on(m.recipe_id, sd.business_date),
                   LATERAL ri_explode(v.id, sd.business_date, false) e
             WHERE sd.scope_id = :s AND sd.day_part_id IS NULL AND sd.business_date BETWEEN :f AND :t AND e.ingredient_id = :i GROUP BY m.recipe_id
            UNION ALL
            SELECT b.recipe_id, -sum(t.qty_base) FROM inventory_transactions t LEFT JOIN inventory_transactions o ON o.id = t.reverses_id
              JOIN production_batches b ON b.id = CASE WHEN t.txn_type = 'reversal' THEN o.reference_id ELSE t.reference_id END
             WHERE t.scope_id = :s2 AND t.ingredient_id = :i2 AND t.occurred_at > ri_site_moment(:s3, :f2::date, false) AND t.occurred_at <= ri_site_moment(:s4, :t2::date, true)
               AND (t.txn_type = 'production_out' OR (t.txn_type = 'reversal' AND o.txn_type = 'production_out')) GROUP BY b.recipe_id) x
          JOIN recipes r ON r.id = x.recipe_id ORDER BY x.qty DESC, r.name");
    $st->execute(['s' => $scopeId, 'f' => $from, 't' => $to, 'i' => $ingredientId, 's2' => $scopeId, 'i2' => $ingredientId, 's3' => $scopeId, 'f2' => $from, 's4' => $scopeId, 't2' => $to]);
    $rows = array_values(array_filter($st->fetchAll(), static fn (array $r): bool => (float) $r['qty'] != 0.0));
    $merged = [];
    foreach ($rows as $r) {
        $id = (int) $r['recipe_id'];
        $merged[$id] ??= ['recipe_id' => $id, 'recipe' => $r['recipe'], 'code' => $r['code'], 'kind' => $r['kind'], 'qty_base' => 0.0];
        $merged[$id]['qty_base'] += (float) $r['qty'];
    }
    $total = array_sum(array_column($merged, 'qty_base'));
    $out = [];
    foreach ($merged as $m) {
        $m['share_pct'] = $total == 0.0 ? null : number_format(100 * $m['qty_base'] / $total, 1, '.', '');
        $m['qty_base'] = number_format($m['qty_base'], 4, '.', '');
        $out[] = $m;
    }
    usort($out, static fn (array $a, array $b): int => (float) $b['qty_base'] <=> (float) $a['qty_base']);
    return $out;
}

// ------------------------------------------------------------------------------------------------ late rows
/** The rows (presented transactions or ledger rows) whose note says the trigger moved them out of a closed period. */
function late_rows(array $txns): array
{
    return array_values(array_filter($txns, static fn (array $t): bool => late_note_parts((string) ($t['note'] ?? '')) !== null));
}

/**
 * A sentence for a posting that landed late: " — dated Oct 2 inside closed week 40; landed in week 41" ('' when nothing moved). Slice 3's receipt
 * handler appends it (periods.md DECISION 1).
 */
function late_landing_sentence(PDO $pdo, int $scopeId, array $txns): string
{
    $seen = [];
    foreach (late_rows($txns) as $t) {
        $p = late_note_parts((string) $t['note']);
        $landed = period_on($pdo, $scopeId, (string) $t['business_date']);
        $closed = one_row($pdo, 'SELECT p.*, c.kind AS calendar_kind FROM mcp_periods p JOIN mcp_costing_calendars c ON c.calendar_id = p.calendar_id WHERE p.scope_id = :s AND :d::date BETWEEN p.starts_on AND p.ends_on ORDER BY p.calendar_id LIMIT 1', ['s' => $scopeId, 'd' => $p['dated']]);
        $k = $p['dated'] . '>' . $t['business_date'];
        $seen[$k] = 'dated ' . (new DateTimeImmutable($p['dated']))->format('M j') . ' inside closed ' . ($closed !== null ? period_label($closed, false) : 'period ' . $p['period_no'])
            . ($landed !== null ? '; landed in ' . period_label($landed, false) : '; landed ' . (new DateTimeImmutable((string) $t['business_date']))->format('M j'));
    }
    return $seen === [] ? '' : ' — ' . implode(' · ', array_values($seen));
}
