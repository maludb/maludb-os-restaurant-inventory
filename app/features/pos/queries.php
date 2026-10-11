<?php
declare(strict_types=1);

/**
 * The POS slice's reads (pos.md "Query functions" — signatures fixed; Phase 4's tools find_sources, get_source, source_pulls,
 * sales_by_day, sales_by_item, depletion_runs, get_depletion_run, pos_health, unmapped_plus call these). Every read goes through the
 * mcp_* views (the site and cost walls) or the db/016 functions; a credential's ciphertext is never selected.
 */
require_once dirname(__DIR__) . '/units/queries.php';

const POS_PAGE = 30;
const SOURCE_INTAKES = ['upload' => 'upload', 'email' => 'email', 'sftp' => 'SFTP'];

function mapping_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, name, mapping, is_builtin, updated_at FROM mcp_sales_file_mappings ORDER BY is_builtin DESC, key')->fetchAll();
    foreach ($rows as &$r) {
        $r['mapping'] = json_decode((string) $r['mapping'], true) ?: [];
        $r['is_builtin'] = (bool) $r['is_builtin'];
    }
    return $rows;
}

/** One mapping by key — from the base table: a mapping holds no secret and the worker (no member) reads it too. */
function mapping_for(PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT key, name, mapping, is_builtin, updated_at FROM sales_file_mappings WHERE key = :k');
    $st->execute(['k' => $key]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['mapping'] = json_decode((string) $r['mapping'], true) ?: [];
    $r['is_builtin'] = (bool) $r['is_builtin'];
    return $r;
}

/** The base row of a source the caller may see (held site), settings decoded; null otherwise. */
function source_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT s.* FROM sources s WHERE s.id = :id AND s.id IN (SELECT source_id FROM mcp_sources)');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** One source for a screen: the view's row (settings for an admin only), the health, the credential's label and last four (an admin's). */
function source_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_sources WHERE source_id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch();
    if ($s === false) {
        return null;
    }
    $base = source_row($pdo, $id);
    $s['settings'] = $s['settings'] === null ? null : (json_decode((string) $s['settings'], true) ?: []);
    $settingsAll = json_decode((string) ($base['settings'] ?? '{}'), true) ?: [];
    $s['intake'] = $s['connector'] === 'file' ? (string) ($settingsAll['intake'] ?? 'upload') : ($s['connector'] === 'manual' ? 'upload' : 'api');
    $s['mapping_key'] = $s['connector'] === 'file' ? (string) ($settingsAll['mapping_key'] ?? 'generic') : null;
    $s['location_id'] = $s['connector'] === 'square' ? (string) ($settingsAll['location_id'] ?? '') : null;
    $st = $pdo->prepare('SELECT credential_id, kind, label, last4, rotated_at, created_at FROM mcp_source_credentials WHERE source_id = :s ORDER BY credential_id DESC');
    $st->execute(['s' => $id]);
    $s['credentials'] = $st->fetchAll();
    $s['has_credential'] = $s['credential_id'] !== null;
    $s['health'] = pos_health_of($pdo, (int) $s['scope_id'])[(int) $s['source_id']] ?? null;
    $s['has_pulls'] = (int) one_value($pdo, 'SELECT count(*) FROM mcp_source_pulls WHERE source_id = :s', ['s' => $id]) > 0;
    $s['has_sales'] = (int) one_value($pdo, 'SELECT count(*) FROM mcp_pos_sales WHERE source_id = :s', ['s' => $id]) > 0;
    return $s;
}

/** The sources of a site as cards, each with its health. $f: status (active|paused|all). */
function source_list(PDO $pdo, int $scope, array $f): array
{
    $where = 'scope_id = :s';
    $status = $f['status'] ?? 'all';
    if ($status === 'active') {
        $where .= ' AND paused_at IS NULL AND active';
    } elseif ($status === 'paused') {
        $where .= ' AND paused_at IS NOT NULL';
    }
    $st = $pdo->prepare("SELECT source_id, scope_id, name, connector, schedule_minutes, last_ok_at, consecutive_failures, backoff_until, paused_at, paused_reason, active, credential_id FROM mcp_sources WHERE $where ORDER BY name");
    $st->execute(['s' => $scope]);
    $rows = $st->fetchAll();
    $health = pos_health_of($pdo, $scope);
    foreach ($rows as &$r) {
        $base = source_row($pdo, (int) $r['source_id']);
        $set = json_decode((string) ($base['settings'] ?? '{}'), true) ?: [];
        $r['intake'] = $r['connector'] === 'file' ? (string) ($set['intake'] ?? 'upload') : ($r['connector'] === 'manual' ? 'upload' : 'api');
        $r['health'] = $health[(int) $r['source_id']] ?? null;
    }
    return $rows;
}

