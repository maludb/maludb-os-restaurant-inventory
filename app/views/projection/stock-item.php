<?php /** One ingredient's projection (screen `projected-ingredient`). Data: p (projected_ingredient()), sugg (suggested_line_for_ingredient()), mayWatch */
$i = $p['ingredient'];
$id = (int) $i['ingredient_id'];
$here = here_url();
$par = $p['par'];
?>
<?= view('shared/header.php', ['id' => 'projected-ingredient', 'title' => $i['name'], 'crumbs' => [['Home', '/'], ['Projection', null], ['Projected stock', '/projection/stock'], [$i['name'], null]], 'back' => back_link() ?? ['/projection/stock', 'the projection']]) ?>
<div class="main-content" id="projected-ingredient-content" data-ingredient-id="<?= $id ?>">
    <?= view('projection/partials/assets.php') ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h6 class="mb-0"><?= e($i['name']) ?></h6><span class="text-muted fs-12"><?= e($i['code']) ?> · <?= e($i['category']) ?></span>
            <?= $p['stockout_on'] !== null ? chip('runs out ' . date('D M j', (int) strtotime($p['stockout_on'])), 'danger', 'projected-stockout-chip') : chip('covered for ' . count($p['days']) . ' days', 'success', 'projected-covered-chip') ?>
            <span class="ms-auto d-flex gap-2"><?= hx_link('/ingredients/' . $id, 'the ingredient', 'fs-12') ?><?php if ($mayWatch): ?><?= hx_link('/projection/watches?ingredient=' . $id . '#watch-form', 'Watch this', 'btn btn-light btn-sm btn-touch', 'id="watch-this-btn"') ?><?php endif; ?></span></div>
        <?= projected_chart_svg($p['days'], $p['stockout_on'], $i['name'], $i['base_unit_code']) ?>
        <div class="row g-2 fs-13 mt-1">
            <div class="col-6 col-md-3"><span class="text-muted">On hand</span><br><span title="<?= e(fmt_num($p['on_hand_base'], 2)) ?> <?= e($i['base_unit_code']) ?>"><?= e(fmt_num_adaptive($p['on_hand_count'])) ?> <?= e($i['count_unit']) ?></span></div>
            <div class="col-6 col-md-3" id="projected-par"><span class="text-muted">Par</span><br><?php if ($par === null || $par['par_base'] === null): ?>none set<?php else: ?><span title="<?= e(fmt_num($par['par_base'], 2)) ?> <?= e($i['base_unit_code']) ?>"><?= e(fmt_num_adaptive($par['par_count'])) ?> <?= e($i['count_unit']) ?></span> <?= par_mode_chip((string) $par['par_mode']) ?><?php endif; ?></div>
        </div>
    </div></div>
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card mb-3"><div class="card-header"><h6 class="mb-0">Day by day</h6></div><div class="fc-table-wrap"><table class="table table-sm align-middle mb-0 fc-table" id="projected-days-table"><thead><tr><th>Day</th><th class="num">Demand</th><th class="num">Delivery</th><th class="num">Projected</th></tr></thead><tbody>
            <?php foreach ($p['days'] as $d): $neg = (float) $d['projected_base'] < 0; ?>
                <tr id="projected-day-<?= e($d['on_date']) ?>" class="<?= $neg ? 'fc-neg' : '' ?>"><td><?= e(date('D M j', (int) strtotime($d['on_date']))) ?></td><td class="num" title="<?= e(fmt_num($d['demand_base'], 2)) ?> <?= e($i['base_unit_code']) ?>"><?= e(fmt_num_adaptive($d['demand_base'])) ?></td><td class="num"><?= (float) $d['receipts_base'] > 0 ? '+' . e(fmt_num_adaptive($d['receipts_base'])) : '<span class="text-muted">—</span>' ?></td><td class="num fw-semibold" title="<?= e(fmt_num($d['projected_base'], 2)) ?> <?= e($i['base_unit_code']) ?>"><?= e(fmt_num_adaptive($d['projected_count'])) ?> <span class="fs-11 text-muted"><?= e($i['count_unit']) ?></span></td></tr>
            <?php endforeach; ?></tbody></table></div><div class="card-footer fs-12 text-muted">Demand and deliveries are in <?= e($i['base_unit_code']) ?>; the projected quantity is in <?= e($i['count_unit']) ?> (the base figure is in each cell's title).</div></div>
            <div class="card mb-3" id="projected-po-lines"><div class="card-header"><h6 class="mb-0">Orders on the way</h6></div>
            <?php if ($p['po_lines'] === []): ?><div class="card-body text-muted fs-13">No open order line is due for this ingredient.</div>
            <?php else: ?><div class="list-group list-group-flush"><?php foreach ($p['po_lines'] as $l): ?><div class="list-group-item d-flex justify-content-between"><div><?= hx_link(with_back('/purchasing/' . (int) $l['purchase_order_id'], $here), e($l['number']), 'fw-semibold') ?> <span class="text-muted"><?= e($l['supplier']) ?></span></div><div class="text-end"><?= e(fmt_num($l['qty_ordered'], 2)) ?> <?= e($l['purchase_unit_code']) ?> <span class="text-muted">· due <?= e(format_date($l['due_on'])) ?></span></div></div><?php endforeach; ?></div><?php endif; ?></div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card mb-3" id="projected-drivers"><div class="card-header"><h6 class="mb-0">What drives the demand</h6></div>
            <?php if ($p['drivers'] === []): ?><div class="card-body text-muted fs-13">No menu item's forecast uses this ingredient in the window.</div>
            <?php else: ?><div class="list-group list-group-flush"><?php foreach ($p['drivers'] as $d): ?><div class="list-group-item d-flex justify-content-between"><div><?= hx_link(with_back('/projection/items/' . (int) $d['menu_item_id'], $here), e($d['name'])) ?></div><div class="text-end"><?= e(fmt_num_adaptive($d['count'])) ?> <?= e($d['count_unit']) ?> <span class="text-muted">· <?= e(fmt_num($d['share_pct'], 1)) ?> %</span></div></div><?php endforeach; ?></div><?php endif; ?></div>
            <div class="card" id="projected-suggested"><div class="card-header"><h6 class="mb-0">The suggested order</h6></div><div class="card-body fs-13">
            <?php if ($sugg === null): ?><span class="text-muted">No supplier item is set up for this ingredient, so nothing is suggested.</span>
            <?php else: $l = $sugg['line']; ?>
                <div><?= hx_link(with_back('/purchasing/suggested?supplier=' . $sugg['supplier_id'], $here), e($sugg['supplier']), 'fw-semibold') ?> · cut-off <?= e(format_ts($sugg['cutoff_at'], site_timezone(), 'D H:i')) ?> for <?= e(date('D M j', (int) strtotime((string) $sugg['delivery_on']))) ?></div>
                <div class="mt-1"><?= par_mode_chip($l['par_mode']) ?> need <?= e(fmt_num_adaptive($l['need_count'])) ?> − have <?= e(fmt_num_adaptive($l['have_count'])) ?> → <span class="fw-semibold"><?= (float) $l['qty_units'] > 0 ? e(fmt_num($l['qty_units'], 2)) . ' ' . e($l['purchase_unit']) : 'covered' ?></span> <span class="text-muted">(<?= e($l['rounding_note']) ?>)</span></div>
            <?php endif; ?></div></div>
        </div>
    </div>
</div>
