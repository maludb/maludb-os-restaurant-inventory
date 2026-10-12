<?php
declare(strict_types=1);
/** Action `covers_sync` (log forecast.covers_sync): forecast.adjust; from (today), to (62 days on) — the booked covers read from Reservations through K7. Reservations not connected is a 200 that says so: the forecast runs without covers. Location the calendar. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$site = (int) current_site_id();
$errors = [];
$today = proj_today($pdo);
$from = proj_date('from_date', $today, 'The first day', $errors) ?? $today;
$to = proj_date('to_date', (new DateTimeImmutable($from))->modify('+61 days')->format('Y-m-d'), 'The last day', $errors) ?? (new DateTimeImmutable($from))->modify('+61 days')->format('Y-m-d');
if ($errors === [] && $to < $from) { $errors['to_date'] = 'The last day is not before the first.'; }
if ($errors === [] && (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 185) { $errors['to_date'] = 'Sync up to 185 days at a time.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static fn (): array => sync_covers($pdo, $site, $from, $to));
proj_log($pdo, 'forecast.covers_sync', 'site', $site, ['after' => ['from' => $from, 'to' => $to, 'days' => $r['days'], 'covers' => $r['covers'], 'connected' => $r['connected'], 'code' => $r['code']]]);
$did = $r['connected'] ? 'Booked covers synced: ' . $r['covers'] . ' covers over ' . $r['days'] . ' day' . ($r['days'] === 1 ? '' : 's')
    : ($r['code'] === 'no_connection' || $r['code'] === 'not_shared' ? 'Reservations is not connected — the forecast runs without covers' : 'Reservations could not be read right now (' . ($r['code'] ?? 'no answer') . ') — the forecast runs without covers');
ri_done($did, $site, ri_land('/projection/calendar?month=' . substr($from, 0, 7), $r['connected'] ? 'synced' : 'notconnected'), 'calendarChanged', ['connected' => $r['connected'], 'days' => $r['days'], 'covers' => $r['covers'], 'from' => $from, 'to' => $to]);
