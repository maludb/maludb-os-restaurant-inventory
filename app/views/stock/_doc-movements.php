<?php /** The movements a posted document wrote. Data: id, title, rows (document_movements), seesCost */ $pdo = db(); ?>
<div class="card mb-3" id="<?= e($id) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($title) ?></h5></div>
<?php if ($rows === []): ?><div class="card-body text-muted fs-12">Nothing posted.</div><?php else: ?>
<div class="table-responsive"><table class="table align-middle mb-0 fs-12" id="<?= e($id) ?>-table"><thead><tr><th>Type</th><th>Ingredient</th><th>Area</th><th class="text-end">Quantity</th><th class="text-end">Unit cost</th><th class="text-end">Value</th></tr></thead><tbody>
<?php foreach ($rows as $t): ?><tr id="<?= e($id) ?>-row-<?= $t['transaction_id'] ?>"><td><?= txn_chip($t['txn_type']) ?></td><td><?= e($t['ingredient']) ?></td><td><?= e($t['area']) ?></td>
    <td class="text-end <?= (float) $t['qty_base'] < 0 ? 'text-danger' : '' ?>" title="<?= e(fmt_num($t['qty_base'], 4) . ' ' . $t['base_unit_code']) ?>"><?= e(format_qty($pdo, $t['ingredient_id'], $t['qty_base'])) ?></td>
    <td class="text-end"><?= $seesCost && $t['unit_cost_per_base'] !== null ? '$' . e(fmt_num($t['unit_cost_per_base'], 6)) . ' / ' . e($t['base_unit_code']) : money(null) ?></td>
    <td class="text-end"><?= $seesCost && $t['value'] !== null ? money((string) $t['value']) : money(null) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
