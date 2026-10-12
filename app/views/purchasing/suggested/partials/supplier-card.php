<?php /** One supplier's suggested order (`supplier-card-{id}`). Data: c, sees, tz, hours */
$id = (int) $c['supplier_id'];
$drafted = $c['drafted_today'];
$inHours = max(0, (int) round((strtotime((string) $c['cutoff_at']) - time()) / 3600));
?>
<div class="card mb-3 supplier-card" id="supplier-card-<?= $id ?>" data-supplier-id="<?= $id ?>">
    <div class="card-header d-flex flex-wrap align-items-start gap-2">
        <div class="me-auto"><h6 class="mb-0"><?= hx_link(with_back('/suppliers/' . $id, here_url()), e($c['name']), 'text-reset') ?></h6>
            <div class="fs-12 text-muted" id="supplier-window-<?= $id ?>"><?php if ($c['scheduled']): ?>Order by <strong><?= e(format_ts($c['cutoff_at'], $tz, 'D g:i A')) ?></strong> (in <?= $inHours ?> h) for delivery <strong><?= e(date('D M j', (int) strtotime((string) $c['delivery_on']))) ?></strong> · covers <?= (int) $c['protection_days'] ?> day<?= (int) $c['protection_days'] === 1 ? '' : 's' ?> until the next one<?php else: ?>No order schedule here — order any day; delivery <strong><?= e(date('D M j', (int) strtotime((string) $c['delivery_on']))) ?></strong> on the supplier's lead time<?php endif; ?></div>
            <?php if ($c['min_order_value'] !== null): ?><div class="fs-12" id="supplier-minimum-<?= $id ?>">Minimum order <?= $sees ? money((string) $c['min_order_value']) : '—' ?><?php if ($sees && $c['total'] !== null): ?> — this draft is <?= money((string) $c['total']) ?> <?= $c['reaches_min'] ? chip('reaches it', 'success') : chip('under the minimum', 'warning', 'supplier-under-min-' . $id) ?><?php endif; ?></div><?php endif; ?></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($drafted !== null): ?>
                <span class="fs-12" id="supplier-drafted-<?= $id ?>">drafted <?= e(format_ts($drafted['created_at'], $tz, 'g:i A')) ?> — <?= hx_link(with_back('/purchasing/' . (int) $drafted['purchase_order_id'], here_url()), e($drafted['number']), 'fw-semibold') ?></span>
            <?php elseif ($c['to_order'] > 0): ?>
                <form method="post" action="/purchasing/suggested/run.php" hx-post="/purchasing/suggested/run.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="supplier" value="<?= $id ?>"><input type="hidden" name="draft" value="yes"><button type="submit" class="btn btn-primary btn-touch" id="sugg-draft-btn-<?= $id ?>"><i class="feather-file-plus me-1"></i>Draft the PO</button></form>
            <?php endif; ?>
            <form method="post" action="/purchasing/suggested/run.php" hx-post="/purchasing/suggested/run.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="supplier" value="<?= $id ?>"><input type="hidden" name="draft" value="no"><button type="submit" class="btn btn-light btn-touch" id="sugg-recompute-<?= $id ?>"><i class="feather-refresh-cw me-1"></i>Recompute</button></form>
        </div>
    </div>
    <?php if ($c['lines'] === []): ?>
        <div class="card-body text-muted" id="supplier-nolines-<?= $id ?>">This supplier has no items on this site's order guide, and no ingredient here prefers one of its items.</div>
    <?php else: ?>
    <div class="sugg-wrap"><table class="table table-sm align-middle mb-0 sugg-table" id="sugg-table-<?= $id ?>">
        <thead><tr><th class="sg-ing">Ingredient</th><th>Rule</th><th class="num" title="fixed: the par. dynamic: demand over the interval plus safety stock">Need</th><th class="num" title="on hand + on order due by the delivery">Have</th><th class="num" title="a perishable is capped at what it can sell before it spoils">Cap</th><th class="num" title="max(min(need, cap) − have, 0)">Short</th><th class="num sg-units">Order</th><?php if ($sees): ?><th class="num">Unit cost</th><th class="num">Value</th><?php endif; ?><th class="sugg-note">How</th></tr></thead>
        <tbody>
        <?php foreach ($c['lines'] as $l): $lid = (int) $l['supplier_item_id']; $order = (float) $l['qty_units'] > 0; ?>
            <tr id="sugg-line-<?= $lid ?>" class="<?= $order ? '' : 'text-muted' ?>" data-ingredient-id="<?= (int) $l['ingredient_id'] ?>">
                <td class="sg-ing"><?= hx_link(with_back('/projection/stock/' . (int) $l['ingredient_id'], here_url()), e($l['name']), 'fw-semibold') ?><div class="fs-11 text-muted"><?= e((string) $l['sku']) ?></div></td>
                <td><?= par_mode_chip($l['par_mode']) ?><?= $l['thin_history'] ? ' ' . chip('thin', 'warning', 'sugg-thin-' . $lid) : '' ?></td>
                <td class="num" title="<?= $l['par_mode'] === 'dynamic' ? 'demand ' . e(fmt_num($l['demand_base'], 2)) . ' + safety ' . e(fmt_num($l['safety_base'], 2)) : ($l['safety_base'] !== null ? 'demand ' . e(fmt_num($l['demand_base'], 2)) . ' + ' . e(fmt_num($l['safety_base'], 2)) . ' (no par set)' : 'par') ?> = <?= e(fmt_num($l['need_base'], 2)) ?> <?= e($l['base_unit']) ?>"><?= e(fmt_num_adaptive($l['need_count'])) ?></td>
                <td class="num" title="on hand <?= e(fmt_num($l['on_hand_base'], 2)) ?> + on order <?= e(fmt_num($l['on_order_base'], 2)) ?> = <?= e(fmt_num($l['have_base'], 2)) ?> <?= e($l['base_unit']) ?>"><?= e(fmt_num_adaptive($l['have_count'])) ?></td>
                <td class="num" <?= $l['cap_base'] !== null ? 'title="' . e(fmt_num($l['cap_base'], 2)) . ' ' . e($l['base_unit']) . '"' : '' ?>><?= $l['cap_base'] === null ? '<span class="text-muted">—</span>' : e(fmt_num_adaptive($l['cap_count'])) ?></td>
                <td class="num" title="<?= e(fmt_num($l['raw_base'], 2)) ?> <?= e($l['base_unit']) ?>"><?= e(fmt_num_adaptive($l['raw_count'])) ?></td>
                <td class="num sg-units fw-semibold"><?= $order ? e(fmt_num($l['qty_units'], 2)) . ' <span class="fs-11 text-muted">' . e($l['purchase_unit']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <?php if ($sees): ?><td class="num"><?= $l['unit_cost'] === null ? '—' : money_price((string) $l['unit_cost']) ?></td><td class="num"><?= $order && $l['line_value'] !== null ? money($l['line_value']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
                <td class="sugg-note fs-12"><?= e($l['rounding_note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($sees && $c['total'] !== null): ?><tfoot><tr><td colspan="<?= $sees ? 8 : 6 ?>" class="text-end fw-semibold">Draft total</td><td class="num fw-semibold" id="supplier-total-<?= $id ?>"><?= money($c['total']) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
    <?php endif; ?>
</div>
