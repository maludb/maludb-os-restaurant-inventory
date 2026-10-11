<?php /** The reopen of the last closed period (form `period-reopen-form`). Data: p, suffix ('' for the first) */
$suffix = $suffix ?? '';
?>
<form method="post" action="/periods/reopen.php" hx-post="/periods/reopen.php" hx-target="#flash" hx-confirm="Reopen <?= e($p['label']) ?>? Its figures go live again." class="row g-2 align-items-end period-reopen-form" id="period-reopen-form<?= e($suffix) ?>">
    <?= csrf_field() ?><input type="hidden" name="period" value="<?= (int) $p['period_id'] ?>">
    <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="period-reopen-form-field-reason<?= e($suffix) ?>">Reopen <?= e($p['label']) ?> — why?</label><input type="text" name="reason" maxlength="200" required id="period-reopen-form-field-reason<?= e($suffix) ?>" class="form-control btn-touch" placeholder="A late invoice, a count to redo…"></div>
    <div class="col-12 col-md-3"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="period-reopen-btn<?= e($suffix) ?>"><i class="feather-unlock me-1"></i>Reopen</button></div>
</form>
