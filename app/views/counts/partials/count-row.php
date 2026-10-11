<?php /** One row of the count list. Data: c, mayFill, seesCost, tz */
$id = (int) $c['count_id'];
?>
<tr id="count-row-<?= $id ?>">
    <td><?= hx_link(count_url($c, $mayFill), e($c['number']), 'fw-semibold') ?></td>
    <td><?= chip(COUNT_KINDS[$c['kind']] ?? $c['kind'], SHEET_KIND_CHIPS[$c['kind']] ?? 'secondary') ?><?php if ($c['sheet_name']): ?><div class="fs-11 text-muted"><?= e($c['sheet_name']) ?></div><?php endif; ?></td>
    <td class="text-nowrap"><?= e(format_ts($c['as_of'], $tz, 'M j, g:i A')) ?></td>
    <td><?= count_status_chip($c['status'], 'count-row-' . $id . '-status') ?><?php if ($c['blind'] && $c['status'] === 'open'): ?> <span class="fs-11 text-muted">blind</span><?php endif; ?></td>
    <td class="fs-12"><?= e($c['started_by_name'] ?? '') ?><div class="text-muted"><?= e(format_ts($c['started_at'], $tz, 'M j g:i A')) ?></div></td>
    <td class="text-end"><?= (int) $c['lines_done'] ?> / <?= (int) $c['lines'] ?></td>
    <td class="text-end"><?= (int) $c['lines_over'] ?></td>
    <td class="text-end"><?= $seesCost && $c['total_variance_value'] !== null ? '<span class="' . ((float) $c['total_variance_value'] < 0 ? 'text-danger' : '') . '">' . money((string) $c['total_variance_value']) . '</span>' : money(null) ?></td>
    <td class="fs-12"><?= $c['approved_at'] ? e(($c['approved_by_name'] ?? '') . ' · ' . format_ts($c['approved_at'], $tz, 'M j g:i A')) : '' ?></td>
</tr>
