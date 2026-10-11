<?php
declare(strict_types=1);

/**
 * Who is asking, and what they may do — without a password anywhere.
 *
 * A person arrives with the kernel's hand-off token (html/sso.php) and holds a session listed in
 * member_sessions; an agent or the kernel's actions server arrives with the tenant's signed action
 * token. The verifiers are the kernel's own (app/auth.php there, A3/A7), copied so one key signs
 * everything across the estate. Gates mirror the SQL rules (db/001, db/004). Scoped by location (scoped-applications.md §4):
 * every right is asked AT A SITE — the session's current site unless a handler names one.
 */

// ---- session accessors --------------------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['member_id']);
}

function current_member(): ?array
{
    static $member = null;
    if (!is_logged_in()) {
        return null;
    }
    if ($member === null) {
        $member = find_member_by_id(db(), (int) $_SESSION['member_id']);
    }
    return $member ?: null;
}

function current_member_id(): ?int
{
    return is_logged_in() ? (int) $_SESSION['member_id'] : null;
}

function find_member_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, member_kind, display_name, email, business_role, is_external, status, capability, job_title, phone, timezone FROM members WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function member_timezone(): string
{
    return (string) (current_member()['timezone'] ?? 'UTC');
}

// ---- the session list (sign-on-and-directory.md §2) ---------------------------------------
function session_hash(string $sessionId): string
{
    return hash('sha256', $sessionId);
}

function session_open(PDO $pdo, int $memberId, string $sessionId): void
{
    $pdo->prepare('INSERT INTO member_sessions (session_hash, member_id) VALUES (:h, :m) ON CONFLICT (session_hash) DO UPDATE SET member_id = EXCLUDED.member_id, ended_at = NULL, ended_by = NULL, last_seen_at = now()')
        ->execute(['h' => session_hash($sessionId), 'm' => $memberId]);
}

function session_is_listed(PDO $pdo, string $sessionId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM member_sessions WHERE session_hash = :h AND ended_at IS NULL');
    $st->execute(['h' => session_hash($sessionId)]);
    return $st->fetchColumn() !== false;
}

function session_touch(PDO $pdo, string $sessionId): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->prepare("UPDATE member_sessions SET last_seen_at = now() WHERE session_hash = :h AND last_seen_at < now() - interval '1 minute'")
        ->execute(['h' => session_hash($sessionId)]);
}

/** End this browser session (own sign-out, or a mirror row that no longer admits the person). */
function end_session(PDO $pdo, string $by): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $pdo->prepare('UPDATE member_sessions SET ended_at = now(), ended_by = :by WHERE session_hash = :h AND ended_at IS NULL')
            ->execute(['by' => $by, 'h' => session_hash(session_id())]);
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
    }
}

/** End every session this application holds for a member (the kernel's sign-out notice). Returns how many. */
function end_member_sessions(PDO $pdo, int $memberId, string $by): int
{
    $st = $pdo->prepare('UPDATE member_sessions SET ended_at = now(), ended_by = :by WHERE member_id = :m AND ended_at IS NULL');
    $st->execute(['by' => $by, 'm' => $memberId]);
    return $st->rowCount();
}

// ---- gates (PHP mirrors of the SQL rules, db/004; the views still decide the rows) --------------
function require_login(): void
{
    if (!is_logged_in()) {
        if (wants_json()) {
            json_error('unauthorized', 'Sign in required.', 401);
        }
        redirect(launcher_url('app=' . rawurlencode(app_key())));
    }
}

