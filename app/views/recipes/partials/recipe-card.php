<?php /** One recipe card (`recipe-card-{id}`). Data: r (a find_recipes row), seesCost */
$id = (int) $r['recipe_id'];
$url = with_back('/recipes/' . $id, here_url());
$color = RECIPE_KIND_COLOR[$r['kind']] ?? 'secondary';
if ($r['current_version_id'] !== null) { $vchip = '<span class="badge bg-soft-success text-success" id="recipe-card-' . $id . '-version">v' . (int) $r['version_no'] . ' since ' . e(format_date($r['effective_from'])) . '</span>'; }
elseif ((int) $r['drafts'] > 0) { $vchip = '<span class="badge bg-soft-warning text-warning" id="recipe-card-' . $id . '-version">draft only</span>'; }
else { $vchip = '<span class="badge bg-soft-dark text-dark" id="recipe-card-' . $id . '-version">no version</span>'; }
?>
<div class="card h-100 recipe-card<?= $r['active'] ? '' : ' bg-light' ?>" id="recipe-card-<?= $id ?>">
    <div class="card-body">
        <div class="d-flex align-items-start gap-2">
            <?php if (!empty($r['photo_attachment_id'])): ?><img class="rounded recipe-card-photo" src="/files/<?= (int) $r['photo_attachment_id'] ?>" alt="" width="48" height="48" loading="lazy">
            <?php else: ?><span class="avatar-text avatar-md rounded bg-soft-<?= $color ?> text-<?= $color ?>"><i class="feather-<?= $r['kind'] === 'prep' ? 'coffee' : ($r['kind'] === 'modifier' ? 'sliders' : 'book-open') ?>"></i></span><?php endif; ?>
            <div class="min-w-0 flex-grow-1">
                <div class="fw-semibold text-truncate"><?= hx_link($url, e($r['name']), 'text-dark', 'id="recipe-card-' . $id . '-name"') ?></div>
                <div class="fs-11 text-muted"><?= e($r['code']) ?></div>
            </div>
        </div>
        <div class="chip-row mt-2">
            <span class="badge bg-soft-<?= $color ?> text-<?= $color ?>"><?= e(RECIPE_KINDS[$r['kind']]) ?></span>
            <?php if ($r['category']): ?><span class="badge bg-soft-light text-dark border"><?= e($r['category']) ?></span><?php endif; ?>
            <?= $vchip ?>
            <?php if (!$r['active']): ?><span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?>
        </div>
        <div class="fs-12 text-muted mt-2" id="recipe-card-<?= $id ?>-facts"><?= (int) $r['lines'] ?> line<?= (int) $r['lines'] === 1 ? '' : 's' ?><?= (int) $r['sub_recipes'] > 0 ? ' · ' . (int) $r['sub_recipes'] . ' sub-recipe' . ((int) $r['sub_recipes'] === 1 ? '' : 's') : '' ?><?= $r['output_ingredient'] ? ' · makes ' . e($r['output_ingredient']) : '' ?></div>
        <div class="fs-12 mt-1 d-flex align-items-center gap-2 flex-wrap" id="recipe-card-<?= $id ?>-cost">
            <?php if (!$seesCost): ?><span class="text-muted" title="cost withheld">—</span>
            <?php elseif ($r['portion_cost'] === null): ?><span class="text-muted">no cost yet</span>
            <?php else: ?><span class="fw-semibold"><?= e(ri_money($r['portion_cost'])) ?> / portion</span><?php if ($r['food_cost_pct'] !== null): ?><span class="badge bg-soft-<?= $r['food_cost_pct'] > 35 ? 'danger' : 'success' ?> text-<?= $r['food_cost_pct'] > 35 ? 'danger' : 'success' ?>"><?= e(ri_fmt($r['food_cost_pct'], 1)) ?> % of <?= e(ri_money($r['menu_price'])) ?></span><?php endif; ?><?php endif; ?>
        </div>
        <?php if ($r['allergens'] !== []): ?><div class="chip-row mt-1" id="recipe-card-<?= $id ?>-allergens"><?php foreach (array_slice($r['allergens'], 0, 3) as $a): ?><span class="badge bg-soft-light text-dark border"><?= e($a) ?></span><?php endforeach; ?><?php if (count($r['allergens']) > 3): ?><span class="badge bg-soft-light text-dark border">+<?= count($r['allergens']) - 3 ?></span><?php endif; ?></div><?php endif; ?>
    </div>
</div>
