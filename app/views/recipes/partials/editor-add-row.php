<?php /** The add row at the bottom of the editor: a kind toggle, the picker, qty + unit, the conversion sentence, yield, group, optional. Data: version, recipe */
$vid = (int) $version['recipe_version_id']; $rid = (int) $recipe['recipe_id']; ?>
<tr id="editor-add-row">
    <td colspan="4">
        <form method="post" action="/recipes/versions/line.php" hx-post="/recipes/versions/line.php" hx-target="#editor-add-row" hx-swap="beforebegin" id="line-form" class="editor-add-form">
            <?= csrf_field() ?><input type="hidden" name="version" value="<?= $vid ?>">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div class="d-flex gap-1 editor-kind" id="line-form-field-kind"><label class="border rounded px-2 d-flex align-items-center gap-1 btn-touch" for="line-form-field-kind-ingredient"><input type="radio" class="form-check-input mt-0" name="_kind" value="ingredient" id="line-form-field-kind-ingredient" checked> ingredient</label><label class="border rounded px-2 d-flex align-items-center gap-1 btn-touch" for="line-form-field-kind-recipe"><input type="radio" class="form-check-input mt-0" name="_kind" value="recipe" id="line-form-field-kind-recipe"> recipe</label></div>
                <div style="min-width: 14rem" id="line-form-pick-ingredient"><?= picker_field(['id' => 'line-form-field-ingredient', 'name' => 'ingredient', 'source' => 'ingredient', 'value' => '', 'title' => 'Ingredients', 'placeholder' => 'Choose an ingredient']) ?></div>
                <div style="min-width: 14rem" class="d-none" id="line-form-pick-recipe"><?= picker_field(['id' => 'line-form-field-sub-recipe', 'name' => 'sub_recipe', 'source' => 'recipe', 'value' => '', 'title' => 'Recipes', 'placeholder' => 'Choose a recipe', 'params' => ['exclude' => $rid]]) ?></div>
                <input type="number" step="0.0001" min="0.0001" name="qty" class="form-control btn-touch" id="line-form-field-qty" placeholder="qty" style="width: 7rem" aria-label="Quantity" required>
                <select name="unit" class="form-select btn-touch" id="line-form-field-unit" style="width: 10rem" aria-label="Unit" hx-get="/recipes/line-units" hx-target="this" hx-trigger="pickChanged from:body" hx-include="#line-form-field-ingredient, #line-form-field-sub-recipe" hx-swap="innerHTML"><option value="">pick first</option></select>
                <div class="input-group" style="width: 7rem"><input type="number" step="0.01" min="0.01" max="100" name="line_yield_pct" value="100" class="form-control btn-touch" id="line-form-field-line-yield-pct" aria-label="Line yield"><span class="input-group-text">%</span></div>
                <input type="text" name="group_name" class="form-control btn-touch" id="line-form-field-group-name" placeholder="group" maxlength="40" style="width: 7rem" aria-label="Group">
                <label class="border rounded px-2 d-flex align-items-center gap-1 btn-touch" for="line-form-field-optional"><input type="hidden" name="optional" value="no"><input type="checkbox" class="form-check-input mt-0" name="optional" value="yes" id="line-form-field-optional"> optional</label>
                <button type="submit" class="btn btn-primary btn-touch" id="line-form-add-btn"><i class="feather-plus me-1"></i>Add line</button>
            </div>
            <div class="fs-12 mt-1" id="editor-conversion" hx-get="/recipes/convert" hx-target="this" hx-trigger="keyup changed delay:300ms from:#line-form-field-qty, change from:#line-form-field-unit, pickChanged from:body" hx-include="#line-form-field-ingredient, #line-form-field-sub-recipe, #line-form-field-qty, #line-form-field-unit" hx-swap="innerHTML"><span class="text-muted">Pick, type a quantity and choose its unit — the conversion is said here.</span></div>
        </form>
    </td>
</tr>
