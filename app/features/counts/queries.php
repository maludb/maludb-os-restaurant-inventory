<?php
declare(strict_types=1);

/**
 * The counts' reads (counts.md "Query functions"; signatures fixed — Phase 4's tools `counts`, `get_count`, `count_lines`, `count_variance`,
 * `areas_skipped`, `count_sheets` and `containers` call these). Every row comes through an mcp_* view, so the site, the blind rule (a blind
 * open count hides the book figure and the variance from the counter) and the cost wall are the database's: a figure a caller may not see
 * arrives null and is passed through as null. A quantity in a count unit is the database's (ri_from_base); PHP sums and converts nothing.
 */
require_once dirname(__DIR__) . '/stock/queries.php';

const COUNT_PAGE = 50;
const COUNT_KINDS = ['full' => 'Full', 'cycle' => 'Cycle', 'spot' => 'Spot'];
const COUNT_STATUS_CHIPS = ['open' => 'warning', 'review' => 'info', 'recount' => 'danger', 'approved' => 'success', 'discarded' => 'dark'];
const COUNT_STATUS_WORDS = ['open' => 'open', 'review' => 'in review', 'recount' => 'recount', 'approved' => 'approved', 'discarded' => 'discarded'];
const COUNT_LINE_CHIPS = ['pending' => 'secondary', 'counted' => 'success', 'zero' => 'secondary', 'skipped' => 'dark'];
const COUNT_LINE_WORDS = ['pending' => 'pending', 'counted' => 'counted', 'zero' => '0', 'skipped' => 'skipped'];
const SHEET_KIND_CHIPS = ['full' => 'primary', 'cycle' => 'info', 'spot' => 'secondary'];
/** The schema's own defaults for an ingredient with no tolerance of its own (db/012 ri_count_review) — shown, never applied here. */
const COUNT_TOLERANCE_PCT = 5;
const COUNT_TOLERANCE_VALUE = 10;
/** The unit a person's loose count is offered in by default, per their display units and the ingredient's class (display only). */
const COUNT_LOOSE_UNIT = ['metric' => ['weight' => 'g', 'volume' => 'ml', 'each' => 'each'], 'imperial' => ['weight' => 'lb', 'volume' => 'floz', 'each' => 'each']];

/** Counts are in their statuses' words and chips. */
function count_status_words(string $s): string { return COUNT_STATUS_WORDS[$s] ?? $s; }

// ------------------------------------------------------------------------------------------------ counts
/** A count's or line's name and creator columns, shared by the list and the single read. */
const COUNT_SELECT = 'SELECT c.*, sh.name AS sheet_name, sm.display_name AS started_by_name, am.display_name AS approved_by_name,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id) AS lines,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND l.state <> \'pending\') AS lines_done,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND l.state = \'pending\') AS lines_pending,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND l.state = \'skipped\') AS lines_skipped,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND l.over_tolerance AND l.recount_qty_base IS NULL) AS lines_awaiting_recount,
        (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND (l.over_tolerance OR l.recount_qty_base IS NOT NULL)) AS lines_over
      FROM mcp_inventory_counts c
      LEFT JOIN mcp_count_sheets sh ON sh.count_sheet_id = c.sheet_id
      LEFT JOIN mcp_members sm ON sm.member_id = c.started_by
      LEFT JOIN mcp_members am ON am.member_id = c.approved_by';

function count_row_decode(array $r): array
{
    $r = stock_ints($r, ['count_id', 'scope_id', 'sheet_id', 'started_by', 'approved_by', 'lines', 'lines_done', 'lines_pending', 'lines_skipped', 'lines_awaiting_recount', 'lines_over']);
    $r['blind'] = (bool) $r['blind'];
    return $r;
}

/**
 * The counts at a site: ['rows', 'total']. $filters: status (a list or a comma string), kind, from / to (dates in the site's zone), q (a number).
 * Over tolerance on a row = the lines flagged over tolerance or recounted (a recounted line was over).
 */
