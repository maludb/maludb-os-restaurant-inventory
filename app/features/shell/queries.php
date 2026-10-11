<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bell's count (mcp_notifications), the business name
 * (ri_settings), my roles AT A SITE (ri_member_roles_at(), db/004) and a site's label. Each is cached for the request.
 */

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    $st = $pdo->prepare('SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL AND member_id = :m');
    if ($cache === null) { $st->execute(['m' => $memberId]); $cache = (int) $st->fetchColumn(); }
    return $cache;
}

/** The business's name on the header (ri_settings.business_name), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) ($pdo->query('SELECT business_name FROM ri_settings WHERE id = 1')->fetchColumn() ?: app_name());
}

/** The roles the member effectively holds at a site, highest first: [{role_key, name}] (ri_member_roles_at(), db/004). */
function my_roles(PDO $pdo, int $memberId, int $scopeId): array
{
    static $cache = [];
    $k = $memberId . '@' . $scopeId;
    if (!isset($cache[$k])) {
        $st = $pdo->prepare('SELECT r.role_key, r.name FROM ri_roles r WHERE r.role_key = ANY (ri_member_roles_at(:m, :s)) ORDER BY r.sort_order DESC');
        $st->execute(['m' => $memberId, 's' => $scopeId]);
        $cache[$k] = $st->fetchAll();
    }
    return $cache[$k];
}

/** A site in words for the header and the switcher: its name (and its time zone's city when it is not the business's). */
function site_label(array $site): string
{
    return (string) ($site['name'] ?? 'Restaurant');
}
