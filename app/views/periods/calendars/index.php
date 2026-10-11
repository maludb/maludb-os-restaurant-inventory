<?php /** The costing calendars (screen `calendar-list`). Data: calendars, edit, chosen, year, preview, made, mayEnsure, notice */
$cur = $edit;
$val = static fn (string $k, mixed $d = ''): string => (string) ($cur[$k] ?? $d);
?>
<?= view('shared/header.php', ['id' => 'calendar-list', 'title' => 'Costing calendars', 'crumbs' => [['Home', '/'], ['Usage', null], ['Costing calendars', null]], 'back' => back_link()]) ?>
<div class="main-content" id="calendar-list-content">
    <?= view('usage/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($calendars === []): ?><div class="alert alert-info" id="calendar-list-empty" role="status">No costing calendar is set yet. Make one below — weeks are the default shape, with a monthly roll-up on the COGS report.</div>
    <?php else: ?>
    <div class="card mb-3"><div class="table-responsive"><table class="table align-middle mb-0" id="calendars-table">
        <thead><tr><th>Name</th><th>Kind</th><th>Year starts</th><th class="d-none d-md-table-cell">Week starts</th><th>Default</th><th></th></tr></thead><tbody>
        <?php foreach ($calendars as $c): ?>
            <tr id="calendar-row-<?= (int) $c['calendar_id'] ?>"><td class="fw-semibold"><?= e($c['name']) ?></td><td><?= e(CALENDAR_KINDS[$c['kind']] ?? $c['kind']) ?></td><td><?= e(format_date($c['year_start_on'])) ?></td><td class="d-none d-md-table-cell"><?= e(WEEK_STARTS[$c['week_starts']] ?? '') ?></td><td><?= $c['is_default'] ? chip('default', 'primary') : '' ?></td>
                <td class="text-end"><?= hx_link('/periods/calendars?edit=' . (int) $c['calendar_id'] . '#calendar-form', 'Change', 'btn btn-sm btn-light btn-touch') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= $cur === null ? 'New calendar' : 'Change ' . e($cur['name']) ?></h5></div><div class="card-body">
    <form method="post" action="/periods/calendars/save.php" hx-post="/periods/calendars/save.php" hx-target="#flash" class="row g-3" id="calendar-form"><?= csrf_field() ?>
        <?php if ($cur !== null): ?><input type="hidden" name="calendar" value="<?= (int) $cur['calendar_id'] ?>"><?php endif; ?>
        <div class="col-12 col-md-4"><label class="form-label" for="calendar-form-field-name">Name</label><input type="text" name="name" id="calendar-form-field-name" maxlength="60" required value="<?= e($val('name')) ?>" class="form-control btn-touch"></div>
        <div class="col-6 col-md-3"><label class="form-label" for="calendar-form-field-kind">Kind</label><select name="kind" id="calendar-form-field-kind" class="form-select btn-touch" required><?php foreach (CALENDAR_KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $val('kind', 'week') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label" for="calendar-form-field-year_start_on">Year starts on</label><input type="date" name="year_start_on" id="calendar-form-field-year_start_on" required value="<?= e($val('year_start_on')) ?>" class="form-control btn-touch"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="calendar-form-field-week_starts">Week starts</label><select name="week_starts" id="calendar-form-field-week_starts" class="form-select btn-touch"><?php foreach (WEEK_STARTS as $k => $label): ?><option value="<?= (int) $k ?>" <?= (int) $val('week_starts', 1) === (int) $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 d-flex align-items-end"><input type="hidden" name="is_default" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="calendar-form-field-is_default" <?= ($cur['is_default'] ?? $calendars === []) ? 'checked' : '' ?>><label class="form-check-label" for="calendar-form-field-is_default">The default calendar</label></div></div>
        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="calendar-form-save"><?= $cur === null ? 'Make the calendar' : 'Save' ?></button><?php if ($cur !== null): ?><?= hx_link('/periods/calendars', 'Cancel', 'btn btn-light btn-touch') ?><?php endif; ?></div>
    </form></div></div>
    <?php if ($chosen !== null): ?>
    <div class="card" id="calendar-ensure"><div class="card-header"><h5 class="card-title mb-0">Make the year's periods at this site</h5></div><div class="card-body">
        <form method="get" action="/periods/calendars" hx-get="/periods/calendars" hx-target="#page-content" hx-push-url="true" class="row g-2 align-items-end mb-3" id="calendar-preview-form">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="calendar-preview-calendar">Calendar</label><select name="calendar" id="calendar-preview-calendar" class="form-select btn-touch"><?php foreach ($calendars as $c): ?><option value="<?= (int) $c['calendar_id'] ?>" <?= $c['calendar_id'] === $chosen['calendar_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="calendar-preview-year">Fiscal year</label><input type="number" name="year" id="calendar-preview-year" min="2000" max="2100" value="<?= (int) $year ?>" class="form-control btn-touch"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="calendar-preview-apply">Preview</button></div>
        </form>
        <div id="calendar-preview"><?php if ($preview === []): ?><p class="text-muted mb-0">Choose a fiscal year from 2000 to 2100.</p><?php else: ?>
            <p class="fs-12 text-muted" id="calendar-preview-note"><?= count($preview) ?> periods would be made<?= $made > 0 ? ' — ' . (int) $made . ' already exist at this site' : '' ?>.</p>
            <div class="table-responsive"><table class="table table-sm fs-12 mb-3" id="calendar-preview-table"><thead><tr><th>No</th><th>From</th><th>To</th><th class="text-end">Days</th></tr></thead><tbody>
            <?php foreach ($preview as $p): ?><tr id="calendar-preview-row-<?= (int) $p['no'] ?>"><td><?= (int) $p['no'] ?></td><td><?= e(format_date($p['starts_on'])) ?></td><td><?= e(format_date($p['ends_on'])) ?></td><td class="text-end"><?= (int) $p['days'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php if ($mayEnsure): ?>
            <form method="post" action="/periods/ensure.php" hx-post="/periods/ensure.php" hx-target="#flash" id="periods-ensure-form"><?= csrf_field() ?><input type="hidden" name="fiscal_year" value="<?= (int) $year ?>"><input type="hidden" name="calendar" value="<?= (int) $chosen['calendar_id'] ?>">
                <button type="submit" class="btn btn-primary btn-touch" id="periods-ensure-btn"<?= $made >= count($preview) ? ' disabled' : '' ?>><?= $made >= count($preview) ? 'Already made' : 'Make the year\'s periods' ?></button></form>
            <?php endif; ?>
        <?php endif; ?></div>
    </div></div>
    <?php endif; ?>
</div>
