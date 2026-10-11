<?php /** The POS sources of the site as cards (screen source-list). Data: rows, status, mayCreate, mayUpload, fileSources, notice */ ?>
<?= view('shared/header.php', ['id' => 'source-list', 'title' => 'POS sources', 'crumbs' => [['Home', '/'], ['Sales', '/sales/'], ['POS sources', null]], 'action' => $mayCreate ? hx_link('/sales/sources/new', '<i class="feather-plus me-1"></i>New source', 'btn btn-primary btn-touch', 'id="source-list-new"') : '']) ?>
<div class="main-content" id="source-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/sales/sources/" class="card mb-3" id="source-list-filters" hx-get="/sales/sources/" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:select">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <select name="status" class="form-select btn-touch w-auto" id="source-list-filter-status" aria-label="Status"><?php foreach (['all' => 'Every source', 'active' => 'Active', 'paused' => 'Paused'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-light-brand btn-touch" id="source-list-filter-go">Filter</button>
            <?php if ($mayUpload && $fileSources !== []): ?><?= hx_link('/sales/upload' . (count($fileSources) === 1 ? '?source=' . (int) $fileSources[0]['source_id'] : ''), '<i class="feather-upload me-1"></i>Upload a file', 'btn btn-light btn-touch ms-auto', 'id="source-list-upload"') ?><?php endif; ?>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="source-list-empty"><div class="card-body empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-rss"></i></span><div><div class="fw-semibold">No POS source<?= $status !== 'all' ? ' ' . e($status) : ' yet' ?></div><div class="fs-12 text-muted"><?= $mayCreate ? 'Add the restaurant\'s POS: a file it exports (upload, email, SFTP) or Square.' : 'The admin adds the POS.' ?></div></div></div></div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($rows as $s): $id = (int) $s['source_id']; $st = source_state($s); $h = $s['health']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 record-card" id="source-card-<?= $id ?>"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-1"><?= hx_link('/sales/sources/' . $id, e($s['name']), 'fw-semibold fs-14', 'id="source-card-' . $id . '-link"') ?><span class="badge <?= pos_chip($st) ?>" id="source-card-<?= $id ?>-state"><?= e($st) ?></span></div>
                <div class="chip-row mb-2"><span class="badge bg-soft-light text-dark border"><?= e($s['connector']) ?></span><span class="badge bg-soft-light text-dark border"><?= e(SOURCE_INTAKES[$s['intake']] ?? $s['intake']) ?></span><span class="badge bg-soft-light text-dark border"><?= e(schedule_label((int) $s['schedule_minutes'])) ?></span></div>
                <div class="fs-12 text-muted">Last ok: <?= $s['last_ok_at'] === null ? 'never' : e(format_ts((string) $s['last_ok_at'], site_timezone((int) $s['scope_id']))) ?><?php if ((int) $s['consecutive_failures'] > 0): ?> · <span class="text-danger" id="source-card-<?= $id ?>-failures"><?= (int) $s['consecutive_failures'] ?> failure<?= (int) $s['consecutive_failures'] === 1 ? '' : 's' ?></span><?php endif; ?></div>
                <?php if ($s['paused_at'] !== null && $s['paused_reason']): ?><div class="fs-12 text-muted">Paused: <?= e($s['paused_reason']) ?></div><?php endif; ?>
                <?php if ($h !== null): ?><div class="fs-12 mt-1"><?php if ($h['days_missing'] !== []): ?><span class="text-warning" id="source-card-<?= $id ?>-missing"><?= count($h['days_missing']) ?> day<?= count($h['days_missing']) === 1 ? '' : 's' ?> missing this week</span><?php else: ?><span class="text-success">No day missing this week</span><?php endif; ?>
                    <?php if ((int) $h['unmapped_plus'] > 0): ?> · <?= hx_link('/menu/?unmapped=1', (int) $h['unmapped_plus'] . ' unmapped PLU' . ((int) $h['unmapped_plus'] === 1 ? '' : 's'), 'text-danger', 'id="source-card-' . $id . '-unmapped"') ?><?php endif; ?></div><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
