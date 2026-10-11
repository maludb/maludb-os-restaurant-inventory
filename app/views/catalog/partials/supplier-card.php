<?php /** One supplier card (`supplier-card-{id}`). Data: s (a find_suppliers row), here */
$id = (int) $s['supplier_id'];
$url = with_back('/suppliers/' . $id, $here);
?>
<div class="card h-100 supplier-card" id="supplier-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold text-truncate"><?= hx_link($url, e($s['name']), 'text-dark', 'id="supplier-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1"><?= supplier_kind_chip($s['kind']) ?><?= order_method_chip($s['order_method']) ?><?php if (!$s['active']): ?><?= chip('archived', 'dark') ?><?php endif; ?><?php if ($s['allow_split_case']): ?><?= chip('split cases', 'light') ?><?php endif; ?></div>
        <div class="fs-12 mt-2" id="supplier-card-<?= $id ?>-window"><i class="feather-clock me-1"></i><?= $s['has_schedule'] ? 'next cut-off ' . e(window_words($s['next_window'])) : '<span class="text-muted">no schedule here</span>' ?></div>
        <div class="fs-12 text-muted mt-1"><?= (int) $s['items'] ?> item<?= (int) $s['items'] === 1 ? '' : 's' ?> · <?= (int) $s['open_pos'] ?> open PO<?= (int) $s['open_pos'] === 1 ? '' : 's' ?><?= $s['kind'] === 'commissary' && $s['commissary_site'] ? ' · ' . e($s['commissary_site']) : '' ?></div>
    </div>
</div>
