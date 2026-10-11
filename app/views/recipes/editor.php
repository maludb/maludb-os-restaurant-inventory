<?php /** THE RECIPE EDITOR (screen recipe-version-edit): a draft's batch form, its lines in place, the add row with the picker and the conversion sentence, the cost panel, activation with its preview, discard. Data: recipe, version, lines, cost, seesCost, units, preview, back, notice */
$v = $version; $vid = (int) $v['recipe_version_id']; $rid = (int) $recipe['recipe_id']; ?>
<?= view('shared/header.php', ['id' => 'recipe-version-edit', 'title' => $recipe['name'] . ' — draft v' . (int) $v['version_no'], 'crumbs' => [['Home', '/'], ['Recipes', '/recipes/'], [$recipe['name'], '/recipes/' . $rid], ['Draft v' . (int) $v['version_no'], null]], 'back' => $back ?? ['/recipes/' . $rid, 'the recipe']]) ?>
<div class="main-content" id="editor" data-version-id="<?= $vid ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-8 col-xl-9">
            <div class="card mb-3" id="editor-batch"><div class="card-header"><h5 class="card-title mb-0">The batch</h5></div><div class="card-body"><?= view('recipes/partials/version-form.php', ['version' => $v, 'recipe' => $recipe, 'units' => $units]) ?></div></div>
            <div class="card mb-3" id="editor-lines"><div class="card-header"><h5 class="card-title mb-0">Lines</h5></div>
                <div class="table-responsive"><table class="table align-middle mb-0" id="editor-lines-table"><thead><tr><th style="width: 3.5rem">#</th><th style="width: 11rem">Ingredient / recipe</th><th>Quantity · unit · yield · group</th><th></th></tr></thead>
                    <tbody id="editor-lines-body">
                        <?php foreach ($lines as $l): ?><?= view('recipes/partials/editor-row.php', ['l' => $l, 'version' => $v]) ?><?php endforeach; ?>
                        <?= view('recipes/partials/editor-add-row.php', ['version' => $v, 'recipe' => $recipe]) ?>
                    </tbody></table></div>
                <?php if ($lines === []): ?><div class="card-footer fs-12 text-muted" id="editor-no-lines">No lines yet — add the first one above.</div><?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4 col-xl-3">
            <div class="editor-side">
            <?= view('recipes/partials/cost-panel.php', ['cost' => $cost, 'seesCost' => $seesCost, 'lines' => $lines, 'recipe' => $recipe, 'version' => $v]) ?>
            <div class="card mt-3" id="editor-activate"><div class="card-header"><h5 class="card-title mb-0">Activate</h5></div><div class="card-body">
                <form method="post" action="/recipes/versions/activate.php" hx-post="/recipes/versions/activate.php" hx-target="#flash" hx-confirm="Activate v<?= (int) $v['version_no'] ?> of <?= e($recipe['name']) ?>? Every cost follows from the date." id="activate-form">
                    <?= csrf_field() ?><input type="hidden" name="version" value="<?= $vid ?>">
                    <label class="form-label fs-12 text-muted" for="activate-form-field-effective-from">In force from</label>
                    <input type="date" name="effective_from" id="activate-form-field-effective-from" class="form-control btn-touch mb-2" value="<?= date('Y-m-d') ?>" hx-get="/recipes/activate-preview?version=<?= $vid ?>" hx-trigger="change" hx-target="#activate-preview" hx-include="this" hx-vals='js:{from: document.getElementById("activate-form-field-effective-from").value}'>
                    <div class="fs-12 mb-2" id="activate-preview"><?= view('recipes/partials/activate-preview.php', ['r' => $preview]) ?></div>
                    <button type="submit" class="btn btn-success btn-touch w-100" id="activate-form-btn"><i class="feather-check me-1"></i>Activate from the date</button>
                </form>
            </div></div>
            <form method="post" action="/recipes/versions/discard.php" hx-post="/recipes/versions/discard.php" hx-target="#flash" hx-confirm="Discard draft v<?= (int) $v['version_no'] ?> and its lines?" class="mt-3" id="discard-draft"><?= csrf_field() ?><input type="hidden" name="version" value="<?= $vid ?>"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="discard-draft-btn">Discard the draft</button></form>
            </div>
        </div>
    </div>
</div>
<script src="/assets/vendors/sortablejs/Sortable.min.js"></script>
<script>
(function () {
    var kind = document.querySelectorAll('input[name="_kind"]');
    function toggle() { var rec = document.getElementById('line-form-field-kind-recipe').checked; document.getElementById('line-form-pick-ingredient').classList.toggle('d-none', rec); document.getElementById('line-form-pick-recipe').classList.toggle('d-none', !rec);
        var ing = document.getElementById('line-form-field-ingredient'), sub = document.getElementById('line-form-field-sub-recipe'); if (rec) { ing.value = ''; } else { sub.value = ''; } document.body.dispatchEvent(new Event('pickChanged')); }
    kind.forEach(function (r) { r.addEventListener('change', toggle); });
    document.addEventListener('change', function (e) { if (e.target && (e.target.id === 'line-form-field-ingredient' || e.target.id === 'line-form-field-sub-recipe')) { document.body.dispatchEvent(new Event('pickChanged')); } });
    // after a line is added, the add row's fields clear and the cost panel refreshes (HX-Trigger recipeChanged from the handler)
    document.body.addEventListener('htmx:afterSwap', function (e) { if (e.detail && e.detail.target && e.detail.target.id === 'editor-add-row') { var f = document.getElementById('line-form'); if (f) { f.querySelector('#line-form-field-qty').value = ''; } } });
    if (window.Sortable && document.getElementById('editor-lines-body')) {
        new Sortable(document.getElementById('editor-lines-body'), { handle: '.editor-handle', draggable: '.editor-line-row', animation: 120, onEnd: function () {
            var ids = Array.prototype.map.call(document.querySelectorAll('#editor-lines-body .editor-line-row'), function (r) { return r.dataset.lineId; });
            var body = new URLSearchParams(); body.append('csrf_token', document.getElementById('csrf-token-meta').content); body.append('version', document.getElementById('editor').dataset.versionId); ids.forEach(function (i) { body.append('lines[]', i); });
            fetch('/recipes/versions/reorder.php', { method: 'POST', body: body, headers: { 'HX-Request': 'true' }, credentials: 'same-origin' }).then(function () { document.querySelectorAll('#editor-lines-body .editor-line-row .editor-seq').forEach(function (s, k) { s.textContent = k + 1; }); });
        } });
    }
})();
</script>
