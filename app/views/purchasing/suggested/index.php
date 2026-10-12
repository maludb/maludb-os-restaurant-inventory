<?php /** The suggested orders (screen `suggested-order-list`). Data: cards (suggested_orders()), hours, supplierId, suppliers, sees, notice, nowIso */
$tz = site_timezone();
$draftAll = '<form method="post" action="/purchasing/suggested/run.php" hx-post="/purchasing/suggested/run.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="draft" value="yes"><input type="hidden" name="due_hours" value="' . (int) $hours . '"><button type="submit" class="btn btn-primary btn-touch" id="sugg-draft-all"><i class="feather-file-plus me-1"></i>Draft all due</button></form>';
?>
<?= view('shared/header.php', ['id' => 'suggested-order-list', 'title' => 'Suggested orders', 'crumbs' => [['Home', '/'], ['Purchasing', null], ['Suggested orders', null]], 'back' => back_link(), 'action' => $draftAll]) ?>
<div class="main-content" id="suggested-order-list-content">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/purchasing/suggested" hx-get="/purchasing/suggested" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select" class="card mb-3" id="suggested-form"><div class="card-body row g-2 align-items-end">
        <div class="col-7 col-md-4"><label class="form-label fs-12 text-muted" for="suggested-form-field-supplier">Supplier</label><select name="supplier" id="suggested-form-field-supplier" class="form-select btn-touch"><option value="">Every supplier due</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= $supplierId === $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-5 col-md-3"><label class="form-label fs-12 text-muted" for="suggested-form-field-due_hours">Cut-off within</label><select name="due_hours" id="suggested-form-field-due_hours" class="form-select btn-touch"><?php foreach (SUGGESTED_DUE_HOURS as $h): ?><option value="<?= $h ?>" <?= $hours === $h ? 'selected' : '' ?>><?= $h < 48 ? $h . ' hours' : ($h / 24) . ' days' ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="suggested-apply">Show</button></div>
    </div></form>
    <?php if ($cards === []): ?>
        <div class="card" id="suggested-empty"><div class="card-body text-center text-muted py-5">No supplier has a cut-off within <?= $hours < 48 ? $hours . ' hours' : ($hours / 24) . ' days' ?>. Suppliers appear here when they have an order schedule for this site; choose one above to see its suggestion anyway.</div></div>
    <?php endif; ?>
    <?php foreach ($cards as $c): ?><?= view('purchasing/suggested/partials/supplier-card.php', ['c' => $c, 'sees' => $sees, 'tz' => $tz, 'hours' => $hours]) ?><?php endforeach; ?>
</div>
