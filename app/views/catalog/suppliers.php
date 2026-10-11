<?php /** The suppliers as cards (screen `supplier-list`). Data: rows, total, page, filters, mayWrite, notice */
$action = $mayWrite ? hx_link('/suppliers/new', '<i class="feather-plus me-1"></i>New supplier', 'btn btn-primary btn-touch', 'id="supplier-list-new-btn"') : '';
$here = here_url();
?>
<?= view('shared/header.php', ['id' => 'supplier-list', 'title' => 'Suppliers', 'crumbs' => [['Home', '/'], ['Suppliers', null]], 'action' => $action]) ?>
<div class="main-content" id="supplier-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/suppliers/" hx-get="/suppliers/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="supplier-list-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="supplier-filter-q">Search</label><input type="search" name="q" id="supplier-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="supplier-filter-kind">Kind</label><select name="kind" id="supplier-filter-kind" class="form-select btn-touch"><option value="">All</option><?php foreach (SUPPLIER_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="supplier-filter-status">Status</label><select name="status" id="supplier-filter-status" class="form-select btn-touch"><?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 d-md-none"><button type="submit" class="btn btn-light btn-touch w-100">Filter</button></div>
    </div></form>
    <?php if ($rows === []): ?><div class="card" id="supplier-list-empty"><div class="card-body text-center text-muted py-5">No suppliers<?= $filters['q'] !== '' ? ' match' : ' yet' ?>.</div></div><?php endif; ?>
    <div class="row g-3" id="supplier-list"><?php foreach ($rows as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('catalog/partials/supplier-card.php', ['s' => $s, 'here' => $here]) ?></div><?php endforeach; ?></div>
</div>
