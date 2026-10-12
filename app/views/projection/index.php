<?php /** The forecast (screen `forecast-list`). Data: l (forecast_list()), categories, category, mayRun, thinWeeks, notice */
$run = $l['run'];
$action = $mayRun ? '<form method="post" action="/projection/run.php" hx-post="/projection/run.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-primary btn-touch" id="forecast-run-btn"><i class="feather-refresh-cw me-1"></i>Run the forecast now</button></form>' : '';
?>
<?= view('shared/header.php', ['id' => 'forecast-list', 'title' => 'Forecast', 'crumbs' => [['Home', '/'], ['Projection', null], ['Forecast', null]], 'back' => back_link(), 'action' => $action]) ?>
<div class="main-content" id="forecast-list-content">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="forecast-run-card"><div class="card-body py-2 fs-13">
        <?php if ($run === null): ?>
            <span class="fw-semibold">Baseline — no run yet.</span> <span class="text-muted">Every number is the same weekday's weighted four-week average; <?= $mayRun ? 'Run the forecast now to keep a run.' : 'a Manager can run the forecast.' ?></span>
        <?php else: ?>
            <span class="fw-semibold"><?= $run['method'] === 'holt_winters' ? 'Holt-Winters' : 'Baseline' ?> run</span> <span class="text-muted">made <?= e(format_ts($run['made_at'], site_timezone(), 'M j, g:i A')) ?> · <?= (int) $run['items'] ?> item<?= $run['items'] === 1 ? '' : 's' ?> · <?= (int) $run['horizon_days'] ?> days ahead<?= $run['method'] === 'holt_winters' ? ' · items with under twelve weeks of history stay on the baseline' : '' ?></span>
        <?php endif; ?>
    </div></div>
    <form method="get" action="/projection/" hx-get="/projection/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select" class="card mb-3" id="forecast-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="forecast-filter-category">Category</label><select name="category" id="forecast-filter-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $category === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-2"><label class="form-label fs-12 text-muted" for="forecast-filter-horizon">Days</label><select name="horizon" id="forecast-filter-horizon" class="form-select btn-touch"><?php foreach (FORECAST_HORIZONS as $h): ?><option value="<?= $h ?>" <?= $l['horizon'] === $h ? 'selected' : '' ?>><?= $h ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-3"><label class="form-label fs-12 text-muted" for="forecast-filter-sort">Sort</label><select name="sort" id="forecast-filter-sort" class="form-select btn-touch"><?php foreach (FORECAST_SORTS as $k => $w): ?><option value="<?= e($k) ?>" <?= $l['sort'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="forecast-filter-apply">Show</button></div>
    </div></form>
    <?php if ($l['rows'] === []): ?>
        <div class="card" id="forecast-empty"><div class="card-body text-center text-muted py-5">No menu item has sold in the last 84 days, so there is nothing to forecast yet. Load a POS file or wait for the next pull.</div></div>
    <?php else: ?>
    <div class="card"><div class="fc-table-wrap"><table class="table table-hover align-middle mb-0 fc-table" id="forecast-table">
        <thead><tr><th>Menu item</th><?php foreach ($l['dates'] as $d): ?><th class="num fc-cell" title="<?= e(format_date($d)) ?>"><?= e(date('D j', (int) strtotime($d))) ?></th><?php endforeach; ?><th class="num">Total <?= (int) $l['horizon'] ?>d</th><th class="num d-none d-md-table-cell">Band</th><th class="num d-none d-md-table-cell" title="weighted absolute percentage error of the baseline, last 28 days">WAPE</th><th class="num d-none d-md-table-cell" title="plus: forecast too high; minus: too low">Bias</th><th class="d-none d-md-table-cell"></th></tr></thead>
        <tbody><?php foreach ($l['rows'] as $r): ?><?= view('projection/partials/forecast-row.php', ['r' => $r, 'horizon' => $l['horizon'], 'thinWeeks' => $thinWeeks]) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2" id="forecast-legend"><?= forecast_source_chip('run') ?> the run's forecast <?= forecast_source_chip('baseline') ?> the same weekday's weighted average <?= forecast_source_chip('override') ?> a person's override <?= forecast_source_chip('closed') ?> closed. A booked-covers factor, when covers differ from the weekday's norm by 15 % or more, is already in the number.</div>
    <?php endif; ?>
</div>
