<?php /** One watch (`watch-row-{id}`). Data: w, admin, me, tz, here */
$id = (int) $w['watch_id'];
$mine = $w['member_id'] === $me;
?>
<tr id="watch-row-<?= $id ?>" class="<?= $w['active'] ? '' : 'text-muted' ?>" data-active="<?= $w['active'] ? '1' : '0' ?>">
    <td><?= hx_link(with_back('/ingredients/' . (int) $w['ingredient_id'], $here), e($w['ingredient']), 'fw-semibold') ?><?php if ($admin && !$mine): ?><div class="fs-11 text-muted"><?= e($w['member']) ?></div><?php endif; ?></td>
    <td><?= watch_kind_chip($w['kind']) ?><?= $w['active'] ? '' : ' ' . chip('stopped', 'dark') ?><?= $w['text_me'] ? ' <i class="feather-message-square" title="texts me"></i>' : '' ?></td>
    <td class="d-none d-md-table-cell"><?= $w['kind'] === 'cost_above' ? '$' . e(fmt_num($w['threshold_recipe_unit'], 4)) . ' / ' . e($w['recipe_unit_code']) : ($w['kind'] === 'cost_moved' ? e(fmt_num($w['threshold'], 1)) . ' %' : '<span class="text-muted">—</span>') ?></td>
    <td class="d-none d-md-table-cell"><?= $w['agent'] === null ? '<span class="text-muted">—</span>' : e($w['agent']) ?></td>
    <td><?= $w['fired_at'] === null ? '<span class="text-muted">never</span>' : e(format_ts($w['fired_at'], $tz, 'M j, g:i A')) ?></td>
    <td class="num"><?= (int) $w['fire_count'] ?></td>
    <td class="text-end text-nowrap"><?php if ($w['active'] && ($mine || $admin)): ?>
        <?= hx_link('/projection/watches?watch=' . $id . '#watch-form', 'Change', 'btn btn-light btn-sm btn-touch', 'id="watch-change-' . $id . '"') ?>
        <form method="post" action="/projection/watches/clear.php" hx-post="/projection/watches/clear.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="watch" value="<?= $id ?>"><button type="submit" class="btn btn-outline-danger btn-sm btn-touch" id="watch-clear-<?= $id ?>">Clear</button></form>
    <?php endif; ?></td>
</tr>
