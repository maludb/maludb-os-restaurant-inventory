<?php /** The counts that frame the period (`period-counts`). Data: counts, p, seesCost */
$tz = site_timezone((int) $p['scope_id']);
$words = ['opening' => 'opening count', 'closing' => 'closing count', 'inside' => 'inside the period'];
?>
<div class="card" id="period-counts">
<?php if ($counts === []): ?><div class="card-body text-muted fs-12">No approved count falls in this period.</div><?php else: ?>
    <div class="table-responsive"><table class="table mb-0" id="period-counts-table"><thead><tr><th>Count</th><th>As of</th><th>Role</th><th class="text-end">Variance</th></tr></thead><tbody>
    <?php foreach ($counts as $c): ?>
        <tr id="period-count-row-<?= (int) $c['count_id'] ?>"><td><?= hx_link(with_back('/counts/' . (int) $c['count_id'] . '/history', here_url()), e($c['number']), 'fw-semibold') ?> <span class="text-muted fs-12"><?= e(COUNT_KINDS[$c['kind']] ?? $c['kind']) ?></span></td><td><?= e(format_ts($c['as_of'], $tz, 'M j g:i A')) ?></td><td><?= chip($words[$c['role']], $c['role'] === 'inside' ? 'secondary' : 'primary') ?></td><td class="text-end"><?= money($seesCost ? $c['total_variance_value'] : null) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?></div>
