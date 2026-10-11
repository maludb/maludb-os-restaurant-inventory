<?php
declare(strict_types=1);

/**
 * A person's own corner (sso-shell.md): how they are told and what they see in (notification_prefs, db/015 + db/018's display_units and
 * default_area_id), their in-app notifications (the bell), their access tokens for the two MCP servers (mcp_access_tokens, db/003), their
 * trail — screens `my-settings`, `my-notifications`, `my-tokens`, `my-trail`; actions preferences_save, notification_read, token_mint,
 * token_revoke. The time zone is the directory's (members.timezone), read-only here.
 */
const ACTIVITY_PAGE = 50;

/** Every event a person can be told about, with the sentence the settings screen shows (db/015's notification kinds). */
const NOTICE_KINDS = [
    'watch'           => 'A watch I set fires (below par, a stock-out projected, a cost over my threshold, a cost that moved)',
    'stockout'        => 'A stock-out is projected before the next delivery',
    'price_alert'     => 'A cost moves more than the threshold on an invoice or a receipt',
    'pull_failed'     => 'A POS pull or import fails',
    'unmapped'        => 'A PLU sells that no recipe is mapped to',
    'po_ack'          => 'A supplier acknowledges a purchase order',
    'po_decline'      => 'A supplier declines a line',
    'po_date'         => 'A supplier sets or changes a delivery date',
    'delivery_due'    => 'A delivery is due and nothing has been received',
    'count_review'    => 'A count is ready for review',
    'count_recount'   => 'A line of a count needs a recount',
    'variance'        => "A count's variance is over tolerance",
    'morning_note'    => "The Purchasing agent's morning note",
    'mention'         => 'Someone mentions me in a note',
    'period_due'      => 'A period is past its end and still open',
    'invoice_dispute' => 'An invoice does not match its receipt',
];

/** The person's choices, or the table's defaults when they never saved any (`saved` false). */
function my_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, display_units, default_area_id FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\{[^']*\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'display_units' => 'metric', 'default_area_id' => null, 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'display_units' => (string) $r['display_units'],
            'default_area_id' => $r['default_area_id'] === null ? null : (int) $r['default_area_id'], 'saved' => true];
}

/**
 * Save the choices ($fields present are changed; absent are kept). Answers ['before', 'after'] of the changed keys and `prefs`. INSERT … ON
 * CONFLICT. `default_area_id` must be an active storage area of $scopeId, or null (a DomainException otherwise — ri_guard() words it).
 */
function save_prefs(PDO $pdo, int $memberId, int $scopeId, array $fields): array
{
    $before = my_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($fields, $before) + $before;
    if ($after['default_area_id'] !== null) {
        $st = $pdo->prepare('SELECT 1 FROM storage_areas WHERE id = :id AND scope_id = :s AND active');
        $st->execute(['id' => (int) $after['default_area_id'], 's' => $scopeId]);
        if ($st->fetchColumn() === false) {
            throw new DomainException('That storage area is not at this restaurant.');
        }
    }
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, display_units, default_area_id) VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :u, :a)
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds, text_kinds = EXCLUDED.text_kinds,
                   display_units = EXCLUDED.display_units, default_area_id = EXCLUDED.default_area_id, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']), 'tk' => $lit($after['text_kinds']),
                   'u' => $after['display_units'], 'a' => $after['default_area_id']]);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/** The site's active storage areas in walk order, for the default-area select. */
