<?php /** The count sheets as cards (screen `count-sheet-list`). Data: sheets, mayWrite, mayCount, notice, errors? */
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => 'count-sheet-list', 'title' => 'Count sheets', 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], ['Count sheets', null]]]) ?>
<div class="main-content" id="count-sheet-list-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
<?php if ($mayWrite): ?>
    <details class="card mb-3" id="sheet-form-details" <?= $errors !== [] ? 'open' : '' ?>><summary class="card-header btn-touch d-flex align-items-center" id="sheet-new-btn"><h5 class="card-title mb-0"><i class="feather-plus me-1"></i>New sheet</h5></summary>
        <form method="post" action="/counts/sheets/save.php" hx-post="/counts/sheets/save.php" hx-target="#flash" id="sheet-form"><?= csrf_field() ?>
            <div class="card-body row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="sheet-form-field-name">Name</label><input type="text" name="name" id="sheet-form-field-name" maxlength="80" required class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" value="<?= e($_POST['name'] ?? '') ?>" placeholder="Full house, Bar, Friday cycle…"><?= $err('name') ?></div>
                <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="sheet-form-field-kind">Kind</label><select name="kind" id="sheet-form-field-kind" class="form-select btn-touch"><?php foreach (COUNT_KINDS as $k => $w): ?><option value="<?= e($k) ?>"><?= e($w) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-3 d-flex align-items-end"><input type="hidden" name="fill" value="no"><label class="d-flex align-items-center gap-2 btn-touch" for="sheet-form-field-fill"><input type="checkbox" class="form-check-input mt-0" name="fill" value="yes" id="sheet-form-field-fill"> Fill from the shelves</label></div>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="sheet-form-submit">Make the sheet</button></div>
        </form></details>
<?php endif; ?>
<?php if ($sheets === []): ?><div class="card" id="count-sheet-list-empty"><div class="card-body text-center text-muted py-5">No count sheets yet. A sheet lists the ingredients of each storage area in walk order, each with the unit it is counted in.<?= $mayWrite ? '' : ' A Chef makes them.' ?></div></div>
<?php else: ?><div class="row g-3" id="sheet-cards"><?php foreach ($sheets as $s): ?><?= view('counts/sheets/partials/sheet-card.php', ['s' => $s, 'mayWrite' => $mayWrite, 'mayCount' => $mayCount]) ?><?php endforeach; ?></div><?php endif; ?>
</div>
