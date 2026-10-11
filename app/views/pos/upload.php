<?php /** The upload (screen sales-upload; ids upload-form-field-{name}): the source, the file, the mapping; Preview (no write) and Import. Data: sources, chosen, mappings, mayCreate, back; preview?, fileName? (JavaScript off: the preview under the form) */ ?>
<?= view('shared/header.php', ['id' => 'sales-upload', 'title' => 'Upload a POS file', 'crumbs' => [['Home', '/'], ['Sales', '/sales/'], ['Upload', null]], 'back' => $back]) ?>
<div class="main-content" id="sales-upload-content">
    <?php if ($sources === [] && !$mayCreate): ?><div class="alert alert-warning" id="sales-upload-no-source">This site has no file source yet — the admin adds one (POS sources).</div><?php else: ?>
    <form method="post" action="/sales/upload.php" hx-post="/sales/upload.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="upload-form" class="card mb-3">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="mb-3"><label class="fw-semibold mb-1" for="upload-form-field-source">Source</label><select name="source" id="upload-form-field-source" class="form-select btn-touch" required>
                <?php if (count($sources) !== 1): ?><option value="">Choose…</option><?php endif; ?>
                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= (int) $s['source_id'] === (int) $chosen ? 'selected' : '' ?> <?= $s['paused_at'] !== null ? 'disabled' : '' ?>><?= e($s['name']) ?><?= $s['paused_at'] !== null ? ' (paused)' : '' ?></option><?php endforeach; ?>
                <?php if ($mayCreate): ?><option value="new" <?= $sources === [] ? 'selected' : '' ?>>A new source fed by upload</option><?php endif; ?>
            </select></div>
            <div class="mb-3"><label class="fw-semibold mb-1" for="upload-form-field-file">The export (CSV or XLSX)</label><input type="file" name="file" id="upload-form-field-file" class="form-control btn-touch w-100" accept=".csv,.xlsx,.txt" required></div>
            <div class="mb-3"><label class="fw-semibold mb-1" for="upload-form-field-mapping">Mapping</label><select name="mapping" id="upload-form-field-mapping" class="form-select btn-touch"><option value="">The source's mapping</option><?php foreach ($mappings as $m): ?><option value="<?= e($m['key']) ?>"><?= e($m['name']) ?> (<?= e($m['key']) ?>)</option><?php endforeach; ?></select></div>
            <div class="form-check mb-2"><input type="hidden" name="deplete" value="no"><input type="checkbox" class="form-check-input" name="deplete" value="yes" id="upload-form-field-deplete" checked><label class="form-check-label" for="upload-form-field-deplete">Deplete the days when imported</label></div>
        </div>
        <div class="card-footer d-flex gap-2 upload-actions" id="upload-actions">
            <button type="submit" class="btn btn-light-brand btn-touch flex-grow-1" id="upload-preview-btn" formaction="/sales/upload.php?preview=1" hx-post="/sales/upload.php?preview=1" hx-target="#upload-preview" hx-encoding="multipart/form-data">Preview</button>
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="upload-import-btn">Import</button>
        </div>
    </form>
    <div id="upload-preview"><?php if (!empty($preview)): ?><?= view('pos/_upload-preview.php', ['p' => $preview, 'fileName' => $fileName ?? '', 'src' => $src ?? null]) ?><?php endif; ?></div>
    <?php endif; ?>
</div>
