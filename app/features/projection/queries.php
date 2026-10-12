<?php
declare(strict_types=1);

/**
 * The projection's reads (projection.md "Query functions"; signatures fixed — Phase 4's tools `item_forecast`, `calendar_days`, `ingredient_demand`,
 * `projected_stock` call these). THE DATABASE IS THE REFEREE: the forecast of an item on a day is ri_item_forecast() (an override, else the current
 * run's row, else the SQL baseline, × the covers factor; a closed day 0); demand is ri_ingredient_demand(); projected stock is
 * ri_projected_stock_all(). PHP never averages a history, explodes a recipe or converts a unit — a quantity in the ingredient's count unit arrives
 * from ri_from_base() in the same statement, the base figure beside it. Dates are the database's own `current_date` (the connection runs UTC; the
 * functions of db/014 count from it), so a cell and the function that made it never disagree about which day is today.
 */
require_once dirname(__DIR__) . '/units/queries.php';
require_once dirname(__DIR__) . '/stock/queries.php';

const FORECAST_SOURCES = ['run' => 'primary', 'baseline' => 'secondary', 'override' => 'warning', 'closed' => 'dark'];
const FORECAST_SOURCE_WORDS = ['run' => 'forecast run', 'baseline' => 'baseline', 'override' => 'override', 'closed' => 'closed'];
const FORECAST_SORTS = ['total' => 'Total, largest first', 'wape' => 'WAPE, worst first', 'name' => 'Name'];
const FORECAST_HORIZONS = [7, 14, 21];
const DEMAND_MAX_DAYS = 28;
const PROJECTION_DAYS = [7, 14, 21, 28];
/** The ingredient's count unit, else its base (the same fragment the usage report uses): `i` is mcp_ingredients. */
const PROJ_COUNT_UNIT = "COALESCE((SELECT iu.unit_code FROM mcp_ingredient_units iu WHERE iu.ingredient_id = i.ingredient_id AND iu.is_default_count AND iu.active LIMIT 1), i.base_unit_code)";

