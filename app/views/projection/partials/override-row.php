<?php /** One override (`override-row-{id}`): what it does, for which days, why, who; Remove (set) or Set / Dismiss (proposed) for forecast.adjust. Data: o, mayAdjust, proposed */
$what = $o['factor'] !== null ? '×' . fmt_num($o['factor'], 2) : fc_qty($o['qty']) . ' a day';
$when = $o['from_date'] === $o['to_date'] ? format_date($o['from_date']) : format_date($o['from_date']) . ' – ' . format_date($o['to_date']);
?>
<div class="d-flex flex-wrap align-items-start gap-2">
    <div class="me-auto"><div class="fw-semibold"><?= e($what) ?> <span class="fw-normal text-muted">· <?= e($when) ?><?= $o['menu_item'] === null ? ' · every item' : '' ?></span></div>
        <div class="fs-12"><?= e($o['reason']) ?></div><div class="fs-11 text-muted"><?= $proposed ? 'Proposed' : 'Set' ?> by <?= e((string) ($o['set_by_name'] ?? 'someone')) ?></div></div>
    <?php if ($mayAdjust && $proposed): ?>
        <form method="post" action="/projection/override-decide.php" hx-post="/projection/override-decide.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="override" value="<?= (int) $o['override_id'] ?>"><input type="hidden" name="decision" value="set"><button type="submit" class="btn btn-primary btn-sm btn-touch" id="override-set-<?= (int) $o['override_id'] ?>">Set</button></form>
        <form method="post" action="/projection/override-decide.php" hx-post="/projection/override-decide.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="override" value="<?= (int) $o['override_id'] ?>"><input type="hidden" name="decision" value="dismiss"><button type="submit" class="btn btn-light btn-sm btn-touch" id="override-dismiss-<?= (int) $o['override_id'] ?>">Dismiss</button></form>
    <?php elseif ($mayAdjust): ?>
        <form method="post" action="/projection/override-remove.php" hx-post="/projection/override-remove.php" hx-target="#flash" hx-confirm="Remove this override? The forecast goes back to the run's." class="d-inline"><?= csrf_field() ?><input type="hidden" name="override" value="<?= (int) $o['override_id'] ?>"><button type="submit" class="btn btn-outline-danger btn-sm btn-touch" id="override-remove-<?= (int) $o['override_id'] ?>">Remove</button></form>
    <?php endif; ?>
</div>
