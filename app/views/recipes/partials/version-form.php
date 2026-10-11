<?php /** The draft's batch form (Pattern C: saved on change). Data: version, recipe, units, saved? */
$v = $version; $vid = (int) $v['recipe_version_id']; $isPrep = $recipe['kind'] === 'prep'; ?>
<form method="post" action="/recipes/versions/save.php" hx-post="/recipes/versions/save.php" hx-target="this" hx-swap="outerHTML" hx-trigger="change delay:200ms, submit" id="version-form" class="row g-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="version" value="<?= $vid ?>">
    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="version-form-field-batch-yield-qty">One batch makes</label><input type="number" step="0.0001" min="0.0001" name="batch_yield_qty" id="version-form-field-batch-yield-qty" class="form-control btn-touch" value="<?= e(ri_fmt($v['batch_yield_qty'], 4)) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="version-form-field-batch-yield-unit">Unit</label>
        <?php if ($isPrep): ?><select name="batch_yield_unit" id="version-form-field-batch-yield-unit" class="form-select btn-touch"><option value="">choose</option><?php foreach ($units as $u): ?><option value="<?= e($u['code']) ?>" <?= $v['batch_yield_unit_code'] === $u['code'] ? 'selected' : '' ?>><?= e($u['label']) ?></option><?php endforeach; ?></select>
        <?php else: ?><input type="text" class="form-control btn-touch" value="portion(s)" id="version-form-field-batch-yield-unit" readonly><input type="hidden" name="batch_yield_unit" value="portion"><?php endif; ?></div>
    <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="version-form-field-expected-loss-pct">Expected loss %</label><input type="number" step="0.01" min="0" max="99.99" name="expected_loss_pct" id="version-form-field-expected-loss-pct" class="form-control btn-touch" value="<?= e(ri_fmt($v['expected_loss_pct'], 2)) ?>"></div>
    <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="version-form-field-change-note">Change note</label><input type="text" name="change_note" id="version-form-field-change-note" class="form-control btn-touch" value="<?= e((string) $v['change_note']) ?>" maxlength="500" placeholder="what this version changes"></div>
    <noscript><div class="col-12"><button type="submit" class="btn btn-light-brand btn-touch">Save the batch</button></div></noscript>
    <?php if (!empty($saved)): ?><div class="col-12 fs-12 text-success" id="version-form-saved">Saved.</div><?php endif; ?>
</form>
