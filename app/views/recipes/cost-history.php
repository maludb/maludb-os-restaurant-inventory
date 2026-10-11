<?php /** The cost per portion by week (screen recipe-cost-history; walled whole). Data: recipe, rows, siteId, sites, weeks, back */
$rid = (int) $recipe['recipe_id'];
$vals = array_filter(array_map(static fn (array $r) => $r['portion_cost'], $rows), static fn ($v): bool => $v !== null);
$max = $vals === [] ? 1.0 : max(1e-6, max($vals)); $n = count($rows); $w = 720; $h = 200; $pad = 24;
$points = []; $marks = []; $lastV = null;
foreach ($rows as $k => $r) { $x = $pad + ($n > 1 ? $k / ($n - 1) : 0) * ($w - 2 * $pad); if ($r['portion_cost'] !== null) { $points[] = round($x, 1) . ',' . round($h - $pad - (float) $r['portion_cost'] / $max * ($h - 2 * $pad), 1); } if ($r['version_no'] !== null && $r['version_no'] !== $lastV) { $marks[] = [$x, $r['version_no']]; $lastV = $r['version_no']; } }
?>
<?= view('shared/header.php', ['id' => 'recipe-cost-history', 'title' => 'Cost history — ' . $recipe['name'], 'crumbs' => [['Home', '/'], ['Recipes', '/recipes/'], [$recipe['name'], '/recipes/' . $rid], ['Cost history', null]], 'back' => $back ?? ['/recipes/' . $rid, 'the recipe']]) ?>
<div class="main-content" id="recipe-cost-history-content">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Cost per portion by week</h5>
        <form method="get" action="/recipes/<?= $rid ?>/cost" class="d-flex gap-2" hx-get="/recipes/<?= $rid ?>/cost" hx-target="#page-content" hx-trigger="change"><select name="site" class="form-select form-select-sm" aria-label="Site"><?php foreach ($sites as $s): ?><option value="<?= (int) $s['scope_id'] ?>" <?= (int) $s['scope_id'] === $siteId ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><select name="weeks" class="form-select form-select-sm" aria-label="Weeks"><?php foreach ([12, 26, 52, 104] as $wk): ?><option value="<?= $wk ?>" <?= $wk === $weeks ? 'selected' : '' ?>><?= $wk ?> weeks</option><?php endforeach; ?></select></form></div>
        <div class="card-body">
            <svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="w-100" id="recipe-cost-chart" role="img" aria-label="Cost per portion by week" style="max-height: 240px">
                <line x1="<?= $pad ?>" y1="<?= $h - $pad ?>" x2="<?= $w - $pad ?>" y2="<?= $h - $pad ?>" stroke="currentColor" stroke-opacity="0.2"/>
                <?php foreach ($marks as [$mx, $vno]): ?><line x1="<?= round($mx, 1) ?>" y1="<?= $pad ?>" x2="<?= round($mx, 1) ?>" y2="<?= $h - $pad ?>" stroke="#f59e0b" stroke-dasharray="3 3"/><text x="<?= round($mx + 3, 1) ?>" y="<?= $pad + 10 ?>" font-size="10" fill="#f59e0b">v<?= (int) $vno ?></text><?php endforeach; ?>
                <?php if (count($points) > 1): ?><polyline points="<?= implode(' ', $points) ?>" fill="none" stroke="#3454d1" stroke-width="2"/><?php elseif ($points !== []): ?><circle cx="<?= explode(',', $points[0])[0] ?>" cy="<?= explode(',', $points[0])[1] ?>" r="3" fill="#3454d1"/><?php endif; ?>
                <text x="<?= $pad ?>" y="<?= $pad - 6 ?>" font-size="10" fill="currentColor" fill-opacity="0.6"><?= e(ri_money($max)) ?></text>
            </svg>
            <?php if ($vals === []): ?><div class="text-muted fs-12">No cost in the window — no version in force or nothing received yet.</div><?php endif; ?>
        </div></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0" id="recipe-cost-table"><thead><tr><th>Week of</th><th>Version</th><th class="text-end">Cost per portion</th><th class="text-end">Menu price</th><th class="text-end">Food cost</th></tr></thead><tbody>
        <?php foreach (array_reverse($rows) as $r): ?><tr id="recipe-cost-week-<?= e($r['week']) ?>"><td><?= e(format_date($r['week'])) ?></td><td><?= $r['version_no'] !== null ? 'v' . (int) $r['version_no'] : '<span class="text-muted">none</span>' ?></td><td class="text-end"><?= $r['portion_cost'] !== null ? e(ri_money($r['portion_cost'], '$', 4)) : '—' ?></td><td class="text-end"><?= $r['menu_price'] !== null ? e(ri_money($r['menu_price'])) : '—' ?></td><td class="text-end"><?= $r['food_cost_pct'] !== null ? e(ri_fmt($r['food_cost_pct'], 1)) . ' %' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</div>
