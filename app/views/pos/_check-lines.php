<?php /** A check's lines (expanded in place). Data: c, lines, mayMap */ ?>
<table class="table table-sm mb-0 fs-12 bg-light" id="check-lines-table-<?= (int) $c['sale_id'] ?>"><tbody>
<?php foreach ($lines as $l): ?><tr class="<?= $l['voided'] ? 'text-decoration-line-through text-muted' : '' ?>"><td class="ps-4"><?= e($l['plu']) ?></td><td><?= e((string) $l['name']) ?></td><td class="text-end"><?= e(fmt_num($l['qty'], 2)) ?></td><td class="text-end"><?= $l['line_total'] === null ? '' : e(ri_money($l['line_total'])) ?></td>
    <td><?= $l['voided'] ? '<span class="badge bg-soft-danger text-danger">void</span>' : '' ?><?= $l['comp'] ? '<span class="badge bg-soft-warning text-warning">comp</span>' : '' ?><?= $l['recipe_id'] === null && empty($l['no_depletion']) ? ' <span class="badge bg-soft-danger text-danger">unmapped</span>' : '' ?></td><td><?= e(implode(', ', array_map(static fn ($m) => (string) ($m['code'] ?? ''), $l['modifiers']))) ?></td></tr><?php endforeach; ?>
</tbody></table>
