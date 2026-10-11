<?php
declare(strict_types=1);
/** /periods/calendars — the costing calendars (screen `calendar-list`; params edit, calendar, year). A calendar is configuration: settings.manage. The form makes or changes one; the preview shows the periods a calendar would make for a year at this site before the button (Make the year's periods is periods.close's). */
require_once dirname(__DIR__, 3) . '/app/features/periods/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
$site = (int) current_site_id();
$all = calendars($pdo);
$edit = request_integer('edit') !== null ? find_calendar($pdo, (int) request_integer('edit')) : null;
if (request_integer('edit') !== null && $edit === null) { respond_not_found('That calendar is not here.'); }
$chosen = request_integer('calendar') !== null ? find_calendar($pdo, (int) request_integer('calendar')) : null;
$chosen ??= array_values(array_filter($all, static fn (array $c): bool => $c['is_default']))[0] ?? ($all[0] ?? null);
$year = request_integer('year') ?? (int) substr(site_today($site), 0, 4);
$preview = $chosen === null || $year < 2000 || $year > 2100 ? [] : calendar_preview($pdo, $chosen['calendar_id'], $year);
$made = $chosen === null ? 0 : calendar_year_made($pdo, $site, $chosen['calendar_id'], $year);
log_screen_view($pdo, 'calendar-list');
if (wants_json()) {
    respond_screen(['calendars' => array_map('present_calendar', $all), 'preview' => ['calendar_id' => $chosen['calendar_id'] ?? null, 'fiscal_year' => $year, 'made' => $made, 'periods' => $preview]]);
}
$notices = ['created' => ['success', 'The calendar is made.'], 'saved' => ['success', 'The calendar is saved.']];
render_screen('Costing calendars', view('periods/calendars/index.php', ['calendars' => $all, 'edit' => $edit, 'chosen' => $chosen, 'year' => $year, 'preview' => $preview, 'made' => $made, 'mayEnsure' => has_right('periods.close'),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'calendar-list', 'screen' => 'calendar-list', 'entity' => 'costing_calendar']);
