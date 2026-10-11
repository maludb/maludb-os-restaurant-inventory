<?php /** The week's waste: three tiles by stage and a table by reason (ri_share_waste_summary()), cost walled. Data: week */ ?>
<div class="row g-2 mb-3" id="waste-week">
    <?php foreach (WASTE_STAGES as $k => $w): $t = $week['tiles'][$k]; ?>
        <div class="col-4"><div class="card stock-tile mb-0" id="waste-tile-<?= $k ?>"><div class="card-body p-2 text-center"><div class="fs-12 text-muted"><?= e($w) ?></div><div class="fs-4 fw-bold" id="waste-tile-<?= $k ?>-entries"><?= (int) $t['entries'] ?></div><div class="fs-12" id="waste-tile-<?= $k ?>-cost"><?= $week['withheld'] ? money(null) : money($t['cost']) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<?php if ($week['reasons'] !== []): ?>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="waste-reasons-table"><thead><tr><th>Reason (<?= e(format_date($week['from'])) ?> – <?= e(format_date($week['to'])) ?>)</th><th class="text-end">Entries</th><th class="text-end">Cost</th></tr></thead><tbody>
<?php foreach ($week['reasons'] as $r): ?><tr id="waste-reason-row-<?= e(preg_replace('/[^a-z0-9]+/', '-', strtolower($r['reason']))) ?>"><td><?= e($r['reason']) ?></td><td class="text-end"><?= (int) $r['entries'] ?></td><td class="text-end"><?= money($r['cost']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
