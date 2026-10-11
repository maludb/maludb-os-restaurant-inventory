<?php /** One transfer (screen `transfer-view`). Data: transfer, lines, unitOptions, mayEdit, mayReceive, movements, seesCost, trail, notice, errors? */
$t = $transfer; $tid = (int) $t['transfer_id']; $errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => 'transfer-view', 'title' => $t['number'], 'crumbs' => [['Home', '/'], ['Transfers', '/transfers/'], [$t['number'], null]], 'back' => back_link() ?? ['/transfers/', 'Transfers']]) ?>
<div class="main-content" id="transfer-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="transfer-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <span><?= doc_chip($t['status'], 'transfer-status') ?></span>
        <span class="fw-semibold"><?= e($t['from_site'] . ' · ' . $t['from_area']) ?></span><i class="feather-arrow-right"></i><span class="fw-semibold"><?= e($t['to_site'] . ' · ' . $t['to_area']) ?></span>
        <?php if ($t['notes']): ?><span class="fs-12 text-muted"><?= e($t['notes']) ?></span><?php endif; ?>
    </div></div>
    <?php if ($t['status'] === 'draft' && $mayEdit): ?>
    <form method="post" action="/transfers/line.php" hx-post="/transfers/line.php" hx-target="#flash" id="transfer-line-form" class="card mb-3 stock-line-form" data-stock-form="transfer">
        <?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $tid ?>">
        <div class="card-header"><h5 class="card-title mb-0">Add a line</h5></div><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" id="transfer-line-form-field-ingredient-label">Ingredient</label><?= picker_field(['id' => 'transfer-line-form-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $_POST['ingredient'] ?? '', 'invalid' => isset($errors['ingredient']), 'labelledby' => 'transfer-line-form-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?><?= $err('ingredient') ?></div>
            <?= view('stock/_qty-unit.php', ['prefix' => 'transfer-line-form', 'name' => 'qty', 'unitName' => 'unit', 'label' => 'Quantity', 'qty' => $_POST['qty'] ?? '', 'unit' => $_POST['unit'] ?? '', 'options' => $unitOptions, 'errors' => $errors]) ?>
        </div><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="transfer-line-form-submit"><i class="feather-plus me-1"></i>Add the line</button></div>
    </form>
    <?php endif; ?>
    <?= view('transfers/_lines-table.php', ['transfer' => $t, 'lines' => $lines, 'mayEdit' => $mayEdit, 'mayReceive' => $mayReceive]) ?>
    <?php if ($t['status'] === 'draft' && $mayEdit): ?>
        <div class="stock-post-bar d-flex gap-2" id="transfer-actions">
            <form method="post" action="/transfers/send.php" hx-post="/transfers/send.php" hx-target="#flash" class="flex-grow-1" hx-confirm="Send <?= e($t['number']) ?>: <?= count($lines) ?> lines leave <?= e($t['from_area']) ?>?"><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $tid ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="transfer-send-btn" <?= $lines === [] ? 'disabled' : '' ?>><i class="feather-send me-1"></i>Send</button></form>
            <form method="post" action="/transfers/cancel.php" hx-post="/transfers/cancel.php" hx-target="#flash" hx-confirm="Cancel <?= e($t['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $tid ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="transfer-cancel-btn">Cancel</button></form>
        </div>
    <?php endif; ?>
    <?php if (in_array($t['status'], ['in_transit', 'received'], true)): ?><?= view('stock/_doc-movements.php', ['id' => 'transfer-movements', 'title' => 'Both halves', 'rows' => $movements, 'seesCost' => $seesCost]) ?><?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'transfer-trail', 'rows' => $trail]) ?>
</div>
