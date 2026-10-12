<?php /** The day's form (`calendar-day-form`) → calendar_day_set. Data: d (calendar_day()), month */
$date = $d['on_date'];
?>
<div class="card mt-3" id="calendar-day-form-card"><div class="card-header"><h6 class="mb-0"><?= e(date('l, F j', (int) strtotime($date))) ?></h6></div><div class="card-body">
    <form method="post" action="/projection/calendar/save.php" hx-post="/projection/calendar/save.php" hx-target="#flash" id="calendar-day-form"><?= csrf_field() ?>
        <input type="hidden" name="on_date" value="<?= e($date) ?>" id="calendar-day-form-field-on_date">
        <div class="row g-2">
            <div class="col-12"><div class="form-check"><input type="hidden" name="closed" value="no"><input class="form-check-input" type="checkbox" name="closed" value="yes" id="calendar-day-form-field-closed" <?= $d['closed'] ? 'checked' : '' ?>><label class="form-check-label" for="calendar-day-form-field-closed">Closed — the day forecasts zero and is left out of the baseline's weeks</label></div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="calendar-day-form-field-holiday">Holiday</label><input type="text" name="holiday" id="calendar-day-form-field-holiday" class="form-control btn-touch" maxlength="80" value="<?= e((string) $d['holiday']) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="calendar-day-form-field-event">Event</label><input type="text" name="event" id="calendar-day-form-field-event" class="form-control btn-touch" maxlength="80" value="<?= e((string) $d['event']) ?>"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="calendar-day-form-field-note">Note</label><input type="text" name="note" id="calendar-day-form-field-note" class="form-control btn-touch" maxlength="200" value="<?= e((string) $d['note']) ?>"></div>
            <div class="col-12 col-md-4"><button type="submit" class="btn btn-primary btn-touch w-100" id="calendar-day-save-btn">Save the day</button></div>
            <div class="col-12 col-md-4"><?= hx_link('/projection/calendar?month=' . $month, 'Cancel', 'btn btn-light btn-touch w-100', 'id="calendar-day-cancel"') ?></div>
        </div>
        <?php if ($d['covers'] !== []): ?><div class="fs-12 mt-2 text-muted" id="calendar-day-covers">Booked: <?php foreach ($d['covers'] as $c): ?><?= e((string) ($c['day_part'] ?? 'whole day')) ?> <?= (int) $c['covers'] ?>; <?php endforeach; ?></div><?php endif; ?>
    </form>
</div></div>
