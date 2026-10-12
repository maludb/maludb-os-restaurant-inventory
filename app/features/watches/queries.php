<?php
declare(strict_types=1);

/**
 * Watches (projection.md "Watches"; tools `my_watches`, `watch_events`): a condition on an ingredient at a site that fires ONCE per change of state (ri_watches_fire(), run
 * by the worker's `watches` pass). Rows are read from the base tables for the site the caller asks for (the caller has asserted the site; the worker has no member for the
 * mcp_* views). A cost threshold is shown back in the ingredient's recipe unit (the database converts).
 */
require_once dirname(__DIR__) . '/projection/queries.php';

const WATCH_KINDS = ['below_par' => 'Below par', 'stockout_projected' => 'Stock-out projected', 'cost_above' => 'Cost above', 'cost_moved' => 'Cost moved'];
const WATCH_KIND_CHIPS = ['below_par' => 'warning', 'stockout_projected' => 'danger', 'cost_above' => 'info', 'cost_moved' => 'info'];

const WATCH_SELECT = 'SELECT w.id AS watch_id, w.member_id, mm.display_name AS member, w.agent_member_id, am.display_name AS agent, w.scope_id, w.ingredient_id, i.name AS ingredient, i.code, i.recipe_unit_code,
       w.kind, w.threshold, CASE WHEN w.kind = \'cost_above\' THEN w.threshold * ri_to_base(w.ingredient_id, 1, i.recipe_unit_code) END AS threshold_recipe_unit, w.text_me, w.last_state, w.fired_at, w.fire_count, w.active, w.note, w.created_at
  FROM watches w JOIN ingredients i ON i.id = w.ingredient_id JOIN members mm ON mm.id = w.member_id LEFT JOIN members am ON am.id = w.agent_member_id';

function watch_decode(array $r): array
{
    $r = stock_ints($r, ['watch_id', 'member_id', 'agent_member_id', 'scope_id', 'ingredient_id', 'fire_count']);
    foreach (['text_me', 'active'] as $b) { $r[$b] = (bool) $r[$b]; }
    $r['last_state'] = $r['last_state'] === null ? null : (bool) $r['last_state'];
    return $r;
}

/** The watches at a site — one member's, or everyone's when $memberId is null (an admin) — active first, newest first. */
function watches(PDO $pdo, int $scopeId, ?int $memberId): array
{
    $st = $pdo->prepare(WATCH_SELECT . ' WHERE w.scope_id = :s AND (CAST(:m AS bigint) IS NULL OR w.member_id = :m2 OR w.agent_member_id = :m3) ORDER BY w.active DESC, w.created_at DESC, w.id DESC');
    $st->execute(['s' => $scopeId, 'm' => $memberId, 'm2' => $memberId, 'm3' => $memberId]);
    return array_map('watch_decode', $st->fetchAll());
}

function find_watch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(WATCH_SELECT . ' WHERE w.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : watch_decode($r);
}

/** The agents that hold this site (a watch may dispatch one when it fires): [{member_id, display_name}]. */
function watch_agents(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare("SELECT m.id AS member_id, m.display_name FROM members m WHERE m.member_kind = 'agent' AND m.status = 'active' AND m.capability IS NOT NULL AND cardinality(ri_member_roles_at(m.id, :s)) > 0 ORDER BY m.display_name");
    $st->execute(['s' => $scopeId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['member_id']), $st->fetchAll());
}

/** The times watches fired at a site, newest first: [{event_id, watch_id, member_id, ingredient_id, ingredient, kind, fired_at}] (tool `watch_events`). */
function watch_events(PDO $pdo, int $scopeId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT e.id AS event_id, e.watch_id, w.member_id, e.ingredient_id, i.name AS ingredient, e.kind, e.fired_at FROM watch_events e JOIN watches w ON w.id = e.watch_id JOIN ingredients i ON i.id = e.ingredient_id
                          WHERE e.scope_id = :s ORDER BY e.id DESC LIMIT :l');
    $st->bindValue('s', $scopeId, PDO::PARAM_INT);
    $st->bindValue('l', max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->execute();
    return array_map(static fn (array $r): array => stock_ints($r, ['event_id', 'watch_id', 'member_id', 'ingredient_id']), $st->fetchAll());
}
