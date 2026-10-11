<?php /** This site's storage areas in walk order (screen `area-list`). Data: rows, site, mayWrite, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'area-list', 'title' => 'Storage areas', 'crumbs' => [['Home', '/'], ['Storage areas', null]], 'action' => $mayWrite ? hx_link('/areas/new', '<i class="feather-plus me-1"></i>New area', 'btn btn-primary btn-touch', 'id="area-list-new-btn"') : '']) ?>
<div class="main-content" id="area-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0"><?= e($site['name']) ?>, in walk order</h5><?php if ($mayWrite): ?><span class="fs-12 text-muted">drag, or ↑ ↓</span><?php endif; ?></div>
        <form method="post" action="/areas/reorder.php" hx-post="/areas/reorder.php" hx-target="#flash" hx-trigger="areaOrderChanged" id="areas-form"><?= csrf_field() ?>
        <ul class="list-group list-group-flush" id="areas-list">
            <?php if ($rows === []): ?><li class="list-group-item text-muted fs-12" id="areas-empty">No storage areas yet<?= $mayWrite ? ' — add the walk-in, the dry store, the bar.' : '.' ?></li><?php endif; ?>
            <?php foreach ($rows as $a): $id = $a['storage_area_id']; ?>
            <li class="list-group-item d-flex align-items-center gap-2 area-row <?= $a['active'] ? '' : 'text-muted' ?>" id="area-row-<?= $id ?>" data-id="<?= $id ?>">
                <?php if ($mayWrite): ?><span class="area-handle btn btn-light btn-touch" title="drag"><i class="feather-menu"></i></span><input type="hidden" name="areas[]" value="<?= $id ?>"><?php endif; ?>
                <div class="flex-grow-1 min-w-0"><div class="fw-semibold"><?= e($a['name']) ?> <?= chip(AREA_KINDS[$a['kind']] ?? $a['kind'], 'light') ?><?= $a['allow_negative'] ? '' : ' ' . chip('no negatives', 'secondary') ?><?= !$a['active'] ? ' ' . chip('archived', 'dark') : '' ?></div><div class="fs-11 text-muted">walk order <?= (int) $a['walk_order'] ?> · <?= (int) $a['items_on_hand'] ?> item<?= (int) $a['items_on_hand'] === 1 ? '' : 's' ?> on hand</div></div>
                <?php if ($mayWrite): ?>
                <button type="button" class="btn btn-light btn-touch area-up" aria-label="Move up">↑</button><button type="button" class="btn btn-light btn-touch area-down" aria-label="Move down">↓</button>
                <?= hx_link(with_back('/areas/' . $id . '/edit', $here), 'Edit', 'btn btn-light btn-touch', 'id="area-row-' . $id . '-edit"') ?>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($mayWrite && $rows !== []): ?><div class="card-body border-top"><button type="submit" class="btn btn-light btn-touch" id="areas-save-order">Save the walk order</button><span class="fs-12 text-muted ms-2">(drag saves at once)</span></div><?php endif; ?>
        </form>
    </div>
</div>
<?php if ($mayWrite): ?>
<script src="/assets/vendors/sortablejs/Sortable.min.js"></script>
<script>
(function () {
    var list = document.getElementById('areas-list'), form = document.getElementById('areas-form'); if (!list || !form) return;
    function send() { if (window.htmx) { htmx.trigger(form, 'areaOrderChanged'); } }
    if (window.Sortable) { Sortable.create(list, { handle: '.area-handle', animation: 120, onEnd: send }); }
    list.addEventListener('click', function (ev) {
        var li = ev.target.closest('.area-row'); if (!li) return;
        if (ev.target.closest('.area-up') && li.previousElementSibling && li.previousElementSibling.classList.contains('area-row')) { list.insertBefore(li, li.previousElementSibling); send(); }
        else if (ev.target.closest('.area-down') && li.nextElementSibling) { list.insertBefore(li.nextElementSibling, li); send(); }
    });
})();
</script>
<?php endif; ?>
