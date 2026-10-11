<?php /** Search (screen `search`): the box, the results of ri_find() grouped by kind, the query echoed escaped, an empty state in words. Data: q, groups */ ?>
<?= view('shared/header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]]]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" hx-get="/search" hx-target="#page-content" hx-push-url="true" id="search-form" class="mb-3" role="search">
        <div class="input-group">
            <span class="input-group-text"><i class="feather-search"></i></span>
            <input type="search" name="q" id="search-form-field-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="An ingredient, a recipe, a PLU, a supplier's SKU…" autofocus autocomplete="off" />
            <button type="submit" class="btn btn-primary btn-touch" id="search-form-submit">Search</button>
        </div>
    </form>
    <div id="search-results">
        <?php if ($q === ''): ?>
            <div class="text-muted fs-12" id="search-hint">Type a name, a code, a PLU or a SKU. Everything with a name is found here.</div>
        <?php elseif ($groups === []): ?>
            <div class="card" id="search-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
                <div><div class="fw-semibold">Nothing matches “<?= e($q) ?>”</div><div class="fs-12 text-muted">No ingredient, recipe, menu item, supplier item or supplier has that in its name or code.</div></div></div></div></div>
        <?php else: ?>
            <?php foreach (SEARCH_KINDS as $kind => $label): if (empty($groups[$kind])) { continue; } ?>
                <div class="card mb-3" id="search-group-<?= e($kind) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($label) ?> <span class="text-muted fs-12 fw-normal">(<?= count($groups[$kind]) ?>)</span></h5></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($groups[$kind] as $r): ?>
                            <?php $inner = '<div class="d-flex justify-content-between gap-2"><span class="fw-semibold">' . e($r['name']) . '</span><span class="fs-12 text-muted">' . e($r['code'] ?? '') . '</span></div>' . ($r['detail'] !== null ? '<div class="fs-12 text-muted">' . e($r['detail']) . '</div>' : ''); ?>
                            <?php if ($r['url'] !== null): ?><?= hx_link(with_back($r['url'], '/search?q=' . rawurlencode($q)), $inner, 'list-group-item list-group-item-action', 'id="search-row-' . e($kind) . '-' . (int) $r['id'] . '"') ?>
                            <?php else: ?><div class="list-group-item" id="search-row-<?= e($kind) ?>-<?= (int) $r['id'] ?>"><?= $inner ?></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
