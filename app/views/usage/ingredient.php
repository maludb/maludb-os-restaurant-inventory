<?php /** One ingredient's window (screen `usage-ingredient`). Data: d (usage_ingredient()), w, seesCost */
$i = $d['ingredient'];
$row = $d['row'];
$tz = site_timezone();
$here = here_url();
$win = http_build_query(['from' => $w['from'], 'to' => $w['to']]);
$H = ['opening' => 'Opening', 'purchases' => 'Purchases', 'transfers_in' => 'Transfers in', 'transfers_out' => 'Transfers out', 'production_in' => 'Production in', 'production_out' => 'Production out',
      'adjustments' => 'Adjustments', 'closing' => 'Closing', 'actual' => 'Actual', 'theoretical' => 'Theoretical', 'variance' => 'Variance', 'waste' => 'Waste', 'comps' => 'Comps', 'unexplained' => 'Unexplained'];
?>
<?= view('shared/header.php', ['id' => 'usage-ingredient', 'title' => $i['name'], 'crumbs' => [['Home', '/'], ['Actual vs theoretical', '/usage/?' . $win], [$i['name'], null]], 'back' => back_link() ?? ['/usage/?' . $win, 'Actual vs theoretical']]) ?>
<div class="main-content" id="usage-ingredient-content">
    <?= view('usage/partials/assets.php') ?>
    <div class="fs-12 text-muted mb-2" id="usage-ingredient-window"><?= hx_link('/ingredients/' . (int) $i['ingredient_id'], e($i['name']), 'fw-semibold') ?> · <?= e($i['code']) ?> · <?= e(format_date($w['from'])) ?> to <?= e(format_date($w['to'])) ?> · in <?= e($row['count_unit'] ?? $i['base_unit_code']) ?></div>
    <?php if ($row === null): ?>
        <div class="card mb-3" id="usage-ingredient-empty"><div class="card-body text-center text-muted py-4">Nothing moved for this ingredient in that window.</div></div>
    <?php else: ?>
    <div class="row g-2 mb-3" id="usage-ingredient-buckets">
        <?php foreach ($H as $b => $label): $neg = $b === 'variance' && (float) $row['variance_base'] < -0.00005; ?>
            <div class="col-6 col-md-3 col-lg-2"><div class="card h-100"><div class="card-body py-2">
                <div class="fs-12 text-muted"><?= e($label) ?></div>
                <div class="fw-semibold <?= $neg ? 'text-danger' : '' ?>" id="bucket-<?= e($b) ?>" title="<?= e(fmt_num($row[$b . '_base'], 4) . ' ' . $i['base_unit_code']) ?>"><?= e((in_array($b, ['transfers_out', 'production_out'], true) && (float) $row[$b . '_count'] != 0.0 ? '−' : '') . fmt_num_adaptive($row[$b . '_count'])) ?></div>
                <?php if (in_array($b, ['opening', 'purchases', 'closing', 'actual', 'theoretical', 'variance', 'waste', 'unexplained'], true)): ?><div class="fs-12 text-muted"><?= money($row[$b . '_value']) ?></div><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
    </div>
    <?php if ((float) $row['variance_base'] < -0.00005): ?><div class="alert alert-warning" id="usage-ingredient-check" role="status">Actual is below theoretical — check the data: a receipt missing or a count wrong.</div><?php endif; ?>
    <p class="fs-12 text-muted" id="usage-ingredient-note">This ingredient's variance is at the ingredient — the recipes beside it are where it went.</p>
    <?php endif; ?>
    <div class="card mb-3" id="usage-consumers"><div class="card-header"><h5 class="card-title mb-0">The recipes that consumed it</h5></div>
    <?php if ($d['consumers'] === []): ?><div class="card-body text-muted fs-12">No recipe took it in that window.</div><?php else: ?>
        <div class="table-responsive"><table class="table mb-0" id="usage-consumers-table"><thead><tr><th>Recipe</th><th>Kind</th><th class="text-end">Used</th><th class="text-end">Share</th></tr></thead><tbody>
        <?php foreach ($d['consumers'] as $c): ?><tr id="consumer-row-<?= (int) $c['recipe_id'] ?>"><td><?= hx_link(with_back('/recipes/' . (int) $c['recipe_id'], $here), e($c['name'] ?? $c['recipe'])) ?></td><td class="text-muted"><?= e($c['kind'] === 'prep' ? 'prep' : ($c['kind'] === 'menu_item' ? 'menu item' : $c['kind'])) ?></td><td class="text-end"><?= e(fmt_num($c['qty_base'], 2) . ' ' . $i['base_unit_code']) ?></td><td class="text-end"><?= e($c['share_pct'] === null ? '—' : fmt_num($c['share_pct'], 1) . ' %') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?></div>
    <div class="card mb-3" id="usage-waste"><div class="card-header"><h5 class="card-title mb-0">Waste logged</h5></div>
    <?php if ($d['waste'] === []): ?><div class="card-body text-muted fs-12">No waste logged for it in that window.</div><?php else: ?>
        <div class="table-responsive"><table class="table mb-0" id="usage-waste-table"><thead><tr><th>Waste log</th><th>Date</th><th>Reason</th><th class="text-end">Quantity</th><th class="text-end">Cost</th></tr></thead><tbody>
        <?php foreach ($d['waste'] as $x): ?><tr id="waste-row-<?= (int) $x['waste_log_id'] ?>"><td><?= hx_link(with_back('/waste/' . (int) $x['waste_log_id'], $here), e($x['number'])) ?></td><td><?= e(format_date($x['business_date'])) ?></td><td><?= e($x['reason']) ?></td><td class="text-end"><?= e(fmt_num($x['qty_base'], 2) . ' ' . $i['base_unit_code']) ?></td><td class="text-end"><?= money($x['cost']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?></div>
    <div class="card mb-3" id="usage-count-lines"><div class="card-header"><h5 class="card-title mb-0">Counted in the window</h5></div>
    <?php if ($d['count_lines'] === []): ?><div class="card-body text-muted fs-12">No approved count covered it in that window.</div><?php else: ?>
        <div class="table-responsive"><table class="table mb-0" id="usage-count-lines-table"><thead><tr><th>Count</th><th>As of</th><th class="text-end">Book</th><th class="text-end">Counted</th><th class="text-end">Variance</th></tr></thead><tbody>
        <?php foreach ($d['count_lines'] as $l): ?><tr id="count-line-row-<?= (int) $l['count_line_id'] ?>"><td><?= hx_link(with_back('/counts/' . (int) $l['count_id'] . '/history', $here), e($l['number'])) ?></td><td><?= e(format_ts($l['as_of'], $tz, 'M j g:i A')) ?></td>
            <td class="text-end"><?= $l['system_qty_base'] === null ? '—' : e(fmt_num($l['system_qty_base'], 2) . ' ' . $i['base_unit_code']) ?></td><td class="text-end"><?= e(fmt_num($l['recount_qty_base'] ?? $l['counted_qty_base'], 2) . ' ' . $i['base_unit_code']) ?></td>
            <td class="text-end"><?= $l['variance_base'] === null ? '—' : e(fmt_num($l['variance_base'], 2)) ?> <span class="text-muted fs-12"><?= $seesCost ? money($l['variance_value']) : '' ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?></div>
    <?php if ($seesCost): ?><div class="card mb-3" id="usage-cost-history"><div class="card-header"><h5 class="card-title mb-0">Cost history in the window</h5></div>
    <?php if ($d['cost_history'] === []): ?><div class="card-body text-muted fs-12">The cost did not change in that window.</div><?php else: ?>
        <div class="table-responsive"><table class="table mb-0 fs-12"><tbody><?php foreach ($d['cost_history'] as $h): ?><tr><td class="text-nowrap"><?= e(format_ts($h['changed_at'], $tz, 'M j g:i A')) ?></td><td>$<?= e(fmt_num($h['new_per_base'], 6)) ?> / <?= e($i['base_unit_code']) ?></td><td class="text-muted"><?= e($h['source_kind']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?></div><?php endif; ?>
    <div class="card" id="usage-movements"><div class="card-header"><h5 class="card-title mb-0">Movements</h5></div>
    <?php if ($d['movements']['rows'] === []): ?><div class="card-body text-muted fs-12">No movement in that window.</div><?php else: ?>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="usage-movements-table"><thead><tr><th>When</th><th>Type</th><th>Area</th><th class="text-end">Quantity</th><th>Document</th></tr></thead><tbody>
        <?php foreach ($d['movements']['rows'] as $m): ?><tr id="movement-row-<?= (int) $m['transaction_id'] ?>"><td class="text-nowrap"><?= e(format_ts($m['occurred_at'], $tz, 'M j g:i A')) ?></td><td><?= txn_chip($m['txn_type']) ?><?= $m['late'] ? ' ' . chip('late', 'info') : '' ?></td><td><?= e($m['area']) ?></td><td class="text-end"><?= e(fmt_num($m['qty_base'], 2) . ' ' . $m['base_unit_code']) ?></td><td><?= e((string) $m['document']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($d['movements']['total'] > count($d['movements']['rows'])): ?><div class="card-footer fs-12"><?= hx_link(with_back('/stock/movements?' . http_build_query(['ingredient' => $i['ingredient_id'], 'from' => $w['from'], 'to' => $w['to']]), $here), 'All ' . (int) $d['movements']['total'] . ' movements') ?></div><?php endif; ?>
    <?php endif; ?></div>
</div>
