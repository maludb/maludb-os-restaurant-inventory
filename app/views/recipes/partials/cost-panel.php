<?php /** The editor's cost panel (walled: "cost withheld" and the function never called). Data: cost, seesCost, lines, recipe, version */ ?>
<div id="editor-cost" class="card" hx-get="/recipes/versions/<?= (int) $version['recipe_version_id'] ?>/cost" hx-trigger="recipeChanged from:body" hx-swap="outerHTML">
    <div class="card-body">
    <?php if (!$seesCost): ?>
        <div class="fw-semibold text-muted" id="editor-cost-withheld" title="cost withheld">Cost withheld</div><div class="fs-12 text-muted">The Chef and above see the cost of a recipe.</div>
    <?php elseif ($cost === null): ?>
        <div class="text-muted">No cost yet.</div>
    <?php else: ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Batch cost</span><span class="fw-semibold" id="editor-cost-batch"><?= e(ri_money($cost['batch_cost'], '$', 4)) ?></span></div>
        <div class="d-flex justify-content-between fs-5"><span class="text-muted">Per <?= $recipe['kind'] === 'prep' ? e($version['batch_yield_unit_code'] ?? 'unit') : 'portion' ?></span><span class="fw-bold" id="editor-cost-portion"><?= e(ri_money($cost['portion_cost'], '$', 4)) ?></span></div>
        <?php $batch = (float) $cost['batch_cost']; $byLine = []; foreach ($cost['lines'] as $cl) { $byLine[(int) $cl['line_id']] = $cl; } ?>
        <div class="mt-2" id="editor-cost-shares">
            <?php foreach ($lines as $l): $cl = $byLine[(int) $l['recipe_line_id']] ?? null; $share = $cl !== null && $batch > 0 ? (float) $cl['line_cost'] / $batch * 100 : 0; ?>
                <div class="fs-11 d-flex justify-content-between" id="editor-cost-share-<?= (int) $l['recipe_line_id'] ?>"><span class="text-truncate"><?= e($l['ingredient_name'] ?? $l['sub_recipe_name']) ?></span><span><?= $cl === null ? '—' : e(ri_money($cl['line_cost'], '$', 4)) ?> · <?= e(ri_fmt($share, 1)) ?> %</span></div>
                <div class="progress mb-1" style="height: 4px"><div class="progress-bar" style="width: <?= (int) round($share) ?>%"></div></div>
            <?php endforeach; ?>
        </div>
        <?php if ((int) $cost['uncosted'] > 0): ?><div class="badge bg-soft-warning text-warning mt-2" id="editor-cost-uncosted"><?= (int) $cost['uncosted'] ?> line<?= (int) $cost['uncosted'] === 1 ? '' : 's' ?> uncosted — no cost yet</div><?php else: ?><span id="editor-cost-uncosted" class="d-none"></span><?php endif; ?>
    <?php endif; ?>
    </div>
</div>
