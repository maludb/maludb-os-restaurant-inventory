<?php /** A record's trail. Data: id, rows (find_record_activity) */ $tz = site_timezone(); ?>
<div class="card mb-3" id="<?= e($id) ?>"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div>
<?php if ($rows === []): ?><div class="card-body text-muted fs-12">Nothing yet.</div><?php else: ?>
<div class="table-responsive"><table class="table mb-0 fs-12" id="<?= e($id) ?>-table"><tbody>
<?php foreach ($rows as $a): ?><tr id="<?= e($id) ?>-row-<?= (int) $a['activity_id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($a['occurred_at'], $tz, 'M j g:i A')) ?></td><td><?= e($a['actor_name'] ?? 'system') ?><?= !empty($a['actor_is_agent']) ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></td><td><code><?= e($a['action']) ?></code></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
