<?php /** The menu categories (screen menu-category-list). Data: rows, counts, notice */ ?>
<?= view('shared/header.php', ['id' => 'menu-category-list', 'title' => 'Menu categories', 'crumbs' => [['Home', '/'], ['Menu items', '/menu/'], ['Menu categories', null]]]) ?>
<div class="main-content" id="menu-category-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/menu/categories/save.php" hx-post="/menu/categories/save.php" hx-target="#flash" class="card mb-3" id="menu-category-form"><?= csrf_field() ?><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <input type="text" name="name" class="form-control btn-touch" placeholder="New category" id="menu-category-form-field-name" required maxlength="60" style="width: 14rem"><input type="text" name="key" class="form-control btn-touch" placeholder="key (from the name)" id="menu-category-form-field-key" style="width: 12rem" pattern="[a-z][a-z0-9_]*">
        <select name="kind" class="form-select btn-touch w-auto" id="menu-category-form-field-kind"><option value="food">food</option><option value="beverage">beverage</option><option value="other">other</option></select>
        <button type="submit" class="btn btn-primary btn-touch" id="menu-category-form-add">Add</button></div></form>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0" id="menu-categories-table"><tbody>
        <?php foreach ($rows as $c): ?><?= view('menu/partials/menu-category-row.php', ['c' => $c, 'counts' => $counts[(int) $c['category_id']] ?? ['recipes' => 0, 'menu_items' => 0]]) ?><?php endforeach; ?>
    </tbody></table></div></div>
</div>
