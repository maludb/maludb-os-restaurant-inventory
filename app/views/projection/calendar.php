<?php /** The trading calendar (screen `calendar-days`). Data: cal (calendar_month()), dayRow, mayAdjust, today, notice */
$month = $cal['month'];
$first = new DateTimeImmutable($month . '-01');
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$tz = site_timezone();
$sync = '';
if ($mayAdjust) {
    $sync = '<form method="post" action="/projection/calendar/covers.php" hx-post="/projection/calendar/covers.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-light btn-touch" id="covers-sync-btn"><i class="feather-refresh-cw me-1"></i>Sync covers</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'calendar-days', 'title' => 'Trading calendar', 'crumbs' => [['Home', '/'], ['Projection', null], ['Trading calendar', null]], 'back' => back_link(), 'action' => $sync]) ?>
<div class="main-content" id="calendar-days-content">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body py-2 d-flex align-items-center gap-2 cal-nav">
        <?= hx_link('/projection/calendar?month=' . $prev, '<i class="feather-chevron-left"></i> ' . e(date('M', (int) strtotime($prev . '-01'))), 'btn btn-light btn-touch', 'id="calendar-prev"') ?>
        <div class="fw-semibold flex-grow-1 text-center" id="calendar-month"><?= e($first->format('F Y')) ?></div>
        <?= hx_link('/projection/calendar?month=' . $next, e(date('M', (int) strtotime($next . '-01'))) . ' <i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="calendar-next"') ?>
    </div>
    <div class="card-footer fs-12 text-muted" id="covers-synced"><?php if ($cal['synced_at'] !== null): ?>Booked covers last synced <?= e(format_ts($cal['synced_at'], $tz, 'M j, g:i A')) ?>.<?php elseif ($cal['last_sync'] !== null && !$cal['last_sync']['connected']): ?>Reservations is not connected — the forecast runs without booked covers.<?php else: ?>No booked covers yet — <?= $mayAdjust ? 'Sync covers reads them from Reservations.' : 'a Manager can sync them from Reservations.' ?><?php endif; ?></div></div>
    <div class="cal-head" aria-hidden="true"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w): ?><div><?= $w ?></div><?php endforeach; ?></div>
    <div class="cal-grid" id="calendar-grid">
        <?php $pad = ((int) $cal['days'][0]['dow']) - 1; for ($i = 0; $i < $pad; $i++): ?><div class="cal-pad"></div><?php endfor; ?>
        <?php foreach ($cal['days'] as $d): ?><?= view('projection/partials/calendar-day.php', ['d' => $d, 'month' => $month, 'today' => $today, 'mayAdjust' => $mayAdjust]) ?><?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted mt-2" id="calendar-legend"><?= chip('closed', 'dark') ?> forecasts zero and is left out of the baseline's weeks. Past days show what was sold; today and ahead show the forecast across the menu. <i class="feather-users"></i> booked covers from Reservations.</div>
    <?php if ($dayRow !== null && $mayAdjust): ?><?= view('projection/partials/calendar-day-form.php', ['d' => $dayRow, 'month' => $month]) ?><?php endif; ?>
</div>
