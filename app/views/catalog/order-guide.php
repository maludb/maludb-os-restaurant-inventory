<?php /** The order guide at THIS site (screen `order-guide-edit`). Data: s, guide, items (the supplier's active items), site, seesCost, notice */
$id = (int) $s['supplier_id'];
$lines = $guide['lines'] ?? [];
$inGuide = array_column($lines, 'supplier_item_id');
?>
<?= view('shared/header.php', ['id' => 'order-guide-edit', 'title' => $s['name'] . ' · order guide at ' . $site['name'], 'crumbs' => [['Home', '/'], ['Suppliers', '/suppliers/'], [$s['name'], '/suppliers/' . $id], ['Order guide', null]], 'back' => back_link() ?? ['/suppliers/' . $id, $s['name']]]) ?>
<div class="main-content" id="order-guide-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/suppliers/guide.php" hx-post="/suppliers/guide.php" hx-target="#flash" id="guide-form">
        <?= csrf_field() ?><input type="hidden" name="supplier" value="<?= $id ?>"><input type="hidden" name="name" value="<?= e($guide['name'] ?? 'Order guide') ?>">
        <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">The guide, in shelf order</h5><span class="fs-12 text-muted">drag the handle, or ↑ ↓</span></div>
            <div class="card-body p-0"><ul class="list-group list-group-flush" id="guide-list">
                <?php if ($lines === []): ?><li class="list-group-item text-muted fs-12" id="guide-empty">Nothing on the guide yet — add the supplier's items below.</li><?php endif; ?>
                <?php foreach ($lines as $k => $l): $sid = $l['supplier_item_id']; ?>
                <li class="list-group-item d-flex align-items-center gap-2 guide-row" id="guide-row-<?= $sid ?>" data-id="<?= $sid ?>">
                    <span class="guide-handle btn btn-light btn-touch" title="drag"><i class="feather-menu"></i></span>
                    <input type="number" name="line[<?= $sid ?>][seq]" value="<?= $k + 1 ?>" class="form-control btn-touch guide-seq" style="width: 5rem" aria-label="order"><input type="hidden" name="line[<?= $sid ?>][supplier_item]" value="<?= $sid ?>">
                    <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($l['ingredient']) ?></div><div class="fs-11 text-muted"><?= e($l['supplier_sku'] ?? '') ?> · <?= e($l['pack'] ?? $l['purchase_unit_code']) ?><?= $seesCost && $l['last_price'] !== null ? ' · $' . e(fmt_num($l['last_price'], 2)) : '' ?></div></div>
                    <input type="hidden" name="line[<?= $sid ?>][preferred]" value="no"><label class="fs-12 d-flex align-items-center gap-1 btn-touch" for="guide-row-<?= $sid ?>-preferred"><input type="checkbox" class="form-check-input mt-0" name="line[<?= $sid ?>][preferred]" value="yes" id="guide-row-<?= $sid ?>-preferred" <?= $l['preferred'] ? 'checked' : '' ?>> preferred</label>
                    <button type="button" class="btn btn-light btn-touch guide-up" aria-label="Move up">↑</button><button type="button" class="btn btn-light btn-touch guide-down" aria-label="Move down">↓</button>
                    <button type="button" class="btn btn-light btn-touch guide-remove" id="guide-row-<?= $sid ?>-remove" aria-label="Remove"><i class="feather-x"></i></button>
                </li>
                <?php endforeach; ?>
            </ul></div>
            <div class="card-body border-top">
                <div class="row g-2 align-items-end"><div class="col-12 col-md-8"><?= form_picker('guide-add', 'add_item', 'Add an item', 'supplier_item', null, [], ['params' => ['supplier' => $id]]) ?></div><div class="col-12 col-md-4"><button type="button" class="btn btn-light btn-touch w-100" id="guide-add">Add to the guide</button></div></div>
                <div class="fs-12 text-muted mt-1">Without JavaScript, change the order numbers and save.</div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="guide-save">Save the guide</button>
    </form>
</div>
<template id="guide-row-template"><li class="list-group-item d-flex align-items-center gap-2 guide-row" data-id=""><span class="guide-handle btn btn-light btn-touch" title="drag"><i class="feather-menu"></i></span><input type="number" class="form-control btn-touch guide-seq" style="width: 5rem" aria-label="order"><input type="hidden" class="guide-item"><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate guide-label"></div></div><input type="hidden" class="guide-pref-hidden" value="no"><label class="fs-12 d-flex align-items-center gap-1 btn-touch"><input type="checkbox" class="form-check-input mt-0 guide-pref" value="yes" checked> preferred</label><button type="button" class="btn btn-light btn-touch guide-up" aria-label="Move up">↑</button><button type="button" class="btn btn-light btn-touch guide-down" aria-label="Move down">↓</button><button type="button" class="btn btn-light btn-touch guide-remove" aria-label="Remove"><i class="feather-x"></i></button></li></template>
<script src="/assets/vendors/sortablejs/Sortable.min.js"></script>
<script>
(function () {
    var list = document.getElementById('guide-list'); if (!list) return;
    function renumber() { var n = 0; list.querySelectorAll('.guide-row').forEach(function (li) { n++; var seq = li.querySelector('.guide-seq'); if (seq) { seq.value = n; } var id = li.getAttribute('data-id'); li.querySelectorAll('input').forEach(function (inp) { if (inp.classList.contains('guide-seq')) inp.name = 'line[' + id + '][seq]'; if (inp.classList.contains('guide-item')) { inp.name = 'line[' + id + '][supplier_item]'; inp.value = id; } if (inp.classList.contains('guide-pref-hidden')) inp.name = 'line[' + id + '][preferred]'; if (inp.classList.contains('guide-pref')) inp.name = 'line[' + id + '][preferred]'; }); }); var e = document.getElementById('guide-empty'); if (e && n > 0) e.remove(); }
    if (window.Sortable) { Sortable.create(list, { handle: '.guide-handle', animation: 120, onEnd: renumber }); }
    document.getElementById('guide-form').addEventListener('submit', renumber, true);   // the save reads the list's order as it stands
    list.addEventListener('click', function (ev) {
        var li = ev.target.closest('.guide-row'); if (!li) return;
        if (ev.target.closest('.guide-up') && li.previousElementSibling && li.previousElementSibling.classList.contains('guide-row')) { list.insertBefore(li, li.previousElementSibling); renumber(); }
        else if (ev.target.closest('.guide-down') && li.nextElementSibling) { list.insertBefore(li.nextElementSibling, li); renumber(); }
        else if (ev.target.closest('.guide-remove')) { li.remove(); renumber(); }
    });
    var add = document.getElementById('guide-add'); if (add) add.addEventListener('click', function () {
        var hidden = document.getElementById('guide-add-field-add-item'); var label = document.querySelector('#guide-add-field-add-item-picker .record-picker-label');
        if (!hidden || !hidden.value) return; var id = hidden.value;
        if (list.querySelector('.guide-row[data-id="' + id + '"]')) return;
        var tpl = document.getElementById('guide-row-template').content.cloneNode(true); var li = tpl.querySelector('li'); li.setAttribute('data-id', id); li.id = 'guide-row-' + id; li.querySelector('.guide-label').textContent = label ? label.textContent : ('item ' + id); li.querySelector('.guide-remove').id = 'guide-row-' + id + '-remove';
        list.appendChild(li); renumber();
        hidden.value = ''; if (label) { label.textContent = 'Choose an item'; label.classList.add('text-muted'); } var clr = document.getElementById('guide-add-field-add-item-clear'); if (clr) clr.classList.add('d-none');
    });
    renumber();
})();
</script>
