<?php /** Make or change an ingredient (screens `ingredient-add`, `ingredient-edit`). Data: cur, categories, globalUnits, prefillCategory, locked, here, errors? */
$id = $cur['ingredient_id'] ?? null;
$screen = $cur === null ? 'ingredient-add' : 'ingredient-edit';
$back = $cur === null ? ['/ingredients/', 'Ingredients'] : ['/ingredients/' . (int) $id, $cur['name']];
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="ingredient-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$v = static fn (string $k, mixed $d = ''): string => e((string) ($_POST[$k] ?? $cur[$k] ?? $d));
$class = (string) ($_POST['measure_class'] ?? $cur['measure_class'] ?? 'weight');
$catNow = $_POST['category'] ?? $cur['category_id'] ?? $prefillCategory;
$allergens = $cur['allergens'] ?? [];
$other = array_values(array_diff($allergens, array_keys(ALLERGENS)));
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New ingredient' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Ingredients', '/ingredients/'], [$cur === null ? 'New' : $cur['name'], $cur === null ? null : '/ingredients/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/ingredients/save.php" hx-post="/ingredients/save.php" hx-target="#flash" id="ingredient-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="ingredient" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="return_to" value="">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The ingredient</h5></div><div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="ingredient-form-field-name">Name</label><input type="text" name="name" id="ingredient-form-field-name" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" maxlength="120" required value="<?= $v('name') ?>"><?= $err('name') ?></div>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="ingredient-form-field-code">Code</label><input type="text" name="code" id="ingredient-form-field-code" class="form-control btn-touch<?= isset($errors['code']) ? ' is-invalid' : '' ?>" maxlength="40" value="<?= $v('code') ?>" placeholder="from the name"><?= $err('code') ?></div>
            </div>
            <div class="mt-3"><?= form_picker('ingredient-form', 'category', 'Category', 'category', $catNow, $errors, ['required' => true]) ?></div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How it is measured</h5></div><div class="card-body">
            <div class="fs-12 text-muted mb-1">Measure class<?= $locked ? ' — locked: the ingredient has stock or recipes' : '' ?></div>
            <div class="d-flex gap-2 mb-2 flex-wrap" id="ingredient-form-field-measure_class">
                <?php foreach (MEASURE_CLASSES as $k => $l): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-grow-1" for="ingredient-form-field-measure_class-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="measure_class" value="<?= $k ?>" id="ingredient-form-field-measure_class-<?= $k ?>" <?= $class === $k ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?> hx-get="/ingredients/unit-options.php" hx-include="#ingredient-form-field-measure_class, #ingredient-form-field-density_g_per_ml, #ingredient-form-field-each_weight_g, #ingredient-form-field-each_volume_ml" hx-vals='js:{class: document.querySelector("input[name=measure_class]:checked").value, density: document.getElementById("ingredient-form-field-density_g_per_ml").value, each: document.getElementById("ingredient-form-field-each_weight_g").value || document.getElementById("ingredient-form-field-each_volume_ml").value, selected: document.getElementById("ingredient-form-field-recipe_unit").value}' hx-target="#ingredient-form-field-recipe_unit" hx-swap="innerHTML"><i class="<?= e(CLASS_ICON[$k]) ?>"></i> <?= e($l) ?> <span class="fs-11 text-muted">(base <?= e(CLASS_BASE[$k]) ?>)</span></label>
                <?php endforeach; ?>
            </div>
            <?php if ($locked): ?><input type="hidden" name="measure_class" value="<?= e($class) ?>"><?php endif; ?>
            <?= $err('measure_class') ?>
            <div class="row g-2 mt-1">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="ingredient-form-field-recipe_unit">Recipe unit — what the chef writes</label>
                    <select name="recipe_unit" id="ingredient-form-field-recipe_unit" class="form-select btn-touch<?= isset($errors['recipe_unit']) ? ' is-invalid' : '' ?>">
                        <?php $ru = (string) ($_POST['recipe_unit'] ?? $cur['recipe_unit_code'] ?? CLASS_BASE[$class]); $classes = [$class];
                        if (($cur['density_g_per_ml'] ?? null) !== null && $class !== 'each') { $classes[] = $class === 'weight' ? 'volume' : 'weight'; }
                        if (($cur['each_weight_g'] ?? null) !== null || ($cur['each_volume_ml'] ?? null) !== null) { $classes[] = $class === 'each' ? 'weight' : 'each'; if ($class === 'each' && ($cur['each_volume_ml'] ?? null) !== null) { $classes[] = 'volume'; } }
                        foreach ($globalUnits as $g): if (!in_array($g['measure_class'], $classes, true)) { continue; } ?><option value="<?= e($g['unit_code']) ?>" <?= $ru === $g['unit_code'] ? 'selected' : '' ?>><?= e($g['unit_code'] . ' — ' . $g['name']) ?></option><?php endforeach; ?>
                    </select><?= $err('recipe_unit') ?></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-density_g_per_ml">Density g/ml</label><input type="number" step="0.000001" min="0" name="density_g_per_ml" id="ingredient-form-field-density_g_per_ml" class="form-control btn-touch<?= isset($errors['density_g_per_ml']) ? ' is-invalid' : '' ?>" value="<?= $v('density_g_per_ml') ?>" placeholder="olive oil 0.916, honey 1.42"><?= $err('density_g_per_ml') ?></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-each_weight_g">Each weighs (g)</label><input type="number" step="0.0001" min="0" name="each_weight_g" id="ingredient-form-field-each_weight_g" class="form-control btn-touch<?= isset($errors['each_weight_g']) ? ' is-invalid' : '' ?>" value="<?= $v('each_weight_g') ?>"><?= $err('each_weight_g') ?></div>
                <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="ingredient-form-field-each_volume_ml">Each holds (ml)</label><input type="number" step="0.0001" min="0" name="each_volume_ml" id="ingredient-form-field-each_volume_ml" class="form-control btn-touch<?= isset($errors['each_volume_ml']) ? ' is-invalid' : '' ?>" value="<?= $v('each_volume_ml') ?>"><?= $err('each_volume_ml') ?></div>
            </div>
            <div class="fs-12 text-muted mt-2">A recipe unit of another class needs the fact that crosses it: a density for weight ↔ volume, an each-weight or each-volume for each ↔ weight/volume. The purchase and count units (a case, a bag, a bottle) are given on the ingredient's Units page.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Yield, shelf life, catch weight</h5></div><div class="card-body row g-2">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-yield_pct">Yield % (edible portion after trim)</label><input type="number" step="0.01" min="0.01" max="100" name="yield_pct" id="ingredient-form-field-yield_pct" class="form-control btn-touch<?= isset($errors['yield_pct']) ? ' is-invalid' : '' ?>" value="<?= $v('yield_pct', '100') ?>"><?= $err('yield_pct') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-shelf_life_days">Shelf life (days)</label><input type="number" min="1" name="shelf_life_days" id="ingredient-form-field-shelf_life_days" class="form-control btn-touch<?= isset($errors['shelf_life_days']) ? ' is-invalid' : '' ?>" value="<?= $v('shelf_life_days') ?>"><?= $err('shelf_life_days') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-variance_tolerance_pct">Count tolerance %</label><input type="number" step="0.01" min="0" name="variance_tolerance_pct" id="ingredient-form-field-variance_tolerance_pct" class="form-control btn-touch" value="<?= $v('variance_tolerance_pct') ?>" placeholder="5"><?= $err('variance_tolerance_pct') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="ingredient-form-field-variance_tolerance_value">Count tolerance $</label><input type="number" step="0.01" min="0" name="variance_tolerance_value" id="ingredient-form-field-variance_tolerance_value" class="form-control btn-touch" value="<?= $v('variance_tolerance_value') ?>" placeholder="10"><?= $err('variance_tolerance_value') ?></div>
            <div class="col-12"><input type="hidden" name="catch_weight" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="ingredient-form-field-catch_weight"><input type="checkbox" class="form-check-input mt-0" name="catch_weight" value="yes" id="ingredient-form-field-catch_weight" <?= !empty($_POST['catch_weight']) && $_POST['catch_weight'] === 'yes' || (!isset($_POST['catch_weight']) && !empty($cur['catch_weight'])) ? 'checked' : '' ?>><span class="fs-12">Catch weight — ordered by the piece, priced and received by weight</span></label></div>
            <?php if ($cur !== null): ?>
            <div class="col-12"><div class="fs-12 text-muted mb-1">Costing method</div>
                <?php foreach (['avg' => 'Weighted average (valuation and variance always use it)', 'last' => 'Last price on this ingredient\'s card'] as $k => $w): ?><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="ingredient-form-field-costing_method-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="costing_method" value="<?= $k ?>" id="ingredient-form-field-costing_method-<?= $k ?>" <?= ($cur['costing_method'] ?? 'avg') === $k ? 'checked' : '' ?>><span class="fs-12"><?= e($w) ?></span></label><?php endforeach; ?></div>
            <?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Allergens</h5></div><div class="card-body">
            <input type="hidden" name="allergens[]" value="_none">
            <div class="row g-2">
            <?php foreach (ALLERGENS as $k => $l): ?><div class="col-6 col-md-4 col-xl-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="ingredient-form-field-allergen-<?= $k ?>"><input type="checkbox" class="form-check-input mt-0" name="allergens[]" value="<?= $k ?>" id="ingredient-form-field-allergen-<?= $k ?>" <?= in_array($k, $allergens, true) ? 'checked' : '' ?>><span class="fs-12"><?= e($l) ?></span></label></div><?php endforeach; ?>
            </div>
            <label class="form-label fs-12 text-muted mt-2" for="ingredient-form-field-allergen_other">Other</label><input type="text" name="allergen_other" id="ingredient-form-field-allergen_other" class="form-control btn-touch" maxlength="40" value="<?= e(implode(', ', $other)) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
            <textarea name="notes" id="ingredient-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= $v('notes') ?></textarea>
            <?php if ($cur !== null): ?>
            <div class="fs-12 text-muted mt-3 mb-1">Status</div>
            <?php foreach (['active' => 'Active', 'archived' => 'Archived — kept out of the way, readable'] as $k => $w): ?><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="ingredient-form-field-status-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="status" value="<?= $k ?>" id="ingredient-form-field-status-<?= $k ?>" <?= ($cur['active'] ? 'active' : 'archived') === $k ? 'checked' : '' ?>><span class="fs-12"><?= e($w) ?></span></label><?php endforeach; ?>
            <?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="ingredient-form-save-btn"><?= $cur === null ? 'Make the ingredient' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="ingredient-form-cancel-link"') ?>
    </form>
</div>
