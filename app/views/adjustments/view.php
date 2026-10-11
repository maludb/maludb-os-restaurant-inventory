<?php /** One adjustment (screen `adjustment-view`). Data: adjustment, lines, unitOptions, mayEdit, seesCost, movements, trail, notice, errors? */
$j = $adjustment; $aid = (int) $j['adjustment_id']; $errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => 'adjustment-view', 'title' => $j['number'] . ' · ' . $j['reason'], 'crumbs' => [['Home', '/'], ['Adjustments', '/adjustments/'], [$j['number'], null]], 'back' => back_link() ?? ['/adjustments/', 'Adjustments']]) ?>
<div class="main-content" id="adjustment-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="adjustment-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center"><span><?= doc_chip($j['status'], 'adjustment-status') ?></span><span><i class="feather-map-pin me-1"></i><?= e($j['area']) ?></span><span><?= e($j['reason']) ?></span><?php if ($j['notes']): ?><span class="fs-12 text-muted"><?= e($j['notes']) ?></span><?php endif; ?></div></div>
    <?php if ($j['status'] === 'draft' && $mayEdit): ?>
    <form method="post" action="/adjustments/line.php" hx-post="/adjustments/line.php" hx-target="#flash" id="adjustment-line-form" class="card mb-3 stock-line-form" data-stock-form="adjust">
        <?= csrf_field() ?><input type="hidden" name="adjustment" value="<?= $aid ?>">
        <div class="card-header"><h5 class="card-title mb-0">Add a line</h5></div><div class="card-body">
            <div class="mb-3"><label class="form-label fs-12 text-muted" id="adjustment-line-form-field-ingredient-label">Ingredient</label><?= picker_field(['id' => 'adjustment-line-form-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => $_POST['ingredient'] ?? '', 'invalid' => isset($errors['ingredient']), 'labelledby' => 'adjustment-line-form-field-ingredient-label', 'extra' => ' data-stock-ingredient']) ?><?= $err('ingredient') ?></div>
            <div class="mb-3"><?= view('stock/_qty-unit.php', ['prefix' => 'adjustment-line-form', 'name' => 'qty_delta', 'unitName' => 'unit', 'label' => 'Change (− for less, + for more)', 'qty' => $_POST['qty_delta'] ?? '', 'unit' => $_POST['unit'] ?? '', 'options' => $unitOptions, 'errors' => $errors, 'signed' => true]) ?></div>
            <?php if ($seesCost): ?><div class="mb-3"><label class="form-label fs-12 text-muted" for="adjustment-line-form-field-unit_cost">Cost per that unit (an opening balance carries its cost)</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="any" min="0" name="unit_cost" id="adjustment-line-form-field-unit_cost" class="form-control btn-touch"></div><?= $err('unit_cost') ?></div><?php endif; ?>
            <div><label class="form-label fs-12 text-muted" for="adjustment-line-form-field-note">Note</label><input type="text" maxlength="500" name="note" id="adjustment-line-form-field-note" class="form-control btn-touch"></div>
        </div><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="adjustment-line-form-submit"><i class="feather-plus me-1"></i>Add the line</button></div>
    </form>
    <?php endif; ?>
    <?= view('adjustments/_lines-table.php', ['adjustment' => $j, 'lines' => $lines, 'mayEdit' => $mayEdit, 'seesCost' => $seesCost]) ?>
    <?php if ($j['status'] === 'draft' && $mayEdit): ?>
        <div class="stock-post-bar d-flex gap-2" id="adjustment-actions">
            <form method="post" action="/adjustments/post.php" hx-post="/adjustments/post.php" hx-target="#flash" class="flex-grow-1" hx-confirm="Post <?= e($j['number']) ?>: <?= count($lines) ?> lines change the stock in <?= e($j['area']) ?> with no other document?"><?= csrf_field() ?><input type="hidden" name="adjustment" value="<?= $aid ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="adjustment-post-btn" <?= $lines === [] ? 'disabled' : '' ?>><i class="feather-check me-1"></i>Post</button></form>
            <form method="post" action="/adjustments/cancel.php" hx-post="/adjustments/cancel.php" hx-target="#flash" hx-confirm="Cancel <?= e($j['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="adjustment" value="<?= $aid ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="adjustment-cancel-btn">Cancel</button></form>
        </div>
    <?php endif; ?>
    <?php if ($j['status'] === 'posted'): ?><?= view('stock/_doc-movements.php', ['id' => 'adjustment-movements', 'title' => 'What it posted', 'rows' => $movements, 'seesCost' => $seesCost]) ?><?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'adjustment-trail', 'rows' => $trail]) ?>
</div>
