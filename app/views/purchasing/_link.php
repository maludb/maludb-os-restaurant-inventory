<?php /** The supplier's link: its state (never the token) and Rotate. Data: po, link (po_link or null), tz */ ?>
<div class="card mb-3" id="po-link"><div class="card-header"><h5 class="card-title mb-0">The supplier's link</h5></div><div class="card-body fs-12">
<?php if ($link === null): ?>
    <p class="mb-2" id="po-link-none"><?= $po['status'] === 'draft' ? 'A draft has no link — it is made when the order is sent or marked placed.' : 'This order has no live link. Make a new one to let the supplier answer.' ?></p>
<?php else: ?>
    <dl class="row mb-2" id="po-link-state">
        <dt class="col-5 col-md-3 text-muted">Made</dt><dd class="col-7 col-md-9"><?= e(format_ts($link['created_at'], $tz, 'M j, Y g:i A')) ?></dd>
        <dt class="col-5 col-md-3 text-muted">Expires</dt><dd class="col-7 col-md-9" id="po-link-expires"><?= $link['expires_at'] ? e(format_ts($link['expires_at'], $tz, 'M j, Y')) : 'never' ?></dd>
        <dt class="col-5 col-md-3 text-muted">Last opened</dt><dd class="col-7 col-md-9" id="po-link-used"><?= $link['last_used_at'] ? e(format_ts($link['last_used_at'], $tz, 'M j, Y g:i A')) : 'never' ?></dd>
        <dt class="col-5 col-md-3 text-muted">Views</dt><dd class="col-7 col-md-9" id="po-link-views"><?= (int) $link['view_count'] ?></dd>
        <?php if ((int) $link['rotations'] > 0): ?><dt class="col-5 col-md-3 text-muted">Replaced</dt><dd class="col-7 col-md-9"><?= (int) $link['rotations'] ?> time<?= (int) $link['rotations'] === 1 ? '' : 's' ?></dd><?php endif; ?>
    </dl>
    <p class="text-muted mb-2">The link itself is kept only as a fingerprint: it is shown once, when it is made. Make a new one to copy it again — the old one stops working.</p>
<?php endif; ?>
<?php if (!in_array($po['status'], ['draft', 'cancelled'], true)): ?>
    <form method="post" action="/purchasing/link.php" hx-post="/purchasing/link.php" hx-target="#flash" hx-confirm="Make a new link for <?= e($po['number']) ?>? The old one stops working at once."><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= (int) $po['purchase_order_id'] ?>">
        <button type="submit" class="btn btn-light btn-touch" id="po-link-rotate-btn"><i class="feather-refresh-cw me-1"></i>Make a new link</button></form>
<?php endif; ?>
</div></div>
