<?php
declare(strict_types=1);
/** Action `periods_ensure` (log period.ensure): periods.close; fiscal_year (2000..2100), calendar (the default one when left out) — ri_periods_ensure() makes the year's periods at this site, 0 when they exist. Location /periods/?year={year}. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
period_begin('periods.close');
$pdo = db();
$site = (int) current_site_id();
$errors = [];
$year = ri_int('fiscal_year', null, 2000, 2100, 'The fiscal year', $errors);
if ($year === null && !isset($errors['fiscal_year'])) { $errors['fiscal_year'] = 'The fiscal year is required.'; }
$cal = ri_ref($pdo, 'calendar', null, 'SELECT 1 FROM mcp_costing_calendars WHERE calendar_id = :id', 'costing calendar', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$made = ri_guard($pdo, static function () use ($pdo, $site, $year, $cal): int {
    $pdo->beginTransaction();
    $n = ensure_periods($pdo, $site, $year, $cal);
    $calId = $cal ?? (int) one_value($pdo, 'SELECT calendar_id FROM mcp_costing_calendars WHERE is_default');
    period_log($pdo, 'period.ensure', 'site', $site, ['after' => ['fiscal_year' => $year, 'calendar_id' => $calId, 'made' => $n]]);
    $pdo->commit();
    return $n;
});
ri_done($made === 0 ? 'The periods of ' . $year . ' were already made' : 'Made ' . $made . ' periods for ' . $year, $site, ri_land('/periods/?year=' . $year, 'ensured'), 'periodChanged', ['fiscal_year' => $year, 'made' => $made]);
