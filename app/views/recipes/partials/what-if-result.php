<?php /** The what-if's answer. Data: result (ri_recipe_what_if row or null), menuPrice, recipe */ ?>
<?php if ($result === null): ?><div class="card" id="what-if-result-card"><div class="card-body text-muted">Type a new price and recalculate.</div></div><?php else:
$old = (float) $result['base_portion_cost']; $new = (float) $result['portion_cost']; $price = $menuPrice !== null ? (float) $menuPrice : null; ?>
<div class="card" id="what-if-result-card"><div class="card-body">
    <div class="row text-center">
        <div class="col-6 col-md-3"><div class="fs-12 text-muted">Plate cost now</div><div class="fs-4 fw-semibold" id="what-if-old-cost"><?= e(ri_money($old, '$', 4)) ?></div></div>
        <div class="col-6 col-md-3"><div class="fs-12 text-muted">Plate cost then</div><div class="fs-4 fw-bold text-<?= $new > $old ? 'danger' : 'success' ?>" id="what-if-new-cost"><?= e(ri_money($new, '$', 4)) ?></div><div class="fs-11 text-muted"><?= $new >= $old ? '+' : '' ?><?= e(ri_money($new - $old, '$', 4)) ?></div></div>
        <?php if ($price !== null && $price > 0): ?><div class="col-6 col-md-3"><div class="fs-12 text-muted">Food cost now</div><div class="fs-4 fw-semibold" id="what-if-old-pct"><?= e(ri_fmt($old / $price * 100, 1)) ?> %</div></div><div class="col-6 col-md-3"><div class="fs-12 text-muted">Food cost then</div><div class="fs-4 fw-bold" id="what-if-new-pct"><?= e(ri_fmt($new / $price * 100, 1)) ?> %</div><div class="fs-11 text-muted">against <?= e(ri_money($price)) ?></div></div><?php else: ?><div class="col-12 fs-12 text-muted mt-2">No menu price at this site — map a menu item and set its price to see the food-cost %.</div><?php endif; ?>
    </div>
</div></div>
<?php endif; ?>
