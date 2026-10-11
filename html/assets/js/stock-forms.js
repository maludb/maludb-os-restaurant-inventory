/* stock-forms.js — the ledger's forms (ledger.md "Files"): when the record picker chooses an ingredient (or a supplier item) on a receiving,
   transfer, adjustment or waste form, the unit select is re-listed for that ingredient (GET /stock/unit-options) and the catch-weight and
   temperature fields show or hide; the production form's preview follows the recipe and the batches. No framework; the forms work without it
   (every field rendered, the irrelevant ones only hidden when the ingredient is known). Loaded once per page; delegated, so HTMX swaps keep it. */
(function () {
    if (window.__stockForms) { return; }
    window.__stockForms = true;
    function formOf(el) { return el && el.closest ? el.closest('[data-stock-form]') : null; }
    function refresh(form, ingredient, supplierItem) {
        if (!form) { return; }
        var purpose = form.getAttribute('data-stock-form') || 'receive';
        var unit = form.querySelector('select.stock-unit[name="unit"], select.stock-unit[name="qty_unit"]');
        var url = '/stock/unit-options?purpose=' + encodeURIComponent(purpose) + '&ingredient=' + encodeURIComponent(ingredient || '') + '&supplier_item=' + encodeURIComponent(supplierItem || '');
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (!j || !j.data) { return; }
            var d = j.data;
            if (unit && d.options) {
                var keep = unit.value;
                unit.innerHTML = '';
                d.options.forEach(function (o) { var opt = document.createElement('option'); opt.value = o.code; opt.textContent = o.label; unit.appendChild(opt); });
                var want = d.default_unit || keep;
                if ([].some.call(unit.options, function (o) { return o.value === want; })) { unit.value = want; }
            }
            if (d.ingredient_id) { var ing = form.querySelector('[data-stock-ingredient]'); if (ing && !ing.value) { ing.value = String(d.ingredient_id); var lab = form.querySelector('#' + ing.id + '-open .record-picker-label'); if (lab && d.ingredient) { lab.textContent = d.ingredient; lab.classList.remove('text-muted'); } } }
            var cw = form.querySelector('.stock-cw'); if (cw) { cw.classList.toggle('d-none', !d.catch_weight); }
            var tp = form.querySelector('.stock-temp'); if (tp) { tp.classList.toggle('d-none', !d.temperature); }
            var weigh = form.querySelector('.stock-weigh'); if (weigh) { weigh.classList.toggle('d-none', d.measure_class !== 'weight'); }
            var cost = form.querySelector('input[name="unit_cost"], input[name="unit_price"]'); if (cost && d.unit_cost && !cost.value) { cost.value = d.unit_cost; }
        });
    }
    function init(root) {
        (root || document).querySelectorAll('[data-stock-form]').forEach(function (form) {
            if (form.__stockInit) { return; }
            form.__stockInit = true;
            var ing = form.querySelector('[data-stock-ingredient]');
            var si = form.querySelector('[data-stock-supplier-item]');
            if (ing && !ing.value && (!si || !si.value)) {
                var cw = form.querySelector('.stock-cw'); if (cw) { cw.classList.add('d-none'); }
                var tp = form.querySelector('.stock-temp'); if (tp) { tp.classList.add('d-none'); }
            }
        });
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        if (t.matches('[data-stock-ingredient]')) { refresh(formOf(t), t.value, ''); }
        else if (t.matches('[data-stock-supplier-item]')) { refresh(formOf(t), '', t.value); }
        else if (t.matches('[data-stock-waste-recipe]')) {
            var wf = formOf(t); var wu = wf && wf.querySelector('select.stock-unit[name="unit"]');
            if (wu && t.value) { wu.innerHTML = '<option value="portion">portion</option><option value="batch">batch</option>'; }
        }
        else if (t.matches('[data-stock-recipe], [data-stock-batches]')) {
            var f = formOf(t); var r = f && f.querySelector('[data-stock-recipe]'); var b = f && f.querySelector('[data-stock-batches]'); var out = document.getElementById('production-preview');
            if (r && r.value && out) { fetch('/production/preview?recipe=' + encodeURIComponent(r.value) + '&batches=' + encodeURIComponent(b ? b.value : '1'), { headers: { 'HX-Request': 'true' }, credentials: 'same-origin' }).then(function (x) { return x.text(); }).then(function (h) { out.innerHTML = h; }); }
        }
    });
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-stock-stage]') : null;
        if (!b) { return; }
        var f = formOf(b); if (!f) { return; }
        var stage = b.getAttribute('data-stock-stage');
        f.querySelectorAll('.stock-for-ingredient').forEach(function (x) { x.classList.toggle('d-none', stage !== 'ingredient' && stage !== 'prep'); });
        f.querySelectorAll('.stock-for-recipe').forEach(function (x) { x.classList.toggle('d-none', stage === 'ingredient'); });
    });
    document.addEventListener('DOMContentLoaded', function () { init(document); });
    document.body && document.body.addEventListener('htmx:afterSwap', function (e) { init(e.target); });
    init(document);
})();
