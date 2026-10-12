<?php /** One day of the calendar (`calendar-day-{date}`). Data: d, month, today, mayAdjust */
$date = $d['on_date'];
$cls = 'cal-day' . ($d['closed'] ? ' cal-closed' : '') . ($date === $today ? ' cal-today' : '');
$inner = '<div class="d-flex justify-content-between align-items-baseline"><span class="fw-semibold">' . e(date('j', (int) strtotime($date))) . '</span><span class="cal-dow fs-12 text-muted">' . e(date('D', (int) strtotime($date))) . '</span></div>'
    . ($d['closed'] ? '<div>' . chip('closed', 'dark') . '</div>' : '')
    . ($d['holiday'] ? '<div class="fs-12 fw-semibold text-warning">' . e($d['holiday']) . '</div>' : '')
    . ($d['event'] ? '<div class="fs-12">' . e($d['event']) . '</div>' : '')
    . ($d['covers'] !== null ? '<div class="fs-12" title="booked covers"><i class="feather-users me-1"></i>' . (int) $d['covers'] . '</div>' : '')
    . ($d['total'] !== null && !$d['closed'] ? '<div class="fs-12 text-muted" title="' . ($d['total_kind'] === 'forecast' ? 'forecast across the menu' : 'sold') . '">' . e(fc_qty($d['total'])) . ' <span class="fs-11">' . ($d['total_kind'] === 'forecast' ? 'fcst' : 'sold') . '</span></div>' : '');
?>
<div class="<?= e($cls) ?>" id="calendar-day-<?= e($date) ?>" data-closed="<?= $d['closed'] ? '1' : '0' ?>">
    <?php if ($mayAdjust): ?><?= hx_link('/projection/calendar?month=' . $month . '&day=' . $date . '#calendar-day-form', $inner, 'text-reset d-block text-decoration-none', 'id="calendar-day-open-' . e($date) . '" aria-label="' . e(date('l F j', (int) strtotime($date))) . '"') ?><?php else: ?><?= $inner ?><?php endif; ?>
</div>
