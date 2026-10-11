<?php /** One waste log (screen `waste-view`). Data: waste, movements, seesCost, mayReview, photo, trail, notice */
$w = $waste; $wid = (int) $w['waste_log_id']; $pdo = db(); $tz = site_timezone();
?>
<?= view('shared/header.php', ['id' => 'waste-view', 'title' => $w['number'] . ' · ' . ($w['ingredient'] ?? $w['recipe']), 'crumbs' => [['Home', '/'], ['Waste', '/waste/'], [$w['number'], null]], 'back' => back_link() ?? ['/waste/', 'Waste']]) ?>
<div class="main-content" id="waste-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="waste-facts"><div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-2"><span><?= doc_chip($w['status'], 'waste-status') ?></span><span><?= chip(WASTE_STAGES[$w['stage']], 'danger') ?></span><span class="fw-semibold"><?= e($w['reason']) ?></span><span><?= $w['ingredient_id'] ? hx_link('/ingredients/' . $w['ingredient_id'], e($w['ingredient'])) : hx_link('/recipes/' . $w['recipe_id'], e($w['recipe'])) ?></span></div>
        <div class="fs-12 text-muted">
            <?= e(fmt_num($w['qty'], 4) . ' ' . $w['unit_code']) ?><?= $w['gross_weight_g'] !== null ? ' · gross ' . e(fmt_num($w['gross_weight_g'], 1)) . ' g, tare ' . e(fmt_num($w['tare_g'] ?? 0, 1)) . ' g' : '' ?>
            · <?= e($w['business_date']) ?> <?= e(format_ts($w['logged_at'], $tz, 'g:i A')) ?> · <?= e((string) $w['logged_by_name']) ?><?= $w['station'] ? ' · ' . e($w['station']) : '' ?><?= $w['day_part'] ? ' · ' . e($w['day_part']) : '' ?> · <?= e(WASTE_DISPOSITIONS[$w['disposition']] ?? $w['disposition']) ?>
            <?php if ($w['reviewed_by_name']): ?> · reviewed by <?= e($w['reviewed_by_name']) ?><?php endif; ?>
        </div>
        <?php if ($seesCost): ?><div class="mt-2">Cost <span class="fw-semibold" id="waste-cost"><?= money($w['cost'] === null ? null : (string) $w['cost']) ?></span></div><?php endif; ?>
        <?php if ($w['notes']): ?><div class="mt-2 fs-12"><?= e($w['notes']) ?></div><?php endif; ?>
        <?php if ($photo !== null): ?><div class="mt-2"><img src="/files/<?= (int) $photo['attachment_id'] ?>" alt="The waste" class="img-fluid rounded" style="max-height:240px" id="waste-photo"></div><?php endif; ?>
    </div></div>
    <?= view('stock/_doc-movements.php', ['id' => 'waste-movements', 'title' => 'What left the shelf', 'rows' => $movements, 'seesCost' => $seesCost]) ?>
    <?php if ($mayReview && $w['status'] !== 'voided'): ?>
        <div class="d-flex gap-2 mb-3" id="waste-actions">
            <?php if ($w['status'] === 'posted'): ?><form method="post" action="/waste/review.php" hx-post="/waste/review.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="waste" value="<?= $wid ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="waste-review-btn"><i class="feather-check me-1"></i>Review</button></form><?php endif; ?>
            <form method="post" action="/waste/void.php" hx-post="/waste/void.php" hx-target="#flash" hx-confirm="Void <?= e($w['number']) ?>? Every movement it posted is reversed."><?= csrf_field() ?><input type="hidden" name="waste" value="<?= $wid ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="waste-void-btn">Void</button></form>
        </div>
    <?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'waste-trail', 'rows' => $trail]) ?>
</div>
