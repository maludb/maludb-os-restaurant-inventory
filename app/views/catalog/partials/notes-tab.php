<?php /** The notes and attachments of a record (`notes-tab`). Data: type, id, notes, attachments, mayNote (notes.write), me, isAdmin, tz, back (the page to land on) */ ?>
<div class="row g-3" id="notes-tab">
    <div class="col-lg-7">
        <div class="card" id="notes-card"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
            <?php if ($mayNote): ?>
            <form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" id="note-form" class="mb-3"><?= csrf_field() ?><input type="hidden" name="record_type" value="<?= e($type) ?>"><input type="hidden" name="record_id" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <textarea name="body" id="note-form-field-body" class="form-control mb-2" rows="2" maxlength="4000" required placeholder="A note everyone here reads"></textarea>
                <button type="submit" class="btn btn-primary btn-touch" id="note-form-save-btn">Add the note</button></form>
            <?php endif; ?>
            <?php if ($notes === []): ?><div class="text-muted fs-12" id="notes-empty">No notes yet.</div><?php endif; ?>
            <?php foreach ($notes as $n): $nid = (int) $n['note_id']; ?>
                <div class="border rounded p-2 mb-2" id="note-row-<?= $nid ?>"><div class="fs-12 text-muted d-flex justify-content-between"><span><?= e($n['author'] ?? 'someone') ?> · <?= e(format_ts($n['created_at'], $tz)) ?></span>
                    <?php if ($isAdmin || (int) $n['member_id'] === $me): ?><form method="post" action="/notes/remove.php" hx-post="/notes/remove.php" hx-target="#flash" hx-confirm="Remove this note?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="note" value="<?= $nid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="note-row-<?= $nid ?>-remove">remove</button></form><?php endif; ?></div>
                    <div class="mt-1"><?= nl2br(e($n['body'])) ?></div></div>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card" id="attachments-card"><div class="card-header"><h5 class="card-title mb-0">Files</h5></div><div class="card-body">
            <?php if ($mayNote): ?>
            <form method="post" action="/attachments/add.php" hx-post="/attachments/add.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="attachment-form" class="mb-3"><?= csrf_field() ?><input type="hidden" name="record_type" value="<?= e($type) ?>"><input type="hidden" name="record_id" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <input type="file" name="file" id="attachment-form-field-file" class="form-control btn-touch mb-2" required accept="image/*,.pdf,.csv,.xlsx,.txt">
                <button type="submit" class="btn btn-light btn-touch" id="attachment-form-save-btn">Attach</button></form>
            <?php endif; ?>
            <?php if ($attachments === []): ?><div class="text-muted fs-12" id="attachments-empty">No files yet.</div><?php endif; ?>
            <?php foreach ($attachments as $a): $aid = (int) $a['attachment_id']; ?>
                <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 fs-12" id="attachment-row-<?= $aid ?>"><a href="/files/<?= $aid ?>" target="_blank" rel="noopener"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?></a><span class="text-muted"><?= e(fmt_bytes((int) $a['byte_size'])) ?>
                    <?php if ($isAdmin || (int) $a['uploaded_by'] === $me): ?><form method="post" action="/attachments/remove.php" hx-post="/attachments/remove.php" hx-target="#flash" hx-confirm="Remove this file?" class="d-inline ms-2"><?= csrf_field() ?><input type="hidden" name="attachment" value="<?= $aid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="attachment-row-<?= $aid ?>-remove">remove</button></form><?php endif; ?></span></div>
            <?php endforeach; ?>
        </div></div>
    </div>
</div>
