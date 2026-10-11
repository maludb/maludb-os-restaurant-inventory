<?php /** The global units by class (screen `unit-list`). Data: rows, edit (a code being edited), notice */ ?>
<?= view('shared/header.php', ['id' => 'unit-list', 'title' => 'Units', 'crumbs' => [['Home', '/'], ['Units', null]]]) ?>
<div class="main-content" id="unit-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Add a unit</h5></div><div class="card-body">
        <form method="post" action="/units/save.php" hx-post="/units/save.php" hx-target="#flash" id="global-unit-form" class="row g-2 align-items-end"><?= csrf_field() ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="global-unit-form-field-code">Code</label><input type="text" name="code" id="global-unit-form-field-code" class="form-control btn-touch" maxlength="16" pattern="[a-z][a-z0-9_]*" required placeholder="gal_uk"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="global-unit-form-field-name">Name</label><input type="text" name="name" id="global-unit-form-field-name" class="form-control btn-touch" maxlength="60" required></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="global-unit-form-field-measure_class">Class</label><select name="measure_class" id="global-unit-form-field-measure_class" class="form-select btn-touch"><?php foreach (MEASURE_CLASSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?> (<?= CLASS_BASE[$k] ?>)</option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="global-unit-form-field-to_base_factor">Base units in one</label><input type="number" step="any" min="0" name="to_base_factor" id="global-unit-form-field-to_base_factor" class="form-control btn-touch" required placeholder="4546.09"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="global-unit-form-field-display_order">Order</label><input type="number" min="0" name="display_order" id="global-unit-form-field-display_order" class="form-control btn-touch" value="100"></div>
            <div class="col-6 col-md-1"><button type="submit" class="btn btn-primary btn-touch w-100" id="global-unit-form-save-btn">Add</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="units-table"><thead class="thead-light"><tr><th>Code</th><th>Name</th><th>Class</th><th>Factor</th><th>Order</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $u): $c = $u['unit_code']; $editing = $edit === $c; ?>
        <tr id="unit-row-<?= e($c) ?>" class="<?= $u['active'] ? '' : 'text-muted' ?>">
            <?php if ($editing): ?>
            <td colspan="6"><form method="post" action="/units/save.php" hx-post="/units/save.php" hx-target="#flash" class="row g-2 align-items-end" id="unit-row-<?= e($c) ?>-form"><?= csrf_field() ?><input type="hidden" name="unit" value="<?= e($c) ?>">
                <div class="col-6 col-md-2"><code><?= e($c) ?></code> <?= $u['is_base'] ? '★' : '' ?></div>
                <div class="col-6 col-md-3"><input type="text" name="name" class="form-control btn-touch" value="<?= e($u['name']) ?>" maxlength="60" aria-label="Name"></div>
                <div class="col-6 col-md-2"><?= e(MEASURE_CLASSES[$u['measure_class']]) ?></div>
                <div class="col-6 col-md-2"><input type="number" step="any" min="0" name="to_base_factor" class="form-control btn-touch" value="<?= e(rtrim(rtrim((string) $u['to_base_factor'], '0'), '.')) ?>" <?= $u['is_base'] ? 'readonly' : '' ?> aria-label="Factor"></div>
                <div class="col-3 col-md-1"><input type="number" min="0" name="display_order" class="form-control btn-touch" value="<?= (int) $u['display_order'] ?>" aria-label="Order"></div>
                <div class="col-3 col-md-1"><input type="hidden" name="active" value="no"><label class="fs-12"><input type="checkbox" class="form-check-input" name="active" value="yes" <?= $u['active'] ? 'checked' : '' ?> <?= $u['is_base'] ? 'disabled' : '' ?>> active</label></div>
                <div class="col-6 col-md-1"><button type="submit" class="btn btn-primary btn-touch w-100">Save</button></div></form></td>
            <?php else: ?>
            <td><code><?= e($c) ?></code> <?= $u['is_base'] ? '★ base' : '' ?></td><td><?= e($u['name']) ?></td><td><?= e(MEASURE_CLASSES[$u['measure_class']]) ?></td><td>1 <?= e($c) ?> = <?= e(fmt_num($u['to_base_factor'], 4)) ?> <?= e(CLASS_BASE[$u['measure_class']]) ?></td><td><?= (int) $u['display_order'] ?></td>
            <td class="text-end"><?= hx_link('/units/?unit=' . rawurlencode($c), 'Edit', 'btn btn-light btn-sm btn-touch', 'id="unit-row-' . e($c) . '-edit"') ?><?= !$u['active'] ? ' ' . chip('retired', 'dark') : '' ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
