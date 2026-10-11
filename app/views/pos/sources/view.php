<?php /** One source (screen source-view). Data: s, admin, pulls, days, unmapped, tab, mayEdit, mayCred, mayPull, mayUpload, mayDelete, mayMap, trail, tz, notice, back */
$id = (int) $s['source_id']; $st = source_state($s); $here = here_url(); $h = $s['health'];
$tabs = ['pulls' => 'Pulls', 'days' => 'Days', 'unmapped' => 'Unmapped' . ($unmapped !== [] ? ' (' . count($unmapped) . ')' : ''), 'trail' => 'Trail'];
?>
<?= view('shared/header.php', ['id' => 'source-view', 'title' => $s['name'], 'crumbs' => [['Home', '/'], ['POS sources', has_right('settings.manage') ? '/sales/sources/' : null], [$s['name'], null]], 'back' => $back,
    'action' => $mayEdit ? hx_link(with_back('/sales/sources/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="source-view-edit"') : '']) ?>
<div class="main-content" id="source-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="source-view-header"><div class="card-body">
        <div class="chip-row mb-2"><span class="badge <?= pos_chip($st) ?>" id="source-view-state"><?= e($st) ?></span><span class="badge bg-soft-light text-dark border"><?= e($s['connector']) ?></span><span class="badge bg-soft-light text-dark border" id="source-view-intake"><?= e(SOURCE_INTAKES[$s['intake']] ?? $s['intake']) ?></span><span class="badge bg-soft-light text-dark border" id="source-view-schedule"><?= e(schedule_label((int) $s['schedule_minutes'])) ?></span></div>
        <div class="fs-12">Last good pull: <?= $s['last_ok_at'] === null ? 'never' : e(format_ts((string) $s['last_ok_at'], $tz)) ?><?php if ((int) $s['consecutive_failures'] > 0): ?> · <span class="text-danger"><?= (int) $s['consecutive_failures'] ?> failure<?= (int) $s['consecutive_failures'] === 1 ? '' : 's' ?> in a row</span><?php if ($s['backoff_until'] !== null): ?>, next try after <?= e(format_ts((string) $s['backoff_until'], $tz)) ?><?php endif; ?><?php endif; ?></div>
        <?php if ($s['paused_at'] !== null): ?><div class="fs-12 text-muted" id="source-view-paused">Paused <?= e(format_ts((string) $s['paused_at'], $tz)) ?><?= $s['paused_reason'] ? ' — ' . e($s['paused_reason']) : '' ?></div><?php endif; ?>
        <?php if ($h !== null): ?><div class="fs-12 mt-1" id="source-view-health"><?= $h['days_missing'] === [] ? 'No day missing this week.' : 'Missing this week: ' . e(implode(', ', array_map('format_date', $h['days_missing']))) . '.' ?> <?= (int) $h['pending_runs'] > 0 ? (int) $h['pending_runs'] . ' day' . ((int) $h['pending_runs'] === 1 ? '' : 's') . ' due for depletion at the site.' : '' ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-3" id="source-view-actions">
            <?php if ($mayPull && ($s['connector'] === 'square' || $s['intake'] === 'sftp')): ?><form method="post" action="/sales/sources/pull.php" hx-post="/sales/sources/pull.php" hx-target="#flash" id="source-pull-form"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch" id="source-view-pull" <?= $s['paused_at'] !== null ? 'disabled' : '' ?>><i class="feather-download-cloud me-1"></i>Pull now</button></form><?php endif; ?>
            <?php if ($mayUpload && $s['connector'] !== 'square'): ?><?= hx_link('/sales/upload?source=' . $id, '<i class="feather-upload me-1"></i>Upload a file', 'btn btn-light-brand btn-touch', 'id="source-view-upload"') ?><?php endif; ?>
            <?php if ($mayEdit || $mayPull): ?><form method="post" action="/sales/sources/probe.php" hx-post="/sales/sources/probe.php" hx-target="#source-view-probe" id="source-probe-form"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="source-view-probe-btn"><i class="feather-activity me-1"></i>Probe</button></form><?php endif; ?>
            <?php if ($mayEdit): ?><form method="post" action="/sales/sources/pause.php" hx-post="/sales/sources/pause.php" hx-target="#flash" class="d-flex gap-1" id="source-pause-form"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>">
                <?php if ($s['paused_at'] === null): ?><input type="hidden" name="paused" value="yes"><input type="text" name="reason" class="form-control btn-touch" placeholder="why (optional)" style="max-width:12rem" id="source-pause-reason"><button type="submit" class="btn btn-light btn-touch" id="source-view-pause">Pause</button>
                <?php else: ?><input type="hidden" name="paused" value="no"><button type="submit" class="btn btn-light btn-touch" id="source-view-resume">Resume</button><?php endif; ?></form><?php endif; ?>
            <?php if ($mayDelete): ?><form method="post" action="/sales/sources/delete.php" hx-post="/sales/sources/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($s['name']) ?>? It has no sales." id="source-delete-form"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><button type="submit" class="btn btn-light text-danger btn-touch" id="source-view-delete">Delete</button></form><?php endif; ?>
        </div>
        <div id="source-view-probe" class="mt-2"></div>
    </div></div>
    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="card h-100" id="source-view-settings"><div class="card-header"><h5 class="card-title mb-0">Settings</h5></div><div class="card-body fs-12">
            <?php if ($admin && is_array($s['settings'])): ?><pre class="mb-0 small" id="source-view-settings-json"><?= e(json_encode(array_diff_key($s['settings'], ['seen_files' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php else: ?><dl class="mb-0" id="source-view-settings-summary"><dt>Connector</dt><dd><?= e($s['connector']) ?></dd><?php if ($s['mapping_key']): ?><dt>Mapping</dt><dd><?= hx_link('/sales/mappings/' . $s['mapping_key'], e($s['mapping_key'])) ?></dd><?php endif; ?><dt>Intake</dt><dd><?= e(SOURCE_INTAKES[$s['intake']] ?? $s['intake']) ?></dd></dl><?php endif; ?>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100" id="source-view-credential"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Credential</h5><?php if ($mayCred && credential_kinds_for(['connector' => $s['connector'], 'settings' => ['intake' => $s['intake']]]) !== []): ?><?= hx_link(with_back('/sales/sources/' . $id . '/credential', $here), $s['has_credential'] ? 'Rotate' : 'Set', 'btn btn-sm btn-light btn-touch', 'id="source-view-credential-set"') ?><?php endif; ?></div><div class="card-body fs-12">
            <?php if (!$s['has_credential']): ?><span class="text-muted">None set<?= $s['connector'] === 'square' || $s['intake'] === 'sftp' ? ' — this source needs one to pull.' : ' — it needs none.' ?></span>
            <?php elseif ($admin && $s['credentials'] !== []): $c = $s['credentials'][0]; ?><div id="source-view-credential-current"><?= e($c['kind']) ?> · <?= e($c['label']) ?> · …<?= e($c['last4']) ?></div><?php if (count($s['credentials']) > 1): ?><div class="text-muted"><?= count($s['credentials']) - 1 ?> earlier, rotated</div><?php endif; ?>
            <?php else: ?><span class="text-muted">A credential is set (the admin's to see).</span><?php endif; ?>
        </div></div></div>
    </div>
    <ul class="nav nav-tabs mb-0" id="source-view-tabs"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><?= hx_link('/sales/sources/' . $id . '?tab=' . $k, e($l), 'nav-link' . ($tab === $k ? ' active' : ''), 'id="source-view-tab-' . $k . '"') ?></li><?php endforeach; ?></ul>
    <div class="card border-top-0" id="source-view-tab-body"><div class="card-body p-0">
        <?php if ($tab === 'days'): ?>
            <?php if ($days === []): ?><div class="p-3 text-muted fs-12">No day imported yet.</div><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="source-days-table"><thead><tr><th>Day</th><th class="text-end">Checks</th><th class="text-end">Covers</th><th>Run</th></tr></thead><tbody>
            <?php foreach ($days as $d): ?><tr id="source-day-row-<?= e($d['business_date']) ?>"><td><?= hx_link('/sales/' . $d['business_date'], e(format_date($d['business_date']))) ?></td><td class="text-end"><?= (int) $d['checks'] ?></td><td class="text-end"><?= (int) $d['covers'] ?></td><td><span class="badge <?= pos_chip($d['run_status']) ?>"><?= e($d['run_status'] ?? 'none') ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        <?php elseif ($tab === 'unmapped'): ?>
            <?= view('pos/sources/_unmapped.php', ['unmapped' => $unmapped, 'mayMap' => $mayMap]) ?>
        <?php elseif ($tab === 'trail'): ?>
            <ul class="list-unstyled mb-0 p-3 fs-12" id="source-view-trail"><?php foreach ($trail as $a): ?><li><?= e(format_ts((string) $a['occurred_at'], $tz)) ?> · <?= e($a['action']) ?> · <?= e($a['actor_name'] ?? 'worker') ?></li><?php endforeach; ?><?php if ($trail === []): ?><li class="text-muted">Nothing yet.</li><?php endif; ?></ul>
        <?php else: ?>
            <?= view('pos/sources/_pulls-table.php', ['pulls' => $pulls, 'tz' => $tz]) ?>
            <?php if ($pulls !== []): ?><div class="p-2 fs-12"><?= hx_link('/sales/sources/' . $id . '/pulls', 'Every pull', '', 'id="source-view-all-pulls"') ?></div><?php endif; ?>
        <?php endif; ?>
    </div></div>
</div>
