<?php /** THE BATCH FORM at 375 px (screen `production-add`). Data: areas, prefill (recipe), preview, recipe, outputUnits, errors? */
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="production-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
?>
<?= view('shared/header.php', ['id' => 'production-add', 'title' => 'Record a prep batch', 'crumbs' => [['Home', '/'], ['Prep batches', '/production/'], ['New', null]], 'back' => back_link() ?? ['/production/', 'Prep batches']]) ?>
<div class="main-content" id="production-add-content">
    <?= view('stock/_assets.php') ?>
    <form method="post" action="/production/save.php" hx-post="/production/save.php" hx-target="#flash" id="production-form" data-stock-form="produce">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" id="production-form-field-recipe-label">The prep recipe</label>
                <?= picker_field(['id' => 'production-form-field-recipe', 'name' => 'recipe', 'source' => 'recipe', 'value' => $val('recipe', $prefill['recipe']), 'params' => ['kind' => 2], 'invalid' => isset($errors['recipe']), 'labelledby' => 'production-form-field-recipe-label', 'extra' => ' data-stock-recipe']) ?><?= $err('recipe') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="production-form-field-batches">Batches</label><input type="number" step="any" min="0" inputmode="decimal" name="batches" id="production-form-field-batches" class="form-control stock-qty<?= isset($errors['batches']) ? ' is-invalid' : '' ?>" value="<?= e($val('batches', '1')) ?>" data-stock-batches><?= $err('batches') ?></div>
            <div class="row g-2 mb-3">
                <div class="col-12 col-sm-6"><label class="form-label fs-12 text-muted" for="production-form-field-area">The output lands in</label><select name="area" id="production-form-field-area" class="form-select btn-touch<?= isset($errors['area']) ? ' is-invalid' : '' ?>" required><?= view('transfers/_area-options.php', ['areas' => $areas, 'selected' => $val('area')]) ?></select><?= $err('area') ?></div>
                <div class="col-12 col-sm-6"><label class="form-label fs-12 text-muted" for="production-form-field-consume_area">The inputs come from</label><select name="consume_area" id="production-form-field-consume_area" class="form-select btn-touch"><option value="">Each ingredient's own area</option><?php foreach ($areas as $a): ?><option value="<?= $a['storage_area_id'] ?>" <?= $val('consume_area') === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select><?= $err('consume_area') ?></div>
            </div>
            <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => 'production-form', 'name' => 'output_qty', 'unitName' => 'output_unit', 'label' => 'The actual output, when it differs from the recipe\'s yield', 'qty' => $val('output_qty'), 'unit' => $val('output_unit'), 'options' => $outputUnits, 'errors' => $errors]) ?></div>
            <div class="row g-2">
                <div class="col-12 col-sm-6"><label class="form-label fs-12 text-muted" for="production-form-field-made_at">Made at</label><input type="datetime-local" name="made_at" id="production-form-field-made_at" class="form-control btn-touch<?= isset($errors['made_at']) ? ' is-invalid' : '' ?>" value="<?= e($val('made_at', (new DateTimeImmutable('now', new DateTimeZone(site_timezone())))->format('Y-m-d\TH:i'))) ?>"><?= $err('made_at') ?></div>
                <div class="col-12 col-sm-6"><label class="form-label fs-12 text-muted" for="production-form-field-notes">Notes</label><input type="text" maxlength="2000" name="notes" id="production-form-field-notes" class="form-control btn-touch" value="<?= e($val('notes')) ?>"></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-body" id="production-preview"><?= view('production/_preview.php', ['preview' => $preview, 'recipe' => $recipe]) ?></div></div>
        <div class="stock-post-bar d-flex gap-2"><button type="submit" class="btn btn-success btn-touch flex-grow-1" id="production-form-post"><i class="feather-check me-1"></i>Post</button><button type="submit" name="draft" value="yes" class="btn btn-light btn-touch" id="production-form-draft">Save as draft</button></div>
    </form>
</div>
