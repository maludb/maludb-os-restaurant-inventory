<?php /** The read-only lines of a version with the cost column (walled). Data: lines, cost, seesCost, versionId */ ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0" id="recipe-lines-table">
    <thead><tr><th>#</th><th>Ingredient / sub-recipe</th><th class="text-end">Quantity</th><th class="text-end">In base</th><th class="text-end">Yield</th><th>Group</th><?php if ($seesCost): ?><th class="text-end">Cost</th><th class="text-end">Share</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $id = (int) $l['recipe_line_id']; ?>
        <tr id="recipe-line-row-<?= $id ?>" class="<?= $l['optional'] ? 'text-muted' : '' ?>">
            <td><?= (int) $l['seq'] ?></td>
            <td><?php if ($l['ingredient_id'] !== null): ?><?= hx_link('/ingredients/' . (int) $l['ingredient_id'], e($l['ingredient_name']), 'text-dark') ?><?php else: ?>↳ <?= hx_link('/recipes/' . (int) $l['sub_recipe_id'], e($l['sub_recipe_name']), 'text-dark') ?> <span class="badge bg-soft-<?= RECIPE_KIND_COLOR[$l['sub_recipe_kind']] ?? 'secondary' ?> text-<?= RECIPE_KIND_COLOR[$l['sub_recipe_kind']] ?? 'secondary' ?>"><?= e(RECIPE_KINDS[$l['sub_recipe_kind']] ?? $l['sub_recipe_kind']) ?></span><?php endif; ?><?= $l['optional'] ? ' <span class="badge bg-soft-light text-dark border">optional</span>' : '' ?><?= $l['notes'] ? '<div class="fs-11 text-muted">' . e($l['notes']) . '</div>' : '' ?></td>
            <td class="text-end"><?= e($l['qty_display']) ?></td>
            <td class="text-end text-muted"><?= e($l['base_display']) ?></td>
            <td class="text-end"><?= (float) $l['line_yield_pct'] !== 100.0 ? e(ri_fmt($l['line_yield_pct'], 2)) . ' %' : '' ?></td>
            <td><?= e((string) $l['group_name']) ?></td>
            <?php if ($seesCost): ?>
                <td class="text-end"><?php if ($cost === null): ?><span class="text-muted">—</span><?php elseif (($l['cost_per_base'] ?? null) === null || (float) ($l['cost_per_base'] ?? 0) == 0.0): ?><span class="badge bg-soft-warning text-warning">no cost yet</span><?php else: ?><?= e(ri_money($l['line_cost'], '$', 4)) ?><?= $l['sub_recipe_id'] !== null ? '<div class="fs-11 text-muted">from its own recipe</div>' : '' ?><?php endif; ?></td>
                <td class="text-end"><?= ($l['share_pct'] ?? null) !== null ? e(ri_fmt($l['share_pct'], 1)) . ' %' : '' ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if ($lines === []): ?><tr><td colspan="8" class="text-muted">No lines yet.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($seesCost): ?><tfoot><tr id="recipe-cost-footer"><td colspan="6" class="text-end fw-semibold">Batch cost</td><td class="text-end fw-semibold"><?= $cost === null ? '<span class="text-muted" title="cost withheld">—</span>' : e(ri_money($cost['batch_cost'], '$', 4)) ?></td><td class="text-end"><?= $cost !== null && (int) $cost['uncosted'] > 0 ? '<span class="badge bg-soft-warning text-warning">' . (int) $cost['uncosted'] . ' uncosted</span>' : '' ?></td></tr>
        <tr><td colspan="6" class="text-end">Per portion</td><td class="text-end fw-semibold" id="recipe-cost-portion"><?= $cost === null ? '—' : e(ri_money($cost['portion_cost'], '$', 4)) ?></td><td></td></tr></tfoot><?php endif; ?>
</table></div>
