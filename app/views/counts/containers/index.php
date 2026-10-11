<?php /** The bottles and kegs the bar weighs (screen `container-list`). Data: rows, ingredient (the filter), edit (a container being changed), mayWrite, notice, errors? */
$errors = $errors ?? [];
$v = static function (string $k, mixed $d = '') use ($edit): string { return (string) ($_POST[$k] ?? ($edit[$k] ?? $d)); };
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="container-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$ingId = $_POST['ingredient'] ?? ($edit['ingredient_id'] ?? ($ingredient['ingredient_id'] ?? ''));
?>
<?= view('shared/header.php', ['id' => 'container-list', 'title' => 'Bottles and kegs', 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], ['Bottles and kegs', null]], 'back' => back_link()]) ?>
<div class="main-content" id="container-list-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
<?php if ($mayWrite): ?>
    <form method="post" action="/counts/containers/save.php" hx-post="/counts/containers/save.php" hx-target="#flash" id="container-form" class="card mb-3"><?= csrf_field() ?>
        <?php if ($edit): ?><input type="hidden" name="container" value="<?= (int) $edit['container_id'] ?>"><?php endif; ?>
        <div class="card-header"><h5 class="card-title mb-0"><?= $edit ? 'Change ' . e($edit['name']) . ' — ' . e($edit['ingredient']) : 'Add a bottle or keg' ?></h5></div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" id="container-form-field-ingredient-label">Ingredient</label><?= picker_field(['id' => 'container-form-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $ingId, 'invalid' => isset($errors['ingredient']), 'labelledby' => 'container-form-field-ingredient-label', 'required' => true]) ?><?= $err('ingredient') ?></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="container-form-field-name">Name</label><input type="text" name="name" id="container-form-field-name" maxlength="60" required class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" value="<?= e($v('name')) ?>" placeholder="750 ml bottle, 1/6 bbl keg"><?= $err('name') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="container-form-field-full_weight_g">Full weight (g)</label><input type="number" step="0.1" min="0" inputmode="decimal" name="full_weight_g" id="container-form-field-full_weight_g" required class="form-control btn-touch<?= isset($errors['full_weight_g']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('full_weight_g') === '' ? null : $v('full_weight_g'))) ?>"><?= $err('full_weight_g') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="container-form-field-empty_weight_g">Empty weight (g)</label><input type="number" step="0.1" min="0" inputmode="decimal" name="empty_weight_g" id="container-form-field-empty_weight_g" required class="form-control btn-touch<?= isset($errors['empty_weight_g']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('empty_weight_g') === '' ? null : $v('empty_weight_g'))) ?>"><?= $err('empty_weight_g') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="container-form-field-pourer_tare_g">Pourer (g)</label><input type="number" step="0.1" min="0" inputmode="decimal" name="pourer_tare_g" id="container-form-field-pourer_tare_g" class="form-control btn-touch" value="<?= e(fmt_input($v('pourer_tare_g', '0'))) ?>"><?= $err('pourer_tare_g') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="container-form-field-volume_ml">Volume (ml)</label><input type="number" step="0.1" min="0" inputmode="decimal" name="volume_ml" id="container-form-field-volume_ml" required class="form-control btn-touch<?= isset($errors['volume_ml']) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($v('volume_ml') === '' ? null : $v('volume_ml'))) ?>"><?= $err('volume_ml') ?></div>
            <div class="col-12 d-flex flex-wrap gap-3"><input type="hidden" name="is_default" value="no"><label class="d-flex align-items-center gap-2 btn-touch" for="container-form-field-is_default"><input type="checkbox" class="form-check-input mt-0" name="is_default" value="yes" id="container-form-field-is_default" <?= $edit ? ($edit['is_default'] ? 'checked' : '') : (isset($_POST['is_default']) ? ($_POST['is_default'] === 'yes' ? 'checked' : '') : 'checked') ?>> The one the count assumes</label>
                <?php if ($edit): ?><input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-2 btn-touch" for="container-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="container-form-field-active" <?= $edit['active'] ? 'checked' : '' ?>> In use</label><?php endif; ?></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="container-form-submit"><?= $edit ? 'Save' : 'Add' ?></button><?php if ($edit): ?><?= hx_link('/counts/containers/' . ($ingredient ? '?ingredient=' . (int) $ingredient['ingredient_id'] : ''), 'Cancel', 'btn btn-light btn-touch', 'id="container-form-cancel"') ?><?php endif; ?></div>
    </form>
<?php endif; ?>
    <?php if ($ingredient): ?><div class="fs-12 text-muted mb-2" id="container-filter-note">Showing the bottles and kegs of <?= e($ingredient['name']) ?>. <?= hx_link('/counts/containers/', 'Show all') ?></div><?php endif; ?>
<?php if ($rows === []): ?><div class="card" id="container-list-empty"><div class="card-body text-center text-muted py-5">No bottles or kegs yet. Say what a full and an empty bottle weigh, and the bar can be counted on a scale.</div></div>
<?php else: ?><div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="containers-table"><thead><tr><th>Ingredient</th><th>Bottle or keg</th><th class="text-end">Full g</th><th class="text-end">Empty g</th><th class="text-end">Pourer g</th><th class="text-end">Volume ml</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?><?= view('counts/containers/partials/container-row.php', ['c' => $c, 'mayWrite' => $mayWrite]) ?><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>
</div>
