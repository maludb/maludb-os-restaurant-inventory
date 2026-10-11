<?php
declare(strict_types=1);

/**
 * The one funnel (memory.md §2). Every state change and every screen entry writes a row; the
 * ingest bridge ships it to the tenant's MaluDB with "application": "restaurant_inventory".
 * $opts: actor_member_id, source, screen, route, before, after, request_id, agent_run_id, department_id, location_id, kernel_request_id,
 *        token_id (the token a call ran under), scope_id (the site — defaults to the session's current site; scoped-applications.md
 *        §4.6: every row carries the site, so memory can answer "what happened at Airport"), and this application's audit keys
 *        ingredient_id, recipe_id, purchase_order_id, count_id (design §6.9) — taken from the entity when the entity IS one of those.
 * What is logged is what people and agents DO: ids, names, codes, quantities, units, costs, counts, states and reasons; never a POS
 * check's raw payload, a credential, a token or a file path. Bulk actions are ONE row with a count.
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, int|string|null $entityId = null, array $opts = []): void
{
    try {
        $eid = null;
        if (is_int($entityId) || (is_string($entityId) && ctype_digit($entityId))) {
            $eid = (int) $entityId;
        }
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO activity_log
                (actor_member_id, source, action, screen, route, entity_type, entity_id,
                 before, after, request_id, session_id, ip_address, agent_run_id, department_id, location_id, kernel_request_id,
                 token_id, scope_id, ingredient_id, recipe_id, purchase_order_id, count_id)
            VALUES
                (:actor, :source, :action, :screen, :route, :etype, :eid,
                 :before, :after, :rid, :sid, :ip, :run, :dept, :loc, :krid, :tok, :scope, :ingredient, :recipe, :po, :count)
        SQL);
        $stmt->execute([
            'actor'  => array_key_exists('actor_member_id', $opts) ? $opts['actor_member_id'] : ($_SESSION['member_id'] ?? null),
            'source' => $opts['source'] ?? default_activity_source(),
            'action' => $action,
            'screen' => $opts['screen'] ?? null,
            'route'  => $opts['route'] ?? request_route(),
            'etype'  => $entityType,
            'eid'    => $eid,
            'before' => isset($opts['before']) ? json_encode($opts['before'], JSON_THROW_ON_ERROR) : null,
            'after'  => isset($opts['after']) ? json_encode($opts['after'], JSON_THROW_ON_ERROR) : null,
            'rid'    => $opts['request_id'] ?? agent_run_request_id() ?? request_id(),
            'sid'    => PHP_SAPI === 'cli' ? null : (session_id() ?: null),
            'ip'     => PHP_SAPI === 'cli' ? null : client_ip(),
            'run'    => $opts['agent_run_id'] ?? current_agent_run_id(),
            'dept'   => $opts['department_id'] ?? null,
            'loc'    => $opts['location_id'] ?? ($entityType === 'location' ? $eid : null),
            'krid'   => $opts['kernel_request_id'] ?? null,
            'tok'    => isset($opts['token_id']) ? (int) $opts['token_id'] : (isset($GLOBALS['__api_token_id']) ? (int) $GLOBALS['__api_token_id'] : null),
            'scope'  => array_key_exists('scope_id', $opts) ? activity_key($opts['scope_id']) : (isset($_SESSION['scope_id']) ? (int) $_SESSION['scope_id'] : null),
            'ingredient' => activity_key($opts['ingredient_id'] ?? ($entityType === 'ingredient' ? $entityId : null)),
            'recipe'     => activity_key($opts['recipe_id'] ?? ($entityType === 'recipe' ? $entityId : null)),
            'po'         => activity_key($opts['purchase_order_id'] ?? ($entityType === 'purchase_order' ? $entityId : null)),
            'count'      => activity_key($opts['count_id'] ?? ($entityType === 'inventory_count' ? $entityId : null)),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}

/** 'web' for the UI; 'agent' under a run token; 'assistant' under a person's action token; 'cron' under CLI; 'api' under a key. ('system' is the worker's own passes.) */
function default_activity_source(): string
{
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'assistant';
    }
    if (!empty($GLOBALS['__public_door'])) {
        return (string) $GLOBALS['__public_door'];      // 'api' (a key's call), 'portal' (the supplier's door — Phase 3)
    }
    return PHP_SAPI === 'cli' ? 'cron' : 'web';
}

/** A screen opened (never for a JSON read unless it asks with X-Screen-View: 1). $keys: the audit keys the screen is about. */
function log_screen_view(PDO $pdo, string $screen, array $keys = []): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen] + $keys);
}

/**
 * Under a run token every row of the run carries the run's own request id (the join to the
 * kernel's prompt ledger). this application cannot read the kernel's database: it asks the run-facts call once
 * per request (agents.md, A7 b) — never a header the agent could set.
 */
function agent_run_request_id(): ?string
{
    static $resolved = false, $id = null;
    if (!$resolved) {
        $resolved = true;
        if (current_agent_run_id() !== null) {
            $facts = run_facts((string) ($GLOBALS['__action_token'] ?? ''));
            $id = $facts !== null && !empty($facts['valid']) ? ($facts['request_id'] ?? null) : null;
        }
    }
    return $id;
}

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $id = $given !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $given) ? $given : bin2hex(random_bytes(8));
    }
    return $id;
}

function request_route(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '';
    return trim($method . ' ' . $path);
}

/** An audit key for activity_log, or null — anything that is not a positive integer is dropped, so a bad value never costs the row. */
function activity_key(mixed $v): ?int
{
    if (is_int($v)) {
        return $v > 0 ? $v : null;
    }
    return is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v) ? (int) $v : null;
}
