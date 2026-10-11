<?php /** The periods (screen `period-list`). Data: rows, figures, marks, year, years, status, toClose, undepleted, closingCounts, seesCost, mayClose, mayReopen, calendars, notice */
$reopenable = array_values(array_filter($rows, static fn (array $p): bool => in_array($p['period_id'], $marks['reopenable'], true)));
?>
<?= view('shared/header.php', ['id' => 'period-list', 'title' => 'Periods', 'crumbs' => [['Home', '/'], ['Usage', null], ['Periods', null]], 'back' => back_link()]) ?>
<div class="main-content" id="period-list-content">
    <?= view('usage/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/periods/" hx-get="/periods/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select" class="card mb-3" id="period-list-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="period-filter-year">Fiscal year</label><select name="year" id="period-filter-year" class="form-select btn-touch"><?php foreach (array_unique(array_merge($years, [$year])) as $y): ?><option value="<?= (int) $y ?>" <?= (int) $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="period-filter-status">Status</label><select name="status" id="period-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (PERIOD_STATUS_CHIPS as $k => $_): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="period-filter-apply">Show</button></div>
    </div></form>
    <?php if ($toClose !== null && $mayClose): ?>
        <?= view('periods/partials/close-form.php', ['p' => $toClose, 'closingCounts' => $closingCounts, 'undepleted' => $undepleted]) ?>
    <?php elseif ($toClose !== null && $undepleted !== []): ?>
        <div class="alert alert-warning" id="period-undepleted" role="status"><?= e(implode(' and ', array_map(static fn (string $d): string => format_date($d), $undepleted))) ?> <?= count($undepleted) === 1 ? 'is' : 'are' ?> not depleted yet — <?= e($toClose['label']) ?> cannot close until <?= count($undepleted) === 1 ? 'it is' : 'they are' ?> run. <?= hx_link('/sales/', 'Sales by day', 'alert-link') ?></div>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="card" id="period-list-empty"><div class="card-body">
            <p class="text-muted mb-2"><?= $yearEmpty ? 'There are no periods for ' . (int) $year . ' at this site.' : 'No period of ' . (int) $year . ' is ' . e($status) . '.' ?></p>
            <?php if (!$yearEmpty): ?>
            <?php elseif ($mayClose && $calendars === []): ?><p class="mb-0">No costing calendar is set yet.<?php if ($mayReopen): ?> <?= hx_link('/periods/calendars', 'Make one', 'fw-semibold') ?> first.<?php else: ?> Ask the administrator to make one.<?php endif; ?></p>
            <?php elseif ($mayClose): ?>
                <form method="post" action="/periods/ensure.php" hx-post="/periods/ensure.php" hx-target="#flash" class="row g-2 align-items-end" id="periods-ensure-form"><?= csrf_field() ?>
                    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="periods-ensure-form-field-fiscal_year">Fiscal year</label><input type="number" name="fiscal_year" id="periods-ensure-form-field-fiscal_year" min="2000" max="2100" value="<?= (int) $year ?>" class="form-control btn-touch" required></div>
                    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="periods-ensure-form-field-calendar">Calendar</label><select name="calendar" id="periods-ensure-form-field-calendar" class="form-select btn-touch"><?php foreach ($calendars as $c): ?><option value="<?= (int) $c['calendar_id'] ?>" <?= $c['is_default'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="periods-ensure-btn">Make the year's periods</button></div>
                </form>
            <?php endif; ?>
        </div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table align-middle mb-0" id="periods-table">
            <thead><tr><th>Period</th><th>Dates</th><th>Status</th><th class="text-end">Opening</th><th class="text-end">Purchases</th><th class="text-end">Ending</th><th class="text-end">COGS</th><th class="text-end">Food sales</th><th class="text-end">Food-cost %</th></tr></thead>
            <tbody><?php foreach ($rows as $p): ?><?= view('periods/partials/period-row.php', ['p' => $p, 'f' => $figures[$p['period_id']], 'marks' => $marks, 'seesCost' => $seesCost]) ?><?php endforeach; ?></tbody>
        </table></div></div>
        <?php $notBegun = count(array_filter($rows, static fn (array $p): bool => !$figures[$p['period_id']]['begun'])); ?>
        <div class="d-md-none" id="period-cards"><?php foreach ($rows as $p): if (!$figures[$p['period_id']]['begun']) { continue; } ?><?= view('periods/partials/period-row.php', ['p' => $p, 'f' => $figures[$p['period_id']], 'marks' => $marks, 'seesCost' => $seesCost, 'asCard' => true]) ?><?php endforeach; ?>
            <?php if ($notBegun > 0): ?><div class="fs-12 text-muted" id="period-cards-later"><?= $notBegun ?> later period<?= $notBegun === 1 ? '' : 's' ?> have not begun — they are on the desk's table.</div><?php endif; ?></div>
        <?php if ($mayReopen && $reopenable !== []): ?>
        <div class="card mt-3" id="period-reopen-card"><div class="card-body">
            <?php foreach ($reopenable as $i => $p): ?><?= view('periods/partials/reopen-form.php', ['p' => $p, 'suffix' => $i === 0 ? '' : '-' . (int) $p['period_id']]) ?><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