function counts(PDO $pdo, int $scopeId, array $filters = [], int $limit = COUNT_PAGE, int $offset = 0): array
{
    $where = ['c.scope_id = :s'];
    $args = ['s' => $scopeId];
    $status = $filters['status'] ?? [];
    $status = is_array($status) ? $status : array_filter(array_map('trim', explode(',', (string) $status)));
    $status = array_values(array_intersect($status, array_keys(COUNT_STATUS_WORDS)));
    if ($status !== []) { $where[] = "c.status = ANY ('" . pg_array_literal($status) . "'::text[])"; }
    if (($filters['kind'] ?? '') !== '' && isset(COUNT_KINDS[$filters['kind']])) { $where[] = 'c.kind = :k'; $args['k'] = $filters['kind']; }
    $tz = site_timezone($scopeId);
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = (string) ($filters[$k] ?? '');
        if ($d !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false) { $where[] = "(c.as_of AT TIME ZONE :tz_$k)::date $op :$k"; $args[$k] = $d; $args['tz_' . $k] = $tz; }
    }
    if (($filters['q'] ?? '') !== '') { $where[] = 'c.number ILIKE :q'; $args['q'] = '%' . $filters['q'] . '%'; }
    $w = ' WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_inventory_counts c' . $w);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(COUNT_SELECT . $w . ' ORDER BY c.as_of DESC, c.count_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return ['rows' => array_map('count_row_decode', $st->fetchAll()), 'total' => $total];
}

