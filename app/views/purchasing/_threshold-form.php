<?php /** The alert thresholds (prices.write): the default % and a category's own. Data: thresholds (default, categories) */ ?>
<div class="card mb-3" id="alert-thresholds"><div class="card-header"><h5 class="card-title mb-0">When a cost counts as moved</h5></div><div class="card-body">
    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <form method="post" action="/purchasing/alerts/threshold.php" hx-post="/purchasing/alerts/threshold.php" hx-target="#flash" id="alert-threshold-form">
                <?= csrf_field() ?>
                <label class="form-label fs-12 text-muted" for="alert-threshold-pct">Everywhere, unless a category says otherwise</label>
                <div class="input-group"><input type="number" step="any" min="0" max="1000" inputmode="decimal" name="pct" id="alert-threshold-pct" class="form-control btn-touch" value="<?= e(fmt_input($thresholds['default'], 2)) ?>" required><span class="input-group-text">%</span><button type="submit" class="btn btn-primary btn-touch" id="alert-threshold-submit">Save</button></div>
            </form>
        </div>
        <div class="col-12 col-lg-7">
            <form method="post" action="/purchasing/alerts/threshold.php" hx-post="/purchasing/alerts/threshold.php" hx-target="#flash" id="alert-category-form">
                <?= csrf_field() ?>
                <label class="form-label fs-12 text-muted" id="alert-category-label">One category's own (leave the % empty to put it back to the default)</label>
                <div class="row g-2">
                    <div class="col-12 col-md-7"><?= picker_field(['id' => 'alert-category-field', 'name' => 'category', 'source' => 'category', 'value' => '', 'labelledby' => 'alert-category-label']) ?></div>
                    <div class="col-8 col-md-3"><div class="input-group"><input type="number" step="any" min="0" max="1000" inputmode="decimal" name="category_pct" id="alert-category-pct" class="form-control btn-touch" aria-label="The category's threshold"><span class="input-group-text">%</span></div></div>
                    <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="alert-category-submit">Set</button></div>
                </div>
            </form>
            <?php $own = array_filter($thresholds['categories'], static fn (array $c): bool => $c['price_alert_pct'] !== null); if ($own !== []): ?>
            <div class="fs-12 text-muted mt-2" id="alert-category-list">Own thresholds: <?php foreach ($own as $c): ?><span class="me-2"><?= e($c['name']) ?> <?= e(fmt_num($c['price_alert_pct'], 2)) ?> %</span><?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div></div>
