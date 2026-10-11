<?php /** The figure cards of a period (`period-figure-{name}`). Data: f (period_figures()), seesCost */
$cards = ['opening' => ['Opening', $f['opening_value'], 'money'], 'purchases' => ['Purchases', $f['purchases_value'], 'money'], 'ending' => ['Ending', $f['ending_value'], 'money'], 'cogs' => ['COGS', $f['cogs_value'], 'money'],
          'theoretical' => ['Theoretical', $f['theoretical_value'], 'money'], 'waste' => ['Waste', $f['waste_value'], 'money'], 'food_sales' => ['Food sales', $f['food_sales'], 'money'],
          'food_cost_pct' => ['Food-cost %', $f['food_cost_pct'], 'pct'], 'theoretical_pct' => ['Theoretical %', $f['theoretical_pct'], 'pct']];
?>
<div class="row g-2 mb-3" id="period-figures">
<?php foreach ($cards as $k => [$label, $v, $kind]): ?>
    <div class="col-6 col-md-4 col-lg period-figure" id="period-figure-<?= e($k) ?>"><div class="card h-100"><div class="card-body py-2">
        <div class="fs-12 text-muted"><?= e($label) ?><?= $f['live'] ? ' <span title="live — not frozen until the period closes">·</span>' : '' ?></div>
        <div class="fig-value"><?= !$f['begun'] ? '<span class="text-muted" title="not begun">—</span>' : ($kind === 'pct' ? ($v === null ? '<span class="text-muted" title="' . ($seesCost ? 'no sales' : 'cost withheld') . '">—</span>' : e(fmt_num($v, 1)) . ' %') : money($v)) ?></div>
    </div></div></div>
<?php endforeach; ?>
</div>
