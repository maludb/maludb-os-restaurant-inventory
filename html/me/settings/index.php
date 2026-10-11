<?php
declare(strict_types=1);
/** /me/settings — my preferences (screen `my-settings`): display units, the default count area of this site, how I am told; who I am here per site; the time zone read-only. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/me/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$site = (int) current_site_id();
$prefs = my_prefs($pdo, $me);
$roles = [];
foreach (held_sites() as $s) { $roles[] = ['scope_id' => $s['scope_id'], 'site' => $s['name'], 'roles' => array_column(my_roles($pdo, $me, $s['scope_id']), 'name')]; }
log_screen_view($pdo, 'my-settings');
if (wants_json()) {
    respond_screen(['prefs' => present_prefs($prefs), 'kinds' => NOTICE_KINDS, 'timezone' => member_timezone(), 'badge' => role_badge(), 'roles' => $roles, 'sees_cost' => sees_cost(),
        'areas' => array_map(static fn (array $a): array => ['storage_area_id' => (int) $a['storage_area_id'], 'name' => $a['name']], site_areas($pdo, $site))]);
}
$notices = ['prefs_saved' => ['success', 'Saved your settings.']];
render_screen('My settings', view('me/settings.php', ['prefs' => $prefs, 'areas' => site_areas($pdo, $site), 'tz' => member_timezone(), 'roles' => $roles, 'badge' => role_badge(), 'seesCost' => sees_cost(),
    'osChannels' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings/channels', 'osProfile' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings',
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'my-settings', 'screen' => 'my-settings', 'entity' => 'member', 'recordId' => (string) $me]);