function find_count(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(COUNT_SELECT . ' WHERE c.count_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : count_row_decode($r);
}

/** Where a count's number leads a person: filling → the sheet; reading → the review or the history. */
function count_url(array $c, bool $mayFill): string
{
    $id = (int) $c['count_id'];
    return match ($c['status']) {
        'open' => $mayFill ? '/counts/' . $id : '/counts/' . $id . '/review',
        'review', 'recount' => '/counts/' . $id . '/review',
        default => '/counts/' . $id . '/history',
    };
}

// ------------------------------------------------------------------------------------------------ lines
/** The unit a line is shown in: its own count unit when that converts, else the ingredient's base (a retired unit must not break a page). */
const COUNT_LINE_SELECT = 'SELECT l.count_line_id, l.count_id, l.storage_area_id, l.ingredient_id, l.shelf_order, l.entries, l.gross_weight_g, l.container_id, l.counted_qty_base, l.state,
        l.recount_qty_base, l.recount_by, l.counted_by, l.counted_at, l.over_tolerance, l.note, l.system_qty_base, l.variance_base, l.variance_value,
        i.code, i.name, i.base_unit_code, i.measure_class, i.variance_tolerance_pct, i.variance_tolerance_value, a.name AS area, a.walk_order, du.unit AS count_unit,
        ri_from_base(l.ingredient_id, l.system_qty_base, du.unit) AS system_qty_count, ri_from_base(l.ingredient_id, l.counted_qty_base, du.unit) AS counted_qty_count,
        ri_from_base(l.ingredient_id, l.recount_qty_base, du.unit) AS recount_qty_count, ri_from_base(l.ingredient_id, l.variance_base, du.unit) AS variance_qty_count,
        cm.display_name AS counted_by_name, rm.display_name AS recount_by_name,
        (SELECT c2.container_id FROM mcp_containers c2 WHERE c2.ingredient_id = l.ingredient_id AND c2.is_default AND c2.active LIMIT 1) AS default_container_id,
        (SELECT count(*) FROM mcp_containers c3 WHERE c3.ingredient_id = l.ingredient_id AND c3.active) AS container_count
      FROM mcp_inventory_count_lines l
      JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id
      JOIN mcp_storage_areas a ON a.storage_area_id = l.storage_area_id
      CROSS JOIN LATERAL (SELECT CASE WHEN l.count_unit_code IS NOT NULL AND (EXISTS (SELECT 1 FROM mcp_ingredient_units u WHERE u.ingredient_id = l.ingredient_id AND u.unit_code = l.count_unit_code AND u.active)
                                       OR l.count_unit_code = i.base_unit_code OR EXISTS (SELECT 1 FROM mcp_units g WHERE g.unit_code = l.count_unit_code AND g.active AND g.measure_class = i.measure_class))
                                   THEN l.count_unit_code ELSE i.base_unit_code END AS unit) du
      LEFT JOIN mcp_members cm ON cm.member_id = l.counted_by
      LEFT JOIN mcp_members rm ON rm.member_id = l.recount_by';

function count_line_decode(array $r): array
{
    $r = stock_ints($r, ['count_line_id', 'count_id', 'storage_area_id', 'ingredient_id', 'shelf_order', 'container_id', 'counted_by', 'recount_by', 'default_container_id', 'container_count']);
    $r['entries'] = is_string($r['entries']) ? (json_decode($r['entries'], true) ?: []) : ($r['entries'] ?? []);
    $r['over_tolerance'] = (bool) $r['over_tolerance'];
    return $r;
}

/** A count's lines in walk and shelf order, one area or all, optionally only the lines over tolerance. */
function count_lines(PDO $pdo, int $countId, ?int $areaId = null, bool $overOnly = false): array
{
    $sql = COUNT_LINE_SELECT . ' WHERE l.count_id = :c' . ($areaId !== null ? ' AND l.storage_area_id = :a' : '') . ($overOnly ? ' AND (l.over_tolerance OR l.recount_qty_base IS NOT NULL)' : '')
        . ' ORDER BY a.walk_order, a.name, l.shelf_order, l.count_line_id';
    $st = $pdo->prepare($sql);
    $st->execute($areaId !== null ? ['c' => $countId, 'a' => $areaId] : ['c' => $countId]);
    return array_map('count_line_decode', $st->fetchAll());
}

function find_count_line(PDO $pdo, int $lineId): ?array
{
    $st = $pdo->prepare(COUNT_LINE_SELECT . ' WHERE l.count_line_id = :id');
    $st->execute(['id' => $lineId]);
    $r = $st->fetch();
    return $r === false ? null : count_line_decode($r);
}

/** The areas a count has lines in, in walk order, each with its lines and how many are done: [{storage_area_id, name, walk_order, lines, done, pending}]. */
function count_areas(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT a.storage_area_id, a.name, a.walk_order, count(*) AS lines, count(*) FILTER (WHERE l.state <> \'pending\') AS done, count(*) FILTER (WHERE l.state = \'pending\') AS pending
        FROM mcp_inventory_count_lines l JOIN mcp_storage_areas a ON a.storage_area_id = l.storage_area_id WHERE l.count_id = :c GROUP BY a.storage_area_id, a.name, a.walk_order ORDER BY a.walk_order, a.name');
    $st->execute(['c' => $countId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['storage_area_id', 'walk_order', 'lines', 'done', 'pending']), $st->fetchAll());
}

/** {lines, pending, counted, zero, skipped} of a count. */
function count_progress(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT count(*) AS lines, count(*) FILTER (WHERE state = \'pending\') AS pending, count(*) FILTER (WHERE state = \'counted\') AS counted,
        count(*) FILTER (WHERE state = \'zero\') AS zero, count(*) FILTER (WHERE state = \'skipped\') AS skipped FROM mcp_inventory_count_lines WHERE count_id = :c');
    $st->execute(['c' => $countId]);
    return array_map('intval', $st->fetch());
}

/**
 * The review's rows: every line of a count with its book figure, counted figure and variance (ri_count_variance is the referee that flagged
 * them; the figures come through the lines view, so a blind open count's and a cost-walled figure arrive null). In walk and shelf order.
 */
function count_variance(PDO $pdo, int $countId, bool $overOnly = false): array
{
    $st = $pdo->prepare(COUNT_LINE_SELECT . ' JOIN ri_count_variance(:v) cv ON cv.line_id = l.count_line_id WHERE l.count_id = :c' . ($overOnly ? ' AND (l.over_tolerance OR l.recount_qty_base IS NOT NULL)' : '')
        . ' ORDER BY a.walk_order, a.name, l.shelf_order, l.count_line_id');
    $st->execute(['v' => $countId, 'c' => $countId]);
    return array_map('count_line_decode', $st->fetchAll());
}

/** The areas a count skipped (every active area with no counted line): [{storage_area_id, name, lines_skipped}]. */
function count_areas_skipped(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT s.storage_area_id, s.name, s.lines_skipped FROM ri_count_areas_skipped(:c) s JOIN mcp_storage_areas a ON a.storage_area_id = s.storage_area_id ORDER BY a.walk_order, a.name');
    $st->execute(['c' => $countId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['storage_area_id', 'lines_skipped']), $st->fetchAll());
}

/** The corrections an approved count posted (mcp_inventory_transactions where reference_kind = 'count'): cost walled by the view. */
function count_corrections(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT t.transaction_id, t.ingredient_id, t.storage_area_id, t.qty_base, t.unit_cost_per_base, t.value, t.occurred_at, t.note, i.name AS ingredient, i.code, i.base_unit_code, a.name AS area,
            ri_from_base(t.ingredient_id, t.qty_base, COALESCE((SELECT u.unit_code FROM mcp_ingredient_units u WHERE u.ingredient_id = t.ingredient_id AND u.is_default_count AND u.active), i.base_unit_code)) AS qty_count,
            COALESCE((SELECT u.unit_code FROM mcp_ingredient_units u WHERE u.ingredient_id = t.ingredient_id AND u.is_default_count AND u.active), i.base_unit_code) AS count_unit
        FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id
        WHERE t.reference_kind = \'count\' AND t.reference_id = :c AND t.txn_type = \'count_correction\' ORDER BY a.walk_order, i.name, t.transaction_id');
    $st->execute(['c' => $countId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['transaction_id', 'ingredient_id', 'storage_area_id']), $st->fetchAll());
}

/** The variance of a count by ingredient category, from the lines: [{category, kind, lines, over, variance_base_lines, variance_value}] (value walled by the lines view). */
function count_variance_by_category(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT c.name AS category, c.kind, count(*) AS lines, count(*) FILTER (WHERE l.over_tolerance OR l.recount_qty_base IS NOT NULL) AS over,
            count(*) FILTER (WHERE l.state IN (\'counted\', \'zero\') AND COALESCE(l.variance_base, 0) <> 0) AS lines_off, sum(l.variance_value) AS variance_value
        FROM mcp_inventory_count_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id JOIN mcp_ingredient_categories c ON c.category_id = i.category_id
        WHERE l.count_id = :c GROUP BY c.name, c.kind, c.sort_order ORDER BY c.sort_order, c.name');
    $st->execute(['c' => $countId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['lines', 'over', 'lines_off']), $st->fetchAll());
}

/** Who counted what: [{member_id, name, lines, recounts}] — the counts of lines by counted_by and recount_by. */
function count_counters(PDO $pdo, int $countId): array
{
    $st = $pdo->prepare('SELECT m.member_id, m.display_name AS name, count(*) FILTER (WHERE l.counted_by = m.member_id) AS lines, count(*) FILTER (WHERE l.recount_by = m.member_id) AS recounts
        FROM mcp_inventory_count_lines l JOIN mcp_members m ON m.member_id IN (l.counted_by, l.recount_by) WHERE l.count_id = :c GROUP BY m.member_id, m.display_name ORDER BY lines DESC, name');
    $st->execute(['c' => $countId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['member_id', 'lines', 'recounts']), $st->fetchAll());
}

// ------------------------------------------------------------------------------------------------ sheets and containers
function sheet_row_decode(array $r): array
{
    $r = stock_ints($r, ['count_sheet_id', 'scope_id', 'created_by', 'lines', 'areas', 'last_count_id']);
    $r['active'] = (bool) $r['active'];
    return $r;
}

/** The count sheets of a site: [{count_sheet_id, name, kind, active, lines, areas, last_count_*}] — the last count made from each. */
function count_sheets(PDO $pdo, int $scopeId, bool $includeInactive = false): array
{
    $st = $pdo->prepare('SELECT s.count_sheet_id, s.scope_id, s.name, s.kind, s.active, s.created_by, s.created_at,
            (SELECT count(*) FROM count_sheet_lines l WHERE l.sheet_id = s.count_sheet_id) AS lines,
            (SELECT count(DISTINCT l.storage_area_id) FROM count_sheet_lines l WHERE l.sheet_id = s.count_sheet_id) AS areas,
            (SELECT string_agg(a.name, \', \' ORDER BY a.walk_order, a.name) FROM mcp_storage_areas a WHERE a.storage_area_id IN (SELECT l.storage_area_id FROM count_sheet_lines l WHERE l.sheet_id = s.count_sheet_id)) AS area_names,
            lc.count_id AS last_count_id, lc.number AS last_count_number, lc.as_of AS last_count_as_of, lc.status AS last_count_status
        FROM mcp_count_sheets s LEFT JOIN LATERAL (SELECT c.count_id, c.number, c.as_of, c.status FROM mcp_inventory_counts c WHERE c.sheet_id = s.count_sheet_id ORDER BY c.as_of DESC, c.count_id DESC LIMIT 1) lc ON true
        WHERE s.scope_id = :s' . ($includeInactive ? '' : ' AND s.active') . ' ORDER BY s.active DESC, s.name');
    $st->execute(['s' => $scopeId]);
    return array_map('sheet_row_decode', $st->fetchAll());
}

function find_count_sheet(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT s.count_sheet_id, s.scope_id, s.name, s.kind, s.active, s.created_by, s.created_at,
            (SELECT count(*) FROM count_sheet_lines l WHERE l.sheet_id = s.count_sheet_id) AS lines,
            (SELECT count(DISTINCT l.storage_area_id) FROM count_sheet_lines l WHERE l.sheet_id = s.count_sheet_id) AS areas
        FROM mcp_count_sheets s WHERE s.count_sheet_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : sheet_row_decode($r);
}

/** The sheet's lines grouped by storage area in walk order (every active area of the site is listed, empty ones too): [{storage_area_id, name, walk_order, lines: [...]}]. */
function count_sheet_areas(PDO $pdo, int $sheetId): array
{
    $sheet = find_count_sheet($pdo, $sheetId);
    if ($sheet === null) { return []; }
    $areas = [];
    foreach (find_storage_areas($pdo, (int) $sheet['scope_id']) as $a) { $areas[$a['storage_area_id']] = ['storage_area_id' => $a['storage_area_id'], 'name' => $a['name'], 'walk_order' => (int) $a['walk_order'], 'kind' => $a['kind'], 'lines' => []]; }
    $st = $pdo->prepare('SELECT l.id AS sheet_line_id, l.sheet_id, l.storage_area_id, l.ingredient_id, l.shelf_order, l.count_unit_code, i.name, i.code, i.base_unit_code, i.active AS ingredient_active,
            (SELECT u.unit_code FROM mcp_ingredient_units u WHERE u.ingredient_id = l.ingredient_id AND u.is_default_count AND u.active) AS default_count_unit
        FROM count_sheet_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id WHERE l.sheet_id = :s ORDER BY l.shelf_order, l.id');
    $st->execute(['s' => $sheetId]);
    foreach ($st->fetchAll() as $l) {
        $l = stock_ints($l, ['sheet_line_id', 'sheet_id', 'storage_area_id', 'ingredient_id', 'shelf_order']);
        if (!isset($areas[$l['storage_area_id']])) {            // a line in an archived area: still shown, so it can be removed
            $a = find_storage_area($pdo, $l['storage_area_id']);
            $areas[$l['storage_area_id']] = ['storage_area_id' => $l['storage_area_id'], 'name' => ($a['name'] ?? 'Area') . ' (archived)', 'walk_order' => (int) ($a['walk_order'] ?? 9999), 'kind' => $a['kind'] ?? 'other', 'lines' => []];
        }
        $areas[$l['storage_area_id']]['lines'][] = $l;
    }
    uasort($areas, static fn (array $a, array $b): int => [$a['walk_order'], $a['name']] <=> [$b['walk_order'], $b['name']]);
    return array_values($areas);
}

/** The units a sheet line (or a count entry) may be counted in: the ingredient's own count and purchase units, the base unit, then the class's other units. [{code, label}] */
function sheet_unit_options(PDO $pdo, int $ingredientId): array
{
    static $cache = [];
    return $cache[$ingredientId] ??= array_map(static fn (array $o): array => ['code' => $o['code'], 'label' => $o['label']], unit_options_for($pdo, $ingredientId, 'count'));
}

/** The units a count line offers, with the defaults for the whole and loose fields: ['options' => [{code, label}], 'whole' => code, 'loose' => code]. */
function count_line_units(PDO $pdo, array $line, string $displayUnits): array
{
    $opts = sheet_unit_options($pdo, (int) $line['ingredient_id']);
    $codes = array_column($opts, 'code');
    $whole = in_array($line['count_unit'], $codes, true) ? $line['count_unit'] : ($line['base_unit_code']);
    $loose = COUNT_LOOSE_UNIT[$displayUnits][$line['measure_class']] ?? $line['base_unit_code'];
    if (!in_array($loose, $codes, true)) { $loose = $line['base_unit_code']; }
    return ['options' => $opts, 'whole' => $whole, 'loose' => $loose];
}

/** Containers: the bottles and kegs weighed, with their ingredient: [{container_id, ingredient_id, ingredient, name, full_weight_g, …}]. */
function containers(PDO $pdo, ?int $ingredientId = null): array
{
    $st = $pdo->prepare('SELECT c.container_id, c.ingredient_id, c.name, c.full_weight_g, c.empty_weight_g, c.pourer_tare_g, c.volume_ml, c.is_default, c.active, i.name AS ingredient, i.code
        FROM mcp_containers c JOIN mcp_ingredients i ON i.ingredient_id = c.ingredient_id' . ($ingredientId !== null ? ' WHERE c.ingredient_id = :i' : '') . ' ORDER BY i.name, c.is_default DESC, c.name');
    $st->execute($ingredientId !== null ? ['i' => $ingredientId] : []);
    return array_map(static function (array $r): array { $r = stock_ints($r, ['container_id', 'ingredient_id']); $r['is_default'] = (bool) $r['is_default']; $r['active'] = (bool) $r['active']; return $r; }, $st->fetchAll());
}

function find_container(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT c.container_id, c.ingredient_id, c.name, c.full_weight_g, c.empty_weight_g, c.pourer_tare_g, c.volume_ml, c.is_default, c.active, i.name AS ingredient, i.code, i.measure_class
        FROM mcp_containers c JOIN mcp_ingredients i ON i.ingredient_id = c.ingredient_id WHERE c.container_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r = stock_ints($r, ['container_id', 'ingredient_id']);
    $r['is_default'] = (bool) $r['is_default'];
    $r['active'] = (bool) $r['active'];
    return $r;
}

/** An ingredient's default container, or null. */
function container_for(PDO $pdo, int $ingredientId): ?array
{
    foreach (containers($pdo, $ingredientId) as $c) { if ($c['is_default'] && $c['active']) { return $c; } }
    return null;
}

/** The containers an ingredient has, for a select on a line: [{container_id, name, is_default}] (active only). */
function containers_of(PDO $pdo, int $ingredientId): array
{
    return array_values(array_filter(containers($pdo, $ingredientId), static fn (array $c): bool => $c['active']));
}

// ------------------------------------------------------------------------------------------------ the default moment
/**
 * The form's default "as of": the site's sales cut that ends the business day we are in — "tonight at 04:00" — as an ISO local string in the
 * site's zone (YYYY-MM-DDTHH:MM). Before the cut time (site clock) the business day is still yesterday's, so its cut is this morning's. The day's
 * depletion is dated at the cut, so a count as of "now" before the cut would miss it. $now: for the proof.
 */
function default_count_as_of(int $scopeId, ?DateTimeImmutable $now = null): string
{
    $pdo = db();
    $tz = new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $scopeId]) ?? 'UTC'));
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);
    $cut = (string) (one_value($pdo, 'SELECT to_char(COALESCE((SELECT sales_cut_time FROM site_settings WHERE scope_id = :s), \'04:00\'::time), \'HH24:MI:SS\')', ['s' => $scopeId]) ?? '04:00:00');
    $date = $now->format('H:i:s') < $cut ? $now->modify('-1 day') : $now;
    $moment = (string) one_value($pdo, 'SELECT to_char(ri_site_moment(:s, CAST(:d AS date), true) AT TIME ZONE :tz, \'YYYY-MM-DD"T"HH24:MI\')', ['s' => $scopeId, 'd' => $date->format('Y-m-d'), 'tz' => $tz->getName()]);
    return $moment;
}

/** The business-wide blind-counts setting. */
function blind_counts_setting(PDO $pdo): bool
{
    return (bool) (one_value($pdo, 'SELECT blind_counts FROM ri_settings WHERE id = 1') ?? true);
}

/** The members holding a right at a site (humans, active): [member_id] — who a notification goes to. */
function members_holding(PDO $pdo, string $right, int $scopeId): array
{
    $st = $pdo->prepare("SELECT DISTINCT sr.member_id FROM member_site_roles sr JOIN members m ON m.id = sr.member_id AND m.status = 'active' AND m.member_kind = 'human'
        WHERE sr.scope_id = :s AND ri_member_has_right(sr.member_id, :r, :s2) ORDER BY sr.member_id");
    $st->execute(['s' => $scopeId, 'r' => $right, 's2' => $scopeId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