function site_areas(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT storage_area_id, name, kind FROM mcp_storage_areas WHERE scope_id = :s AND active ORDER BY walk_order, name');
    $st->execute(['s' => $scopeId]);
    return $st->fetchAll();
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
/** My notices, unread first then newest first (mcp_notifications), 50 a page. */
function find_my_notifications(PDO $pdo, int $memberId, int $page = 1, bool $unreadOnly = false, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare('SELECT n.notification_id, n.scope_id, s.name AS site_name, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at
                           FROM mcp_notifications n LEFT JOIN sites s ON s.scope_id = n.scope_id WHERE n.member_id = :m'
        . ($unreadOnly ? ' AND n.read_at IS NULL' : '') . ' ORDER BY (n.read_at IS NULL) DESC, n.created_at DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . (max(1, $page) - 1) * $limit);
    $st->execute(['m' => $memberId]);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens -----------------------------------------------------------------------------------------------------------
/** My tokens (mcp_access_tokens_mine, db/018), newest first. */
function my_tokens(PDO $pdo, int $memberId): array
{
    return $pdo->query('SELECT token_id AS id, label, scope, last4, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine ORDER BY created_at DESC')->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $tokenId): ?array
{
    $st = $pdo->prepare('SELECT token_id AS id, label, scope, last4, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine WHERE token_id = :id');
    $st->execute(['id' => $tokenId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint a token: `ri_` + 48 hex, shown once; only the sha256 is stored (as bin/mint_mcp_token.php does). Returns id, raw, label, expires_at. */
function mint_token(PDO $pdo, int $memberId, string $label, ?int $expiresInDays): array
{
    $raw = 'ri_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, expires_at) VALUES (:m, :l, :h, :s, CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d2 AS integer)) END) RETURNING id, expires_at');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => 'mcp', 'd' => $expiresInDays, 'd2' => $expiresInDays]);
    $r = $st->fetch();
    return ['id' => (int) $r['id'], 'raw' => $raw, 'label' => $label, 'expires_at' => $r['expires_at']];
}

/** Revoke one of the member's own live tokens (revoked_at = now()). False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND revoked_at IS NULL RETURNING id');
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}

// ---- the trail ---------------------------------------------------------------------------------------------------------
/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool, default true when no key is given), from, to
 * (dates), action (a prefix), scope / ingredient / recipe / purchase_order / count (the audit keys), member (another actor — the admin;
 * an agent's rows any reader).
 */
function find_my_activity(PDO $pdo, int $memberId, int $pageNo, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['scope' => 'scope_id', 'ingredient' => 'ingredient_id', 'recipe' => 'recipe_id', 'purchase_order' => 'purchase_order_id', 'count' => 'count_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = "$col = :$k";
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['member'] ?? null) !== null) {
        $where[] = 'actor_member_id = :actor';
        $args['actor'] = (int) $filters['member'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim((string) $filters['action'], '.')) . '%';
    }
    if (($filters['from'] ?? null) !== null) { $where[] = 'occurred_at >= CAST(:from AS date)'; $args['from'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'occurred_at < CAST(:to AS date) + 1'; $args['to'] = $filters['to']; }
    $sql = 'SELECT a.activity_id, a.occurred_at, a.actor_member_id, a.actor_name, a.actor_is_agent, a.source, a.action, a.screen, a.entity_type, a.entity_id, a.after, a.agent_run_id,
                   a.scope_id, s.name AS site_name, a.ingredient_id, a.recipe_id, a.purchase_order_id, a.count_id, a.token_id
              FROM mcp_activity_log a LEFT JOIN sites s ON s.scope_id = a.scope_id' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', array_map(static fn (string $w): string => 'a.' . $w, $where)))
        . ' ORDER BY a.occurred_at DESC, a.activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . ((max(1, $pageNo) - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail by entity type and id (the view decides who sees it). */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT a.*, s.name AS site_name FROM mcp_activity_log a LEFT JOIN sites s ON s.scope_id = a.scope_id WHERE a.entity_type = :t AND a.entity_id = :id ORDER BY a.occurred_at DESC, a.activity_id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

/** A record's trail by an audit key (scope_id / ingredient_id / recipe_id / purchase_order_id / count_id): everything that touched it. */
function find_activity_by_key(PDO $pdo, string $key, int $id, int $limit = 50): array
{
    if (!in_array($key, ['scope_id', 'ingredient_id', 'recipe_id', 'purchase_order_id', 'count_id', 'token_id'], true)) {
        throw new InvalidArgumentException('Not an audit key: ' . $key);
    }
    $st = $pdo->prepare("SELECT a.*, s.name AS site_name FROM mcp_activity_log a LEFT JOIN sites s ON s.scope_id = a.scope_id WHERE a.$key = :id ORDER BY a.occurred_at DESC, a.activity_id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}
