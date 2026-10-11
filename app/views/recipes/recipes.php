<?php /** The recipes as cards grouped by kind (screen recipe-list). Data: rows, total, page, filters, categories, mayWrite, seesCost, notice */ ?>
<?= view('shared/header.php', ['id' => 'recipe-list', 'title' => 'Recipes', 'crumbs' => [['Home', '/'], ['Recipes', null]], 'action' => $mayWrite ? hx_link('/recipes/new', '<i class="feather-plus me-1"></i>New recipe', 'btn btn-primary btn-touch', 'id="recipe-list-new"') : '']) ?>
<div class="main-content" id="recipe-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/recipes/" class="card mb-3" id="recipe-list-filters" hx-get="/recipes/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <input type="search" name="q" value="<?= e($filters['q']) ?>" class="form-control btn-touch flex-grow-1" placeholder="Name or code" id="recipe-list-filter-q" aria-label="Search recipes">
            <select name="kind" class="form-select btn-touch w-auto" id="recipe-list-filter-kind" aria-label="Kind"><option value="">Every kind</option><?php foreach (RECIPE_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?>s</option><?php endforeach; ?></select>
            <select name="category" class="form-select btn-touch w-auto" id="recipe-list-filter-category" aria-label="Category"><option value="">Every category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= (string) $filters['category'] === (string) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <select name="status" class="form-select btn-touch w-auto" id="recipe-list-filter-status" aria-label="Status"><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option><option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option></select>
            <button type="submit" class="btn btn-light-brand btn-touch" id="recipe-list-filter-go">Filter</button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="recipe-list-empty"><div class="card-body empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-book-open"></i></span><div><div class="fw-semibold">No recipes<?= $filters['q'] !== '' ? ' match “' . e($filters['q']) . '”' : ' yet' ?></div><div class="fs-12 text-muted"><?= $mayWrite ? 'Make the first one — a prep, a menu item or a modifier.' : 'The Chef makes them.' ?></div></div></div></div>
    <?php else: ?>
        <?php $groups = []; foreach ($rows as $r) { $groups[$r['kind']][] = $r; } ?>
        <?php foreach (['menu_item', 'prep', 'modifier'] as $kind): if (empty($groups[$kind])) { continue; } ?>
            <h6 class="text-muted text-uppercase fs-11 mt-2 mb-2" id="recipe-list-group-<?= $kind ?>"><?= e(RECIPE_KINDS[$kind]) ?>s</h6>
            <div class="row g-3 mb-3"><?php foreach ($groups[$kind] as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('recipes/partials/recipe-card.php', ['r' => $r, 'seesCost' => $seesCost]) ?></div><?php endforeach; ?></div>
        <?php endforeach; ?>
        <?php if ($total > RECIPE_PAGE): ?><div class="d-flex justify-content-between align-items-center" id="recipe-list-paging"><span class="fs-12 text-muted"><?= $total ?> recipes</span><div class="btn-group"><?php if ($page > 1): ?><?= hx_link('/recipes/?' . http_build_query(['page' => $page - 1] + array_filter($filters)), 'Previous', 'btn btn-light btn-touch') ?><?php endif; ?><?php if ($page * RECIPE_PAGE < $total): ?><?= hx_link('/recipes/?' . http_build_query(['page' => $page + 1] + array_filter($filters)), 'Next', 'btn btn-light btn-touch') ?><?php endif; ?></div></div><?php endif; ?>
    <?php endif; ?>
</div>
