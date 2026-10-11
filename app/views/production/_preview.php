<?php /** What a batch will consume (Pattern A under the batch form): ri_explode × batches against on hand; short lines red — a warning, not a stop. Data: preview, recipe */ $pdo = db(); ?>
<?php if ($preview === null): ?><div class="text-muted fs-12" id="production-preview-empty">Choose a prep recipe to see what it uses.</div>
<?php elseif ($preview['version_id'] === null): ?><div class="alert alert-warning mb-0" id="production-preview-no-version">No version of <?= e($recipe['name'] ?? 'the recipe') ?> is in force — activate one first.</div>
<?php else: ?>
<div class="fs-12 text-muted mb-1">What it will use (the version in force today):</div>
<table class="table table-sm mb-2 fs-12" id="production-preview-table"><tbody>
<?php foreach ($preview['rows'] as $r): ?><tr id="production-preview-row-<?= $r['ingredient_id'] ?>" class="<?= $r['short'] ? 'text-danger' : '' ?>"><td><?= e($r['name']) ?></td><td class="text-end"><?= e(format_qty($pdo, $r['ingredient_id'], $r['need_base'])) ?></td><td class="text-end text-muted">on hand <?= e(format_qty($pdo, $r['ingredient_id'], $r['on_hand_base'])) ?><?= $r['short'] ? ' — short' : '' ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ($preview['output_base'] !== null && $preview['output_ingredient_id'] !== null): ?><div class="fs-12" id="production-preview-output">It makes <span class="fw-semibold"><?= e(format_qty($pdo, (int) $preview['output_ingredient_id'], $preview['output_base'])) ?></span> (after the recipe's loss).</div><?php endif; ?>
<?php endif; ?>
