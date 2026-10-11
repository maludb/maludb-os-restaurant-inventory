<?php /** One menu item row. Data: m, mayWrite, tz */ $id = (int) $m['menu_item_id']; $unmapped = $m['recipe_id'] === null && !$m['no_depletion']; ?>
<tr id="menu-item-row-<?= $id ?>" class="<?= $unmapped && $m['active'] ? 'table-warning' : ($m['active'] ? '' : 'text-muted') ?>">
    <td><code><?= e($m['plu']) ?></code></td>
    <td><?= hx_link(with_back('/menu/' . $id, here_url()), e($m['name']), 'text-dark fw-semibold') ?><?= $m['no_depletion'] ? ' <span class="badge bg-soft-secondary text-secondary">sells nothing from stock</span>' : '' ?><?= $m['active'] ? '' : ' <span class="badge bg-soft-dark text-dark">archived</span>' ?></td>
    <td><span class="badge bg-soft-light text-dark border"><?= e($m['source_name'] ?? 'typed') ?></span></td>
    <td><?= e((string) ($m['category'] ?? '')) ?></td>
    <td class="text-end"><?= $m['price'] !== null ? e(ri_money($m['price'])) : '—' ?></td>
    <td><?php if ($m['recipe_id'] !== null): ?><?= hx_link('/recipes/' . (int) $m['recipe_id'], e($m['recipe_name'])) ?><?= (float) $m['portions'] !== 1.0 ? ' <span class="text-muted">× ' . e(ri_fmt($m['portions'], 2)) . '</span>' : '' ?><?php elseif ($m['no_depletion']): ?><span class="text-muted">—</span><?php elseif ($mayWrite): ?><?= hx_link(with_back('/menu/' . $id . '/map', here_url()), 'Map', 'btn btn-sm btn-warning', 'id="menu-item-row-' . $id . '-map"') ?><?php else: ?><span class="text-warning">unmapped</span><?php endif; ?></td>
    <td class="fs-12 text-muted"><?= $m['last_sold_at'] ? e(format_ts($m['last_sold_at'], $tz, 'M j')) : 'never' ?></td>
</tr>
