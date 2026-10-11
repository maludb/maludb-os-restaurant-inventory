<?php /** The cost history chart and table (walled whole). Data: i, rows (cost_history, newest first), tz, seesCost */
if (!$seesCost): ?>
<div class="card" id="ingredient-cost-withheld"><div class="card-body text-muted fs-12">Cost is withheld from you here.</div></div>
<?php return; endif; ?>
<div class="card mb-3"><div class="card-body">
    <?= view('shared/series-chart.php', ['id' => 'ingredient-cost-chart', 'series' => cost_series($rows), 'unit' => '$ / ' . $i['base_unit_code'], 'table_id' => 'ingredient-cost-table', 'empty' => 'No cost yet — a receipt, an invoice, a supplier item\'s price or a cost override writes one.', 'title' => 'Cost per ' . $i['base_unit_code'] . ' at this site', 'tz' => $tz]) ?>
</div></div>
<div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-cost-table">
    <thead class="thead-light"><tr><th>When</th><th>Old → new per <?= e($i['base_unit_code']) ?></th><th>Per <?= e($i['recipe_unit_code']) ?></th><th>Source</th><th>Reason</th><th>By</th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="ingredient-cost-empty">No cost history yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $h): ?>
        <tr id="cost-row-<?= (int) $h['price_history_id'] ?>"><td><?= e(format_ts($h['changed_at'], $tz)) ?></td><td><?= $h['old_per_base'] !== null ? '$' . e(fmt_num($h['old_per_base'], 6)) . ' → ' : '' ?><strong>$<?= e(fmt_num($h['new_per_base'], 6)) ?></strong><?= $h['scope_id'] === null ? ' <span class="text-muted">(every site)</span>' : '' ?></td>
            <td>$<?= e(fmt_num(cost_per_unit(db(), (int) $i['ingredient_id'], (string) $h['new_per_base'], $i['recipe_unit_code']) ?? '0', 4)) ?></td><td><?= chip($h['source_kind'], 'light') ?><?= $h['per_unit_price'] !== null ? ' <span class="text-muted">$' . e(fmt_num($h['per_unit_price'], 2)) . ' / ' . e($h['per_unit_code']) . '</span>' : '' ?></td>
            <td><?= e($h['reason'] ?? '') ?></td><td><?= e($h['changed_by_name'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div></div>
