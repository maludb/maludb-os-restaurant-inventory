<?php /** Start or change a transfer (screen `transfer-add`; the draft's header when `cur`). Data: cur, sites, fromAreas, toAreas, prefill (to_site, from_area, to_area, ingredient), unitOptions, errors? */
$id = $cur['transfer_id'] ?? null;
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="transfer-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$val = static fn (string $k, mixed $d = ''): string => (string) ($_POST[$k] ?? $d);
$toSite = $val('to_site', (string) ($cur['to_scope_id'] ?? $prefill['to_site']));
?>
<?= view('shared/header.php', ['id' => 'transfer-add', 'title' => $id === null ? 'New transfer' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['Transfers', '/transfers/'], [$id === null ? 'New' : $cur['number'], $id === null ? null : '/transfers/' . $id]], 'back' => back_link() ?? ['/transfers/', 'Transfers']]) ?>
<div class="main-content" id="transfer-add-content">
    <?= view('stock/_assets.php') ?>
    <form method="post" action="/transfers/save.php" hx-post="/transfers/save.php" hx-target="#flash" id="transfer-form" data-stock-form="transfer">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="transfer" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="transfer-form-field-from_area">From (this site's area)</label><select name="from_area" id="transfer-form-field-from_area" class="form-select btn-touch<?= isset($errors['from_area']) ? ' is-invalid' : '' ?>" required><?= view('transfers/_area-options.php', ['areas' => $fromAreas, 'selected' => $val('from_area', (string) ($cur['from_area_id'] ?? $prefill['from_area']))]) ?></select><?= $err('from_area') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="transfer-form-field-to_site">To site</label><select name="to_site" id="transfer-form-field-to_site" class="form-select btn-touch<?= isset($errors['to_site']) ? ' is-invalid' : '' ?>" hx-get="/transfers/area-options" hx-trigger="change" hx-target="#transfer-form-field-to_area" hx-include="this" hx-vals='js:{site: document.getElementById("transfer-form-field-to_site").value}'>
                <?php foreach ($sites as $s): ?><option value="<?= (int) $s['scope_id'] ?>" <?= $toSite === (string) $s['scope_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?= $err('to_site') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="transfer-form-field-to_area">To area</label><select name="to_area" id="transfer-form-field-to_area" class="form-select btn-touch<?= isset($errors['to_area']) ? ' is-invalid' : '' ?>" required><?= view('transfers/_area-options.php', ['areas' => $toAreas, 'selected' => $val('to_area', (string) ($cur['to_area_id'] ?? $prefill['to_area']))]) ?></select><?= $err('to_area') ?></div>
            <?php if ($id === null && $prefill['ingredient'] !== ''): ?>
                <input type="hidden" name="ingredient" value="<?= e($prefill['ingredient']) ?>" data-stock-ingredient>
                <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => 'transfer-form', 'name' => 'qty', 'unitName' => 'unit', 'label' => 'How much of ' . ($prefill['ingredient_name'] ?? 'it'), 'qty' => $val('qty'), 'unit' => $val('unit', $unitOptions[0]['code'] ?? ''), 'options' => $unitOptions, 'errors' => $errors]) ?></div>
            <?php endif; ?>
            <div><label class="form-label fs-12 text-muted" for="transfer-form-field-notes">Notes</label><textarea name="notes" id="transfer-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($val('notes', (string) ($cur['notes'] ?? ''))) ?></textarea></div>
        </div></div>
        <div class="stock-post-bar"><button type="submit" class="btn btn-primary btn-touch w-100" id="transfer-form-submit"><?= $id === null ? 'Create — then add the lines' : 'Save' ?></button></div>
    </form>
</div>
