<?php /** One ingredient card (`ingredient-card-{id}`). Data: i (a find_ingredients row), seesCost, here, units (display units) */
$id = (int) $i['ingredient_id'];
$url = with_back('/ingredients/' . $id, $here);
$onHand = $i['on_hand_base'] === null ? null : format_qty(db(), $id, $i['on_hand_base'], $i['count_unit']);
$perRecipe = $seesCost && $i['avg_cost_per_base'] !== null ? cost_per_unit(db(), $id, $i['avg_cost_per_base'], $i['recipe_unit_code']) : null;
?>
<div class="card h-100 ingredient-card" id="ingredient-card-<?= $id ?>">
    <div class="card-body">
        <div class="d-flex align-items-start gap-2">
            <span class="avatar-text avatar-md rounded flex-shrink-0" title="<?= e(MEASURE_CLASSES[$i['measure_class']]) ?>"><i class="<?= e(class_icon($i['measure_class'])) ?>"></i></span>
            <div class="min-w-0 flex-grow-1">
                <div class="fw-semibold text-truncate"><?= hx_link($url, e($i['name']), 'text-dark', 'id="ingredient-card-' . $id . '-name"') ?></div>
                <div class="fs-11 text-muted"><code><?= e($i['code']) ?></code> · <?= e($i['category']) ?> · <?= e($i['recipe_unit_code']) ?></div>
            </div>
        </div>
        <div class="chip-row mt-2">
            <?php if ($i['is_prep']): ?><?= chip('prep', 'info') ?><?php endif; ?>
            <?php if ($i['catch_weight']): ?><?= chip('catch weight', 'warning') ?><?php endif; ?>
            <?php if (!$i['active']): ?><?= chip('archived', 'dark') ?><?php endif; ?>
        </div>
        <div class="fs-12 mt-2" id="ingredient-card-<?= $id ?>-on-hand"><?= $onHand === null ? '<span class="text-muted">nothing on hand here</span>' : e($onHand) . ($i['count_unit'] !== null && $i['count_unit'] !== $i['base_unit_code'] ? ' <span class="text-muted">(' . e(fmt_num($i['on_hand_base'], 2)) . ' ' . e($i['base_unit_code']) . ')</span>' : '') ?></div>
        <div class="fs-12 d-flex justify-content-between mt-1">
            <span id="ingredient-card-<?= $id ?>-cost"><?= $seesCost ? ($perRecipe === null ? '<span class="text-muted">no cost yet</span>' : '$' . e(fmt_num($perRecipe, 4)) . ' / ' . e($i['recipe_unit_code'])) : '<span class="text-muted" title="cost withheld">—</span>' ?></span>
            <span class="text-muted"><?= $i['days_on_hand'] !== null ? e(fmt_num($i['days_on_hand'], 1)) . ' days on hand' : '' ?></span>
        </div>
    </div>
</div>
