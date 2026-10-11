<?php /** Pulls as a table. Data: pulls, tz */ ?>
<?php if ($pulls === []): ?><div class="p-3 text-muted fs-12" id="pulls-none">No pull yet.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="pulls-table"><thead><tr><th>Kind</th><th>Started</th><th>Status</th><th>File</th><th>Days</th><th class="text-end">Checks seen / new</th><th class="text-end">Lines</th><th class="text-end">New PLUs</th><th>By</th><th>Error</th></tr></thead><tbody>
<?php foreach ($pulls as $p): ?><tr id="pull-row-<?= (int) $p['pull_id'] ?>"><td><span class="badge bg-soft-light text-dark border"><?= e($p['kind']) ?></span></td><td><?= e(format_ts((string) $p['started_at'], $tz)) ?></td><td><span class="badge <?= pos_chip($p['status']) ?>"><?= e($p['status']) ?></span></td>
    <td><?= $p['attachment_id'] !== null ? '<a href="/files/' . (int) $p['attachment_id'] . '" id="pull-file-' . (int) $p['pull_id'] . '">' . e($p['file_name'] ?? 'file') . '</a>' : e($p['file_name'] ?? '') ?></td>
    <td><?= e(implode(', ', array_map('format_date', $p['dates_seen']))) ?></td><td class="text-end"><?= (int) $p['sales_seen'] ?> / <?= (int) $p['sales_new'] ?></td><td class="text-end"><?= (int) $p['lines_seen'] ?></td><td class="text-end"><?= (int) $p['plus_new'] ?></td>
    <td><?= e($p['started_by_name'] ?? ($p['started_by'] === null ? 'worker' : '')) ?></td><td class="text-danger"><?= e(mb_substr((string) $p['error'], 0, 200)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
