<?php /** The Override form (`override-form`, `override-form-field-{name}`) → forecast_override_set. Data: item, errors */
$today = proj_today(db());
?>
<div class="card" id="override-card"><div class="card-header"><h6 class="mb-0">Override the forecast</h6></div><div class="card-body">
    <form method="post" action="/projection/override.php" hx-post="/projection/override.php" hx-target="#flash" id="override-form"><?= csrf_field() ?>
        <input type="hidden" name="menu_item" value="<?= (int) $item['menu_item_id'] ?>" id="override-form-field-menu_item">
        <div class="row g-2">
            <div class="col-6"><label class="form-label fs-12 text-muted" for="override-form-field-from_date">From</label><input type="date" name="from_date" id="override-form-field-from_date" class="form-control btn-touch" value="<?= e($today) ?>" required></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="override-form-field-to_date">To</label><input type="date" name="to_date" id="override-form-field-to_date" class="form-control btn-touch" value="<?= e($today) ?>" required></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="override-form-field-factor">Factor</label><input type="number" name="factor" id="override-form-field-factor" class="form-control btn-touch" step="0.01" min="0" inputmode="decimal" placeholder="1.5 is 50 % more"></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="override-form-field-qty">or a quantity a day</label><input type="number" name="qty" id="override-form-field-qty" class="form-control btn-touch" step="0.01" min="0" inputmode="decimal" placeholder="e.g. 60"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="override-form-field-reason">Why</label><input type="text" name="reason" id="override-form-field-reason" class="form-control btn-touch" maxlength="200" required placeholder="The festival, a private party…"></div>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="override-save-btn">Set the override</button></div>
        </div>
        <div class="form-text">Give a factor <em>or</em> a quantity, not both. It counts from the moment it is set.</div>
    </form>
</div></div>
