<?php
declare(strict_types=1);
/** / — home (screen `home`, sso-shell.md): the regions as cards, each with its count that opens its list and an empty state naming its slice; the bell's five newest. Writes only `screen.view`. People only. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
if (!is_member_here() || current_site_id() === null) {
    refuse(403, right_refusal('ri.read'));
}
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me, (int) current_site_id());
if (wants_json()) {
    respond_screen(['site_id' => $s['site_id'], 'regions' => array_map(static fn (array $r): array => ['title' => $r['title'], 'slice' => $r['slice'], 'url' => $r['url'], 'count' => $r['count']], $s['regions']),
        'roles' => array_values(array_map(static fn (array $r): string => (string) $r['role_key'], my_roles($pdo, $me, (int) current_site_id()))), 'badge' => role_badge(), 'unread' => array_map(static fn (array $n): array => ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'title' => $n['title']], $s['bell']), 'sees_cost' => $s['may']['cost']]);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone(), 'seesCost' => sees_cost(), 'site' => current_site()]), ['activeNav' => 'home', 'screen' => 'home']);
