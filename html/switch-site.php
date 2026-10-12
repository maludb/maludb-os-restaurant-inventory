<?php
declare(strict_types=1);

/**
 * The restaurant (site) switcher (scoped-applications.md §4.2; screen `site-switch`, action `site_switch`). GET: the held sites as a page of
 * buttons — the phone's page for the header's dropdown. POST: the session's current site becomes one the person HOLDS; any other — a closed
 * site, a site of someone else's — is refused 403 and nothing changes (`member.site_switch.refused`). The bootstrap re-checks the choice on
 * every request. Logs `member.site_switch` (the manifest's event; the kit's `site.switch` renamed in Phase 2). Location /; refresh siteChanged.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login();
require_human();
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    log_screen_view($pdo, 'site-switch');
    if (wants_json()) {
        respond_screen(['current' => current_site_id(), 'sites' => array_map(static fn (array $s): array => ['scope_id' => $s['scope_id'], 'name' => $s['name'], 'timezone' => $s['timezone'], 'role' => $s['role_key']], held_sites())]);
    }
    render_screen('Switch restaurant', view('me/switch-site.php', ['sites' => held_sites(), 'current' => current_site_id()]), ['activeNav' => '', 'screen' => 'site-switch']);
}
require_post();
verify_csrf();
$to = request_integer('site');
$held = array_column(held_sites(), 'scope_id');
if ($to === null || !in_array($to, $held, true)) {
    log_activity($pdo, 'member.site_switch.refused', 'site', $to, ['after' => ['reason' => 'not held']]);
    refuse(403, 'You do not hold that restaurant.');
}
$from = current_site_id();
$_SESSION['scope_id'] = $to;
db_use_site_zone($pdo, $to);                                       // the restaurant's day for current_date (app/db.php)
$pdo->prepare('UPDATE member_sessions SET scope_id = :s WHERE session_hash = :h')->execute(['s' => $to, 'h' => session_hash(session_id())]);
log_activity($pdo, 'member.site_switch', 'site', $to, ['scope_id' => $to, 'before' => ['scope_id' => $from], 'after' => ['scope_id' => $to]]);
$name = '';
foreach (held_sites() as $s) { if ($s['scope_id'] === $to) { $name = $s['name']; } }
emit_action_status(true, ['did' => 'Switched to ' . $name, 'record_id' => $to, 'refresh' => 'siteChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Switched to ' . $name, 'record_id' => $to, 'site_id' => $to, 'location' => '/', 'refresh' => 'siteChanged']);
}
if (is_htmx_request()) {
    header('HX-Redirect: /');
    exit;
}
redirect('/');
