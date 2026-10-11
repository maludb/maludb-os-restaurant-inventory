<?php
declare(strict_types=1);
/** Action `notification_read` (log `member.notification_read`, after.count): one of my notifications, or every unread one when `notification` is empty. Own. Location /me/notifications; refresh notificationChanged. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification');
$n = mark_notifications_read($pdo, $me, $id);
log_activity($pdo, 'member.notification_read', 'notification', $id, ['after' => ['count' => $n, 'all' => $id === null]]);
ri_done($n === 0 ? 'Nothing was unread' : ($id === null ? 'Marked ' . $n . ' read' : 'Marked it read'), $id ?? $me, '/me/notifications', 'notificationChanged');
