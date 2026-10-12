<?php /** The watch form (`watch-form`, `watch-form-field-{name}`) → watch_set. Data: edit, prefill, agents */
$p = $prefill;
?>
<div class="card" id="watch-form-card"><div class="card-header"><h6 class="mb-0"><?= $edit === null ? 'New watch' : 'Change the watch on ' . e($edit['ingredient']) ?></h6></div><div class="card-body">
    <form method="post" action="/projection/watches/save.php" hx-post="/projection/watches/save.php" hx-target="#flash" id="watch-form"><?= csrf_field() ?>
        <?php if ($edit !== null): ?><input type="hidden" name="watch" value="<?= (int) $edit['watch_id'] ?>"><?php endif; ?>
        <div class="row g-2">
            <div class="col-12 col-lg-6"><?= form_picker('watch-form', 'ingredient', 'Ingredient', 'ingredient', $p['ingredient_id'], [], ['required' => true]) ?></div>
            <div class="col-12 col-lg-3"><label class="form-label fs-12 text-muted" for="watch-form-field-kind">Watch for</label><select name="kind" id="watch-form-field-kind" class="form-select btn-touch"><?php foreach (WATCH_KINDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $p['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-lg-3"><label class="form-label fs-12 text-muted" for="watch-form-field-threshold">Threshold</label><input type="number" name="threshold" id="watch-form-field-threshold" class="form-control btn-touch" step="any" min="0" inputmode="decimal" value="<?= e($p['threshold'] === null || $p['threshold'] === '' ? '' : fmt_input($p['threshold'], 4)) ?>" placeholder="cost per recipe unit, or a %"><div class="form-text">Cost above: a cost per recipe unit. Cost moved: a percentage. Not needed for the others.</div></div>
            <div class="col-12 col-lg-4"><label class="form-label fs-12 text-muted" for="watch-form-field-agent">Dispatch an agent when it fires</label><select name="agent" id="watch-form-field-agent" class="form-select btn-touch"><option value="">No one</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['member_id'] ?>" <?= (int) $p['agent_member_id'] === (int) $a['member_id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-lg-4"><label class="form-label fs-12 text-muted" for="watch-form-field-note">Note</label><input type="text" name="note" id="watch-form-field-note" class="form-control btn-touch" maxlength="200" value="<?= e((string) $p['note']) ?>"></div>
            <div class="col-12 col-lg-4 d-flex align-items-end"><div class="form-check mb-2"><input type="hidden" name="text_me" value="no"><input class="form-check-input" type="checkbox" name="text_me" value="yes" id="watch-form-field-text_me" <?= $p['text_me'] ? 'checked' : '' ?>><label class="form-check-label" for="watch-form-field-text_me">Text me</label></div></div>
            <div class="col-12 col-md-4"><button type="submit" class="btn btn-primary btn-touch w-100" id="watch-save-btn"><?= $edit === null ? 'Watch it' : 'Save the watch' ?></button></div>
            <?php if ($edit !== null): ?><div class="col-12 col-md-3"><?= hx_link('/projection/watches', 'Cancel', 'btn btn-light btn-touch w-100', 'id="watch-cancel"') ?></div><?php endif; ?>
        </div>
    </form>
</div></div>
