<?php /** COGS and food-cost % by period (screen `cogs-report`). Data: c (cogs_by_period()), years, year, seesCost */
$weekly = $c['rows'] !== [] && $c['rows'][0]['calendar_kind'] === 'week';
$months = array_column($c['months'], null, 'month');
$pct = static fn (?string $v): string => $v === null ? '—' : fmt_num($v, 1) . ' %';
$pts = static fn (?string $v): string => $v === null ? '—' : ((float) $v > 0 ? '+' : '') . fmt_num($v, 1) . ' pts';
$cell = static function (array $r) use ($pct, $pts): string {
    return '<td class="text-end">' . money($r['food_sales']) . '</td><td class="text-end">' . money($r['cogs_value']) . '</td><td class="text-end">' . e($pct($r['food_cost_pct'])) . '</td><td class="text-end">' . money($r['theoretical_value'])
        . '</td><td class="text-end">' . e($pct($r['theoretical_pct'])) . '</td><td class="text-end ' . ($r['variance_value'] !== null && (float) $r['variance_value'] > 0 ? 'text-danger' : '') . '">' . money($r['variance_value']) . ' <span class="text-muted fs-12">' . e($pts($r['variance_points'])) . '</span></td><td class="text-end">' . money($r['waste_value']) . '</td>';
};
$monthOf = static fn (array $r): string => (new DateTimeImmutable($r['starts_on']))->format('Y-m');
?>
<?= view('shared/header.php', ['id' => 'cogs-report', 'title' => 'COGS', 'crumbs' => [['Home', '/'], ['Usage', null], ['COGS', null]], 'back' => back_link()]) ?>
<div class="main-content" id="cogs-report-content">
    <?= view('usage/partials/assets.php') ?>
    <form method="get" action="/usage/cogs" hx-get="/usage/cogs" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="cogs-form"><div class="card-body row g-2 align-items-end">
        <div class="col-8 col-md-3"><label class="form-label fs-12 text-muted" for="cogs-form-field-year">Fiscal year</label><select name="year" id="cogs-form-field-year" class="form-select btn-touch"><?php foreach (array_unique(array_merge($years, [$year])) as $y): ?><option value="<?= (int) $y ?>" <?= (int) $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="cogs-form-apply">Show</button></div>
    </div></form>
    <?php if (!$seesCost): ?><div class="alert alert-secondary" id="cogs-withheld" role="status">Cost is withheld from you at this site — COGS and the food-cost percentage are cost.</div><?php endif; ?>
    <?php if ($c['rows'] === []): ?>
        <div class="card" id="cogs-empty"><div class="card-body text-center text-muted py-5">No periods for <?= (int) $year ?> yet. <?= hx_link('/periods/?year=' . (int) $year, 'Make the year\'s periods') ?>.</div></div>
    <?php else: ?>
    <?php if ($seesCost): ?><div class="card mb-3" id="cogs-chart"><div class="card-header"><h5 class="card-title mb-0">Cost of goods sold by period</h5><div class="fs-12"><span class="cogs-key cogs-key-actual"></span>actual <span class="cogs-key cogs-key-theoretical ms-2"></span>theoretical</div></div>
        <div class="card-body cogs-chart-wrap"><?= cogs_chart_svg($c['rows']) ?></div></div><?php endif; ?>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0" id="cogs-table">
        <thead><tr><th>Period</th><th class="d-none d-md-table-cell">Dates</th><th class="text-end">Food sales</th><th class="text-end">COGS</th><th class="text-end">Food-cost %</th><th class="text-end">Theoretical COGS</th><th class="text-end">Theoretical %</th><th class="text-end">Variance</th><th class="text-end">Waste</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($c['rows'] as $i => $r): ?>
            <tr id="cogs-row-<?= (int) $r['period_id'] ?>"><td><?= hx_link(with_back('/periods/' . (int) $r['period_id'], here_url()), e(ucfirst(period_label($r, false))), 'fw-semibold') ?></td><td class="d-none d-md-table-cell text-muted"><?= e(format_date($r['starts_on'])) ?> – <?= e(format_date($r['ends_on'])) ?></td><?= $cell($r) ?><td><?= period_status_chip($r['status']) ?><?= $r['live'] ? ' <span class="text-muted fs-12">live</span>' : '' ?></td></tr>
            <?php $next = $c['rows'][$i + 1] ?? null;
            if ($weekly && $seesCost && ($next === null || $monthOf($next) !== $monthOf($r)) && isset($months[$monthOf($r)])): $m = $months[$monthOf($r)]; ?>
            <tr class="table-light fw-semibold" id="cogs-month-<?= e($m['month']) ?>"><td colspan="2"><?= e($m['label']) ?> <span class="text-muted fw-normal">(<?= (int) $m['periods'] ?> weeks)</span></td><?= $cell($m) ?><td></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold" id="cogs-totals"><td colspan="2">The year</td><?= $cell($c['totals']) ?><td></td></tr></tfoot>
    </table></div></div>
    <?php if ($c['not_begun'] > 0): ?><div class="fs-12 text-muted mt-2" id="cogs-not-begun"><?= (int) $c['not_begun'] ?> later period<?= $c['not_begun'] === 1 ? '' : 's' ?> of <?= (int) $year ?> have not begun.</div><?php endif; ?>
    <?php endif; ?>
</div>
