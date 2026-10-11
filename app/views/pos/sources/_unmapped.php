<?php /** The unmapped PLUs a source brought (ri_unmapped_plus()), by what they sold; Map → slice 2's menu map. Data: unmapped, mayMap */ ?>
<?php if ($unmapped === []): ?><div class="p-3 text-muted fs-12" id="source-unmapped-none">Every PLU this source sold is mapped.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="source-unmapped-table"><thead><tr><th>PLU</th><th>Name</th><th class="text-end">Sold</th><th class="text-end">Net</th><th>Last sold</th><th></th></tr></thead><tbody>
<?php foreach ($unmapped as $u): ?><tr id="unmapped-row-<?= (int) $u['menu_item_id'] ?>"><td><?= e($u['plu']) ?></td><td><?= e($u['name']) ?></td><td class="text-end"><?= e(fmt_num($u['qty_sold'], 2)) ?></td><td class="text-end"><?= e(ri_money($u['net'])) ?></td><td><?= $u['last_sold_at'] === null ? '—' : e(format_date(substr((string) $u['last_sold_at'], 0, 10))) ?></td>
    <td class="text-end"><?= $mayMap ? hx_link(with_back('/menu/' . (int) $u['menu_item_id'] . '/map', here_url()), 'Map', 'btn btn-sm btn-primary btn-touch', 'id="unmapped-map-' . (int) $u['menu_item_id'] . '"') : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
