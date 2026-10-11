<?php
declare(strict_types=1);

/**
 * The periods' reads (periods.md "Query functions"; signatures fixed — Phase 4's tools `periods`, `get_period` and the COGS reads call these). Every
 * row of a period comes through mcp_periods, so the site and the cost wall are the database's (a value a caller may not see is null). An OPEN period's
 * figures are live from ri_usage() and ri_food_sales() — the same functions the close freezes — and are walled here by sees_cost(), because the
 * functions answer unnulled (db/018: the wall is the caller's). PHP adds the percentages; it computes no quantity and no cost.
 */
require_once dirname(__DIR__) . '/stock/queries.php';

const PERIOD_STATUS_CHIPS = ['open' => 'warning', 'closed' => 'success'];
const CALENDAR_KINDS = ['week' => 'Weeks', 'month' => 'Months', '4-4-5' => '4-4-5 weeks', '4-5-4' => '4-5-4 weeks', '5-4-4' => '5-4-4 weeks', '13x4' => '13 × 4 weeks'];
const WEEK_STARTS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
const PERIOD_TABS = ['usage' => 'Usage', 'counts' => 'Counts', 'late' => 'Late rows', 'trail' => 'Trail'];

function period_row_decode(array $r): array
{
    $r = stock_ints($r, ['period_id', 'scope_id', 'calendar_id', 'no', 'fiscal_year', 'opening_count_id', 'closing_count_id', 'closed_by']);
    $r['label'] = period_label($r);
    return $r;
}

/** "week 40 (Sep 29 – Oct 5)" — a weekly calendar's periods are weeks, any other's are numbered periods. */
function period_label(array $p, bool $withDates = true): string
{
    $word = ($p['calendar_kind'] ?? 'week') === 'week' ? 'week' : 'period';
    $out = $word . ' ' . $p['no'];
    if ($withDates) {
        $a = new DateTimeImmutable((string) $p['starts_on']);
        $b = new DateTimeImmutable((string) $p['ends_on']);
        $out .= ' (' . $a->format('M j') . ' – ' . ($a->format('M') === $b->format('M') ? $b->format('j') : $b->format('M j')) . ')';
    }
    return $out;
}

const PERIOD_SELECT = 'SELECT p.*, c.name AS calendar_name, c.kind AS calendar_kind, cm.display_name AS closed_by_name
      FROM mcp_periods p JOIN mcp_costing_calendars c ON c.calendar_id = p.calendar_id LEFT JOIN mcp_members cm ON cm.member_id = p.closed_by';

/** The fiscal year a screen shows by default: the year of the period holding today, else the latest year made, else the calendar year. */
function period_default_year(PDO $pdo, int $scopeId): int
{
    $v = one_value($pdo, 'SELECT fiscal_year FROM mcp_periods WHERE scope_id = :s AND CAST(:t AS date) BETWEEN starts_on AND ends_on ORDER BY calendar_id LIMIT 1', ['s' => $scopeId, 't' => site_today($scopeId)]);
    $v ??= one_value($pdo, 'SELECT max(fiscal_year) FROM mcp_periods WHERE scope_id = :s', ['s' => $scopeId]);
    return (int) ($v ?? date('Y'));
}

