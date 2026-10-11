<?php /** The mapping editor (screen mapping-edit; ids mapping-form-field-{name}). A built-in is read-only (Copy makes ours); a sample file previews the first five rows. Data: m, base, mayEdit, mayCopy, notice, back */
$isNew = $m === null; $mm = ($m ?? $base)['mapping'] ?? []; $cols = (array) ($mm['columns'] ?? []); $ro = !$mayEdit ? 'readonly' : '';
$required = ['external_id' => true, 'business_date' => true, 'plu' => true, 'qty' => true];
$labels = ['external_id' => 'Check id', 'business_date' => 'Business date', 'closed_at' => 'Close time', 'plu' => 'PLU', 'item_name' => 'Item name', 'qty' => 'Quantity', 'unit_price' => 'Unit price', 'line_total' => 'Line total',
    'voided' => 'Void flag', 'comp' => 'Comp flag (or discount)', 'modifiers' => 'Modifiers', 'covers' => 'Covers', 'external_line_id' => 'Line id', 'tax' => 'Tax', 'total' => 'Check total'];
?>
<?= view('shared/header.php', ['id' => 'mapping-edit', 'title' => $isNew ? 'New mapping' : 'Mapping · ' . $m['name'], 'crumbs' => [['Home', '/'], ['File mappings', has_right('settings.manage') ? '/sales/mappings/' : null], [$isNew ? 'New' : $m['key'], null]], 'back' => $back,
    'action' => (!$isNew && $m['is_builtin'] && $mayCopy) ? hx_link('/sales/mappings/new?copy=' . $m['key'], '<i class="feather-copy me-1"></i>Copy to change', 'btn btn-primary btn-touch', 'id="mapping-edit-copy"') : '']) ?>
<div class="main-content" id="mapping-edit-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$isNew && $m['is_builtin']): ?><div class="alert alert-info fs-12" id="mapping-edit-builtin">A built-in mapping is read-only. Copy it to change it.<?= !empty($mm['to_confirm']) ? ' Its column names are to be confirmed against a real export of the POS.' : '' ?></div><?php endif; ?>
    <form method="post" action="/sales/mappings/save.php" hx-post="/sales/mappings/save.php" hx-target="#flash" id="mapping-form" class="card mb-3">
        <?= csrf_field() ?>
        <?php if (!$isNew): ?><input type="hidden" name="mapping_key" value="<?= e($m['key']) ?>"><?php endif; ?>
        <div class="card-body">
            <?php if ($isNew): ?><div class="row mb-2 align-items-center"><div class="col-lg-4"><label class="fw-semibold" for="mapping-form-field-key">Key</label></div><div class="col-lg-8"><input type="text" name="key" id="mapping-form-field-key" class="form-control btn-touch" pattern="[a-z][a-z0-9_]{0,39}" required value="<?= e(($base['key'] ?? 'generic') . '_ours') ?>"></div></div><?php endif; ?>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label class="fw-semibold" for="mapping-form-field-name">Name</label></div><div class="col-lg-8"><input type="text" name="name" id="mapping-form-field-name" class="form-control btn-touch" maxlength="80" required <?= $ro ?> value="<?= e($isNew ? ($base['name'] ?? 'Generic') . ' (ours)' : $m['name']) ?>"></div></div>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-delimiter">Delimiter · date format</label></div><div class="col-lg-8"><div class="input-group"><input type="text" name="delimiter" id="mapping-form-field-delimiter" class="form-control btn-touch" maxlength="2" style="max-width:6rem" <?= $ro ?> value="<?= e((string) ($mm['delimiter'] ?? ',')) ?>"><input type="text" name="date_format" id="mapping-form-field-date-format" class="form-control btn-touch" <?= $ro ?> value="<?= e((string) ($mm['date_format'] ?? '')) ?>" placeholder="YYYY-MM-DD"></div></div></div>
            <h6 class="fs-12 text-muted text-uppercase mt-3">The column of each field</h6>
            <?php foreach (RiPosNormalizer::FIELDS as $f): ?><div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-col-<?= $f ?>"><?= e($labels[$f]) ?><?= isset($required[$f]) ? ' <span class="text-danger" title="required">*</span>' : '' ?></label></div><div class="col-lg-8"><input type="text" name="col_<?= $f ?>" id="mapping-form-field-col-<?= $f ?>" class="form-control btn-touch" <?= $ro ?> value="<?= e((string) ($cols[$f] ?? '')) ?>"></div></div><?php endforeach; ?>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-void-values">A void when the column says</label></div><div class="col-lg-8"><input type="text" name="void_values" id="mapping-form-field-void-values" class="form-control btn-touch" <?= $ro ?> value="<?= e(implode(', ', (array) ($mm['void_values'] ?? ['yes', 'true', '1', 'y', 'void']))) ?>"></div></div>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-comp-rule">A comp is</label></div><div class="col-lg-8"><select name="comp_rule" id="mapping-form-field-comp-rule" class="form-select btn-touch" <?= $mayEdit ? '' : 'disabled' ?>><option value="" <?= ($mm['comp_rule'] ?? '') === '' ? 'selected' : '' ?>>a flag (the words below)</option><option value="full_discount" <?= ($mm['comp_rule'] ?? '') === 'full_discount' ? 'selected' : '' ?>>a discount of the whole line</option></select></div></div>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-comp-values">Comp words</label></div><div class="col-lg-8"><input type="text" name="comp_values" id="mapping-form-field-comp-values" class="form-control btn-touch" <?= $ro ?> value="<?= e(implode(', ', (array) ($mm['comp_values'] ?? ['yes', 'true', '1', 'y', 'comp']))) ?>"></div></div>
            <div class="row mb-2 align-items-center"><div class="col-lg-4"><label for="mapping-form-field-modifier-split">Modifiers separated by</label></div><div class="col-lg-8"><input type="text" name="modifier_split" id="mapping-form-field-modifier-split" class="form-control btn-touch" maxlength="3" style="max-width:6rem" <?= $ro ?> value="<?= e((string) ($mm['modifier_split'] ?? ';')) ?>"></div></div>
        </div>
        <?php if ($mayEdit): ?><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch" id="mapping-form-save"><?= $isNew ? 'Make the mapping' : 'Save' ?></button></div><?php endif; ?>
    </form>
    <?php if ($mayCopy): ?>
    <form method="post" action="/sales/mappings/preview.php" hx-post="/sales/mappings/preview.php" hx-target="#mapping-preview" hx-encoding="multipart/form-data" hx-include="#mapping-form" enctype="multipart/form-data" id="mapping-sample-form" class="card">
        <?= csrf_field() ?>
        <div class="card-body d-flex flex-wrap gap-2 align-items-center"><label class="fw-semibold" for="mapping-sample-file">Try a sample file</label><input type="file" name="sample" id="mapping-sample-file" class="form-control btn-touch" accept=".csv,.xlsx,.txt" style="max-width:24rem"><button type="submit" class="btn btn-light-brand btn-touch" id="mapping-sample-preview">Preview</button></div>
        <div id="mapping-preview" class="px-3 pb-3"></div>
    </form>
    <?php endif; ?>
</div>
