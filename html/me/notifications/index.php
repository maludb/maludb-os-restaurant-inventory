<?php
declare(strict_types=1);
/** /me/notifications — my notices, unread first (screen `my-notifications`; ?unread=1 only the unread; ?page=; ?count=1 the bell's count alone — Pattern A, no log). People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/me/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
if (request_string('count') === '1') {
    header('Vary: HX-Request');
    echo view('shared/bell.php', ['unread' => bell_count($pdo, $me)]);
    exit;
}
$unreadOnly = request_bool('unread');
$page = max(1, (int) (request_integer('page') ?? 1));
$r = find_my_notifications($pdo, $me, $page, $unreadOnly);
log_screen_view($pdo, 'my-notifications');
if (wants_json()) {
    respond_screen(['unread_only' => $unreadOnly, 'page' => $page, 'more' => $r['more'], 'unread' => bell_count($pdo, $me), 'notifications' => array_map('present_notification', $r['rows'])]);
}
render_screen('Notifications', view('me/notifications.php', ['rows' => $r['rows'], 'more' => $r['more'], 'page' => $page, 'tz' => member_timezone(), 'unreadOnly' => $unreadOnly]),
    ['activeNav' => 'my-notifications', 'screen' => 'my-notifications', 'entity' => 'notification']);