// ---- sites (scoped-applications.md §4): every right is asked AT a site --------------------------
/** The live sites the member holds a role at, by name: [{scope_id, name, timezone, role_key, roles}] (db/001, ri_held_scope_ids). */
function held_sites(): array
{
    static $sites = null;
    if ($sites === null) {
        $sites = db()->query('SELECT s.scope_id, s.name, s.timezone, r.role_key, r.roles FROM sites s
                               JOIN member_site_roles r ON r.scope_id = s.scope_id AND r.member_id = app_current_member_id()
                              WHERE s.scope_id IN (SELECT ri_held_scope_ids()) ORDER BY s.name')->fetchAll();
        foreach ($sites as &$site) {
            $site['scope_id'] = (int) $site['scope_id'];
            $site['roles'] = pg_text_array((string) ($site['roles'] ?? '{}'));
        }
        unset($site);
    }
    return $sites;
}

/** A PostgreSQL text[] literal as a PHP list (unquoted elements — role keys). */
function pg_text_array(string $literal): array
{
    $inner = trim($literal, '{}');
    return $inner === '' ? [] : array_map(static fn (string $x): string => trim($x, '"'), explode(',', $inner));
}

/** The session's current site (chosen on the launcher, switched in the shell; re-checked by the bootstrap). */
function current_site_id(): ?int
{
    return isset($_SESSION['scope_id']) ? (int) $_SESSION['scope_id'] : null;
}

function current_site(): ?array
{
    foreach (held_sites() as $site) {
        if ($site['scope_id'] === current_site_id()) {
            return $site;
        }
    }
    return null;
}

/** The site's own time zone — every time on a screen is shown in it; the business date follows it. */
function site_timezone(?int $scopeId = null): string
{
    $scopeId ??= current_site_id();
    foreach (held_sites() as $site) {
        if ($site['scope_id'] === $scopeId) {
            return (string) $site['timezone'];
        }
    }
    return 'UTC';
}

/** A right the member's roles give at the site (db/004 ri_has_right). The current site when none is named. */
function has_right(string $right, ?int $scopeId = null): bool
{
    static $cache = [];
    $scopeId ??= current_site_id();
    if ($scopeId === null) {
        return false;
    }
    return $cache[$right . '@' . $scopeId] ??= db_bool(db(), 'SELECT ri_has_right(:r, :s)', ['r' => $right, 's' => $scopeId]);
}

/** A right held at ANY site the member holds — what opens a record shared across sites (an ingredient, a recipe, a supplier). */
function has_right_anywhere(string $right): bool
{
    static $cache = [];
    return $cache[$right] ??= db_bool(db(), 'SELECT ri_has_right_anywhere(:r)', ['r' => $right]);
}

/** Runs the application at the site: holds the admin role there (a super-admin everywhere). */
function is_ri_admin(?int $scopeId = null): bool
{
    static $cache = [];
    $scopeId ??= current_site_id();
    return $cache[(string) $scopeId] ??= db_bool(db(), 'SELECT ri_is_admin(:s)', ['s' => $scopeId]);
}

/** Cost is the wall (design §3): Chef and above at the site, Kitchen when the site says so, never a Viewer (db/004 ri_sees_cost). */
function sees_cost(?int $scopeId = null): bool
{
    static $cache = [];
    $scopeId ??= current_site_id();
    if ($scopeId === null) {
        return false;
    }
    return $cache[$scopeId] ??= db_bool(db(), 'SELECT ri_sees_cost(:s)', ['s' => $scopeId]);
}

/** Someone who belongs here: active, admitted, holding a live site (db/004). */
function is_member_here(): bool
{
    static $v = null;
    return $v ??= db_bool(db(), 'SELECT ri_is_member_here()');
}

function is_super_admin(): bool
{
    static $v = null;
    return $v ??= db_bool(db(), 'SELECT app_is_super_admin()');
}

/** A human at the keyboard or an agent under a token — never an agent on a person-only screen. */
function require_human(): void
{
    if ((current_member()['member_kind'] ?? '') !== 'human') {
        refuse(403, 'This is for people; an agent uses the tools.');
    }
}

/** A site the member does not hold does not exist to them — the same sentence as a missing one. */
function require_site(int $scopeId): void
{
    require_login();
    if (!in_array($scopeId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Not found.');
    }
}

/** The admin screens: the admin role at the site, told why otherwise — never a bare 403. */
function require_admin(?int $scopeId = null): void
{
    require_login();
    $scopeId ??= current_site_id();
    if ($scopeId === null) {
        refuse(404, 'Not found.');
    }
    require_site($scopeId);
    if (!is_ri_admin($scopeId)) {
        refuse(403, right_refusal('settings.manage', $scopeId));
    }
}

function require_super(): void
{
    require_login();
    if (!is_super_admin()) {
        refuse(403, 'This needs the super-admin.');
    }
}

/** A record the member may not see does not exist to them — the same sentence as a missing one (the view decides). $id is a bigint or a UUID. */
function require_visible(PDO $pdo, string $view, string $idColumn, int|string $id, string $what = 'Record'): void
{
    require_login();
    $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$idColumn} = :id");
    $st->execute(['id' => $id]);
    if ($st->fetchColumn() === false) {
        refuse(404, $what . ' not found.');
    }
}

const RIGHT_WORDS = [
    'ri.read' => 'see this', 'tokens.own' => 'keep tokens', 'stock.count' => 'count stock', 'stock.waste' => 'log waste', 'stock.receive' => 'receive a delivery',
    'stock.transfer' => 'move stock', 'stock.produce' => 'post a prep batch', 'notes.write' => 'add a note', 'recipes.write' => 'change recipes',
    'ingredients.write' => 'change ingredients', 'counts.approve' => 'approve a count', 'waste.review' => 'review waste', 'purchasing.draft' => 'draft an order',
    'reports.read' => 'see the reports', 'suppliers.write' => 'change suppliers', 'purchasing.send' => 'send a purchase order', 'invoices.write' => 'enter invoices',
    'prices.write' => 'change costs or alerts', 'periods.close' => 'close a period', 'forecast.adjust' => 'adjust the forecast', 'exports.all' => 'export this',
    'settings.manage' => 'change the settings', 'sources.credentials' => "set a source's credential", 'agents.settings' => "change the agents' settings",
    'records.delete' => 'delete this', 'sequences.manage' => 'change numbering', 'sites.manage' => "change a site's settings",
];

/** Why the acting member lacks a right at the site, in the schema's sentence (ri_right_refusal, db/004). */
function right_refusal(string $right, ?int $scopeId = null): string
{
    $scopeId ??= current_site_id();
    $why = $scopeId === null ? null : one_value(db(), 'SELECT ri_right_refusal(:r, :s)', ['r' => $right, 's' => $scopeId]);
    return is_string($why) && $why !== '' ? $why : 'You may not ' . (RIGHT_WORDS[$right] ?? str_replace(['.', '_'], ' ', $right)) . ' here.';
}

/** A right at a site (the current one when none is named), with the sentence a person reads when they lack it. */
function require_right(string $right, ?int $scopeId = null): void
{
    require_login();
    $scopeId ??= current_site_id();
    if ($scopeId === null) {
        refuse(404, 'Not found.');
    }
    require_site($scopeId);
    if (!has_right($right, $scopeId)) {
        refuse(403, right_refusal($right, $scopeId));
    }
}

/** A right at any held site — for a record shared across sites; refused in the current site's sentence. */
function require_right_anywhere(string $right): void
{
    require_login();
    if (!has_right_anywhere($right)) {
        refuse(403, right_refusal($right));
    }
}

// ---- the tenant's keys and the token shapes (copied from the kernel, A3/A7) -----------------
function action_token_key(): string
{
    $k = (string) env('ACTION_TOKEN_KEY', '');
    if (strlen($k) < 32) {
        throw new RuntimeException('ACTION_TOKEN_KEY is not configured.');
    }
    return $k;
}

function base64url_decode(string $text): string|false
{
    return base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
}

/** [member_id, nonce] for a valid hand-off token bound to THIS application, else null. */
function verify_sso_token(string $token, string $expectedAppKey): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 5) {
        return null;
    }
    [$mid, $exp, $app, $nonce, $mac] = $parts;
    if (!ctype_digit($mid) || !ctype_digit($exp) || (int) $exp < time() || $app !== $expectedAppKey
        || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
        return null;
    }
    $expected = hash_hmac('sha256', 'sso:' . $mid . '.' . $exp . '.' . $app . '.' . $nonce, action_token_key());
    return hash_equals($expected, $mac) ? [(int) $mid, $nonce] : null;
}

