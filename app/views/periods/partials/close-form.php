<?php /** The close of the first open period past its end (form `period-close-form`). Data: p (the period), closingCounts, undepleted */
$tz = site_timezone((int) $p['scope_id']);
$confirm = 'Close ' . $p['label'] . '? Its figures freeze.';
?>
<div class="card mb-3 period-close-form" id="period-close-card"><div class="card-body">
    <div class="fw-semibold mb-1">Close <?= e($p['label']) ?></div>
    <?php if ($undepleted !== []): ?>
        <div class="alert alert-warning mb-2" id="period-undepleted" role="status"><?= e(implode(' and ', array_map(static fn (string $d): string => format_date($d), $undepleted))) ?> <?= count($undepleted) === 1 ? 'is' : 'are' ?> not depleted yet — run <?= count($undepleted) === 1 ? 'it' : 'them' ?> first. <?= hx_link('/sales/', 'Sales by day', 'alert-link') ?></div>
    <?php endif; ?>
    <form method="post" action="/periods/close.php" hx-post="/periods/close.php" hx-target="#flash" hx-confirm="<?= e($confirm) ?>" class="row g-2 align-items-end" id="period-close-form">
        <?= csrf_field() ?><input type="hidden" name="period" value="<?= (int) $p['period_id'] ?>">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="period-close-form-field-closing_count">Closing count (optional)</label>
            <select name="closing_count" id="period-close-form-field-closing_count" class="form-select btn-touch"><option value="">None — close on the book</option>
            <?php foreach ($closingCounts as $c): ?><option value="<?= (int) $c['count_id'] ?>"><?= e($c['number'] . ' — ' . format_ts($c['as_of'], $tz, 'M j g:i A')) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="period-close-btn"><i class="feather-lock me-1"></i>Close</button></div>
    </form>
</div></div>
