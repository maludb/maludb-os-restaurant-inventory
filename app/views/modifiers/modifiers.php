<?php /** The modifiers (screen modifier-list). Data: rows, filters, sources, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'modifier-list', 'title' => 'Modifiers', 'crumbs' => [['Home', '/'], ['Menu items', '/menu/'], ['Modifiers', null]]]) ?>
<div class="main-content" id="modifier-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/modifiers/" class="card mb-3" id="modifier-list-filters" hx-get="/modifiers/" hx-target="#page-content" hx-push-url="true" hx-trigger="change"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <select name="source" class="form-select btn-touch w-auto" id="modifier-list-filter-source"><option value="">Every source</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= $filters['source'] === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <label class="border rounded px-3 btn-touch d-flex align-items-center gap-2" for="modifier-list-filter-unmapped"><input type="checkbox" class="form-check-input mt-0" name="unmapped" value="1" id="modifier-list-filter-unmapped" <?= $filters['unmapped'] ? 'checked' : '' ?>>Unmapped only</label></div></form>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0" id="modifiers-table"><thead><tr><th>Code</th><th>Name</th><th>Source</th><th>Action</th><th>Recipe</th><th class="text-end">Factor</th><th>First seen</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $m): ?><?= view('modifiers/partials/modifier-row.php', ['m' => $m, 'tz' => $tz]) ?><?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="8" class="text-muted p-4 text-center" id="modifiers-empty">No modifier seen yet — the POS import (slice 5) brings them.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
