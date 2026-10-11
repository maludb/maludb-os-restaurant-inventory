<?php /** What a count did (screen `count-history`). Data: count, corrections, byCategory, counters, skipped, seesCost, trail, notice */
$id = (int) $count['count_id'];
$tz = site_timezone((int) $count['scope_id']);
?>
<?= view('shared/header.php', ['id' => 'count-history', 'title' => $count['number'] . ' — history', 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], [$count['number'], '/counts/' . $id . '/review'], ['History', null]], 'back' => back_link() ?? ['/counts/', 'Counts']]) ?>
<div class="main-content" id="count-history-content">
    <?= view('counts/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="count-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center fs-12">
        <?= count_status_chip($count['status'], 'count-status') ?> <?= chip(COUNT_KINDS[$count['kind']] ?? $count['kind'], SHEET_KIND_CHIPS[$count['kind']] ?? 'secondary') ?>
        <span>as of <?= e(format_ts($count['as_of'], $tz, 'M j, g:i A')) ?></span>
        <span>started by <?= e($count['started_by_name'] ?? '—') ?> <?= e(format_ts($count['started_at'], $tz, 'M j g:i A')) ?></span>
        <?php if ($count['approved_at']): ?><span id="count-approved-by">approved by <?= e($count['approved_by_name'] ?? '—') ?> <?= e(format_ts($count['approved_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
        <span><?= (int) $count['lines'] ?> lines · <?= (int) $count['lines_over'] ?> over tolerance</span>
        <span id="count-total-variance">total variance <?= $seesCost && $count['total_variance_value'] !== null ? money((string) $count['total_variance_value']) : money(null) ?></span>
    </div></div>
    <div class="card mb-3" id="history-corrections"><div class="card-header"><h5 class="card-title mb-0">What it posted</h5></div>
<?php if ($corrections === []): ?><div class="card-body text-muted fs-12"><?= $count['status'] === 'approved' ? 'Every counted line matched the book — nothing needed correcting.' : 'Nothing was posted' . ($count['status'] === 'discarded' ? ' — the count was discarded.' : ' — the count is not approved yet.') ?></div>
<?php else: ?><div class="table-responsive"><table class="table align-middle mb-0 fs-12" id="history-corrections-table"><thead><tr><th>Ingredient</th><th>Area</th><th class="text-end">Change</th><th class="text-end d-none d-md-table-cell">Unit cost</th><th class="text-end">Value</th></tr></thead><tbody>
    <?php foreach ($corrections as $t): ?><tr id="history-correction-<?= (int) $t['transaction_id'] ?>"><td><?= e($t['ingredient']) ?></td><td><?= e($t['area']) ?></td>
        <td class="text-end text-nowrap <?= (float) $t['qty_base'] < 0 ? 'text-danger' : '' ?>" title="<?= e(fmt_num($t['qty_base'], 4) . ' ' . $t['base_unit_code']) ?>"><?= ((float) $t['qty_base'] > 0 ? '+' : '') . e(fmt_num($t['qty_count'], 2)) ?> <?= e($t['count_unit']) ?></td>
        <td class="text-end d-none d-md-table-cell"><?= $seesCost && $t['unit_cost_per_base'] !== null ? '$' . e(fmt_num($t['unit_cost_per_base'], 6)) . ' / ' . e($t['base_unit_code']) : money(null) ?></td>
        <td class="text-end"><?= $seesCost && $t['value'] !== null ? money((string) $t['value']) : money(null) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
    <div class="card mb-3" id="history-categories"><div class="card-header"><h5 class="card-title mb-0">Variance by category</h5></div>
<?php if ($byCategory === []): ?><div class="card-body text-muted fs-12">No lines.</div><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0 fs-12" id="history-categories-table"><thead><tr><th>Category</th><th class="text-end">Lines</th><th class="text-end">Off the book</th><th class="text-end">Over tolerance</th><th class="text-end">Variance</th></tr></thead><tbody>
    <?php foreach ($byCategory as $r): ?><tr><td><?= e($r['category']) ?></td><td class="text-end"><?= (int) $r['lines'] ?></td><td class="text-end"><?= (int) $r['lines_off'] ?></td><td class="text-end"><?= (int) $r['over'] ?></td>
        <td class="text-end"><?= $seesCost && $r['variance_value'] !== null ? '<span class="' . ((float) $r['variance_value'] < 0 ? 'text-danger' : '') . '">' . money((string) $r['variance_value']) . '</span>' : money(null) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
    <div class="card mb-3" id="history-counters"><div class="card-header"><h5 class="card-title mb-0">Who counted what</h5></div>
<?php if ($counters === []): ?><div class="card-body text-muted fs-12">Nobody has counted a line yet.</div><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0 fs-12" id="history-counters-table"><thead><tr><th>Person</th><th class="text-end">Lines counted</th><th class="text-end">Lines recounted</th></tr></thead><tbody>
    <?php foreach ($counters as $c): ?><tr><td><?= e($c['name']) ?></td><td class="text-end"><?= (int) $c['lines'] ?></td><td class="text-end"><?= (int) $c['recounts'] ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
    <?= view('counts/partials/skipped.php', ['skipped' => $skipped]) ?>
    <?= view('stock/_trail.php', ['id' => 'count-trail', 'rows' => $trail]) ?>
</div>
