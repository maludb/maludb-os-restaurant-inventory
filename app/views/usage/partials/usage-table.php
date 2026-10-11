<?php
/** actual vs theoretical (table `usage-table`). Data: u (usage()), w (the window), seesCost. One row per ingredient sorted as asked; the category subtotals and the grand total in the foot. */
$H = [
    'opening' => ['Opening', 'the approved count\'s figure when one closed the previous period, else the ledger as of the window\'s start (the site\'s sales cut)'],
    'purchases' => ['Purchases', 'the receipts in the window — reversals net out'],
    'transfers_in' => ['Transfers in', 'from the other site or area'], 'transfers_out' => ['Transfers out', 'to the other site or area'],
    'production_in' => ['Production in', 'a prep\'s output'], 'production_out' => ['Production out', 'a prep\'s inputs'],
    'adjustments' => ['Adjustments', 'signed — the reasons are on the adjustments'], 'closing' => ['Closing', 'as of the window\'s end — the approved count\'s figure when it closed the period'],
    'actual' => ['Actual', 'what left the shelves: opening + purchases ± transfers ± production + adjustments − closing'], 'theoretical' => ['Theoretical', 'Σ depletion of the window\'s sales'],
    'variance' => ['Variance', 'actual − theoretical'], 'waste' => ['Waste', 'the waste logged — explained'], 'comps' => ['Comps', 'the comped sales\' explosion — explained, inside theoretical already'],
    'unexplained' => ['Unexplained', 'variance − waste'],
];
$V = ['unit_cost' => ['Unit cost', 'per count unit at the basis'], 'opening_value' => ['Opening value', ''], 'purchases_value' => ['Purchases value', ''], 'closing_value' => ['Closing value', ''],
      'actual_value' => ['Actual value', ''], 'theoretical_value' => ['Theoretical value', ''], 'variance_value' => ['Variance value', ''], 'waste_value' => ['Waste value', ''], 'unexplained_value' => ['Unexplained value', '']];
$keyClass = ['actual' => 'u-actual u-key', 'theoretical' => 'u-theo u-key', 'unexplained' => 'u-unexp u-key'];
$here = here_url();
$qty = static function (array $r, string $b) {
    $v = $r[$b . '_count'];
    if ($v === null) { return '—'; }
    $s = fmt_num_adaptive($v);
    return (in_array($b, ['transfers_out', 'production_out'], true) && (float) $v != 0.0 ? '−' : '') . $s;
};
?>
<div class="card" id="usage-card"><div class="table-responsive"><table class="table align-middle mb-0 usage-table" id="usage-table">
    <thead><tr>
        <th class="u-ing u-key">Ingredient</th><th class="d-none d-md-table-cell">Category</th>
        <?php foreach ($H as $b => [$label, $hint]): ?><th class="num <?= e($keyClass[$b] ?? '') ?>" title="<?= e($hint) ?>"><?= $b === 'transfers_out' || $b === 'production_out' ? e($label) : e($label) ?></th><?php endforeach; ?>
        <?php foreach ($V as $k => [$label, $hint]): ?><th class="num" title="<?= e($hint) ?>"><?= e($label) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($u['rows'] as $r):
        $neg = (float) $r['variance_base'] < -0.00005;
        $over = (float) $r['theoretical_base'] > 0 && (float) $r['variance_base'] > 0.05 * (float) $r['theoretical_base'];
        $unex = abs((float) $r['unexplained_base']) > 0.02 * abs((float) $r['actual_base']) && abs((float) $r['unexplained_base']) > 0.00005;
        $link = with_back('/usage/' . $r['ingredient_id'] . '?' . http_build_query(['from' => $w['from'], 'to' => $w['to']]), $here); ?>
        <tr id="usage-row-<?= (int) $r['ingredient_id'] ?>">
            <td class="u-ing u-key"><?= hx_link($link, e($r['name']), 'fw-semibold', 'title="' . e($r['category']) . '"') ?><?php if ($r['has_late']): ?> <span class="badge bg-soft-info text-info" title="a receipt dated in a closed period landed here">late</span><?php endif; ?></td>
            <td class="d-none d-md-table-cell text-muted"><?= e($r['category']) ?></td>
            <?php foreach ($H as $b => [$label, $hint]):
                $cls = 'num ' . ($keyClass[$b] ?? '');
                $title = fmt_num($r[$b . '_base'], 4) . ' ' . $r['base_unit_code'] . ' · in ' . $r['count_unit'];
                if ($b === 'variance') { $cls .= $neg ? ' text-danger' : ($over ? ' text-warning' : ''); if ($neg) { $title = 'actual below theoretical — check the data: a receipt missing or a count wrong · ' . $title; } }
                if ($b === 'actual' || $b === 'theoretical') { $cls .= ' fw-semibold'; }
                if ($b === 'unexplained') { $cls .= $unex ? ' fw-bold' : ' fw-semibold'; } ?>
            <td class="<?= e($cls) ?>" title="<?= e($title) ?>" id="usage-cell-<?= (int) $r['ingredient_id'] ?>-<?= e($b) ?>"><?= e($qty($r, $b)) ?><?= $b === 'variance' && $neg ? ' <i class="feather-alert-circle" aria-label="check the data"></i>' : '' ?></td>
            <?php endforeach; ?>
            <td class="num" title="<?= $seesCost ? e('$' . fmt_num($r['unit_cost'], 6) . ' per ' . $r['base_unit_code']) : 'cost withheld' ?>"><?= $seesCost && $r['unit_cost_count'] !== null ? money_price((string) $r['unit_cost_count']) . ' / ' . e($r['count_unit']) : money(null) ?></td>
            <?php foreach (USAGE_VALUE_COLS as $k): ?><td class="num"><?= money($r[$k]) ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <?php foreach ($u['categories'] as $c): ?>
        <tr class="u-sub" id="usage-cat-<?= e($c['category_key']) ?>">
            <th class="u-ing u-key"><?= e($c['category']) ?> <span class="text-muted fw-normal">(<?= (int) $c['ingredients'] ?>)</span></th><td class="d-none d-md-table-cell"></td>
            <?php foreach ($H as $b => $_): ?><td class="num <?= e($keyClass[$b] ?? '') ?>">—</td><?php endforeach; ?>
            <td class="num"></td>
            <?php foreach (USAGE_VALUE_COLS as $k): ?><td class="num"><?= money($c[$k]) ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
        <tr class="fw-semibold" id="usage-totals">
            <th class="u-ing u-key">Total (<?= (int) $u['totals']['ingredients'] ?>)</th><td class="d-none d-md-table-cell"></td>
            <?php foreach ($H as $b => $_): ?><td class="num <?= e($keyClass[$b] ?? '') ?>">—</td><?php endforeach; ?>
            <td class="num"></td>
            <?php foreach (USAGE_VALUE_COLS as $k): ?><td class="num" id="usage-total-<?= e($k) ?>"><?= money($u['totals'][$k]) ?></td><?php endforeach; ?>
        </tr>
    </tfoot>
</table></div></div>
