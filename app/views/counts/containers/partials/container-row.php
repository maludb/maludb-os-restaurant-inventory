<?php /** One bottle or keg. Data: c, mayWrite */
$id = (int) $c['container_id'];
?>
<tr id="container-row-<?= $id ?>" class="<?= $c['active'] ? '' : 'text-muted' ?>">
    <td><?= hx_link('/ingredients/' . (int) $c['ingredient_id'], e($c['ingredient']), 'fw-semibold') ?></td>
    <td><?= e($c['name']) ?><?= $c['is_default'] ? ' ' . chip('default', 'primary') : '' ?><?= $c['active'] ? '' : ' ' . chip('retired', 'dark') ?></td>
    <td class="text-end"><?= e(fmt_num($c['full_weight_g'], 1)) ?></td><td class="text-end"><?= e(fmt_num($c['empty_weight_g'], 1)) ?></td><td class="text-end"><?= e(fmt_num($c['pourer_tare_g'], 1)) ?></td><td class="text-end"><?= e(fmt_num($c['volume_ml'], 1)) ?></td>
    <td class="text-end"><?php if ($mayWrite): ?><?= hx_link('/counts/containers/?ingredient=' . (int) $c['ingredient_id'] . '&edit=' . $id . '#container-form', 'Edit', 'btn btn-light btn-touch', 'id="container-row-' . $id . '-edit"') ?><?php endif; ?></td>
</tr>
