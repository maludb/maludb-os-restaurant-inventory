<?php /** Make or change a storage area (screens `area-add`, `area-edit`). Data: cur, nextOrder, site, errors? */
$id = $cur['storage_area_id'] ?? null;
$screen = $cur === null ? 'area-add' : 'area-edit';
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New storage area' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Storage areas', '/areas/'], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? ['/areas/', 'Storage areas']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/areas/save.php" hx-post="/areas/save.php" hx-target="#flash" id="area-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="area" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="return_to" value="">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">At <?= e($site['name']) ?></h5></div><div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="area-form-field-name">Name</label><input type="text" name="name" id="area-form-field-name" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Walk-in"><?= $err('name') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="area-form-field-kind">Kind</label><select name="kind" id="area-form-field-kind" class="form-select btn-touch"><?php foreach (AREA_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= ($cur['kind'] ?? 'dry') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="area-form-field-walk_order">Walk order</label><input type="number" min="0" name="walk_order" id="area-form-field-walk_order" class="form-control btn-touch" value="<?= (int) ($cur['walk_order'] ?? $nextOrder) ?>"></div>
            <div class="col-12"><input type="hidden" name="allow_negative" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="area-form-field-allow_negative"><input type="checkbox" class="form-check-input mt-0" name="allow_negative" value="yes" id="area-form-field-allow_negative" <?= ($cur['allow_negative'] ?? true) ? 'checked' : '' ?>><span class="fs-12">Allows a negative balance — a kitchen depletes before it counts</span></label></div>
            <?php if ($cur !== null): ?><div class="col-12"><input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="area-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="area-form-field-active" <?= $cur['active'] ? 'checked' : '' ?>><span class="fs-12">Active</span></label></div><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="area-form-save-btn"><?= $cur === null ? 'Make the area' : 'Save' ?></button>
        <?= hx_link('/areas/', 'Cancel', 'btn btn-light btn-touch w-100') ?>
    </form>
</div>
