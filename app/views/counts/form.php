<?php /** Start a count (screen `count-add`). Data: sheets, sheetId, kind, asOf (site-local YYYY-MM-DDTHH:MM), blind, mayBlind, openFull, errors? */
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="count-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$tonight = date_create_immutable($asOf)->format('g:i A');
?>
<?= view('shared/header.php', ['id' => 'count-add', 'title' => 'Start a count', 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], ['Start', null]], 'back' => back_link() ?? ['/counts/', 'Counts']]) ?>
<div class="main-content" id="count-add-content">
    <?= view('counts/partials/assets.php') ?>
    <?php if ($openFull !== []): ?><div class="alert alert-warning" id="count-form-open-note" role="status">A full count is already open at this site — <?= hx_link(count_url($openFull, true), e($openFull['number']), 'alert-link') ?>. Finish or discard it before starting another full count; a cycle or spot count may open beside it.</div><?php endif; ?>
    <form method="post" action="/counts/start.php" hx-post="/counts/start.php" hx-target="#flash" id="count-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="count-form-field-sheet">What to count</label>
                <select name="sheet" id="count-form-field-sheet" class="form-select btn-touch<?= isset($errors['sheet']) ? ' is-invalid' : '' ?>"><option value="">The whole site — every ingredient with a balance, area by area</option>
                    <?php foreach ($sheets as $s): ?><option value="<?= (int) $s['count_sheet_id'] ?>" <?= $sheetId === $s['count_sheet_id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['lines'] ?> lines)</option><?php endforeach; ?></select><?= $err('sheet') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="count-form-field-kind">Kind</label>
                <select name="kind" id="count-form-field-kind" class="form-select btn-touch<?= isset($errors['kind']) ? ' is-invalid' : '' ?>"><?php foreach (COUNT_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select><?= $err('kind') ?></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="count-form-field-as_of">As of</label>
                <input type="datetime-local" name="as_of" id="count-form-field-as_of" value="<?= e($_POST['as_of'] ?? $asOf) ?>" class="form-control btn-touch<?= isset($errors['as_of']) ? ' is-invalid' : '' ?>" required>
                <div class="form-text" id="count-form-as_of-help">The book figure is frozen as of this moment. Tonight at <?= e($tonight) ?> is the day's sales cut — the day's sales are dated at it, so a count as of now before the cut would miss them. A count taken this morning for last night picks yesterday's cut.</div><?= $err('as_of') ?></div>
            <div class="mb-3"><?php if ($mayBlind): ?><input type="hidden" name="blind" value="no"><?php endif; ?>
                <label class="d-flex align-items-center gap-2 btn-touch" for="count-form-field-blind"><input type="checkbox" class="form-check-input mt-0" name="blind" value="yes" id="count-form-field-blind" <?= $blind ? 'checked' : '' ?> <?= $mayBlind ? '' : 'disabled' ?>> Blind — hide the book figure from the counter until review</label>
                <?php if (!$mayBlind): ?><div class="form-text">A Chef or the Manager chooses this; it follows the business's setting (<?= $blind ? 'blind' : 'not blind' ?>) for you.</div><?php endif; ?></div>
            <div><label class="form-label fs-12 text-muted" for="count-form-field-notes">Notes</label><textarea name="notes" id="count-form-field-notes" maxlength="2000" rows="2" class="form-control"><?= e($_POST['notes'] ?? '') ?></textarea><?= $err('notes') ?></div>
        </div></div>
        <div class="stock-post-bar"><button type="submit" class="btn btn-primary btn-touch w-100" id="count-form-submit">Start</button></div>
    </form>
</div>
