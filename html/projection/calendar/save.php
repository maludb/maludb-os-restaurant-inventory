<?php
declare(strict_types=1);
/** Action `calendar_day_set` (log calendar.day_set): forecast.adjust; on_date, closed (yes/no), holiday, event, note. A closed day forecasts zero and is left out of the baseline's weeks. A day with nothing to say is no row. Location the month. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$site = (int) current_site_id();
$errors = [];
$date = proj_date('on_date', null, 'The day', $errors, true);
$cur = $date === null ? null : calendar_day($pdo, $site, $date);
$f = ['on_date' => $date, 'closed' => ri_yes('closed', $cur['closed'] ?? false),
      'holiday' => ri_text('holiday', $cur['holiday'] ?? null, 80, 'The holiday', $errors), 'event' => ri_text('event', $cur['event'] ?? null, 80, 'The event', $errors), 'note' => ri_text('note', $cur['note'] ?? null, 200, 'The note', $errors)];
if ($errors !== []) { ri_refuse_fields($errors); }
ri_guard($pdo, static function () use ($pdo, $site, $f): void { set_calendar_day($pdo, $site, $f); });
proj_log($pdo, 'calendar.day_set', 'site', $site, ['after' => ['on_date' => $date, 'closed' => $f['closed'], 'holiday' => $f['holiday'], 'event' => $f['event']], 'before' => ['closed' => $cur['closed'] ?? false, 'holiday' => $cur['holiday'] ?? null, 'event' => $cur['event'] ?? null]]);
ri_done(($f['closed'] ? 'Closed ' : 'Saved ') . date('D M j', (int) strtotime((string) $date)), (string) $date, ri_land('/projection/calendar?month=' . substr((string) $date, 0, 7), 'saved', 'calendar-day-' . $date), 'calendarChanged', ['on_date' => $date, 'closed' => $f['closed']]);
