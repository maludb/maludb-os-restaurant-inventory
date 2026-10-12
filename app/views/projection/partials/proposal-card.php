<?php /** One proposal (`proposal-card-{id}`): the finding, its detail, the drafted record and the subject, Accept / Dismiss. Data: p */
$id = (int) $p['proposal_id'];
$drafted = record_url($p['drafted_record_type'], $p['drafted_record_id']);
$subject = record_url($p['subject_type'], $p['subject_id']);
$here = here_url();
?>
<div class="card mb-2 proposal-card" id="proposal-card-<?= $id ?>" data-status="<?= e($p['status']) ?>" data-kind="<?= e($p['kind']) ?>"><div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-semibold"><?= e($p['title']) ?></div><div><?= proposal_chip($p['status'], 'proposal-status-' . $id) ?></div></div>
    <div class="fs-13 mt-1"><?= proposal_detail_html($p) ?></div>
    <div class="fs-12 text-muted mt-1"><?= $p['proposed_by_name'] !== null ? e($p['proposed_by_name']) : 'The worker' ?> · <?= e(format_ts($p['created_at'], site_timezone(), 'g:i A')) ?>
        <?php if ($drafted !== null): ?> · <?= hx_link(with_back($drafted, $here), 'the ' . e(str_replace('_', ' ', (string) $p['drafted_record_type'])), 'fw-semibold', 'id="proposal-drafted-' . $id . '"') ?><?php endif; ?>
        <?php if ($subject !== null): ?> · <?= hx_link(with_back($subject, $here), 'the ' . e(str_replace('_', ' ', $p['subject_type']))) ?><?php endif; ?>
        <?php if ($p['status'] !== 'proposed' && $p['decided_by_name'] !== null): ?> · <?= e($p['status']) ?> by <?= e($p['decided_by_name']) ?><?php endif; ?></div>
    <?php if ($p['status'] === 'proposed'): ?>
    <div class="d-flex gap-2 mt-2">
        <form method="post" action="/projection/proposals/decide.php" hx-post="/projection/proposals/decide.php" hx-target="#flash" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><input type="hidden" name="decision" value="accept"><button type="submit" class="btn btn-primary btn-touch w-100" id="proposal-accept-<?= $id ?>"><i class="feather-check me-1"></i>Accept</button></form>
        <form method="post" action="/projection/proposals/decide.php" hx-post="/projection/proposals/decide.php" hx-target="#flash" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><input type="hidden" name="decision" value="dismiss"><button type="submit" class="btn btn-light btn-touch w-100" id="proposal-dismiss-<?= $id ?>"><i class="feather-x me-1"></i>Dismiss</button></form>
    </div>
    <?php endif; ?>
</div></div>
