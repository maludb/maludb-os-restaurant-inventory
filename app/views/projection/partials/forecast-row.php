<?php /** One menu item of the forecast table (`forecast-row-{menu_item}`). Data: r, horizon, thinWeeks */
$link = with_back('/projection/items/' . (int) $r['menu_item_id'], here_url());
?>
<tr id="forecast-row-<?= (int) $r['menu_item_id'] ?>">
    <td class="fc-name"><?= hx_link($link, e($r['name']), 'fw-semibold') ?><?php if ($r['thin']): ?> <?= chip('thin', 'warning') ?><?php endif; ?><div class="fs-11 text-muted d-md-none"><?= e((string) $r['category']) ?></div></td>
    <?php foreach (array_slice($r['days'], 0, 7) as $d): ?>
        <td class="num fc-cell fc-src-<?= e($d['source']) ?>" id="forecast-cell-<?= (int) $r['menu_item_id'] ?>-<?= e($d['on_date']) ?>" data-source="<?= e($d['source']) ?>" title="<?= e(FORECAST_SOURCE_WORDS[$d['source']] ?? $d['source']) ?> — <?= e(format_date($d['on_date'])) ?>"><?= e(fc_qty($d['qty'])) ?></td>
    <?php endforeach; ?>
    <td class="num fw-semibold" id="forecast-total-<?= (int) $r['menu_item_id'] ?>"><?= e(fc_qty($r['total'])) ?></td>
    <td class="num d-none d-md-table-cell text-muted"><?= $r['lo'] === null ? '<span title="the baseline has no band">—</span>' : e(fc_qty($r['lo'])) . '–' . e(fc_qty($r['hi'])) ?></td>
    <td class="num d-none d-md-table-cell"><?= $r['wape'] === null ? '<span class="text-muted" title="not scored yet">—</span>' : e(number_format((float) $r['wape'] * 100, 0)) . ' %' ?></td>
    <td class="num d-none d-md-table-cell"><?= $r['bias'] === null ? '<span class="text-muted">—</span>' : ((float) $r['bias'] > 0 ? '+' : '') . e(number_format((float) $r['bias'] * 100, 0)) . ' %' ?></td>
    <td class="d-none d-md-table-cell"><?php if ($r['thin']): ?><span class="fs-11 text-muted" title="fewer than <?= (int) $thinWeeks ?> weeks of history">thin history</span><?php endif; ?></td>
</tr>
