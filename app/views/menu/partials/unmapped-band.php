<?php /** The unmapped queue: PLUs sold in the last 28 days with no recipe. Data: queue, mayWrite */ ?>
<?php if ($queue !== []): ?>
<div class="alert alert-warning mb-3" id="unmapped-band" role="status"><strong><?= count($queue) ?> PLU<?= count($queue) === 1 ? '' : 's' ?> sold with no recipe</strong> — their sales deplete nothing until mapped.
    <ul class="mb-0 mt-1 fs-12"><?php foreach (array_slice($queue, 0, 8) as $u): ?><li id="unmapped-<?= (int) $u['menu_item_id'] ?>"><code><?= e($u['plu']) ?></code> <?= e($u['name']) ?> — <?= e(ri_fmt($u['qty_sold'], 0)) ?> sold<?php if ($mayWrite): ?> · <?= hx_link(with_back('/menu/' . (int) $u['menu_item_id'] . '/map', here_url()), 'map it') ?><?php endif; ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