function verify_sso_claims(string $signed): ?array
{
    $dot = strrpos($signed, '.');
    if ($dot === false) {
        return null;
    }
    $text = substr($signed, 0, $dot);
    $mac = substr($signed, $dot + 1);
    if (!hash_equals(hash_hmac('sha256', $text, action_token_key()), $mac)) {
        return null;
    }
    $json = base64url_decode($text);
    $claims = $json === false ? null : json_decode($json, true);
    return is_array($claims) ? $claims : null;
}

function verify_sso_logout_notice(string $notice, string $expectedAppKey, int $ttlSeconds = 120): ?int
{
    $parts = explode('.', $notice);
    if (count($parts) !== 4) {
        return null;
    }
    [$mid, $issued, $app, $mac] = $parts;
    if (!ctype_digit($mid) || !ctype_digit($issued) || $app !== $expectedAppKey || abs(time() - (int) $issued) > $ttlSeconds) {
        return null;
    }
    $expected = hash_hmac('sha256', 'sso-logout:' . $mid . '.' . $issued . '.' . $app, action_token_key());
    return hash_equals($expected, $mac) ? (int) $mid : null;
}

/** [member_id, run_id|null] from a token's signature alone (no relay) — what a replay carries. */
function verify_token_signature(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) === 4) {
        [$mid, $exp, $run, $sig] = $parts;
        if (!ctype_digit($mid) || !ctype_digit($exp) || !ctype_digit($run) || time() > (int) $exp) {
            return null;
        }
        $expected = hash_hmac('sha256', 'run:' . $mid . '.' . $exp . '.' . $run, action_token_key());
        return hash_equals($expected, (string) $sig) ? [(int) $mid, (int) $run] : null;
    }
    if (count($parts) !== 3) {
        return null;
    }
    [$mid, $exp, $sig] = $parts;
    if (!ctype_digit($mid) || !ctype_digit($exp) || time() > (int) $exp) {
        return null;
    }
    return hash_equals(hash_hmac('sha256', $mid . '.' . $exp, action_token_key()), (string) $sig) ? [(int) $mid, null] : null;
}

