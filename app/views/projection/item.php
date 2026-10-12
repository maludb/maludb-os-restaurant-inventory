<?php /** One item's forecast (screen `forecast-item`). Data: f (item_forecast()), item, weekdays, on, actuals, mayAdjust, thinWeeks, notice */
$days = $f['days'];
$acc = $f['accuracy'];
$thin = intdiv((int) $item['days_sold'], 7) < $thinWeeks;
?>
<?= view('shared/header.php', ['id' => 'forecast-item', 'title' => $item['name'], 'crumbs' => [['Home', '/'], ['Projection', null], ['Forecast', '/projection/'], [$item['name'], null]], 'back' => back_link() ?? ['/projection/', 'the forecast']]) ?>
<div class="main-content" id="forecast-item-content" data-menu-item-id="<?= (int) $item['menu_item_id'] ?>">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h6 class="mb-0"><?= e($item['name']) ?></h6><span class="text-muted fs-12"><?= e((string) $item['plu']) ?><?= $item['category'] ? ' · ' . e($item['category']) : '' ?></span>
            <?= $thin ? chip('thin history', 'warning') : '' ?>
            <?= hx_link('/menu/' . (int) $item['menu_item_id'], 'the menu item', 'fs-12 ms-auto') ?></div>
        <?= forecast_chart_svg($actuals, $days, override_days(array_merge($f['overrides'], $f['proposed'])), $item['name']) ?>
        <?php if ($acc !== null): ?>
            <div class="fs-12 mt-2" id="forecast-accuracy"><span class="text-muted">Accuracy of the baseline, last 28 days:</span> MAE <?= e(fmt_num($acc['mae'], 2)) ?> · WAPE <?= $acc['wape'] === null ? '—' : e(number_format((float) $acc['wape'] * 100, 0)) . ' %' ?> · bias <?= $acc['bias'] === null ? '—' : ((float) $acc['bias'] > 0 ? '+' : '') . e(number_format((float) $acc['bias'] * 100, 0)) . ' %' ?> · <?= (int) $acc['n'] ?> days</div>
        <?php else: ?>
            <div class="fs-12 text-muted mt-2" id="forecast-accuracy">Not scored yet — the first run scores the baseline.</div>
        <?php endif; ?>
    </div></div>

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card mb-3"><div class="card-header"><h6 class="mb-0">The next three weeks</h6></div><div class="fc-table-wrap"><table class="table table-sm align-middle mb-0 fc-table" id="forecast-days-table">
                <thead><tr><th>Day</th><th class="num">Forecast</th><th>Source</th><th class="num d-none d-md-table-cell">Band</th><th class="num d-none d-md-table-cell" title="booked covers against the weekday's norm">Covers factor</th></tr></thead><tbody>
                <?php foreach ($days as $d): ?>
                    <tr id="forecast-day-<?= e($d['on_date']) ?>" class="<?= $d['source'] === 'closed' ? 'table-active' : '' ?>">
                        <td><?= e(date('D M j', (int) strtotime($d['on_date']))) ?></td>
                        <td class="num fw-semibold"><?= e(fc_qty($d['qty'])) ?></td>
                        <td><?= forecast_source_chip($d['source']) ?></td>
                        <td class="num d-none d-md-table-cell text-muted"><?= $d['lo'] === null ? '—' : e(fc_qty($d['lo'])) . '–' . e(fc_qty($d['hi'])) ?></td>
                        <td class="num d-none d-md-table-cell"><?= (float) $d['covers_factor'] == 1.0 ? '<span class="text-muted">—</span>' : '×' . e(fmt_num($d['covers_factor'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?></tbody></table></div></div>
            <div class="card mb-3" id="same-weekdays"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h6 class="mb-0">The same weekdays</h6>
                <form method="get" action="/projection/items/<?= (int) $item['menu_item_id'] ?>" hx-get="/projection/items/<?= (int) $item['menu_item_id'] ?>" hx-target="#page-content" hx-push-url="true" hx-trigger="change" class="ms-auto"><label class="visually-hidden" for="same-weekdays-on">Day</label><input type="date" name="on" id="same-weekdays-on" class="form-control form-control-sm btn-touch" value="<?= e($on) ?>"></form></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="same-weekdays-table"><thead><tr><th>Day</th><th class="num">Sold</th><th class="num">Weight</th><th></th></tr></thead><tbody>
                <?php foreach ($weekdays as $w): ?><tr><td><?= e(date('D M j', (int) strtotime($w['on_date']))) ?></td><td class="num"><?= $w['qty'] === null ? '—' : e(fc_qty($w['qty'])) ?></td><td class="num"><?= $w['used'] ? e(fmt_num($w['weight'], 1)) : '<span class="text-muted">—</span>' ?></td><td class="fs-12 text-muted"><?= $w['why'] === null ? 'used' : e($w['why']) . ' — left out, the rest re-weighed' ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="card-footer fs-12 text-muted">The baseline is these four days weighted 0.4, 0.3, 0.2, 0.1 (a closed day or a day with no sales recorded is left out).</div></div>
        </div>
        <div class="col-12 col-xl-5">
            <?php if ($f['proposed'] !== []): ?>
            <div class="card mb-3" id="override-proposals"><div class="card-header"><h6 class="mb-0">Proposed overrides</h6></div><div class="list-group list-group-flush">
                <?php foreach ($f['proposed'] as $o): ?><div class="list-group-item" id="override-row-<?= (int) $o['override_id'] ?>"><?= view('projection/partials/override-row.php', ['o' => $o, 'mayAdjust' => $mayAdjust, 'proposed' => true]) ?></div><?php endforeach; ?></div></div>
            <?php endif; ?>
            <div class="card mb-3" id="overrides-card"><div class="card-header"><h6 class="mb-0">Overrides in force</h6></div>
                <?php if ($f['overrides'] === []): ?><div class="card-body text-muted fs-13" id="overrides-empty">None. A person's override beats the run: a festival, a rainy week, a catering order.</div>
                <?php else: ?><div class="list-group list-group-flush"><?php foreach ($f['overrides'] as $o): ?><div class="list-group-item" id="override-row-<?= (int) $o['override_id'] ?>"><?= view('projection/partials/override-row.php', ['o' => $o, 'mayAdjust' => $mayAdjust, 'proposed' => false]) ?></div><?php endforeach; ?></div><?php endif; ?></div>
            <?php if ($mayAdjust): ?><?= view('projection/partials/override-form.php', ['item' => $item, 'errors' => []]) ?><?php endif; ?>
        </div>
    </div>
</div>
