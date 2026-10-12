<?php
declare(strict_types=1);
/** /projection/calendar — the site's trading calendar (screen `calendar-days`; params month, day): closures, holidays and events by day, the booked covers read from Reservations, the forecast's total (the day's sales in the past). Anyone with reports.read reads; forecast.adjust names a day and syncs the covers. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$month = month_or_null(request_string('month')) ?: substr(proj_today($pdo), 0, 7);
$cal = calendar_month($pdo, $site, $month);
$day = proj_query_date('day', '');
$dayRow = $day !== '' && substr($day, 0, 7) === $month ? calendar_day($pdo, $site, $day) : null;
log_screen_view($pdo, 'calendar-days', ['after' => ['month' => $month]]);
if (wants_json()) { respond_screen(present_calendar($cal)); }
$notices = ['saved' => ['success', 'The day is saved.'], 'synced' => ['success', 'The booked covers are synced from Reservations.'], 'notconnected' => ['warning', 'Reservations is not connected — the forecast runs without covers. A super-admin approves the connection in the kernel.']];
render_screen('Trading calendar', view('projection/calendar.php', ['cal' => $cal, 'dayRow' => $dayRow, 'mayAdjust' => has_right('forecast.adjust'), 'today' => proj_today($pdo), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'calendar-days', 'screen' => 'calendar-days', 'entity' => 'calendar']);
