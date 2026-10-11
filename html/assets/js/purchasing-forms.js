/* purchasing-forms.js — the invoice line form (purchasing.md "invoice-edit"): when the record picker chooses a supplier item (or an ingredient) the
   "receipt line it bills" select narrows to the lines of that ingredient (the select is rendered with every line of the billed receipt, each tagged with
   its ingredient; the unit and price are stock-forms.js's). No framework; the form works without it (every line stays listed, "Find it for me" is the
   default). Loaded once; delegated, so HTMX swaps keep it. */
(function () {
    if (window.__purchasingForms) { return; }
    window.__purchasingForms = true;
    function narrow(sel, ingredient) {
        if (!sel) { return; }
        [].forEach.call(sel.options, function (o) {
            if (!o.value) { o.hidden = false; o.disabled = false; return; }
            var show = !ingredient || o.getAttribute('data-ing') === String(ingredient);
            o.hidden = !show;
            o.disabled = !show;
        });
        if (sel.selectedOptions.length && sel.selectedOptions[0].disabled) { sel.value = ''; }
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        var form = t.closest ? t.closest('[data-invoice-line-form]') : null;
        if (!form) { return; }
        var sel = form.querySelector('select[data-narrow-receipt-lines]');
        if (t.matches('[data-stock-supplier-item]')) {
            if (!t.value) { narrow(sel, ''); return; }
            fetch('/stock/unit-options?purpose=receive&supplier_item=' + encodeURIComponent(t.value), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { if (j && j.data && j.data.ingredient_id) { narrow(sel, j.data.ingredient_id); } });
        } else if (t.matches('[data-stock-ingredient]')) {
            narrow(sel, t.value);
        }
    });
})();
