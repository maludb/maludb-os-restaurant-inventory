<?php /** The modifier form (screen modifier-map; ids modifier-form-field-{name}) and the per-item overrides. Data: m, parents, back, notice */ $id = (int) $m['modifier_id']; ?>
<?= view('shared/header.php', ['id' => 'modifier-map', 'title' => 'Map ' . $m['code'] . ' · ' . $m['name'], 'crumbs' => [['Home', '/'], ['Modifiers', '/modifiers/'], [$m['name'], null]], 'back' => $back ?? ['/modifiers/', 'Modifiers']]) ?>
<div class="main-content" id="modifier-map-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/modifiers/map.php" hx-post="/modifiers/map.php" hx-target="#flash" class="card mb-3" id="modifier-form"><?= csrf_field() ?><input type="hidden" name="modifier" value="<?= $id ?>">
        <div class="card-body">
            <div class="row mb-3"><div class="col-lg-4"><label class="fw-semibold">Action</label></div><div class="col-lg-8" id="modifier-form-field-action">
                <?php foreach (['add' => 'add: the modifier recipe\'s lines are depleted on top', 'remove' => 'remove: the modifier recipe\'s lines are taken back from the parent', 'swap' => 'swap: one thing out, the modifier recipe\'s in', 'none' => 'none: cosmetic — nothing moves'] as $k => $l): ?><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="modifier-form-field-action-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="action" value="<?= $k ?>" id="modifier-form-field-action-<?= $k ?>" <?= $m['action'] === $k ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div></div>
            <?= form_picker('modifier-form', 'recipe', 'Modifier recipe', 'recipe', $m['recipe_id'], [], ['params' => ['kind' => 3], 'help' => 'Required unless the action is none.', 'create' => ['label' => 'New modifier recipe', 'url' => with_back('/recipes/new?kind=modifier', here_url())]]) ?>
            <div class="row mb-3 align-items-center"><div class="col-lg-4"><label class="fw-semibold" for="modifier-form-field-qty-factor">Quantity factor</label></div><div class="col-lg-8"><input type="number" step="0.01" min="0.01" name="qty_factor" id="modifier-form-field-qty-factor" class="form-control btn-touch" value="<?= e(ri_fmt($m['qty_factor'], 2)) ?>"><div class="form-text">"Double" is a factor of 2 on the modifier recipe's lines.</div></div></div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch" id="modifier-form-save">Save</button></div>
    </form>
    <div class="card" id="modifier-per-item"><div class="card-header"><h5 class="card-title mb-0">Per menu item</h5></div>
        <div class="card-body fs-12 text-muted">"No tomato" means a different quantity on the wedge and on the burger: an override per parent names its own modifier recipe and factor.</div>
        <div class="table-responsive"><table class="table align-middle mb-0" id="per-item-table"><thead><tr><th>Menu item</th><th>This item's recipe and factor</th></tr></thead><tbody>
            <?php foreach ($parents as $p): ?><?= view('modifiers/partials/per-item-row.php', ['p' => $p, 'modifierId' => $id]) ?><?php endforeach; ?>
            <tr id="per-item-add"><td colspan="2"><form method="post" action="/modifiers/map.php" hx-post="/modifiers/map.php" hx-target="#flash" class="d-flex gap-1 align-items-center flex-wrap" id="per-item-add-form"><?= csrf_field() ?><input type="hidden" name="modifier" value="<?= $id ?>">
                <div style="min-width: 14rem"><?= picker_field(['id' => 'per-item-add-field-menu-item', 'name' => 'menu_item', 'source' => 'menu_item', 'value' => '', 'placeholder' => 'Add a menu item', 'params' => $m['pos_source_id'] !== null ? ['source' => (int) $m['pos_source_id']] : []]) ?></div>
                <div style="min-width: 14rem"><?= picker_field(['id' => 'per-item-add-field-parent-recipe', 'name' => 'parent_recipe', 'source' => 'recipe', 'value' => '', 'params' => ['kind' => 3], 'placeholder' => "the modifier's own recipe"]) ?></div>
                <input type="number" step="0.01" min="0.01" name="parent_qty_factor" class="form-control btn-touch" style="width: 6rem" placeholder="factor" aria-label="Factor" id="per-item-add-field-parent-qty-factor">
                <button type="submit" class="btn btn-light-brand btn-touch" id="per-item-add-btn">Add</button></form></td></tr>
        </tbody></table></div></div>
</div>
