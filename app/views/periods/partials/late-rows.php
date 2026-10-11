<?php /** The rows that landed in this period from a closed one (`period-late-rows`). Data: late, seesCost, p */
$tz = site_timezone((int) $p['scope_id']);
?>
<div class="card" id="period-late-rows">
<?php if ($late === []): ?><div class="card-body text-muted fs-12">Nothing landed here from a closed period.</div><?php else: ?>
    <div class="table-responsive"><table class="table mb-0 fs-12" id="period-late-table"><thead><tr><th>When</th><th>Ingredient</th><th>Type</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th>Why</th></tr></thead><tbody>
    <?php foreach ($late as $r): $l = $r['late']; ?>
        <tr id="late-row-<?= (int) $r['transaction_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j g:i A')) ?></td><td><?= hx_link(with_back('/ingredients/' . (int) $r['ingredient_id'], here_url()), e($r['ingredient'])) ?></td><td><?= txn_chip($r['txn_type']) ?></td>
            <td class="text-end"><?= e(fmt_num($r['qty_base'], 2) . ' ' . $r['base_unit_code']) ?></td><td class="text-end"><?= money($seesCost ? $r['value'] : null) ?></td>
            <td><?= $l === null ? e((string) $r['note']) : chip('late: dated ' . (new DateTimeImmutable($l['dated']))->format('M j') . ' in closed period ' . $l['period_no'], 'info') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?></div>
