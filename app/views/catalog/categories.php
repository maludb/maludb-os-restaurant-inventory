<?php /** The ingredient categories (screen `category-list`). Data: rows, edit (an id being edited), notice */ ?>
<?= view('shared/header.php', ['id' => 'category-list', 'title' => 'Categories', 'crumbs' => [['Home', '/'], ['Categories', null]]]) ?>
<div class="main-content" id="category-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Add a category</h5></div><div class="card-body">
        <form method="post" action="/categories/save.php" hx-post="/categories/save.php" hx-target="#flash" id="category-form" class="row g-2 align-items-end"><?= csrf_field() ?>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="category-form-field-name">Name</label><input type="text" name="name" id="category-form-field-name" class="form-control btn-touch" maxlength="80" required></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="category-form-field-key">Key</label><input type="text" name="key" id="category-form-field-key" class="form-control btn-touch" maxlength="40" placeholder="from the name"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="category-form-field-kind">Kind</label><select name="kind" id="category-form-field-kind" class="form-select btn-touch"><?php foreach (CATEGORY_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="category-form-field-parent">Parent</label><select name="parent" id="category-form-field-parent" class="form-select btn-touch"><option value="">—</option><?php foreach ($rows as $r): ?><option value="<?= (int) $r['category_id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="category-form-save-btn">Add</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="categories-table"><thead class="thead-light"><tr><th>Name</th><th>Key</th><th>Kind</th><th>Parent</th><th>Safety %</th><th>Alert %</th><th>Temp</th><th>Items</th><th>Order</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $c): $id = (int) $c['category_id']; $editing = $edit === $id; ?>
        <tr id="category-row-<?= $id ?>" class="<?= $c['active'] ? '' : 'text-muted' ?>">
            <?php if ($editing): ?>
            <td colspan="10"><form method="post" action="/categories/save.php" hx-post="/categories/save.php" hx-target="#flash" class="row g-2 align-items-end" id="category-row-<?= $id ?>-form"><?= csrf_field() ?><input type="hidden" name="category" value="<?= $id ?>">
                <div class="col-6 col-md-2"><input type="text" name="name" class="form-control btn-touch" value="<?= e($c['name']) ?>" maxlength="80" aria-label="Name"></div>
                <div class="col-6 col-md-2"><select name="kind" class="form-select btn-touch" aria-label="Kind"><?php foreach (CATEGORY_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $c['kind'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-2"><select name="parent" class="form-select btn-touch" aria-label="Parent"><option value="">—</option><?php foreach ($rows as $r): if ((int) $r['category_id'] === $id) { continue; } ?><option value="<?= (int) $r['category_id'] ?>" <?= (int) ($c['parent_id'] ?? 0) === (int) $r['category_id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-3 col-md-1"><input type="number" step="0.01" min="0" max="100" name="safety_stock_pct" class="form-control btn-touch" value="<?= e(fmt_input($c['safety_stock_pct'], 2)) ?>" aria-label="Safety stock %"></div>
                <div class="col-3 col-md-1"><input type="number" step="0.01" min="0" name="price_alert_pct" class="form-control btn-touch" value="<?= e($c['price_alert_pct'] === null ? '' : fmt_input($c['price_alert_pct'], 2)) ?>" aria-label="Price alert %" placeholder="setting"></div>
                <div class="col-3 col-md-1"><input type="hidden" name="temperature_check" value="no"><label class="fs-12"><input type="checkbox" class="form-check-input" name="temperature_check" value="yes" <?= $c['temperature_check'] ? 'checked' : '' ?>> temp</label></div>
                <div class="col-3 col-md-1"><input type="number" min="0" name="sort_order" class="form-control btn-touch" value="<?= (int) $c['sort_order'] ?>" aria-label="Order"></div>
                <div class="col-3 col-md-1"><input type="hidden" name="active" value="no"><label class="fs-12"><input type="checkbox" class="form-check-input" name="active" value="yes" <?= $c['active'] ? 'checked' : '' ?>> active</label></div>
                <div class="col-6 col-md-1"><button type="submit" class="btn btn-primary btn-touch w-100">Save</button></div></form></td>
            <?php else: ?>
            <td><?= e($c['name']) ?></td><td><code><?= e($c['key']) ?></code></td><td><?= e(CATEGORY_KINDS[$c['kind']] ?? $c['kind']) ?></td><td><?= e((string) (array_values(array_filter($rows, static fn (array $r): bool => (int) $r['category_id'] === (int) ($c['parent_id'] ?? 0)))[0]['name'] ?? '')) ?></td><td><?= e(fmt_num($c['safety_stock_pct'], 0)) ?> %</td><td><?= $c['price_alert_pct'] === null ? '<span class="text-muted">setting</span>' : e(fmt_num($c['price_alert_pct'], 0)) . ' %' ?></td><td><?= $c['temperature_check'] ? '✓' : '' ?></td><td><?= (int) $c['ingredients'] ?></td><td><?= (int) $c['sort_order'] ?></td>
            <td class="text-end"><?= hx_link('/categories/?category=' . $id, 'Edit', 'btn btn-light btn-sm btn-touch', 'id="category-row-' . $id . '-edit"') ?><?= !$c['active'] ? ' ' . chip('retired', 'dark') : '' ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
