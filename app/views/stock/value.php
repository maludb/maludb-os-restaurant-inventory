<?php /** Stock value by category at this site as of a day (screen `stock-value`). Data: value (stock_value_rows), asOf */ ?>
<?= view('shared/header.php', ['id' => 'stock-value', 'title' => 'Stock value', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Value', null]], 'back' => back_link()]) ?>
<div class="main-content" id="stock-value-content">
    <?= view('stock/_assets.php') ?>
    <form method="get" action="/stock/value" hx-get="/stock/value" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="stock-value-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="stock-value-as-of">As of the close of</label><input type="date" name="as_of" id="stock-value-as-of" class="form-control btn-touch" value="<?= e($asOf) ?>"></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="stock-value-apply">Show</button></div>
    </div></form>
    <?php if ($value['withheld']): ?>
        <div class="card" id="stock-value-withheld"><div class="card-body text-center text-muted py-5">The stock's value is cost, and cost is withheld from you at this site — the quantities are on <?= hx_link('/stock/', 'On hand') ?>.</div></div>
    <?php elseif ($value['rows'] === []): ?>
        <div class="card" id="stock-value-empty"><div class="card-body text-center text-muted py-5">Nothing on hand at that moment.</div></div>
    <?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0" id="stock-value-table">
        <thead><tr><th>Category</th><th class="text-end">Ingredients</th><th class="text-end">Value</th></tr></thead><tbody>
        <?php foreach ($value['rows'] as $c): ?>
            <tr id="value-row-<?= e($c['category_key']) ?>"><td><a href="#value-detail-<?= e($c['category_key']) ?>" data-bs-toggle="collapse" class="fw-semibold"><?= e($c['category']) ?></a></td><td class="text-end"><?= (int) $c['ingredients'] ?></td><td class="text-end"><?= money($c['value']) ?></td></tr>
            <tr class="collapse" id="value-detail-<?= e($c['category_key']) ?>"><td colspan="3" class="p-0"><table class="table table-sm mb-0 fs-12"><tbody>
                <?php foreach ($c['rows'] as $i): ?><tr id="value-ingredient-row-<?= $i['ingredient_id'] ?>"><td class="ps-4"><?= hx_link('/ingredients/' . $i['ingredient_id'], e($i['name'])) ?></td><td class="text-end"><?= e(fmt_num($i['on_hand_base'], 3) . ' ' . $i['base_unit_code']) ?></td><td class="text-end">$<?= e(fmt_num($i['unit_cost'], 6)) ?> / <?= e($i['base_unit_code']) ?></td><td class="text-end"><?= money($i['value']) ?></td></tr><?php endforeach; ?>
            </tbody></table></td></tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr><th>Site total</th><th></th><th class="text-end" id="stock-value-total"><?= money($value['total']) ?></th></tr></tfoot></table></div></div>
    <?php endif; ?>
</div>
