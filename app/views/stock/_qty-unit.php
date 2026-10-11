<?php /** A quantity and its unit side by side (ledger.md "Mobile rule": the number ≥ 56 px tall, the unit select ≥ 44 px). Data: prefix, name, label, qty, unit, options [{code, label}], errors, unitName?, help?, signed? */
$unitName = $unitName ?? ($name . '_unit');
$errors = $errors ?? [];
$qid = $prefix . '-field-' . $name;
$uid = $prefix . '-field-' . ($unitName === 'unit' ? 'unit' : $unitName);
$codes = array_column($options, 'code');
?>
<label class="form-label fs-12 text-muted" for="<?= e($qid) ?>"><?= e($label) ?></label>
<div class="input-group stock-qty-unit">
    <input type="number" step="any" <?= empty($signed) ? 'min="0"' : '' ?> inputmode="decimal" name="<?= e($name) ?>" id="<?= e($qid) ?>" class="form-control stock-qty<?= isset($errors[$name]) ? ' is-invalid' : '' ?>" value="<?= e(fmt_input($qty === '' ? null : $qty)) ?>">
    <select name="<?= e($unitName) ?>" id="<?= e($uid) ?>" class="form-select stock-unit<?= isset($errors[$unitName]) ? ' is-invalid' : '' ?>" aria-label="Unit">
        <?php if ($unit !== null && $unit !== '' && !in_array($unit, $codes, true)): ?><option value="<?= e($unit) ?>" selected><?= e($unit) ?></option><?php endif; ?>
        <?php foreach ($options as $o): ?><option value="<?= e($o['code']) ?>" <?= (string) $unit === (string) $o['code'] ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
    </select>
</div>
<?php foreach ([$name, $unitName] as $k): if (isset($errors[$k])): ?><div class="invalid-feedback d-block" id="<?= e($prefix) ?>-error-<?= e($k) ?>"><?= e($errors[$k]) ?></div><?php endif; endforeach; ?>
<?php if (!empty($help)): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
