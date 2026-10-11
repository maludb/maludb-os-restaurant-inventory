<?php /** One category's inline row form (Pattern C). Data: c, counts, saved? */ $id = (int) $c['category_id']; ?>
<tr id="menu-category-row-<?= $id ?>"><td colspan="6">
    <form method="post" action="/menu/categories/save.php" hx-post="/menu/categories/save.php" hx-target="#menu-category-row-<?= $id ?>" hx-swap="outerHTML" hx-trigger="change delay:150ms, submit" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="menu_category" value="<?= $id ?>">
        <input type="text" name="name" value="<?= e($c['name']) ?>" class="form-control btn-touch" style="width: 12rem" id="menu-category-row-<?= $id ?>-field-name" aria-label="Name" maxlength="60">
        <code class="text-muted" style="min-width: 7rem"><?= e($c['key']) ?></code>
        <select name="kind" class="form-select btn-touch" style="width: 9rem" id="menu-category-row-<?= $id ?>-field-kind" aria-label="Kind"><?php foreach (['food', 'beverage', 'other'] as $k): ?><option value="<?= $k ?>" <?= $c['kind'] === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select>
        <input type="number" name="sort_order" value="<?= (int) $c['sort_order'] ?>" class="form-control btn-touch" style="width: 6rem" id="menu-category-row-<?= $id ?>-field-sort-order" aria-label="Order" min="0">
        <span class="fs-12 text-muted"><?= (int) $counts['recipes'] ?> recipes · <?= (int) $counts['menu_items'] ?> items</span>
        <label class="border rounded px-2 d-flex align-items-center gap-1 btn-touch" for="menu-category-row-<?= $id ?>-field-active"><input type="hidden" name="active" value="no"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="menu-category-row-<?= $id ?>-field-active" <?= $c['active'] ? 'checked' : '' ?>> active</label>
        <noscript><button type="submit" class="btn btn-light-brand btn-touch">Save</button></noscript>
        <?php if (!empty($saved)): ?><span class="fs-12 text-success" id="menu-category-row-<?= $id ?>-saved">Saved.</span><?php endif; ?>
    </form></td></tr>
