<?php /** The arithmetic of a suggested or agent-drafted line (need · have · on order · cap · safety · raw · the rounding note) in the ingredient's purchase unit. Data: line (a po_lines() row), id */
$pdo = db();
$iid = (int) $line['ingredient_id'];
$unit = (string) $line['purchase_unit_code'];
$q = static fn (?string $base): string => $base === null ? '—' : format_qty($pdo, $iid, $base, $unit);
$parts = ['Need' => $line['need_base'], 'Have' => $line['have_base'], 'On order' => $line['on_order_base'], 'Cap' => $line['cap_base'], 'Safety' => $line['safety_base'], 'Raw' => $line['raw_base']];
?>
<div class="fs-12" id="<?= e($id) ?>">
    <?php foreach ($parts as $label => $base): if ($base === null) { continue; } ?><span class="me-3 text-nowrap"><span class="text-muted"><?= e($label) ?></span> <?= e($q((string) $base)) ?></span><?php endforeach; ?>
    <?php if (!empty($line['rounding_note'])): ?><div class="text-muted mt-1"><?= e($line['rounding_note']) ?></div><?php endif; ?>
</div>
