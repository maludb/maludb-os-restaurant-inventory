<?php /** The sheet by storage area (screen `count-sheet-edit`). Data: sheet, areas, units (by ingredient), mayWrite, mayCount, notice */
$sid = (int) $sheet['count_sheet_id'];
$start = $mayCount && $sheet['active'] ? hx_link('/counts/new?sheet=' . $sid, '<i class="feather-clipboard me-1"></i>Start a count from this sheet', 'btn btn-primary btn-touch', 'id="sheet-start-count-btn"') : '';
?>
<?= view('shared/header.php', ['id' => 'count-sheet-edit', 'title' => $sheet['name'], 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], ['Count sheets', '/counts/sheets/'], [$sheet['name'], null]], 'back' => back_link() ?? ['/counts/sheets/', 'Count sheets'], 'action' => $start]) ?>
<div class="main-content" id="count-sheet-edit-content" data-sheet="<?= $sid ?>">
    <?= view('counts/partials/assets.php') ?>
    <script src="/assets/vendors/sortablejs/Sortable.min.js"></script>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="sheet-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <span><?= sheet_kind_chip($sheet['kind']) ?><?= $sheet['active'] ? '' : ' ' . chip('archived', 'dark') ?></span>
        <span class="fs-12 text-muted"><?= (int) $sheet['lines'] ?> line<?= (int) $sheet['lines'] === 1 ? '' : 's' ?> in <?= (int) $sheet['areas'] ?> area<?= (int) $sheet['areas'] === 1 ? '' : 's' ?></span>
<?php if ($mayWrite): ?>
        <form method="post" action="/counts/sheets/save.php" hx-post="/counts/sheets/save.php" hx-target="#flash" class="d-flex flex-wrap gap-2 align-items-center ms-md-auto" id="sheet-settings-form"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= $sid ?>">
            <input type="text" name="name" value="<?= e($sheet['name']) ?>" maxlength="80" class="form-control btn-touch" style="width: 12rem" aria-label="Name" id="sheet-settings-name">
            <select name="kind" class="form-select btn-touch" style="width: 8rem" aria-label="Kind" id="sheet-settings-kind"><?php foreach (COUNT_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $sheet['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
            <input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-1 btn-touch" for="sheet-settings-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="sheet-settings-active" <?= $sheet['active'] ? 'checked' : '' ?>> in use</label>
            <button type="submit" class="btn btn-light btn-touch" id="sheet-settings-save">Save</button></form>
        <form method="post" action="/counts/sheets/save.php" hx-post="/counts/sheets/save.php" hx-target="#flash" hx-confirm="Add every ingredient with a balance that is not on the sheet yet?"><?= csrf_field() ?><input type="hidden" name="sheet" value="<?= $sid ?>"><input type="hidden" name="fill" value="yes"><button type="submit" class="btn btn-outline-primary btn-touch" id="sheet-fill"><i class="feather-download me-1"></i>Fill from the shelves</button></form>
<?php endif; ?>
    </div></div>
    <div id="sheet-areas">
<?php foreach ($areas as $a): ?><?= view('counts/sheets/partials/sheet-area.php', ['a' => $a, 'sheetId' => $sid, 'unitsByIngredient' => $units, 'mayWrite' => $mayWrite]) ?><?php endforeach; ?>
<?php if ($areas === []): ?><div class="card"><div class="card-body text-center text-muted py-4">This site has no storage areas yet — <?= hx_link('/areas/', 'add them first') ?>.</div></div><?php endif; ?>
    </div>
</div>
