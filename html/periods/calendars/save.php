<?php
declare(strict_types=1);
/** Action `calendar_set` (log calendar.set; `other` for an agent — it pauses): settings.manage; name (up to 60), kind (week, month, 4-4-5, 4-5-4, 5-4-4, 13x4), year_start_on, week_starts (0..6, Monday by default), is_default (one default — the previous is cleared in the same transaction); `calendar` changes an existing one. Location /periods/calendars#calendar-row-{id}. */
require_once dirname(__DIR__, 3) . '/app/features/periods/handler.php';
period_begin('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$cur = null;
if (req_has('calendar') && (string) req_val('calendar') !== '') {
    $cur = find_calendar($pdo, (int) request_integer('calendar'));
    if ($cur === null) { respond_not_found('That calendar is not here.'); }
}
$errors = [];
$f = [];
$f['name'] = ri_text('name', $cur['name'] ?? null, 60, 'The name', $errors, $cur === null) ?? ($cur['name'] ?? '');
if ($cur === null && trim((string) $f['name']) === '' && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$f['kind'] = ri_choice('kind', $cur['kind'] ?? null, array_keys(CALENDAR_KINDS), 'The kind', $errors, false);
if ($f['kind'] === null && !isset($errors['kind'])) { $errors['kind'] = 'The kind is required.'; }
$ys = req_has('year_start_on') ? (string) req_val('year_start_on') : (string) ($cur['year_start_on'] ?? '');
$d = DateTimeImmutable::createFromFormat('!Y-m-d', $ys);
if ($ys === '') { $errors['year_start_on'] = 'The first day of the year is required.'; }
elseif ($d === false || $d->format('Y-m-d') !== $ys) { $errors['year_start_on'] = 'The first day of the year is a date (YYYY-MM-DD).'; }
$f['year_start_on'] = $ys;
$f['week_starts'] = ri_int('week_starts', $cur['week_starts'] ?? 1, 0, 6, 'The week start', $errors) ?? 1;
$f['is_default'] = ri_yes('is_default', $cur['is_default'] ?? false);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f): int {
    $pdo->beginTransaction();
    $id = save_calendar($pdo, $cur['calendar_id'] ?? null, $f);
    $after = ['kind' => $f['kind'], 'year_start_on' => $f['year_start_on'], 'is_default' => $f['is_default'], 'name' => $f['name'], 'week_starts' => $f['week_starts']];
    if ($cur === null) { period_log($pdo, 'calendar.set', 'costing_calendar', $id, ['after' => $after]); }
    else { $dd = ri_diff(['name' => $cur['name'], 'kind' => $cur['kind'], 'year_start_on' => $cur['year_start_on'], 'week_starts' => $cur['week_starts'], 'is_default' => $cur['is_default']], $after); period_log($pdo, 'calendar.set', 'costing_calendar', $id, ['before' => $dd['before'], 'after' => $dd['after'] + ['kind' => $f['kind'], 'year_start_on' => $f['year_start_on'], 'is_default' => $f['is_default']]]); }
    $pdo->commit();
    return $id;
});
ri_done(($cur === null ? 'Made the calendar ' : 'Saved the calendar ') . $f['name'], $id, ri_land('/periods/calendars', $cur === null ? 'created' : 'saved', 'calendar-row-' . $id), 'calendarChanged', ['calendar_id' => $id]);