/** The fiscal years that have periods at this site, newest first. */
function period_years(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT DISTINCT fiscal_year FROM mcp_periods WHERE scope_id = :s ORDER BY fiscal_year DESC');
    $st->execute(['s' => $scopeId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** The periods of a fiscal year at a site (the year holding today by default), optionally by status, in order. */
function periods(PDO $pdo, int $scopeId, ?int $fiscalYear = null, ?string $status = null): array
{
    $fiscalYear ??= period_default_year($pdo, $scopeId);
    $where = 'p.scope_id = :s AND p.fiscal_year = :y';
    $args = ['s' => $scopeId, 'y' => $fiscalYear];
    if ($status !== null && isset(PERIOD_STATUS_CHIPS[$status])) { $where .= ' AND p.status = :st'; $args['st'] = $status; }
    $st = $pdo->prepare(PERIOD_SELECT . ' WHERE ' . $where . ' ORDER BY p.calendar_id, p.starts_on');
    $st->execute($args);
    return array_map('period_row_decode', $st->fetchAll());
}

function find_period(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(PERIOD_SELECT . ' WHERE p.period_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r = period_row_decode($r);
    $r['reopened_at'] = $r['reopened_at'] ?? null;
    return $r;
}

/** The period holding a date at a site (the database's choice), or null. */
function period_on(PDO $pdo, int $scopeId, string $date): ?array
{
    $id = one_value($pdo, 'SELECT ri_period_on(:s, :d::date)', ['s' => $scopeId, 'd' => $date]);
    return $id === null ? null : find_period($pdo, (int) $id);
}

/** The first open period past its last day — the one the close function admits (an earlier one still open would refuse it). */
function period_to_close(PDO $pdo, int $scopeId): ?array
{
    $id = one_value($pdo, "SELECT period_id FROM mcp_periods WHERE scope_id = :s AND status = 'open' AND ends_on < current_date ORDER BY starts_on, calendar_id LIMIT 1", ['s' => $scopeId]);
    return $id === null ? null : find_period($pdo, (int) $id);
}

/** The ids the list marks: the first open period of each calendar when it is past its end ("closes next"), and the last closed one of each ("can reopen"). */
function period_marks(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare("SELECT DISTINCT ON (calendar_id) period_id FROM mcp_periods WHERE scope_id = :s AND status = 'open' ORDER BY calendar_id, starts_on");
    $st->execute(['s' => $scopeId]);
    $firstOpen = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $closes = [];
    foreach ($firstOpen as $id) {
        if ((bool) one_value($pdo, 'SELECT ends_on < current_date FROM mcp_periods WHERE period_id = :id', ['id' => $id])) { $closes[] = $id; }
    }
    $st = $pdo->prepare("SELECT DISTINCT ON (calendar_id) period_id FROM mcp_periods WHERE scope_id = :s AND status = 'closed' ORDER BY calendar_id, starts_on DESC");
    $st->execute(['s' => $scopeId]);
    return ['closes_next' => $closes, 'reopenable' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
}

/** A ratio of two money strings in per cent to two places, or null when the divisor is missing or zero. */
function period_pct(?string $part, ?string $whole): ?string
{
    if ($part === null || $whole === null || (float) $whole == 0.0) { return null; }
    return number_format(100 * (float) $part / (float) $whole, 2, '.', '');
}

/**
 * A period's figures: frozen when closed (the view's, walled), live from ri_usage() and ri_food_sales() while open (null for a caller who sees no cost).
 * {opening_value, purchases_value, ending_value, cogs_value, theoretical_value, waste_value, food_sales, food_cost_pct, theoretical_pct, live}
 */
function period_figures(PDO $pdo, array $period): array
{
    static $cache = [];
    $key = $period['period_id'] . ':' . $period['status'] . ':' . ($period['closed_at'] ?? '');
    if (isset($cache[$key])) { return $cache[$key]; }
    $live = $period['status'] !== 'closed';
    $begun = $period['status'] === 'closed' || $period['starts_on'] <= site_today((int) $period['scope_id']);
    $f = ['opening_value' => null, 'purchases_value' => null, 'ending_value' => null, 'cogs_value' => null, 'theoretical_value' => null, 'waste_value' => null, 'food_sales' => null];
    if (!$live) {
        foreach ($f as $k => $_) { $f[$k] = $period[$k] ?? null; }
    } elseif ($begun && sees_cost((int) $period['scope_id'])) {      // a period that has not begun has nothing to compute (a year's weeks are not each a ri_usage() call)
        $st = $pdo->prepare('SELECT sum(opening_value) AS opening_value, sum(purchases_value) AS purchases_value, sum(closing_value) AS ending_value, sum(actual_value) AS cogs_value,
                                    sum(theoretical_value) AS theoretical_value, sum(waste_value) AS waste_value FROM ri_usage(:s, :a, :b)');
        $st->execute(['s' => $period['scope_id'], 'a' => $period['starts_on'], 'b' => $period['ends_on']]);
        $t = $st->fetch();
        foreach (['opening_value', 'purchases_value', 'ending_value', 'cogs_value', 'theoretical_value', 'waste_value'] as $k) { $f[$k] = number_format((float) ($t[$k] ?? 0), 2, '.', ''); }
        $f['food_sales'] = number_format((float) one_value($pdo, 'SELECT ri_food_sales(:s, :a, :b, NULL)', ['s' => $period['scope_id'], 'a' => $period['starts_on'], 'b' => $period['ends_on']]), 2, '.', '');
    }
    $f['food_cost_pct'] = period_pct($f['cogs_value'], $f['food_sales']);
    $f['theoretical_pct'] = period_pct($f['theoretical_value'], $f['food_sales']);
    $f['live'] = $live;
    $f['begun'] = $begun;
    return $cache[$key] = $f;
}

/** Approved counts whose moment lies inside the period's last business day (after the day's sales cut that opens it, up to the cut that ends it): the closing count's choices. */
function period_closing_count_options(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT count_id, number, kind, as_of FROM mcp_inventory_counts WHERE scope_id = :s AND status = 'approved'
                          AND as_of > ri_site_moment(:s2, :d::date, false) AND as_of <= ri_site_moment(:s3, :d2::date, true) ORDER BY as_of DESC, count_id DESC");
    $st->execute(['s' => $period['scope_id'], 's2' => $period['scope_id'], 's3' => $period['scope_id'], 'd' => $period['ends_on'], 'd2' => $period['ends_on']]);
    return array_map(static fn (array $r): array => stock_ints($r, ['count_id']), $st->fetchAll());
}

/** The days inside the period whose sales have no live depletion run — what ri_period_close() would refuse on. */
function period_undepleted_days(PDO $pdo, array $period): array
{
    $st = $pdo->prepare('SELECT business_date FROM ri_depletion_due() WHERE scope_id = :s AND business_date BETWEEN :a AND :b ORDER BY business_date');
    $st->execute(['s' => $period['scope_id'], 'a' => $period['starts_on'], 'b' => $period['ends_on']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** The ledger rows that landed in this period from a closed one (the trigger's "late:" note), newest first. */
function period_late_rows(PDO $pdo, int $periodId, int $limit = 100): array
{
    $p = find_period($pdo, $periodId);
    if ($p === null) { return []; }
    $st = $pdo->prepare("SELECT t.*, i.name AS ingredient, i.base_unit_code FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id
                          WHERE t.scope_id = :s AND t.business_date BETWEEN :a AND :b AND t.note LIKE '%late:%' ORDER BY t.occurred_at DESC, t.transaction_id DESC LIMIT " . (int) $limit);
    $st->execute(['s' => $p['scope_id'], 'a' => $p['starts_on'], 'b' => $p['ends_on']]);
    return array_map(static function (array $r): array {
        $r = stock_ints($r, ['transaction_id', 'ingredient_id', 'storage_area_id', 'reference_id']);
        $r['late'] = late_note_parts((string) $r['note']);
        return $r;
    }, $st->fetchAll());
}

/** What the trigger wrote: "late: dated 2026-10-02 in closed period 40" → {dated, period_no}; null when the note says nothing of the kind. */
function late_note_parts(string $note): ?array
{
    return preg_match('/late: dated (\d{4}-\d{2}-\d{2}) in closed period (\d+)/', $note, $m) ? ['dated' => $m[1], 'period_no' => (int) $m[2]] : null;
}

/** The counts that frame a period: its opening and closing counts, and every approved count inside it. */
function period_counts(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT c.count_id, c.number, c.kind, c.as_of, c.status, c.total_variance_value FROM mcp_inventory_counts c WHERE c.scope_id = :s AND (c.count_id IN (:o, :c)
          OR (c.status = 'approved' AND c.as_of > ri_site_moment(:s2, :a::date, false) AND c.as_of <= ri_site_moment(:s3, :b::date, true))) ORDER BY c.as_of, c.count_id");
    $st->execute(['s' => $period['scope_id'], 'o' => $period['opening_count_id'] ?? 0, 'c' => $period['closing_count_id'] ?? 0, 's2' => $period['scope_id'], 's3' => $period['scope_id'], 'a' => $period['starts_on'], 'b' => $period['ends_on']]);
    return array_map(static function (array $r) use ($period): array {
        $r = stock_ints($r, ['count_id']);
        $r['role'] = $r['count_id'] === ($period['closing_count_id'] ?? null) ? 'closing' : ($r['count_id'] === ($period['opening_count_id'] ?? null) ? 'opening' : 'inside');
        return $r;
    }, $st->fetchAll());
}

// ------------------------------------------------------------------------------------------------ calendars
function calendars(PDO $pdo): array
{
    return array_map(static function (array $c): array { $c = stock_ints($c, ['calendar_id', 'week_starts']); $c['is_default'] = (bool) $c['is_default']; return $c; }, $pdo->query('SELECT * FROM mcp_costing_calendars ORDER BY is_default DESC, name')->fetchAll());
}

function find_calendar(PDO $pdo, int $id): ?array
{
    foreach (calendars($pdo) as $c) { if ($c['calendar_id'] === $id) { return $c; } }
    return null;
}

/** The periods a calendar would make for a fiscal year — ri_calendar_periods(), previewed before the button. [{no, starts_on, ends_on, days}] */
function calendar_preview(PDO $pdo, int $calendarId, int $fiscalYear): array
{
    $st = $pdo->prepare('SELECT no, starts_on, ends_on, (ends_on - starts_on + 1) AS days FROM ri_calendar_periods(:c, :y) ORDER BY no');
    $st->execute(['c' => $calendarId, 'y' => $fiscalYear]);
    return array_map(static fn (array $r): array => ['no' => (int) $r['no'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'days' => (int) $r['days']], $st->fetchAll());
}

/** How many periods of a calendar's year this site already has. */
function calendar_year_made(PDO $pdo, int $scopeId, int $calendarId, int $fiscalYear): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_periods WHERE scope_id = :s AND calendar_id = :c AND fiscal_year = :y', ['s' => $scopeId, 'c' => $calendarId, 'y' => $fiscalYear]);
}

// ------------------------------------------------------------------------------------------------ COGS
/**
 * COGS by period for a fiscal year: each period's figures (the open one live) with the variance, the year's totals and — for a weekly calendar —
 * a subtotal per calendar month (DECISION 6). Walled: every value is null for a caller who sees no cost.
 */
function cogs_by_period(PDO $pdo, int $scopeId, int $fiscalYear): array
{
    $rows = [];
    $sum = ['food_sales' => 0.0, 'cogs_value' => 0.0, 'theoretical_value' => 0.0, 'waste_value' => 0.0];
    $months = [];
    $sees = sees_cost($scopeId);
    $today = site_today($scopeId);
    $notBegun = 0;
    foreach (periods($pdo, $scopeId, $fiscalYear) as $p) {
        if ($p['status'] === 'open' && $p['starts_on'] > $today) { $notBegun++; continue; }      // a period that has not begun has nothing to show yet
        $f = period_figures($pdo, $p);
        $row = ['period_id' => $p['period_id'], 'no' => $p['no'], 'label' => $p['label'], 'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'], 'status' => $p['status'], 'calendar_kind' => $p['calendar_kind'], 'live' => $f['live']]
            + array_intersect_key($f, array_flip(['food_sales', 'cogs_value', 'theoretical_value', 'waste_value', 'food_cost_pct', 'theoretical_pct']));
        $row['variance_value'] = $f['cogs_value'] === null ? null : number_format((float) $f['cogs_value'] - (float) $f['theoretical_value'], 2, '.', '');
        $row['variance_points'] = $f['food_cost_pct'] === null || $f['theoretical_pct'] === null ? null : number_format((float) $f['food_cost_pct'] - (float) $f['theoretical_pct'], 2, '.', '');
        $rows[] = $row;
        if ($sees) {
            foreach ($sum as $k => $_) { $sum[$k] += (float) $f[$k]; }
            $m = (new DateTimeImmutable($p['starts_on']))->format('Y-m');
            $months[$m] ??= ['month' => $m, 'label' => (new DateTimeImmutable($p['starts_on']))->format('F Y'), 'food_sales' => 0.0, 'cogs_value' => 0.0, 'theoretical_value' => 0.0, 'waste_value' => 0.0, 'periods' => 0];
            foreach ($sum as $k => $_) { $months[$m][$k] += (float) $f[$k]; }
            $months[$m]['periods']++;
        }
    }
    $fmt = static function (array $t) use ($sees): array {
        if (!$sees) { return ['food_sales' => null, 'cogs_value' => null, 'theoretical_value' => null, 'waste_value' => null, 'food_cost_pct' => null, 'theoretical_pct' => null, 'variance_value' => null, 'variance_points' => null]; }
        foreach (['food_sales', 'cogs_value', 'theoretical_value', 'waste_value'] as $k) { $t[$k] = number_format($t[$k], 2, '.', ''); }
        $t['food_cost_pct'] = period_pct($t['cogs_value'], $t['food_sales']);
        $t['theoretical_pct'] = period_pct($t['theoretical_value'], $t['food_sales']);
        $t['variance_value'] = number_format((float) $t['cogs_value'] - (float) $t['theoretical_value'], 2, '.', '');
        $t['variance_points'] = $t['food_cost_pct'] === null || $t['theoretical_pct'] === null ? null : number_format((float) $t['food_cost_pct'] - (float) $t['theoretical_pct'], 2, '.', '');
        return $t;
    };
    return ['fiscal_year' => $fiscalYear, 'not_begun' => $notBegun, 'rows' => $rows, 'totals' => $fmt($sum), 'months' => array_values(array_map($fmt, $months)), 'withheld' => !$sees];
}

/**
 * The COGS chart: one pair of bars per period — actual and theoretical cost of goods — as an inline SVG (no script, no library), ≤ 600 px wide and
 * scaling to its container; the two neutrals of the design system's data palette; every bar carries its figure in a <title>. '' when nothing is drawn.
 */
function cogs_chart_svg(array $rows): string
{
    $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['cogs_value'] !== null));
    if ($rows === []) { return ''; }
    $max = max(1.0, ...array_map(static fn (array $r): float => max((float) $r['cogs_value'], (float) $r['theoretical_value']), $rows));
    $n = count($rows);
    $w = 600; $h = 220; $left = 44; $bottom = 28; $top = 12; $plotH = $h - $bottom - $top; $plotW = $w - $left - 8;
    $slot = $plotW / $n; $bar = max(2.0, min(18.0, $slot / 2.6));
    $svg = '<svg class="cogs-chart-svg" id="cogs-chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Cost of goods sold by period, actual beside theoretical" xmlns="http://www.w3.org/2000/svg">';
    foreach ([0.0, 0.5, 1.0] as $g) {
        $y = $top + $plotH * (1 - $g);
        $svg .= '<line class="cogs-grid" x1="' . $left . '" x2="' . ($w - 8) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/><text class="cogs-axis" x="' . ($left - 4) . '" y="' . round($y + 3, 1) . '" text-anchor="end">$' . e(number_format($max * $g, 0)) . '</text>';
    }
    foreach ($rows as $i => $r) {
        $cx = $left + $slot * ($i + 0.5);
        foreach (['cogs_value' => ['cogs-bar-actual', 'actual', -1], 'theoretical_value' => ['cogs-bar-theoretical', 'theoretical', 0]] as $k => [$cls, $word, $off]) {
            $hh = $plotH * (float) $r[$k] / $max;
            $x = $cx + ($off === -1 ? -$bar - 0.5 : 0.5);
            $svg .= '<rect class="' . $cls . '" x="' . round($x, 1) . '" y="' . round($top + $plotH - $hh, 1) . '" width="' . round($bar, 1) . '" height="' . round($hh, 1) . '"><title>' . e($r['label'] . ' — ' . $word . ' $' . number_format((float) $r[$k], 2)) . '</title></rect>';
        }
        $svg .= '<text class="cogs-axis" x="' . round($cx, 1) . '" y="' . ($h - 10) . '" text-anchor="middle">' . ($n > 30 && $i % 2 ? '' : (int) $r['no']) . '</text>';
    }
    return $svg . '</svg>';
}