/**
 * The member an action token speaks for, or null. A run token (four parts) is honoured only with
 * the relay signature the kernel's actions server adds — the agent never holds the relay key.
 */
function verify_action_token(string $token, string $relay = ''): ?int
{
    $parts = explode('.', $token);
    if (count($parts) === 4) {
        $signed = verify_token_signature($token);
        if ($signed === null) {
            return null;
        }
        $relayKey = (string) env('ACTIONS_RELAY_KEY', '');
        if (strlen($relayKey) < 32 || $relay === '' || !hash_equals(hash_hmac('sha256', $token, $relayKey), $relay)) {
            return null;
        }
        return $signed[0];
    }
    if (count($parts) !== 3) {
        return null;
    }
    $signed = verify_token_signature($token);
    return $signed === null ? null : $signed[0];
}

function action_token_run_id(string $token): ?int
{
    $parts = explode('.', $token);
    return count($parts) === 4 && ctype_digit($parts[2]) ? (int) $parts[2] : null;
}

function current_agent_run_id(): ?int
{
    return $GLOBALS['__agent_run_id'] ?? null;
}

function is_action_authed(): bool
{
    return !empty($GLOBALS['__action_authed']);
}

// ---- the kernel's run-facts call (agents.md, A7 b) ------------------------------------------
/**
 * What the kernel says about the token a caller presented: {valid, is_agent, member_id, run_id,
 * request_id, trigger, is_eval, run_status, endpoints[]; requested_by_* since K38}. An agent sees what its role may at the sites it is
 * granted (design §3) — the facts' scopes[] and the mirror decide. Null when the kernel cannot be reached — callers fail closed on
 * null exactly as on valid:false.
 */
function run_facts(string $token): ?array
{
    static $cache = [];
    if ($token === '') {
        return null;
    }
    if (array_key_exists($token, $cache)) {
        return $cache[$token];
    }
    $answer = kernel_call('POST', '/api/v1/runs/facts.php', ['token' => $token]);
    $cache[$token] = ($answer !== null && $answer['status'] === 200 && is_array($answer['body'])) ? $answer['body'] : null;
    return $cache[$token];
}

/**
 * One call to the kernel's internal API with the application token. Returns
 * ['status' => int, 'body' => array|null, 'request_id' => ?string], or null when unreachable.
 */
function kernel_call(string $method, string $path, ?array $body = null, array $headers = [], int $timeout = 15): ?array
{
    $base = rtrim((string) env('OS_INTERNAL_URL', 'http://127.0.0.1:8080'), '/');
    $token = (string) env('OS_APPLICATION_TOKEN', '');
    if ($token === '') {
        error_log('kernel_call: OS_APPLICATION_TOKEN is not configured');
        return null;
    }
    $ch = curl_init($base . $path);
    $hdrs = array_merge([
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'X-Request-Id: ' . request_id(),
    ], $headers);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 3,
             CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        $hdrs[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $hdrs;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        error_log('kernel_call ' . $method . ' ' . $path . ': ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerText = substr((string) $raw, 0, $headerSize);
    $bodyText = substr((string) $raw, $headerSize);
    $requestId = null;
    if (preg_match('/^X-Request-Id:\s*(\S+)/mi', $headerText, $m)) {
        $requestId = $m[1];
    }
    $decoded = json_decode($bodyText, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'request_id' => $requestId];
}
