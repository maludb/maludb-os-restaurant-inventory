<?php /** A yield test (screen `yield-test-add`). Data: i, options, here */
$id = (int) $i['ingredient_id'];
?>
<?= view('shared/header.php', ['id' => 'yield-test-add', 'title' => $i['name'] . ' · yield test', 'crumbs' => [['Home', '/'], ['Ingredients', '/ingredients/'], [$i['name'], '/ingredients/' . $id], ['Yield test', null]], 'back' => back_link() ?? ['/ingredients/' . $id, $i['name']]]) ?>
<div class="main-content" id="yield-test-add-content">
    <form method="post" action="/ingredients/yield-tests/save.php" hx-post="/ingredients/yield-tests/save.php" hx-target="#flash" id="yield-test-form">
        <?= csrf_field() ?><input type="hidden" name="ingredient" value="<?= $id ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The test</h5></div><div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="yield-test-form-field-ap_qty">As purchased</label><div class="input-group"><input type="number" step="any" min="0" name="ap_qty" id="yield-test-form-field-ap_qty" class="form-control btn-touch" required hx-post="/ingredients/yield-tests/save.php" hx-vals='{"preview":"1"}' hx-include="#yield-test-form" hx-trigger="change, keyup changed delay:400ms" hx-target="#yield-test-preview" hx-swap="outerHTML"><select name="ap_qty_unit" id="yield-test-form-field-ap_qty_unit" class="form-select btn-touch"><?php foreach ($options as $o): ?><option value="<?= e($o['code']) ?>" <?= $o['code'] === $i['base_unit_code'] ? 'selected' : '' ?>><?= e($o['code']) ?></option><?php endforeach; ?></select></div></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="yield-test-form-field-ep_qty">Edible portion</label><div class="input-group"><input type="number" step="any" min="0" name="ep_qty" id="yield-test-form-field-ep_qty" class="form-control btn-touch" required hx-post="/ingredients/yield-tests/save.php" hx-vals='{"preview":"1"}' hx-include="#yield-test-form" hx-trigger="change, keyup changed delay:400ms" hx-target="#yield-test-preview" hx-swap="outerHTML"><select name="ep_qty_unit" id="yield-test-form-field-ep_qty_unit" class="form-select btn-touch"><?php foreach ($options as $o): ?><option value="<?= e($o['code']) ?>" <?= $o['code'] === $i['base_unit_code'] ? 'selected' : '' ?>><?= e($o['code']) ?></option><?php endforeach; ?></select></div></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="yield-test-form-field-trim_loss_pct">Trim loss %</label><input type="number" step="0.01" min="0" max="100" name="trim_loss_pct" id="yield-test-form-field-trim_loss_pct" class="form-control btn-touch"></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="yield-test-form-field-cook_loss_pct">Cook loss %</label><input type="number" step="0.01" min="0" max="100" name="cook_loss_pct" id="yield-test-form-field-cook_loss_pct" class="form-control btn-touch"></div>
            </div>
            <div class="mt-3 fs-12 text-muted" id="yield-test-preview">Enter both quantities to see the yield.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">By-products (the butcher's test)</h5></div><div class="card-body">
            <?php for ($n = 0; $n < 3; $n++): ?>
            <div class="row g-2 mb-2"><div class="col-5"><input type="text" name="bp[<?= $n ?>][name]" id="yield-test-form-field-bp-<?= $n ?>-name" class="form-control btn-touch" maxlength="80" placeholder="bones, fat…"></div><div class="col-4"><div class="input-group"><input type="number" step="any" min="0" name="bp[<?= $n ?>][qty]" id="yield-test-form-field-bp-<?= $n ?>-qty" class="form-control btn-touch"><select name="bp[<?= $n ?>][unit]" class="form-select btn-touch"><?php foreach ($options as $o): ?><option value="<?= e($o['code']) ?>"><?= e($o['code']) ?></option><?php endforeach; ?></select></div></div><div class="col-3"><div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" name="bp[<?= $n ?>][unit_value]" class="form-control btn-touch" placeholder="value"></div></div></div>
            <?php endfor; ?>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <input type="hidden" name="apply" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="yield-test-form-field-apply"><input type="checkbox" class="form-check-input mt-0" name="apply" value="yes" id="yield-test-form-field-apply"><span class="fs-12">Set the ingredient's yield to the test's</span></label>
            <textarea name="notes" id="yield-test-form-field-notes" class="form-control" rows="2" maxlength="1000" placeholder="Notes"></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="yield-test-form-save-btn">Record the test</button>
        <?= hx_link('/ingredients/' . $id, 'Cancel', 'btn btn-light btn-touch w-100') ?>
    </form>
</div>
