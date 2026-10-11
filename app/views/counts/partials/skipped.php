<?php /** The areas a count skipped. Data: skipped [{storage_area_id, name, lines_skipped}] */ ?>
<div class="card mb-3" id="review-skipped"><div class="card-body py-2 fs-12">
<?php if ($skipped === []): ?><i class="feather-check-circle text-success me-1"></i>No area was skipped.
<?php else: ?><i class="feather-alert-triangle text-warning me-1"></i><strong>Skipped:</strong> <?= e(implode(', ', array_map(static fn (array $a): string => $a['name'] . ($a['lines_skipped'] > 0 ? ' (' . $a['lines_skipped'] . ' line' . ($a['lines_skipped'] === 1 ? '' : 's') . ' skipped)' : ' (not counted)'), $skipped))) ?>. <span class="text-muted">Nothing is posted for them; their stock shows as usage until they are counted.</span>
<?php endif; ?></div></div>