/** One row of a statement, or null (the worker and the screens share this file, so it does not lean on a handler prelude). */
function proj_row(PDO $pdo, string $sql, array $args = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** An ingredient's count unit and base unit: ['count' => unit, 'base' => unit] (the count unit is the base when none is flagged), or null when it is not here. */
function proj_units(PDO $pdo, int $ingredientId): ?array
{
    $r = proj_row($pdo, 'SELECT i.base_unit_code AS base, ' . PROJ_COUNT_UNIT . ' AS count FROM mcp_ingredients i WHERE i.ingredient_id = :i', ['i' => $ingredientId]);
    return $r;
}

/** Today as the database counts it (db/014 reads current_date; the connection is UTC). */
function proj_today(PDO $pdo): string
{
    return (string) one_value($pdo, 'SELECT current_date');
}

/** The current forecast run at a site: id, made_at, method, horizon, items, params — or null (the baseline answers). */
function current_run(PDO $pdo, int $scopeId): ?array
{
    $st = $pdo->prepare('SELECT r.forecast_run_id, r.made_at, r.method, r.horizon_days, r.history_days, r.items, r.params, r.made_by, m.display_name AS made_by_name
                           FROM mcp_forecast_runs r LEFT JOIN mcp_members m ON m.member_id = r.made_by WHERE r.scope_id = :s AND r.is_current');
    $st->execute(['s' => $scopeId]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r = stock_ints($r, ['forecast_run_id', 'horizon_days', 'history_days', 'items', 'made_by']);
    $r['params'] = json_decode((string) $r['params'], true) ?: [];
    return $r;
}

/** The weeks of history an item (or the site) needs before the periodic-review rule trusts it (ri_settings.dynamic_par_after_weeks). */
function thin_after_weeks(PDO $pdo): int
{
    return (int) (one_value($pdo, 'SELECT dynamic_par_after_weeks FROM ri_settings WHERE id = 1') ?? 4);
}

/**
 * The forecast list: every active menu item sold in the last 84 days, its next days from ri_item_forecast() with the source of each, the total over the horizon,
 * the run's band when it covers the horizon, the accuracy (horizon 7, window 28) and a thin-history flag. $filters: category (a menu category key), sort
 * (total|wape|name). Returns ['rows' => [...], 'horizon', 'run' => current_run(), 'dates' => [the next 7 dates]].
 */
function forecast_list(PDO $pdo, int $scopeId, array $filters, int $horizon = 21): array
{
    $horizon = in_array($horizon, FORECAST_HORIZONS, true) ? $horizon : 21;
    $sort = isset(FORECAST_SORTS[$filters['sort'] ?? '']) ? $filters['sort'] : 'total';
    $args = ['s1' => $scopeId, 's2' => $scopeId, 's3' => $scopeId];
    $where = '';
    if (($filters['category'] ?? '') !== '') { $where = ' AND c.key = :cat'; $args['cat'] = (string) $filters['category']; }
    $st = $pdo->prepare("SELECT m.menu_item_id, m.plu, m.name, c.key AS category_key, c.name AS category,
              (SELECT count(DISTINCT sd.business_date) FROM mcp_sales_daily sd WHERE sd.scope_id = :s1 AND sd.menu_item_id = m.menu_item_id AND sd.day_part_id IS NULL AND sd.business_date >= current_date - 364) AS days_sold,
              (SELECT count(DISTINCT sd.business_date) FROM mcp_sales_daily sd WHERE sd.scope_id = :s1b AND sd.menu_item_id = m.menu_item_id AND sd.day_part_id IS NULL AND sd.business_date >= current_date - 364) / 7
                  < (SELECT dynamic_par_after_weeks FROM ri_settings WHERE id = 1) AS thin,
              a.mae, a.wape, a.bias, a.n AS accuracy_n
         FROM mcp_menu_items m LEFT JOIN mcp_menu_categories c ON c.category_id = m.category_id
         LEFT JOIN mcp_forecast_accuracy a ON a.scope_id = :s2 AND a.menu_item_id = m.menu_item_id AND a.horizon_days = 7 AND a.window_days = 28
        WHERE m.active AND EXISTS (SELECT 1 FROM mcp_sales_daily sd WHERE sd.scope_id = :s3 AND sd.menu_item_id = m.menu_item_id AND sd.business_date >= current_date - 84){$where}
        ORDER BY m.name");
    $args['s1b'] = $scopeId;
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['menu_item_id', 'days_sold', 'accuracy_n']);
        $r['thin'] = (bool) $r['thin'];
        $r['days'] = [];
        $r['total'] = '0';
        $r['lo'] = null;
        $r['hi'] = null;
        $rows[$r['menu_item_id']] = $r;
    }
    $dates = [];
    if ($rows !== []) {
        $ids = pg_array_literal(array_keys($rows));
        $st = $pdo->prepare("SELECT t.id AS menu_item_id, d::date AS on_date, f.qty, f.source
                               FROM unnest(CAST(:ids AS bigint[])) t(id) CROSS JOIN generate_series(current_date, current_date + CAST(:h AS integer) - 1, interval '1 day') d
                               CROSS JOIN LATERAL ri_item_forecast(:s, t.id, d::date) f ORDER BY t.id, d");
        $st->execute(['ids' => $ids, 'h' => $horizon, 's' => $scopeId]);
        foreach ($st->fetchAll() as $d) {
            $rows[(int) $d['menu_item_id']]['days'][] = ['on_date' => $d['on_date'], 'qty' => $d['qty'], 'source' => $d['source']];
        }
        $st = $pdo->prepare("SELECT t.id AS menu_item_id, (SELECT sum(x.qty) FROM generate_series(current_date, current_date + CAST(:h AS integer) - 1, interval '1 day') d CROSS JOIN LATERAL ri_item_forecast(:s, t.id, d::date) x) AS total
                               FROM unnest(CAST(:ids AS bigint[])) t(id)");
        $st->execute(['ids' => $ids, 'h' => $horizon, 's' => $scopeId]);
        foreach ($st->fetchAll() as $t) { $rows[(int) $t['menu_item_id']]['total'] = (string) ($t['total'] ?? '0'); }
        $st = $pdo->prepare("SELECT f.menu_item_id, sum(f.lo) AS lo, sum(f.hi) AS hi, count(*) AS n FROM mcp_item_forecasts f JOIN mcp_forecast_runs r ON r.forecast_run_id = f.run_id AND r.is_current
                              WHERE f.scope_id = :s AND f.on_date BETWEEN current_date AND current_date + CAST(:h AS integer) - 1 GROUP BY f.menu_item_id");
        $st->execute(['s' => $scopeId, 'h' => $horizon]);
        foreach ($st->fetchAll() as $b) {
            if ((int) $b['n'] === $horizon && isset($rows[(int) $b['menu_item_id']])) { $rows[(int) $b['menu_item_id']]['lo'] = $b['lo']; $rows[(int) $b['menu_item_id']]['hi'] = $b['hi']; }
        }
        $first = reset($rows);
        $dates = array_map(static fn (array $d): string => (string) $d['on_date'], array_slice($first['days'], 0, 7));
    }
    $rows = array_values($rows);
    usort($rows, static fn (array $a, array $b): int => match ($sort) {
        'name' => strcasecmp($a['name'], $b['name']),
        'wape' => ((float) ($b['wape'] ?? -1) <=> (float) ($a['wape'] ?? -1)) ?: strcasecmp($a['name'], $b['name']),
        default => ((float) $b['total'] <=> (float) $a['total']) ?: strcasecmp($a['name'], $b['name']),
    });
    return ['rows' => $rows, 'horizon' => $horizon, 'sort' => $sort, 'run' => current_run($pdo, $scopeId), 'dates' => $dates];
}

/** One menu item for the forecast screens (mapped recipe, category), or null when it is not here. */
function forecast_menu_item(PDO $pdo, int $scopeId, int $menuItemId): ?array
{
    $st = $pdo->prepare('SELECT m.menu_item_id, m.plu, m.name, m.recipe_id, m.portions, m.active, c.name AS category,
              (SELECT count(DISTINCT sd.business_date) FROM mcp_sales_daily sd WHERE sd.scope_id = :s AND sd.menu_item_id = m.menu_item_id AND sd.day_part_id IS NULL AND sd.business_date >= current_date - 364) AS days_sold
         FROM mcp_menu_items m LEFT JOIN mcp_menu_categories c ON c.category_id = m.category_id WHERE m.menu_item_id = :i');
    $st->execute(['s' => $scopeId, 'i' => $menuItemId]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['menu_item_id', 'recipe_id', 'days_sold']);
}

/**
 * One item's forecast: ['menu_item', 'days' => [{on_date, qty, source, lo, hi, sigma, covers_factor}], 'accuracy', 'overrides', 'proposed', 'run'] —
 * the tool `item_forecast`. `lo`/`hi` are the current run's band (null for a baseline day).
 */
function item_forecast(PDO $pdo, int $scopeId, int $menuItemId, int $days = 21): array
{
    $days = max(1, min(92, $days));
    $item = forecast_menu_item($pdo, $scopeId, $menuItemId);
    if ($item === null) { return []; }
    $st = $pdo->prepare("SELECT d::date AS on_date, f.qty, f.source, f.sigma, ri_covers_factor(:s, d::date) AS covers_factor, r.lo, r.hi
                           FROM generate_series(current_date, current_date + CAST(:n AS integer) - 1, interval '1 day') d
                           CROSS JOIN LATERAL ri_item_forecast(:s2, :i, d::date) f
                           LEFT JOIN LATERAL (SELECT x.lo, x.hi FROM mcp_item_forecasts x JOIN mcp_forecast_runs fr ON fr.forecast_run_id = x.run_id AND fr.is_current
                                               WHERE x.scope_id = :s3 AND x.menu_item_id = :i2 AND x.on_date = d::date) r ON true ORDER BY d");
    $st->execute(['s' => $scopeId, 's2' => $scopeId, 's3' => $scopeId, 'n' => $days, 'i' => $menuItemId, 'i2' => $menuItemId]);
    $acc = $pdo->prepare('SELECT mae, wape, bias, n, computed_at FROM mcp_forecast_accuracy WHERE scope_id = :s AND menu_item_id = :i AND horizon_days = 7 AND window_days = 28');
    $acc->execute(['s' => $scopeId, 'i' => $menuItemId]);
    return ['menu_item' => $item, 'days' => $st->fetchAll(), 'accuracy' => $acc->fetch() ?: null, 'overrides' => forecast_overrides($pdo, $scopeId, $menuItemId, 'set'),
            'proposed' => forecast_overrides($pdo, $scopeId, $menuItemId, 'proposed'), 'run' => current_run($pdo, $scopeId)];
}

/** The overrides at a site (still in force or ahead: to_date today or later) of an item — and the all-items ones — by status (set|proposed); newest first. */
function forecast_overrides(PDO $pdo, int $scopeId, ?int $menuItemId, string $status = 'set'): array
{
    $st = $pdo->prepare('SELECT o.override_id, o.menu_item_id, mi.name AS menu_item, o.from_date, o.to_date, o.factor, o.qty, o.reason, o.status, o.set_by, m.display_name AS set_by_name, o.created_at
                           FROM mcp_forecast_overrides o LEFT JOIN mcp_menu_items mi ON mi.menu_item_id = o.menu_item_id LEFT JOIN mcp_members m ON m.member_id = o.set_by
                          WHERE o.scope_id = :s AND o.status = :st AND o.to_date >= current_date AND (CAST(:i AS bigint) IS NULL OR o.menu_item_id = :i2 OR o.menu_item_id IS NULL) ORDER BY o.from_date, o.override_id DESC');
    $st->execute(['s' => $scopeId, 'st' => $status, 'i' => $menuItemId, 'i2' => $menuItemId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['override_id', 'menu_item_id', 'set_by']), $st->fetchAll());
}

function find_override(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT o.override_id, o.scope_id, o.menu_item_id, o.from_date, o.to_date, o.factor, o.qty, o.reason, o.status FROM mcp_forecast_overrides o WHERE o.override_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['override_id', 'scope_id', 'menu_item_id']);
}

/** The four same weekdays the baseline weighs (weights 0.4 0.3 0.2 0.1): [{on_date, weight, qty, used, why}]; a closed day or a day the site recorded no sales is not used. */
function same_weekdays(PDO $pdo, int $scopeId, int $menuItemId, string $onDate): array
{
    $st = $pdo->prepare("SELECT w.k, CAST(:d AS date) - 7 * w.k AS on_date, CASE w.k WHEN 1 THEN 0.4 WHEN 2 THEN 0.3 WHEN 3 THEN 0.2 ELSE 0.1 END AS weight,
              (SELECT sd.qty FROM mcp_sales_daily sd WHERE sd.scope_id = :s AND sd.menu_item_id = :i AND sd.business_date = CAST(:d2 AS date) - 7 * w.k AND sd.day_part_id IS NULL) AS qty,
              EXISTS (SELECT 1 FROM mcp_sales_daily x WHERE x.scope_id = :s2 AND x.business_date = CAST(:d3 AS date) - 7 * w.k) AS site_sold,
              COALESCE((SELECT cd.closed FROM mcp_calendar_days cd WHERE cd.scope_id = :s3 AND cd.on_date = CAST(:d4 AS date) - 7 * w.k), false) AS closed
         FROM generate_series(1, 4) w(k) ORDER BY w.k");
    $st->execute(['d' => $onDate, 'd2' => $onDate, 'd3' => $onDate, 'd4' => $onDate, 's' => $scopeId, 's2' => $scopeId, 's3' => $scopeId, 'i' => $menuItemId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['closed'] = (bool) $r['closed'];
        $r['used'] = !$r['closed'] && $r['site_sold'];
        $r['why'] = $r['closed'] ? 'closed that day' : (!$r['site_sold'] ? 'no sales recorded that day' : null);
        $r['qty'] = $r['qty'] ?? ($r['site_sold'] ? '0' : null);
        $out[] = $r;
    }
    return $out;
}

/** The last $weeks weeks of one item's whole-day sales, one entry per day (qty null when the site recorded no sales), with the closed days. */
function item_actuals(PDO $pdo, int $scopeId, int $menuItemId, int $weeks = 8): array
{
    $st = $pdo->prepare("SELECT d::date AS on_date,
              (SELECT sd.qty FROM mcp_sales_daily sd WHERE sd.scope_id = :s AND sd.menu_item_id = :i AND sd.business_date = d::date AND sd.day_part_id IS NULL) AS qty,
              EXISTS (SELECT 1 FROM mcp_sales_daily x WHERE x.scope_id = :s2 AND x.business_date = d::date) AS site_sold,
              COALESCE((SELECT cd.closed FROM mcp_calendar_days cd WHERE cd.scope_id = :s3 AND cd.on_date = d::date), false) AS closed
         FROM generate_series(current_date - CAST(:n AS integer) * 7, current_date - 1, interval '1 day') d ORDER BY d");
    $st->execute(['s' => $scopeId, 's2' => $scopeId, 's3' => $scopeId, 'i' => $menuItemId, 'n' => $weeks]);
    return array_map(static function (array $r): array { $r['closed'] = (bool) $r['closed']; $r['qty'] = $r['qty'] ?? ($r['site_sold'] ? '0' : null); return $r; }, $st->fetchAll());
}

/** The overrides (any status) covering days of the next three weeks plus those proposed — for the item's chart markers. */
function override_days(array $overrides): array
{
    $days = [];
    foreach ($overrides as $o) {
        for ($d = new DateTimeImmutable($o['from_date']); $d <= new DateTimeImmutable($o['to_date']) && count($days) < 400; $d = $d->modify('+1 day')) { $days[$d->format('Y-m-d')] = true; }
    }
    return $days;
}

// ---------------------------------------------------------------------------------------------------------------------- the trading calendar

/** Is $month a month, as 'YYYY-MM'? */
function month_or_null(?string $month): ?string
{
    return $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null;
}

/**
 * A month of the trading calendar: ['month', 'first', 'days' => [{on_date, dow, closed, holiday, event, note, covers, total, total_kind}], 'synced_at', 'last_sync'].
 * `total` is the day's forecast across the menu (today and ahead) or the day's recorded sales (the past) — `total_kind` says which.
 */
function calendar_month(PDO $pdo, int $scopeId, string $month): array
{
    $first = $month . '-01';
    $st = $pdo->prepare("WITH items AS (SELECT m.menu_item_id FROM mcp_menu_items m WHERE m.active AND EXISTS (SELECT 1 FROM mcp_sales_daily sd WHERE sd.scope_id = :s AND sd.menu_item_id = m.menu_item_id AND sd.business_date >= current_date - 84))
         SELECT d::date AS on_date, extract(isodow FROM d)::int AS dow, COALESCE(cd.closed, false) AS closed, cd.holiday, cd.event, cd.note,
                (SELECT sum(c.covers) FROM mcp_covers_expected c WHERE c.scope_id = :s2 AND c.on_date = d::date) AS covers,
                CASE WHEN d::date >= current_date THEN (SELECT sum(f.qty) FROM items it CROSS JOIN LATERAL ri_item_forecast(:s3, it.menu_item_id, d::date) f)
                     ELSE (SELECT sum(sd.qty) FROM mcp_sales_daily sd WHERE sd.scope_id = :s4 AND sd.business_date = d::date AND sd.day_part_id IS NULL) END AS total,
                CASE WHEN d::date >= current_date THEN 'forecast' ELSE 'sold' END AS total_kind
           FROM generate_series(CAST(:first AS date), CAST(:first2 AS date) + interval '1 month' - interval '1 day', interval '1 day') d
           LEFT JOIN mcp_calendar_days cd ON cd.scope_id = :s5 AND cd.on_date = d::date ORDER BY d");
    $st->execute(['s' => $scopeId, 's2' => $scopeId, 's3' => $scopeId, 's4' => $scopeId, 's5' => $scopeId, 'first' => $first, 'first2' => $first]);
    $days = array_map(static function (array $r): array { $r['closed'] = (bool) $r['closed']; $r['dow'] = (int) $r['dow']; return $r; }, $st->fetchAll());
    $synced = one_value($pdo, "SELECT max(synced_at) FROM mcp_covers_expected WHERE scope_id = :s AND source = 'reservations'", ['s' => $scopeId]);
    $last = proj_row($pdo, "SELECT occurred_at, after FROM activity_log WHERE action = 'forecast.covers_sync' AND scope_id = :s ORDER BY id DESC LIMIT 1", ['s' => $scopeId]);
    $after = $last === null ? [] : (json_decode((string) $last['after'], true) ?: []);
    return ['month' => $month, 'first' => $first, 'days' => $days, 'synced_at' => $synced, 'last_sync' => $last === null ? null : ['at' => $last['occurred_at'], 'connected' => (bool) ($after['connected'] ?? false), 'days' => (int) ($after['days'] ?? 0)]];
}

/** One day of the calendar: the row (closed, holiday, event, note), the booked covers by service. Null row fields when the day was never named. */
function calendar_day(PDO $pdo, int $scopeId, string $onDate): array
{
    $st = $pdo->prepare('SELECT cd.closed, cd.holiday, cd.event, cd.note FROM mcp_calendar_days cd WHERE cd.scope_id = :s AND cd.on_date = :d');
    $st->execute(['s' => $scopeId, 'd' => $onDate]);
    $r = $st->fetch() ?: ['closed' => false, 'holiday' => null, 'event' => null, 'note' => null];
    $r['closed'] = (bool) $r['closed'];
    return ['on_date' => $onDate] + $r + ['covers' => covers_for($pdo, $scopeId, $onDate, $onDate)];
}

/** Booked covers from Reservations (covers_expected): [{on_date, day_part, covers, source, synced_at}] from..to, by service. */
function covers_for(PDO $pdo, int $scopeId, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT c.on_date, p.name AS day_part, c.covers, c.source, c.synced_at FROM mcp_covers_expected c LEFT JOIN mcp_day_parts p ON p.day_part_id = c.day_part_id
                          WHERE c.scope_id = :s AND c.on_date BETWEEN :f AND :t ORDER BY c.on_date, p.starts_at NULLS LAST');
    $st->execute(['s' => $scopeId, 'f' => $from, 't' => $to]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------------------------------------------------------- demand

/**
 * Ingredient demand for a window (ri_ingredient_demand(): the forecast exploded through the recipes), pivoted: ['dates', 'rows' => [{ingredient_id, name, code,
 * category, count_unit, base_unit, days => {date => {count, base}}, total_count, total_base}], 'from', 'to']. $filters: category (a key), ingredient (an id).
 * The window is at most DEMAND_MAX_DAYS days (the caller refuses a longer one in words).
 */
function ingredient_demand(PDO $pdo, int $scopeId, string $from, string $to, array $filters = []): array
{
    $where = '';
    $args = ['s' => $scopeId, 'f' => $from, 't' => $to];
    if (($filters['category'] ?? '') !== '') { $where .= ' AND c.key = :cat'; $args['cat'] = (string) $filters['category']; }
    if (($filters['ingredient'] ?? '') !== '' && ctype_digit((string) $filters['ingredient'])) { $where .= ' AND x.ingredient_id = :ing'; $args['ing'] = (int) $filters['ingredient']; }
    $base = "FROM ri_ingredient_demand(:s, CAST(:f AS date), CAST(:t AS date)) x JOIN mcp_ingredients i ON i.ingredient_id = x.ingredient_id JOIN mcp_ingredient_categories c ON c.category_id = i.category_id{$where}";
    $st = $pdo->prepare('SELECT x.ingredient_id, x.on_date, x.qty_base, ri_from_base(x.ingredient_id, x.qty_base, ' . PROJ_COUNT_UNIT . ') AS qty_count, i.name, i.code, i.base_unit_code, c.name AS category, ' . PROJ_COUNT_UNIT . ' AS count_unit ' . $base . ' ORDER BY i.name, x.on_date');
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['ingredient_id'];
        $rows[$id] ??= ['ingredient_id' => $id, 'name' => $r['name'], 'code' => $r['code'], 'category' => $r['category'], 'count_unit' => $r['count_unit'], 'base_unit' => $r['base_unit_code'], 'days' => [], 'total_count' => null, 'total_base' => null];
        $rows[$id]['days'][$r['on_date']] = ['count' => $r['qty_count'], 'base' => $r['qty_base']];
    }
    $st = $pdo->prepare('SELECT x.ingredient_id, sum(x.qty_base) AS total_base, ri_from_base(x.ingredient_id, sum(x.qty_base), ' . PROJ_COUNT_UNIT . ') AS total_count ' . $base . ' GROUP BY x.ingredient_id, i.ingredient_id, i.base_unit_code');
    $st->execute($args);
    foreach ($st->fetchAll() as $t) { $rows[(int) $t['ingredient_id']]['total_base'] = $t['total_base']; $rows[(int) $t['ingredient_id']]['total_count'] = $t['total_count']; }
    $dates = [];
    for ($d = new DateTimeImmutable($from); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) { $dates[] = $d->format('Y-m-d'); }
    return ['from' => $from, 'to' => $to, 'dates' => $dates, 'rows' => array_values($rows)];
}

/** What makes an ingredient's demand: per menu item the forecast × the recipe's explosion over the window, with its share. [{menu_item_id, name, forecast_qty, qty_base, count, share_pct}]. */
function demand_drivers(PDO $pdo, int $scopeId, int $ingredientId, string $from, string $to): array
{
    $u = proj_units($pdo, $ingredientId);
    if ($u === null) { return []; }
    $st = $pdo->prepare("SELECT m.id AS menu_item_id, m.name, round(sum(f.qty), 4) AS forecast_qty, round(sum(f.qty * m.portions / v.batch_yield_qty * e.qty_base), 4) AS qty_base,
              round(100 * sum(f.qty * m.portions / v.batch_yield_qty * e.qty_base) / NULLIF(sum(sum(f.qty * m.portions / v.batch_yield_qty * e.qty_base)) OVER (), 0), 1) AS share_pct,
              ri_from_base(CAST(:ing2 AS bigint), round(sum(f.qty * m.portions / v.batch_yield_qty * e.qty_base), 4), CAST(:u AS text)) AS qty_count
         FROM menu_items m CROSS JOIN generate_series(CAST(:f AS date), CAST(:t AS date), interval '1 day') d
         CROSS JOIN LATERAL ri_item_forecast(:s, m.id, d::date) f
         JOIN recipe_versions v ON v.id = ri_recipe_version_on(m.recipe_id, d::date)
         CROSS JOIN LATERAL ri_explode(v.id, d::date, false) e
        WHERE m.active AND m.recipe_id IS NOT NULL AND e.ingredient_id = :ing AND f.qty > 0
          AND EXISTS (SELECT 1 FROM sales_daily sd WHERE sd.scope_id = :s2 AND sd.menu_item_id = m.id AND sd.business_date >= CAST(:f2 AS date) - 84)
        GROUP BY m.id, m.name ORDER BY 4 DESC");
    $st->execute(['ing' => $ingredientId, 'ing2' => $ingredientId, 'u' => $u['count'], 'f' => $from, 'f2' => $from, 't' => $to, 's' => $scopeId, 's2' => $scopeId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['menu_item_id']) + ['count' => $r['qty_count'], 'count_unit' => $u['count'], 'base_unit' => $u['base']], $st->fetchAll());
}

// ---------------------------------------------------------------------------------------------------------------------- projected stock

/**
 * Projected on hand per ingredient per day (ri_projected_stock_all(), one explosion of the menu for every ingredient): ['dates', 'rows' => [{ingredient_id, name, code,
 * category, count_unit, base_unit, on_hand_base, on_hand_count, par_base, par_count, par_mode, days => [{on_date, demand_base, receipts_base, projected_base, projected_count}],
 * stockout_on, stockout_in_days}]]. $filters: category (a key), stockouts_only (yes), q. A prep (made, not bought) is left out of stockouts_only.
 */
function projected_stock(PDO $pdo, int $scopeId, array $filters = [], int $days = 14): array
{
    $days = in_array($days, PROJECTION_DAYS, true) ? $days : 14;
    $args = ['s' => $scopeId, 'd' => $days, 's2' => $scopeId, 's3' => $scopeId, 'ids' => ($filters['ingredient'] ?? '') !== '' && ctype_digit((string) $filters['ingredient']) ? pg_array_literal([(int) $filters['ingredient']]) : null];
    $where = '';
    if (($filters['category'] ?? '') !== '') { $where .= ' AND c.key = :cat'; $args['cat'] = (string) $filters['category']; }
    if (($filters['q'] ?? '') !== '') { $where .= ' AND (i.name ILIKE :q OR i.code ILIKE :q2)'; $args['q'] = '%' . $filters['q'] . '%'; $args['q2'] = '%' . $filters['q'] . '%'; }
    $st = $pdo->prepare('SELECT p.ingredient_id, p.on_date, p.demand_base, p.receipts_base, p.projected_base, ri_from_base(p.ingredient_id, p.projected_base, ' . PROJ_COUNT_UNIT . ') AS projected_count,
              i.name, i.code, i.base_unit_code, i.is_prep, c.name AS category, ' . PROJ_COUNT_UNIT . ' AS count_unit,
              ri_on_hand(p.ingredient_id, :s2) AS on_hand_base, ri_from_base(p.ingredient_id, ri_on_hand(p.ingredient_id, :s3), ' . PROJ_COUNT_UNIT . ') AS on_hand_count,
              ss.par_base, ss.par_mode, CASE WHEN ss.par_base IS NULL THEN NULL ELSE ri_from_base(p.ingredient_id, ss.par_base, ' . PROJ_COUNT_UNIT . ') END AS par_count
         FROM ri_projected_stock_all(:s, CAST(:d AS integer), CAST(:ids AS bigint[])) p JOIN mcp_ingredients i ON i.ingredient_id = p.ingredient_id JOIN mcp_ingredient_categories c ON c.category_id = i.category_id
         LEFT JOIN mcp_ingredient_site_settings ss ON ss.ingredient_id = p.ingredient_id AND ss.scope_id = :s4
        WHERE true' . $where . ' ORDER BY i.name, p.on_date');
    $args['s4'] = $scopeId;
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['ingredient_id'];
        $rows[$id] ??= ['ingredient_id' => $id, 'name' => $r['name'], 'code' => $r['code'], 'category' => $r['category'], 'count_unit' => $r['count_unit'], 'base_unit' => $r['base_unit_code'], 'is_prep' => (bool) $r['is_prep'],
            'on_hand_base' => $r['on_hand_base'], 'on_hand_count' => $r['on_hand_count'], 'par_base' => $r['par_base'], 'par_count' => $r['par_count'], 'par_mode' => $r['par_mode'], 'days' => [], 'stockout_on' => null];
        $rows[$id]['days'][] = ['on_date' => $r['on_date'], 'demand_base' => $r['demand_base'], 'receipts_base' => $r['receipts_base'], 'projected_base' => $r['projected_base'], 'projected_count' => $r['projected_count']];
        if ($rows[$id]['stockout_on'] === null && (float) $r['projected_base'] < 0) { $rows[$id]['stockout_on'] = $r['on_date']; }
    }
    $today = proj_today($pdo);
    foreach ($rows as &$r) { $r['stockout_in_days'] = $r['stockout_on'] === null ? null : (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($r['stockout_on']))->format('%a'); }
    unset($r);
    $list = array_values($rows);
    if (($filters['stockouts_only'] ?? '') === 'yes') { $list = array_values(array_filter($list, static fn (array $r): bool => $r['stockout_on'] !== null && !$r['is_prep'])); }
    $dates = $list === [] ? [] : array_map(static fn (array $d): string => (string) $d['on_date'], $list[0]['days']);
    return ['days' => $days, 'dates' => $dates, 'rows' => $list, 'today' => $today];
}

/**
 * One ingredient's projection: ['ingredient', 'days', 'po_lines' (the open purchase order lines due), 'par', 'drivers', 'suggested' (the line of its supplier's suggested order, or null)].
 * The ingredient is null when it is not here.
 */
function projected_ingredient(PDO $pdo, int $scopeId, int $ingredientId, int $days = 14): ?array
{
    $days = in_array($days, PROJECTION_DAYS, true) ? $days : 14;
    $ing = proj_row($pdo, 'SELECT i.ingredient_id, i.code, i.name, i.base_unit_code, i.is_prep, c.name AS category, ' . PROJ_COUNT_UNIT . ' AS count_unit FROM mcp_ingredients i JOIN mcp_ingredient_categories c ON c.category_id = i.category_id WHERE i.ingredient_id = :i', ['i' => $ingredientId]);
    if ($ing === null) { return null; }
    $ing['ingredient_id'] = (int) $ing['ingredient_id'];
    $st = $pdo->prepare('SELECT p.on_date, p.demand_base, p.receipts_base, p.projected_base, ri_from_base(CAST(:i AS bigint), p.projected_base, CAST(:u AS text)) AS projected_count
                           FROM ri_projected_stock(:s, :i3, CAST(:d AS integer)) p ORDER BY p.on_date');
    $st->execute(['i' => $ingredientId, 'u' => $ing['count_unit'], 's' => $scopeId, 'i3' => $ingredientId, 'd' => $days]);
    $rows = $st->fetchAll();
    $on = proj_row($pdo, 'SELECT ri_on_hand(:i, :s) AS on_hand_base, ri_from_base(CAST(:i2 AS bigint), ri_on_hand(:i3, :s2), CAST(:u AS text)) AS on_hand_count', ['i' => $ingredientId, 's' => $scopeId, 'i2' => $ingredientId, 'i3' => $ingredientId, 's2' => $scopeId, 'u' => $ing['count_unit']]);
    $par = proj_row($pdo, 'SELECT par_base, par_mode, service_level, preferred_supplier_item_id,
              CASE WHEN par_base IS NULL THEN NULL ELSE ri_from_base(CAST(:i AS bigint), par_base, CAST(:u AS text)) END AS par_count FROM mcp_ingredient_site_settings WHERE ingredient_id = :i2 AND scope_id = :s',
        ['i' => $ingredientId, 'u' => $ing['count_unit'], 'i2' => $ingredientId, 's' => $scopeId]);
    $stockout = null;
    foreach ($rows as $r) { if ($stockout === null && (float) $r['projected_base'] < 0) { $stockout = $r['on_date']; } }
    $lines = $pdo->prepare("SELECT l.purchase_order_line_id, l.purchase_order_id, p.number, p.status, s.name AS supplier, COALESCE(l.expected_on, p.expected_on) AS due_on, l.qty_ordered, l.purchase_unit_code, l.qty_ordered_base, l.qty_received_base
              FROM mcp_purchase_order_lines l JOIN mcp_purchase_orders p ON p.purchase_order_id = l.purchase_order_id JOIN mcp_suppliers s ON s.supplier_id = p.supplier_id
             WHERE l.ingredient_id = :i AND p.scope_id = :s AND p.status IN ('sent', 'acknowledged', 'partial') AND l.status IN ('open', 'acknowledged', 'partial') ORDER BY due_on");
    $lines->execute(['i' => $ingredientId, 's' => $scopeId]);
    $from = $rows === [] ? proj_today($pdo) : (string) $rows[0]['on_date'];
    $to = $rows === [] ? $from : (string) $rows[count($rows) - 1]['on_date'];
    return ['ingredient' => $ing, 'days' => $rows, 'on_hand_base' => $on['on_hand_base'] ?? null, 'on_hand_count' => $on['on_hand_count'] ?? null, 'stockout_on' => $stockout, 'par' => $par,
            'po_lines' => $lines->fetchAll(), 'drivers' => demand_drivers($pdo, $scopeId, $ingredientId, $from, $to), 'from' => $from, 'to' => $to];
}

/** The projected stock-outs within $days (ri_stockouts_projected): [{ingredient_id, name, stockout_on, on_hand_base, on_hand_count, count_unit}], soonest first. */
function stockouts(PDO $pdo, int $scopeId, int $days = 7): array
{
    $st = $pdo->prepare('SELECT s.ingredient_id, i.name, i.code, s.stockout_on, s.on_hand_base, ri_from_base(s.ingredient_id, s.on_hand_base, ' . PROJ_COUNT_UNIT . ') AS on_hand_count, ' . PROJ_COUNT_UNIT . ' AS count_unit
                           FROM ri_stockouts_projected(:s, CAST(:d AS integer)) s JOIN mcp_ingredients i ON i.ingredient_id = s.ingredient_id ORDER BY s.stockout_on, i.name');
    $st->execute(['s' => $scopeId, 'd' => $days]);
    return array_map(static fn (array $r): array => stock_ints($r, ['ingredient_id']), $st->fetchAll());
}
