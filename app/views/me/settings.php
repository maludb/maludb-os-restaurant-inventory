<?php
/**
 * My settings (screen `my-settings`, sso-shell.md): the prefs form (display units, the default count area of this site, email on/off, text
 * on/off, the kinds, the text kinds), the time zone read-only (the directory's), the role badge and the roles held per site.
 * Data: prefs, areas, tz, roles (per site: [{site, roles[]}]), badge, osChannels, osProfile, notice, seesCost
 */
?>
<?= view('shared/header.php', ['id' => 'my-settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="my-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/me/settings', 'My settings', 'btn btn-touch btn-primary', 'id="settings-tab-prefs"') ?>
        <?= hx_link('/me/tokens', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?= hx_link('/me/trail', 'My trail', 'btn btn-touch btn-light', 'id="settings-tab-trail"') ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="post" action="/me/settings/save.php" hx-post="/me/settings/save.php" hx-target="#flash" id="prefs-form">
                <?= csrf_field() ?>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you see quantities</h5></div><div class="card-body">
                    <div class="d-flex gap-2 mb-3" id="prefs-form-field-display-units">
                        <?php foreach (['metric' => 'Metric (g, kg, ml, l)', 'imperial' => 'Imperial (oz, lb, fl oz, qt)'] as $v => $l): ?>
                            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-grow-1" for="prefs-form-field-display-units-<?= $v ?>"><input type="radio" class="form-check-input mt-0" name="units" value="<?= $v ?>" id="prefs-form-field-display-units-<?= $v ?>" <?= $prefs['display_units'] === $v ? 'checked' : '' ?>><?= e($l) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label fs-12 text-muted" for="prefs-form-field-default-area">Where you usually count</label>
                    <select name="default_area" id="prefs-form-field-default-area" class="form-select btn-touch">
                        <option value="">No default</option>
                        <?php foreach ($areas as $a): ?><option value="<?= (int) $a['storage_area_id'] ?>" <?= (int) $a['storage_area_id'] === ($prefs['default_area_id'] ?? 0) ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">The storage areas of this restaurant. A count starts there.</div>
                </div></div>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you are told</h5></div><div class="card-body">
                    <input type="hidden" name="email_enabled" value="no"><input type="hidden" name="text_enabled" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-email-enabled"><input type="checkbox" class="form-check-input mt-0" name="email_enabled" value="yes" id="prefs-form-field-email-enabled" <?= $prefs['email_enabled'] ? 'checked' : '' ?>><i class="feather-mail"></i> Email</label>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-text-enabled"><input type="checkbox" class="form-check-input mt-0" name="text_enabled" value="yes" id="prefs-form-field-text-enabled" <?= $prefs['text_enabled'] ? 'checked' : '' ?>><i class="feather-message-square"></i> Text message</label>
                    <div class="fs-12 text-muted mb-2" id="prefs-text-hint">Texts go to the phone you verified in the operating system — this application never sees the number. <a href="<?= e($osChannels) ?>" id="prefs-text-link">Your channels</a></div>
                </div></div>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tell me when</h5></div><div class="card-body" id="prefs-kinds">
                    <input type="hidden" name="kinds[]" value="">
                    <?php foreach (NOTICE_KINDS as $k => $label): ?>
                        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-kinds-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="kinds[]" value="<?= e($k) ?>" id="prefs-form-field-kinds-<?= e($k) ?>" <?= in_array($k, $prefs['kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
                    <?php endforeach; ?>
                </div></div>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Text me when</h5></div><div class="card-body" id="prefs-text-kinds">
                    <div class="fs-12 text-muted mb-2">Only when texts are on. Fewer is better: a text should mean "look now".</div>
                    <input type="hidden" name="text_kinds[]" value="">
                    <?php foreach (NOTICE_KINDS as $k => $label): ?>
                        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-text-kinds-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="text_kinds[]" value="<?= e($k) ?>" id="prefs-form-field-text-kinds-<?= e($k) ?>" <?= in_array($k, $prefs['text_kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
                    <?php endforeach; ?>
                </div></div>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="prefs-form-save-btn">Save</button>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="settings-roles"><div class="card-header"><h5 class="card-title mb-0">Who you are here</h5></div><div class="card-body">
                <div class="mb-2"><span class="badge bg-soft-<?= e(role_badge_kind($badge)) ?> text-<?= role_badge_kind($badge) === 'light' ? 'dark' : e(role_badge_kind($badge)) ?>" id="settings-role-badge"><?= e($badge) ?></span></div>
                <ul class="list-unstyled fs-12 text-muted mb-1" id="settings-roles-held">
                    <?php foreach ($roles as $r): ?><li id="settings-roles-site-<?= (int) $r['scope_id'] ?>"><strong><?= e($r['site']) ?></strong>: <?= $r['roles'] === [] ? 'no role yet' : e(implode(', ', $r['roles'])) ?></li><?php endforeach; ?>
                </ul>
                <div class="fs-12 text-muted">A super-admin grants and changes roles in the operating system.</div>
                <div class="fs-12 text-muted mt-1" id="settings-cost-note"><?= $seesCost ? 'You see cost here.' : 'Cost is withheld from you here (the Chef and above see it).' ?></div>
            </div></div>
            <div class="card mb-3" id="settings-timezone"><div class="card-header"><h5 class="card-title mb-0">Your time zone</h5></div><div class="card-body">
                <div class="fw-semibold" id="settings-timezone-value"><?= e($tz) ?></div>
                <div class="fs-12 text-muted">Times are shown in it. It comes from your profile in the operating system: <a href="<?= e($osProfile) ?>" id="settings-timezone-link">changed in the operating system</a>, it follows within a minute.</div>
            </div></div>
        </div>
    </div>
</div>
