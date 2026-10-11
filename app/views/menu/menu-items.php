<?php /** The menu items (screen menu-item-list). Data: rows, queue, filters, sources, categories, mayWrite, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'menu-item-list', 'title' => 'Menu items', 'crumbs' => [['Home', '/'], ['Menu items', null]], 'action' => $mayWrite ? '<button type="button" class="btn btn-primary btn-touch" id="menu-item-list-new" data-bs-toggle="collapse" data-bs-target="#menu-item-new"><i class="feather-plus me-1"></i>New menu item</button>' : '']) ?>
<div class="main-content" id="menu-item-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($mayWrite): ?>
    <div class="collapse<?= !empty($_GET['new']) ? ' show' : '' ?>" id="menu-item-new"><form method="post" action="/menu/save.php" hx-post="/menu/save.php" hx-target="#flash" class="card mb-3" id="menu-item-form"><?= csrf_field() ?><div class="card-body row g-2">
        <div class="col-6 col-md-2"><input type="text" name="plu" class="form-control btn-touch" placeholder="PLU" id="menu-item-form-field-plu" required maxlength="40"></div>
        <div class="col-6 col-md-3"><input type="text" name="name" class="form-control btn-touch" placeholder="Name" id="menu-item-form-field-name" required maxlength="120"></div>
        <div class="col-6 col-md-2"><select name="source" class="form-select btn-touch" id="menu-item-form-field-source"><option value="">typed (no POS)</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="menu_category" class="form-select btn-touch" id="menu-item-form-field-menu-category"><option value="">category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" name="sale_price" class="form-control btn-touch" placeholder="price" id="menu-item-form-field-sale-price"></div></div>
        <div class="col-6 col-md-1"><button type="submit" class="btn btn-primary btn-touch w-100" id="menu-item-form-save">Add</button></div>
    </div></form></div>
    <?php endif; ?>
    <?= view('menu/partials/unmapped-band.php', ['queue' => $queue, 'mayWrite' => $mayWrite]) ?>
    <form method="get" action="/menu/" class="card mb-3" id="menu-item-list-filters" hx-get="/menu/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select, change from:input[type=checkbox]"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <input type="search" name="q" value="<?= e($filters['q']) ?>" class="form-control btn-touch flex-grow-1" placeholder="Name or PLU" id="menu-item-list-filter-q" aria-label="Search">
        <select name="source" class="form-select btn-touch w-auto" id="menu-item-list-filter-source"><option value="">Every source</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= $filters['source'] === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <select name="category" class="form-select btn-touch w-auto" id="menu-item-list-filter-category"><option value="">Every category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= $filters['category'] === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <label class="border rounded px-3 btn-touch d-flex align-items-center gap-2" for="menu-item-list-filter-unmapped"><input type="checkbox" class="form-check-input mt-0" name="unmapped" value="1" id="menu-item-list-filter-unmapped" <?= $filters['unmapped'] ? 'checked' : '' ?>>Unmapped only</label>
        <label class="border rounded px-3 btn-touch d-flex align-items-center gap-2" for="menu-item-list-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="menu-item-list-filter-archived" <?= $filters['archived'] ? 'checked' : '' ?>>Archived too</label>
        <button type="submit" class="btn btn-light-brand btn-touch">Filter</button></div></form>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0" id="menu-items-table"><thead><tr><th>PLU</th><th>Item</th><th>Source</th><th>Category</th><th class="text-end">Price</th><th>Recipe</th><th>Last sold</th></tr></thead><tbody>
        <?php foreach ($rows as $m): ?><?= view('menu/partials/menu-item-row.php', ['m' => $m, 'mayWrite' => $mayWrite, 'tz' => $tz]) ?><?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-muted p-4 text-center" id="menu-items-empty">No menu items yet — the POS import (slice 5) brings them, or add one.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
