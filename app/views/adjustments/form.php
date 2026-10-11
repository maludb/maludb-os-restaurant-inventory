<?php /** Start an adjustment (screen `adjustment-add`). Data: areas, reasons, prefill (area, reason, ingredient, ingredient_name), unitOptions, seesCost, errors? */
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="adjustment-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
?>
<?= view('shared/header.php', ['id' => 'adjustment-add', 'title' => 'New adjustment', 'crumbs' => [['Home', '/'], ['Adjustments', '/adjustments/'], ['New', null]], 'back' => back_link() ?? ['/adjustments/', 'Adjustments']]) ?>
<div class="main-content" id="adjustment-add-content">
    <?= view('stock/_assets.php') ?>
    <form method="post" action="/adjustments/save.php" hx-post="/adjustments/save.php" hx-target="#flash" id="adjustment-form" data-stock-form="adjust">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="adjustment-form-field-area">Area</label><select name="area" id="adjustment-form-field-area" class="form-select btn-touch<?= isset($errors['area']) ? ' is-invalid' : '' ?>" required><?= view('transfers/_area-options.php', ['areas' => $areas, 'selected' => $val('area', $prefill['area'])]) ?></select><?= $err('area') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="adjustment-form-field-reason">Reason</label><select name="reason" id="adjustment-form-field-reason" class="form-select btn-touch<?= isset($errors['reason']) ? ' is-invalid' : '' ?>" required><option value="">Choose…</option><?php foreach ($reasons as $r): ?><option value="<?= e($r['code']) ?>" <?= $val('reason', $prefill['reason']) === $r['code'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select><?= $err('reason') ?></div>
            <?php if ($prefill['ingredient'] !== ''): ?>
                <input type="hidden" name="ingredient" value="<?= e($prefill['ingredient']) ?>" data-stock-ingredient>
                <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => 'adjustment-form', 'name' => 'qty_delta', 'unitName' => 'unit', 'label' => 'Change in ' . $prefill['ingredient_name'] . ' (− for less)', 'qty' => $val('qty_delta'), 'unit' => $val('unit', $unitOptions[0]['code'] ?? ''), 'options' => $unitOptions, 'errors' => $errors, 'signed' => true]) ?></div>
            <?php endif; ?>
            <div><label class="form-label fs-12 text-muted" for="adjustment-form-field-notes">Notes</label><textarea name="notes" id="adjustment-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($val('notes')) ?></textarea></div>
        </div></div>
        <div class="stock-post-bar"><button type="submit" class="btn btn-primary btn-touch w-100" id="adjustment-form-submit">Create — then add the lines</button></div>
    </form>
</div>
