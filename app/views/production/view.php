<?php /** One batch (screen `production-view`). Data: batch, movements, seesCost, mayProduce, trail, notice */
$b = $batch; $bid = (int) $b['production_batch_id']; $pdo = db(); $tz = site_timezone();
$consumed = array_values(array_filter($movements, static fn (array $t): bool => $t['txn_type'] === 'production_out'));
$made = array_values(array_filter($movements, static fn (array $t): bool => $t['txn_type'] === 'production_in'));
?>
<?= view('shared/header.php', ['id' => 'production-view', 'title' => $b['number'] . ' · ' . $b['recipe'], 'crumbs' => [['Home', '/'], ['Prep batches', '/production/'], [$b['number'], null]], 'back' => back_link() ?? ['/production/', 'Prep batches']]) ?>
<div class="main-content" id="production-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="production-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center"><span><?= doc_chip($b['status'], 'production-status') ?></span><span><?= hx_link('/recipes/' . $b['recipe_id'], e($b['recipe'])) ?></span><span><?= e(fmt_num($b['batches'], 4)) ?> batch<?= (float) $b['batches'] === 1.0 ? '' : 'es' ?></span><span><i class="feather-map-pin me-1"></i><?= e($b['area']) ?></span><span class="fs-12 text-muted"><?= e(format_ts($b['made_at'], $tz, 'M j g:i A')) ?> · <?= e((string) $b['made_by_name']) ?></span>
        <?php if ($seesCost && $b['cost_total'] !== null): ?><span class="ms-auto">Cost <span class="fw-semibold" id="production-cost-total"><?= money((string) $b['cost_total']) ?></span></span><?php endif; ?></div></div>
    <?php if ($b['status'] === 'posted'): ?>
        <div class="row g-3">
            <div class="col-lg-6"><div class="card mb-3" id="production-consumed"><div class="card-header"><h5 class="card-title mb-0">What it used</h5></div><table class="table mb-0 fs-12" id="production-consumed-table"><tbody>
                <?php foreach ($consumed as $t): ?><tr id="production-consumed-row-<?= $t['ingredient_id'] ?>"><td><?= e($t['ingredient']) ?></td><td class="text-end"><?= e(format_qty($pdo, $t['ingredient_id'], (string) abs((float) $t['qty_base']))) ?></td><td class="text-end"><?= $seesCost ? money((string) abs((float) $t['value'])) : money(null) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
            <div class="col-lg-6"><div class="card mb-3" id="production-made"><div class="card-header"><h5 class="card-title mb-0">What it made</h5></div><table class="table mb-0 fs-12" id="production-made-table"><tbody>
                <?php foreach ($made as $t): ?><tr id="production-made-row-<?= $t['ingredient_id'] ?>"><td><?= e($t['ingredient']) ?></td><td class="text-end fw-semibold"><?= e(format_qty($pdo, $t['ingredient_id'], $t['qty_base'])) ?></td><td class="text-end"><?= $seesCost ? '$' . e(fmt_num($t['unit_cost_per_base'], 6)) . ' / ' . e($t['base_unit_code']) : money(null) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        </div>
        <?= view('stock/_doc-movements.php', ['id' => 'production-movements', 'title' => 'The movements', 'rows' => $movements, 'seesCost' => $seesCost]) ?>
    <?php elseif ($b['status'] === 'draft' && $mayProduce): ?>
        <div class="stock-post-bar d-flex gap-2" id="production-actions">
            <form method="post" action="/production/save.php" hx-post="/production/save.php" hx-target="#flash" class="flex-grow-1" hx-confirm="Post <?= e($b['number']) ?>? The inputs leave the shelf and the output lands."><?= csrf_field() ?><input type="hidden" name="batch" value="<?= $bid ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="production-post-btn"><i class="feather-check me-1"></i>Post</button></form>
            <form method="post" action="/production/cancel.php" hx-post="/production/cancel.php" hx-target="#flash" hx-confirm="Cancel <?= e($b['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="batch" value="<?= $bid ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="production-cancel-btn">Cancel</button></form>
        </div>
    <?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'production-trail', 'rows' => $trail]) ?>
</div>
