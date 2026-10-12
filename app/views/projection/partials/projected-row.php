<?php /** One ingredient of the projected-stock table (`projected-row-{ingredient}`). Data: r, here */
$id = (int) $r['ingredient_id'];
?>
<tr id="projected-row-<?= $id ?>" class="<?= $r['stockout_on'] !== null ? '' : '' ?>">
    <td><?= hx_link(with_back('/projection/stock/' . $id, $here), e($r['name']), 'fw-semibold') ?><?php if ($r['stockout_on'] !== null): ?><div class="fs-11 text-danger" id="projected-stockout-<?= $id ?>">out <?= e(date('D M j', (int) strtotime($r['stockout_on']))) ?> · in <?= (int) $r['stockout_in_days'] ?> day<?= $r['stockout_in_days'] === 1 ? '' : 's' ?></div><?php endif; ?></td>
    <td class="num" title="<?= e(fmt_num($r['on_hand_base'], 2)) ?> <?= e($r['base_unit']) ?>"><?= e(fmt_num_adaptive($r['on_hand_count'])) ?> <span class="fs-11 text-muted"><?= e($r['count_unit']) ?></span></td>
    <?php foreach ($r['days'] as $d): $neg = (float) $d['projected_base'] < 0; $recv = (float) $d['receipts_base'] > 0; ?>
        <td class="num fc-cell <?= $neg ? 'fc-neg' : '' ?> <?= $recv ? 'fc-recv' : '' ?>" id="projected-cell-<?= $id ?>-<?= e($d['on_date']) ?>" title="<?= e(fmt_num($d['projected_base'], 2)) ?> <?= e($r['base_unit']) ?> · demand <?= e(fmt_num($d['demand_base'], 2)) ?><?= $recv ? ' · +' . e(fmt_num($d['receipts_base'], 2)) . ' delivered' : '' ?>"><?= e(fmt_num_adaptive($d['projected_count'])) ?><?= $recv ? '<sup>+</sup>' : '' ?></td>
    <?php endforeach; ?>
    <td class="d-none d-md-table-cell text-muted fs-12"><?= $r['par_base'] === null ? '—' : e(fmt_num_adaptive($r['par_count'])) . ' ' . e($r['count_unit']) . ' ' . par_mode_chip((string) $r['par_mode']) ?></td>
</tr>
