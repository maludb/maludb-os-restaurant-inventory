<?php /** The supplier's order and delivery days at THIS site (screen `supplier-schedules`). Data: s, rows, windows, site, tz, notice */
$id = (int) $s['supplier_id'];
?>
<?= view('shared/header.php', ['id' => 'supplier-schedules', 'title' => $s['name'] . ' · schedule at ' . $site['name'], 'crumbs' => [['Home', '/'], ['Suppliers', '/suppliers/'], [$s['name'], '/suppliers/' . $id], ['Schedule', null]], 'back' => back_link() ?? ['/suppliers/' . $id, $s['name']]]) ?>
<div class="main-content" id="supplier-schedules-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Add an order day</h5></div><div class="card-body">
        <form method="post" action="/suppliers/schedules/save.php" hx-post="/suppliers/schedules/save.php" hx-target="#flash" id="schedule-form" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="supplier" value="<?= $id ?>">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="schedule-form-field-order_dow">Order on</label><select name="order_dow" id="schedule-form-field-order_dow" class="form-select btn-touch"><?php foreach (DOW as $k => $d): ?><option value="<?= $k ?>"><?= $d ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="schedule-form-field-cutoff_time">By (cut-off)</label><input type="time" name="cutoff_time" id="schedule-form-field-cutoff_time" class="form-control btn-touch" value="13:00" required></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="schedule-form-field-delivery_dow">Delivered on</label><select name="delivery_dow" id="schedule-form-field-delivery_dow" class="form-select btn-touch"><?php foreach (DOW as $k => $d): ?><option value="<?= $k ?>" <?= $k === 2 ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="schedule-form-save-btn">Add</button></div>
            <div class="col-12 fs-12 text-muted">Times are in <?= e($tz) ?>, the site's zone. The suggested order is drafted ahead of each cut-off for the delivery it leads to.</div>
        </form>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="schedules-table"><thead class="thead-light"><tr><th>Schedule</th><th></th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="2" class="text-center text-muted py-4" id="schedules-empty">No schedule at this site yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr id="schedule-row-<?= $r['supplier_schedule_id'] ?>"><td><?= e($r['words']) ?></td><td class="text-end"><form method="post" action="/suppliers/schedules/remove.php" hx-post="/suppliers/schedules/remove.php" hx-target="#flash" hx-confirm="Remove this order day?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="schedule" value="<?= $r['supplier_schedule_id'] ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="schedule-row-<?= $r['supplier_schedule_id'] ?>-remove">Remove</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
        <?php if ($windows !== []): ?><div class="card-body fs-12 text-muted" id="schedule-windows">The next three: <?php foreach ($windows as $w): ?><span class="me-3" id="schedule-window-<?= e(date('Ymd', strtotime((string) $w['cutoff_at']))) ?>"><?= e(format_ts($w['cutoff_at'], $tz, 'D M j H:i')) ?> → <?= e(date('D M j', strtotime((string) $w['delivery_on']))) ?></span><?php endforeach; ?></div><?php endif; ?>
    </div></div>
</div>
