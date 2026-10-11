<?php /** The what-if scratchpad (screen recipe-what-if; walled whole). Data: recipe, versionId, leaves, result, menuPrice, overrides, back */ $rid = (int) $recipe['recipe_id']; ?>
<?= view('shared/header.php', ['id' => 'recipe-what-if', 'title' => 'What if — ' . $recipe['name'], 'crumbs' => [['Home', '/'], ['Recipes', '/recipes/'], [$recipe['name'], '/recipes/' . $rid], ['What if', null]], 'back' => $back ?? ['/recipes/' . $rid, 'the recipe']]) ?>
<div class="main-content" id="recipe-what-if-content">
    <?php if ($versionId === null): ?><div class="card"><div class="card-body text-muted">No version is in force — activate one first.</div></div><?php else: ?>
    <form method="post" action="/recipes/<?= $rid ?>/what-if" hx-post="/recipes/<?= $rid ?>/what-if" hx-target="#what-if-result" id="what-if-form" class="card mb-3">
        <?= csrf_field() ?>
        <div class="card-header"><h5 class="card-title mb-0">If an ingredient's price changes…</h5></div>
        <div class="table-responsive"><table class="table align-middle mb-0" id="what-if-table"><thead><tr><th>Ingredient</th><th class="text-end">One batch uses</th><th class="text-end">Costs now</th><th>New price</th></tr></thead><tbody>
        <?php foreach ($leaves as $l): $iid = (int) $l['ingredient_id']; ?>
            <tr id="what-if-row-<?= $iid ?>"><td><?= e($l['name']) ?></td><td class="text-end text-muted"><?= e(ri_fmt($l['qty_base'], $l['base_unit_code'] === 'each' ? 0 : 2)) ?> <?= e($l['base_unit_code']) ?></td><td class="text-end"><?= $l['cost_per_recipe_unit'] === null ? '—' : e(ri_money($l['cost_per_recipe_unit'], '$', 4)) . ' / ' . e($l['recipe_unit_code']) ?></td>
                <td><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.0001" min="0" name="price[<?= $iid ?>]" class="form-control btn-touch" id="what-if-row-<?= $iid ?>-field-price" value="<?= e($l['prefill']['price'] ?? '') ?>" placeholder="per unit"><select name="unit[<?= $iid ?>]" class="form-select btn-touch" style="max-width: 9rem" id="what-if-row-<?= $iid ?>-field-unit" aria-label="per unit"><?php foreach ($l['units'] as $u): ?><option value="<?= e($u['code']) ?>" <?= ($l['prefill']['unit'] ?? $l['recipe_unit_code']) === $u['code'] ? 'selected' : '' ?>>per <?= e($u['code']) ?></option><?php endforeach; ?></select></div></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="what-if-recalc">Recalculate</button><span class="fs-12 text-muted align-self-center">Nothing is saved — a scratchpad.</span></div>
    </form>
    <div id="what-if-result"><?= view('recipes/partials/what-if-result.php', ['result' => $result, 'menuPrice' => $menuPrice, 'recipe' => $recipe]) ?></div>
    <?php endif; ?>
</div>
