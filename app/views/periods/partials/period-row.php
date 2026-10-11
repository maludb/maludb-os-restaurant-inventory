<?php /** One period in the table (`period-row-{id}`) and as a card at 375 px (`period-card-{id}`). Data: p, f, marks, seesCost */
$closes = in_array($p['period_id'], $marks['closes_next'], true);
$link = with_back('/periods/' . (int) $p['period_id'], here_url());
$chip = period_status_chip($p['status']) . ($closes ? ' ' . chip('closes next', 'primary') : '');
$m = static fn (?string $v): string => ($f['begun'] ?? true) ? money($v) : '<span class="text-muted" title="not begun">—</span>';
$pct = !($f['begun'] ?? true) ? '<span class="text-muted" title="not begun">—</span>' : ($f['food_cost_pct'] === null ? '<span class="text-muted" title="' . ($seesCost ? 'no sales' : 'cost withheld') . '">—</span>' : e(fmt_num($f['food_cost_pct'], 1)) . ' %');
?>
<?php if (($asCard ?? false) === false): ?>
<tr id="period-row-<?= (int) $p['period_id'] ?>" class="<?= $closes ? 'table-active' : '' ?>">
    <td><?= hx_link($link, e(ucfirst(period_label($p, false))), 'fw-semibold') ?></td><td class="text-muted"><?= e(format_date($p['starts_on'])) ?> – <?= e(format_date($p['ends_on'])) ?></td><td><?= $chip ?><?= $f['live'] && $seesCost && ($f['begun'] ?? true) ? ' <span class="text-muted fs-12">live</span>' : '' ?></td>
    <td class="text-end"><?= $m($f['opening_value']) ?></td><td class="text-end"><?= $m($f['purchases_value']) ?></td><td class="text-end"><?= $m($f['ending_value']) ?></td><td class="text-end"><?= $m($f['cogs_value']) ?></td><td class="text-end"><?= $m($f['food_sales']) ?></td><td class="text-end"><?= $pct ?></td>
</tr>
<?php else: ?>
<div class="card mb-2 period-card <?= $closes ? 'closes-next' : '' ?>" id="period-card-<?= (int) $p['period_id'] ?>"><div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center"><div><?= hx_link($link, e(ucfirst($p['label'])), 'fw-semibold') ?></div><div><?= $chip ?></div></div>
    <div class="row g-1 fs-12 mt-1">
        <div class="col-4"><span class="text-muted">Opening</span><br><?= $m($f['opening_value']) ?></div><div class="col-4"><span class="text-muted">Purchases</span><br><?= $m($f['purchases_value']) ?></div><div class="col-4"><span class="text-muted">Ending</span><br><?= $m($f['ending_value']) ?></div>
        <div class="col-4"><span class="text-muted">COGS</span><br><?= $m($f['cogs_value']) ?></div><div class="col-4"><span class="text-muted">Food sales</span><br><?= $m($f['food_sales']) ?></div><div class="col-4"><span class="text-muted">Food-cost %</span><br><?= $pct ?></div>
    </div>
</div></div>
<?php endif; ?>