/** ri_pos_health() keyed by source: days missing this week, unmapped PLUs and modifiers, pending runs. */
function pos_health_of(PDO $pdo, int $scope): array
{
    static $memo = [];
    if (isset($memo[$scope])) {
        return $memo[$scope];
    }
    $st = $pdo->prepare('SELECT * FROM ri_pos_health(:s)');
    $st->execute(['s' => $scope]);
    $out = [];
    foreach ($st->fetchAll() as $h) {
        $h['days_missing'] = pg_text_list((string) ($h['days_missing'] ?? ''));
        $out[(int) $h['source_id']] = $h;
    }
    return $memo[$scope] = $out;
}

function pos_health(PDO $pdo, int $scope): array
{
    return array_values(pos_health_of($pdo, $scope));
}

/** "{2026-10-05,2026-10-06}" → list. */
function pg_text_list(string $literal): array
{
    $literal = trim($literal, '{}');
    return $literal === '' ? [] : array_map(static fn ($v) => trim($v, '"'), explode(',', $literal));
}

function source_pulls(PDO $pdo, int $id, int $page, int $size = 20): array
{
    $st = $pdo->prepare('SELECT p.*, (SELECT count(*) FROM mcp_source_pulls x WHERE x.source_id = p.source_id) AS total,
                                (SELECT a.attachment_id FROM mcp_attachments a WHERE a.attachment_id = (SELECT sp.attachment_id FROM source_pulls sp WHERE sp.id = p.pull_id)) AS attachment_id,   -- the kept file (on the source; the pull names it)
                                (SELECT display_name FROM mcp_members m WHERE m.member_id = p.started_by) AS started_by_name
                           FROM mcp_source_pulls p WHERE p.source_id = :s ORDER BY p.started_at DESC, p.pull_id DESC LIMIT :l OFFSET :o');
    $st->execute(['s' => $id, 'l' => $size, 'o' => max(0, $page - 1) * $size]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['dates_seen'] = pg_text_list((string) $r['dates_seen']);
    }
    return $rows;
}

/** The days a source brought, with the live run's status. */
function source_days(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT s.business_date::text AS business_date, count(*) AS checks, sum(COALESCE(s.covers, 0)) AS covers, max(s.imported_at) AS imported_at,
                                (SELECT r.status FROM mcp_depletion_runs r WHERE r.scope_id = s.scope_id AND r.business_date = s.business_date AND r.status <> 'void' ORDER BY r.depletion_run_id DESC LIMIT 1) AS run_status
                           FROM mcp_pos_sales s WHERE s.source_id = :s GROUP BY s.scope_id, s.business_date ORDER BY s.business_date DESC LIMIT 60");
    $st->execute(['s' => $id]);
    return $st->fetchAll();
}

/** The unmapped PLUs this source brought, by what they sold (ri_unmapped_plus() for the site, this source's). */
function source_unmapped(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT u.* FROM ri_unmapped_plus((SELECT scope_id FROM mcp_sources WHERE source_id = :s)) u JOIN mcp_menu_items m ON m.menu_item_id = u.menu_item_id WHERE m.pos_source_id = :s2 ORDER BY u.qty_sold DESC, u.plu');
    $st->execute(['s' => $id, 's2' => $id]);
    return $st->fetchAll();
}

/** The file (or manual) sources of a site — the upload's select. */
function file_sources_for_site(PDO $pdo, int $scope): array
{
    $st = $pdo->prepare("SELECT source_id, name, connector, paused_at FROM mcp_sources WHERE scope_id = :s AND connector IN ('file', 'manual') AND active ORDER BY name");
    $st->execute(['s' => $scope]);
    return $st->fetchAll();
}

/** The days of a site in a window: checks, covers, net, comps, voids (ri_share_sales_by_day() folded to a day), the live run, due or not. */
function days_list(PDO $pdo, int $scope, string $from, string $to, int $page): array
{
    $st = $pdo->prepare("WITH d AS (SELECT business_date, sum(checks) AS checks, sum(COALESCE(covers, 0)) AS covers, sum(net) AS net, sum(comps) AS comps, sum(voids) AS voids
                                      FROM ri_share_sales_by_day(:s, :f::date, :t::date) GROUP BY business_date)
                         SELECT d.business_date::text AS business_date, d.checks, d.covers, d.net, d.comps, d.voids,
                                r.depletion_run_id AS run_id, r.status AS run_status, r.lines_unmapped, r.modifiers_unmapped, r.value AS run_value, r.run_no,
                                EXISTS (SELECT 1 FROM ri_depletion_due() x WHERE x.scope_id = :s2 AND x.business_date = d.business_date) AS due,
                                count(*) OVER () AS total
                           FROM d LEFT JOIN LATERAL (SELECT * FROM mcp_depletion_runs r WHERE r.scope_id = :s3 AND r.business_date = d.business_date ORDER BY (r.status <> 'void') DESC, r.depletion_run_id DESC LIMIT 1) r ON true
                          ORDER BY d.business_date DESC LIMIT :l OFFSET :o");
    $st->execute(['s' => $scope, 'f' => $from, 't' => $to, 's2' => $scope, 's3' => $scope, 'l' => POS_PAGE, 'o' => max(0, $page - 1) * POS_PAGE]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => (int) ($rows[0]['total'] ?? 0)];
}

/** One day's header: checks, covers, net, comps, voids, the day parts' rows, the live run. Null when the site sold nothing that day. */
function day_for(PDO $pdo, int $scope, string $date): ?array
{
    $st = $pdo->prepare('SELECT * FROM ri_share_sales_by_day(:s, :d::date, :d2::date)');
    $st->execute(['s' => $scope, 'd' => $date, 'd2' => $date]);
    $parts = $st->fetchAll();
    if ($parts === []) {
        return null;
    }
    $sum = static fn (string $k): float => array_sum(array_map(static fn ($p) => (float) ($p[$k] ?? 0), $parts));
    return ['business_date' => $date, 'checks' => (int) $sum('checks'), 'covers' => (int) $sum('covers'), 'net' => $sum('net'), 'comps' => $sum('comps'), 'voids' => $sum('voids'),
            'day_parts' => $parts, 'run' => day_run($pdo, $scope, $date), 'runs' => day_runs($pdo, $scope, $date),
            'due' => (bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM ri_depletion_due() WHERE scope_id = :s AND business_date = :d::date)', ['s' => $scope, 'd' => $date])];
}

function day_run(PDO $pdo, int $scope, string $date): ?array
{
    $st = $pdo->prepare("SELECT * FROM mcp_depletion_runs WHERE scope_id = :s AND business_date = :d::date ORDER BY (status <> 'void') DESC, depletion_run_id DESC LIMIT 1");
    $st->execute(['s' => $scope, 'd' => $date]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function day_runs(PDO $pdo, int $scope, string $date): array
{
    $st = $pdo->prepare('SELECT depletion_run_id, run_no, status, started_at, finished_at FROM mcp_depletion_runs WHERE scope_id = :s AND business_date = :d::date ORDER BY run_no');
    $st->execute(['s' => $scope, 'd' => $date]);
    return $st->fetchAll();
}

/** Sales by item for the day (the whole day, or one day part): item, PLU, qty, net, comps, mapped? */
function day_items(PDO $pdo, int $scope, string $date, ?int $dayPart): array
{
    $st = $pdo->prepare('SELECT sd.menu_item_id, m.plu, m.name, sd.qty, sd.net, sd.comps_qty, m.recipe_id, m.no_depletion, (SELECT r.name FROM mcp_recipes r WHERE r.recipe_id = m.recipe_id) AS recipe_name
                           FROM mcp_sales_daily sd JOIN mcp_menu_items m ON m.menu_item_id = sd.menu_item_id
                          WHERE sd.scope_id = :s AND sd.business_date = :d::date AND sd.day_part_id IS NOT DISTINCT FROM :p::bigint ORDER BY sd.qty DESC, m.name');
    $st->execute(['s' => $scope, 'd' => $date, 'p' => $dayPart]);
    $rows = $st->fetchAll();
    if ($rows === [] && $dayPart === null) {                                        // no run yet: read the lines themselves
        $st = $pdo->prepare('SELECT l.menu_item_id, m.plu, m.name, sum(l.qty) AS qty, sum(CASE WHEN l.comp THEN 0 ELSE COALESCE(l.line_total, 0) END) AS net, sum(CASE WHEN l.comp THEN l.qty ELSE 0 END) AS comps_qty,
                                    m.recipe_id, m.no_depletion, (SELECT r.name FROM mcp_recipes r WHERE r.recipe_id = m.recipe_id) AS recipe_name
                               FROM mcp_pos_sale_lines l JOIN mcp_menu_items m ON m.menu_item_id = l.menu_item_id
                              WHERE l.scope_id = :s AND l.business_date = :d::date AND NOT l.voided GROUP BY l.menu_item_id, m.plu, m.name, m.recipe_id, m.no_depletion ORDER BY 4 DESC, m.name');
        $st->execute(['s' => $scope, 'd' => $date]);
        $rows = $st->fetchAll();
    }
    return $rows;
}

function day_checks(PDO $pdo, int $scope, string $date): array
{
    $st = $pdo->prepare('SELECT s.*, (SELECT d.name FROM mcp_day_parts d WHERE d.day_part_id = s.day_part_id) AS day_part, (SELECT x.name FROM mcp_sources x WHERE x.source_id = s.source_id) AS source_name,
                                (SELECT count(*) FROM mcp_pos_sale_lines l WHERE l.sale_id = s.sale_id) AS line_count
                           FROM mcp_pos_sales s WHERE s.scope_id = :s AND s.business_date = :d::date ORDER BY s.closed_at NULLS LAST, s.external_id');
    $st->execute(['s' => $scope, 'd' => $date]);
    return $st->fetchAll();
}

function check_lines(PDO $pdo, int $sale): array
{
    $st = $pdo->prepare('SELECT l.*, m.recipe_id, m.no_depletion FROM mcp_pos_sale_lines l LEFT JOIN mcp_menu_items m ON m.menu_item_id = l.menu_item_id WHERE l.sale_id = :s ORDER BY l.sale_line_id');
    $st->execute(['s' => $sale]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['modifiers'] = json_decode((string) $r['modifiers'], true) ?: [];
    }
    return $rows;
}

/** The modifiers seen on the day, with how often and their mapping state. */
function day_modifiers(PDO $pdo, int $scope, string $date): array
{
    $st = $pdo->prepare("SELECT md.modifier_id, md.code, md.name, md.action, md.recipe_id, sum((mm->>'qty')::numeric) AS qty
                           FROM mcp_pos_sale_lines l JOIN mcp_menu_items mi ON mi.menu_item_id = l.menu_item_id CROSS JOIN LATERAL jsonb_array_elements(l.modifiers) mm
                           JOIN mcp_modifiers md ON md.pos_source_id = mi.pos_source_id AND md.code = mm->>'code'
                          WHERE l.scope_id = :s AND l.business_date = :d::date AND NOT l.voided GROUP BY md.modifier_id, md.code, md.name, md.action, md.recipe_id ORDER BY md.code");
    $st->execute(['s' => $scope, 'd' => $date]);
    return $st->fetchAll();
}

/** What a run posted, by ingredient: the quantity in the count unit, the value (walled — the view nulls it). */
function day_depletion(PDO $pdo, int $run): array
{
    $st = $pdo->prepare("SELECT t.ingredient_id, i.name, i.code, sum(t.qty_base) AS qty_base, sum(t.value) AS value, string_agg(DISTINCT a.name, ', ') AS areas, bool_or(t.txn_type = 'return') AS returned
                           FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id LEFT JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id
                          WHERE t.reference_kind = 'depletion_run' AND t.reference_id = :r GROUP BY t.ingredient_id, i.name, i.code ORDER BY i.name");
    $st->execute(['r' => $run]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['qty_display'] = format_qty($pdo, (int) $r['ingredient_id'], -(float) $r['qty_base']);
    }
    return $rows;
}

function run_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['r.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '') {
        $where[] = 'r.status = :st';
        $args['st'] = $f['status'];
    }
    if (($f['from'] ?? '') !== '') {
        $where[] = 'r.business_date >= :f::date';
        $args['f'] = $f['from'];
    }
    if (($f['to'] ?? '') !== '') {
        $where[] = 'r.business_date <= :t::date';
        $args['t'] = $f['to'];
    }
    $st = $pdo->prepare('SELECT r.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = r.started_by) AS started_by_name, count(*) OVER () AS total
                           FROM mcp_depletion_runs r WHERE ' . implode(' AND ', $where) . ' ORDER BY r.business_date DESC, r.run_no DESC LIMIT :l OFFSET :o');
    $st->execute($args + ['l' => POS_PAGE, 'o' => max(0, $page - 1) * POS_PAGE]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => (int) ($rows[0]['total'] ?? 0)];
}

function run_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = r.started_by) AS started_by_name FROM mcp_depletion_runs r WHERE r.depletion_run_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The movements a run posted — or, for a void run, those and their reversals. */
function run_movements(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT t.transaction_id, t.txn_type, t.ingredient_id, i.name, t.qty_base, t.unit_cost_per_base, t.value, t.occurred_at, t.business_date, t.reverses_id, a.name AS area
                           FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id LEFT JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id
                          WHERE (t.reference_kind = 'depletion_run' AND t.reference_id = :r)
                             OR (t.txn_type = 'reversal' AND t.reverses_id IN (SELECT transaction_id FROM mcp_inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = :r2))
                          ORDER BY t.transaction_id");
    $st->execute(['r' => $id, 'r2' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['qty_display'] = format_qty($pdo, (int) $r['ingredient_id'], (string) $r['qty_base']);
    }
    return $rows;
}

/** The trail of a record (screens' Trail cards). */
function pos_activity(PDO $pdo, string $entityType, int $id, int $limit = 30): array
{
    $st = $pdo->prepare('SELECT occurred_at, action, actor_name, after FROM mcp_activity_log WHERE entity_type = :t AND entity_id = :id ORDER BY activity_id DESC LIMIT :l');
    $st->execute(['t' => $entityType, 'id' => $id, 'l' => $limit]);
    return $st->fetchAll();
}
