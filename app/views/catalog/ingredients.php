<?php /** The ingredients as cards by category (screen `ingredient-list`). Data: rows, total, page, filters, categories, mayWrite, seesCost, units, notice */
$action = $mayWrite ? hx_link('/ingredients/new', '<i class="feather-plus me-1"></i>New ingredient', 'btn btn-primary btn-touch', 'id="ingredient-list-new-btn"') : '';
$byCat = [];
foreach ($rows as $r) { $byCat[$r['category']][] = $r; }
$here = here_url();
$qs = static fn (array $over): string => '/ingredients/?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'ingredient-list', 'title' => 'Ingredients', 'crumbs' => [['Home', '/'], ['Ingredients', null]], 'action' => $action]) ?>
<div class="main-content" id="ingredient-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/ingredients/" hx-get="/ingredients/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:select" class="card mb-3" id="ingredient-list-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="ingredient-filter-q">Search</label><input type="search" name="q" id="ingredient-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>" placeholder="name or code"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="ingredient-filter-category">Category</label><select name="category" id="ingredient-filter-category" class="form-select btn-touch"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= e($c['key']) ?>" <?= $filters['category'] === $c['key'] || $filters['category'] === (string) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="ingredient-filter-kind">Kind</label><select name="kind" id="ingredient-filter-kind" class="form-select btn-touch"><option value="">All</option><?php foreach (CATEGORY_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="ingredient-filter-status">Status</label><select name="status" id="ingredient-filter-status" class="form-select btn-touch"><?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="ingredient-filter-prep">Prep</label><select name="is_prep" id="ingredient-filter-prep" class="form-select btn-touch"><option value="">Any</option><option value="yes" <?= $filters['is_prep'] === 'yes' ? 'selected' : '' ?>>Prep only</option><option value="no" <?= $filters['is_prep'] === 'no' ? 'selected' : '' ?>>Bought only</option></select></div>
            <div class="col-12 d-md-none"><button type="submit" class="btn btn-light btn-touch w-100" id="ingredient-filter-apply">Filter</button></div>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="ingredient-list-empty"><div class="card-body text-center text-muted py-5"><?= $total === 0 && $filters['q'] === '' ? 'No ingredients yet.' . ($mayWrite ? ' Make the first one.' : '') : 'Nothing matches.' ?></div></div>
    <?php endif; ?>
    <?php foreach ($byCat as $cat => $list): ?>
        <h6 class="text-muted fs-12 text-uppercase mt-3 mb-2" id="ingredient-group-<?= e(key_from_name((string) $cat)) ?>"><?= e((string) $cat) ?> <span class="fw-normal">(<?= count($list) ?>)</span></h6>
        <div class="row g-3" id="ingredient-list">
            <?php foreach ($list as $i): ?><div class="col-12 col-md-6 col-xl-4 col-xxl-3"><?= view('catalog/partials/ingredient-card.php', ['i' => $i, 'seesCost' => $seesCost, 'here' => $here, 'units' => $units]) ?></div><?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($total > INGREDIENT_PAGE): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 fs-12" id="ingredient-list-pager">
            <span class="text-muted"><?= ($page - 1) * INGREDIENT_PAGE + 1 ?>–<?= min($total, $page * INGREDIENT_PAGE) ?> of <?= $total ?></span>
            <span><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch me-1') : '' ?><?= $page * INGREDIENT_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></span>
        </div>
    <?php endif; ?>
</div>
