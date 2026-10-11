/* count-sheet.js — the entries that survive a dead signal (counts.md "count-sheet.js", exactly). Each unsent entry is kept in localStorage under
   ri.count.<count_id>.<line_id> as {payload, at}, POSTed to /counts/line.php with fetch and removed when the server says 200 (the row is swapped for the
   server's own partial) or 422 (the sentence is shown under the line; the person must fix it). A network error or a 5xx keeps the key, marks the row
   line-pending (an amber dot) and shows the banner #count-offline. A resend runs on `online`, on the page becoming visible and every 20 s while keys
   exist, oldest first, one at a time; on page load any key is replayed first. Without JavaScript every input pair is its own <form> with a Save button.
   The keys are a queue, not a store: the server is the truth. The script never computes a quantity in base units or a variance — the server answers both.
   The sheet editor's drag (SortableJS on .sheet-lines → count_sheet_reorder) lives here too. Delegated, loaded once; boot() runs at every execution. */
(function () {
    'use strict';
    if (window.__countSheet) { window.__countSheet.boot(); return; }
    var PREFIX = 'ri.count.';
    var failing = false, busy = false, seq = 0, timers = {};
    var S = window.__countSheet = { boot: boot };
    document.documentElement.classList.add('count-js');

    function store() { try { return window.localStorage; } catch (e) { return null; } }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function keyOf(count, line) { return PREFIX + count + '.' + line; }
    function allKeys() {
        var ls = store(), out = [];
        if (!ls) { return out; }
        for (var i = 0; i < ls.length; i++) {
            var k = ls.key(i);
            if (k && k.indexOf(PREFIX) === 0) {
                try { var e = JSON.parse(ls.getItem(k)); if (e && e.payload) { out.push({ key: k, entry: e }); } } catch (x) { /* a damaged key is not ours to send */ }
            }
        }
        out.sort(function (a, b) { return a.entry.at - b.entry.at || (a.entry.v || 0) - (b.entry.v || 0); });   // oldest first: by when a line was first typed
        return out;
    }
    function rowOf(line) { return document.getElementById('count-line-' + line); }
    function countOfPage() { var el = document.getElementById('count-lines'); return el ? el.getAttribute('data-count') : null; }

    // ---- the amber dots and the banner
    function mark(line, on) {
        var row = rowOf(line);
        if (row) { row.classList.toggle('line-pending', on); }
    }
    function banner() {
        var el = document.getElementById('count-offline');
        var keys = allKeys();
        keys.forEach(function (k) { mark(k.entry.payload.count_line, failing); });
        if (!el) { return; }
        if (failing && keys.length) {
            el.textContent = keys.length + (keys.length === 1 ? ' entry saved on this phone. It is sent when the signal returns.' : ' entries saved on this phone. They are sent when the signal returns.');
            el.hidden = false;
        } else {
            el.hidden = true;
            el.textContent = '';
        }
    }

    // ---- building a line's payload from its inputs
    function payloadFor(row, state) {
        var p = { count_line: row.getAttribute('data-line') };
        if (state) { p.state = state; return p; }
        var q = function (f) { var el = row.querySelector('[data-field="' + f + '"]'); return el ? el.value : null; };
        ['whole', 'whole_unit', 'loose', 'loose_unit'].forEach(function (f) { var v = q(f); if (v !== null) { p[f] = v; } });
        var g = q('gross'); if (g !== null) { p.gross_weight_g = g; }
        var c = q('container'); if (c) { p.container = c; }
        return p;
    }
    function hasEntry(p) { return !!(p.state || (p.whole && p.whole !== '') || (p.loose && p.loose !== '') || (p.gross_weight_g && p.gross_weight_g !== '')); }

    function enqueue(row, payload) {
        var ls = store(), count = row.getAttribute('data-count') || countOfPage(), line = payload.count_line;
        var key = keyOf(count, line), prev = null;
        if (ls) { try { prev = JSON.parse(ls.getItem(key)); } catch (e) { prev = null; } }
        if (prev && prev.payload && JSON.stringify(prev.payload) === JSON.stringify(payload)) { return; }       // the same entry again (a debounce after a change): nothing new to send
        var entry = { payload: payload, at: prev && prev.at ? prev.at : Date.now(), v: ++seq + Date.now() };    // `at` keeps the place in the queue; `v` tells a newer entry from the one in flight
        if (ls) { try { ls.setItem(key, JSON.stringify(entry)); } catch (e) { /* storage full or blocked: the send still goes */ } }
        setError(line, '');
        pump(ls ? null : { key: null, entry: entry });
    }

    // ---- sending, oldest first, one at a time
    function pump(only) {
        if (busy) { return; }
        var next = only || allKeys()[0];
        if (!next) { failing = false; banner(); return; }
        busy = true;
        send(next).then(function (ok) {
            busy = false;
            if (ok) { pump(); } else { failing = true; banner(); }
        });
    }
    function send(item) {
        var body = new URLSearchParams();
        Object.keys(item.entry.payload).forEach(function (k) { body.set(k, item.entry.payload[k]); });
        return fetch('/counts/line.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'HX-Request': 'true' }, body: body.toString() })
            .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t, progress: r.headers.get('X-Count-Progress') }; }); })
            .then(function (res) {
                var line = item.entry.payload.count_line, ls = store(), cur = null;
                if (ls && item.key) { try { cur = JSON.parse(ls.getItem(item.key)); } catch (e) { cur = null; } }
                var stale = cur && cur.v !== item.entry.v;                    // typed again while this one was in flight: leave the newer key to be sent
                if (res.status === 200) {
                    if (!stale && ls && item.key) { ls.removeItem(item.key); }
                    if (!stale) { applyRow(line, res.text); applyProgress(res.progress); mark(line, false); }
                    failing = false;
                    banner();
                    return true;
                }
                if (res.status === 422 || res.status === 404) {
                    if (!stale && ls && item.key) { ls.removeItem(item.key); }
                    setError(line, sentence(res.text));
                    mark(line, false);
                    failing = false;
                    banner();
                    return true;
                }
                return false;                                                     // 401/403/5xx: keep the key, try again
            })
            .catch(function () { return false; });                                // no network: keep the key
    }
    function sentence(html) {
        try {
            var d = new DOMParser().parseFromString(html, 'text/html');
            var m = d.querySelector('#flash-message') || d.querySelector('.alert') || d.body;
            return (m.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 300) || 'That could not be saved.';
        } catch (e) { return 'That could not be saved.'; }
    }
    function setError(line, text) {
        var el = document.getElementById('count-line-' + line + '-error');
        if (el) { el.textContent = text; }
    }

    // ---- swapping the row for the server's own
    function applyRow(line, html) {
        var old = rowOf(line);
        if (!old || !html) { return; }
        var tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        var neu = tpl.content.firstElementChild;
        if (!neu || neu.id !== old.id) { return; }
        if (old.contains(document.activeElement) && document.activeElement !== document.body) {
            // the person is still typing in this row: refresh what the server says about it, not the inputs they hold
            ['state', 'total', 'error'].forEach(function (s) {
                var a = document.getElementById('count-line-' + line + '-' + s), b = neu.querySelector('#count-line-' + line + '-' + s);
                if (a && b) { a.replaceWith(b); } else if (!a && b && s === 'total') { var er = document.getElementById('count-line-' + line + '-error'); if (er) { er.before(b); } }
            });
            old.setAttribute('data-state', neu.getAttribute('data-state'));
            old.className = neu.className + (old.classList.contains('line-pending') ? ' line-pending' : '');
            return;
        }
        old.replaceWith(neu);
    }
    function applyProgress(header) {
        if (!header) { return; }
        var p; try { p = JSON.parse(header); } catch (e) { return; }
        var set = function (id, text) { var el = document.getElementById(id); if (el) { el.textContent = text; } };
        set('count-progress-done', String(p.done)); set('count-progress-total', String(p.lines));
        var bar = document.getElementById('count-progress-bar'); if (bar) { bar.style.width = (p.lines ? Math.floor(p.done * 100 / p.lines) : 0) + '%'; }
        var ap = document.getElementById('count-area-progress');
        if (ap && p.area && String(ap.getAttribute('data-area')) === String(p.area.id)) { ap.textContent = p.area.done + ' of ' + p.area.lines; }
        var btn = document.getElementById('count-review-btn'), lines = document.getElementById('count-lines');
        if (btn && lines && lines.getAttribute('data-status') === 'open') { btn.disabled = p.pending > 0; }
        var note = document.getElementById('count-pending-note');
        if (note && p.pending === 0) { note.remove(); }
    }

    // ---- events (delegated)
    function rowFor(el) { return el && el.closest ? el.closest('.count-line') : null; }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches('.count-line-form [data-field]')) { return; }
        var row = rowFor(t); if (!row) { return; }
        var p = payloadFor(row);
        if (hasEntry(p)) { enqueue(row, p); }
    });
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches('.count-line-form input[data-field]')) { return; }
        var row = rowFor(t); if (!row) { return; }
        var line = row.getAttribute('data-line');
        clearTimeout(timers[line]);
        timers[line] = setTimeout(function () { var p = payloadFor(row); if (hasEntry(p) && document.body.contains(row)) { enqueue(row, p); } }, 600);
    });
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.matches || !f.matches('form[data-count-line-form]')) { return; }
        var row = rowFor(f); if (!row) { return; }
        e.preventDefault();
        var st = f.querySelector('input[name="state"]');
        var p = payloadFor(row, st ? st.value : null);
        if (!hasEntry(p)) { setError(row.getAttribute('data-line'), 'Enter a count or a weight, or press 0 or Skip.'); return; }
        clearTimeout(timers[row.getAttribute('data-line')]);
        enqueue(row, p);
    });
    window.addEventListener('online', function () { pump(); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { pump(); } });
    setInterval(function () { if (allKeys().length) { pump(); } }, 20000);

    // ---- on load: replay what was left, show it as pending first
    function boot() {
        var keys = allKeys();
        keys.forEach(function (k) {
            var line = k.entry.payload.count_line, row = rowOf(line);
            if (!row || row.getAttribute('data-count') !== k.key.split('.')[2]) { return; }
            var p = k.entry.payload;
            Object.keys(p).forEach(function (f) {
                var map = { gross_weight_g: 'gross' }, name = map[f] || f, el = row.querySelector('[data-field="' + name + '"]');
                if (el && f !== 'count_line' && f !== 'state') { el.value = p[f]; }
            });
        });
        if (keys.length) { failing = true; banner(); pump(); }
        initSortable(document);
    }

    // ---- the sheet editor: drag lines to shelf order
    function refreshMoves(list) {
        var lines = [].slice.call(list.querySelectorAll('.sheet-line')), ids = lines.map(function (n) { return n.getAttribute('data-line'); });
        lines.forEach(function (n, i) {
            [['up', i - 1], ['down', i + 1]].forEach(function (d) {
                var f = n.querySelector('form[data-move="' + d[0] + '"]'); if (!f) { return; }
                [].slice.call(f.querySelectorAll('input[name="lines[]"]')).forEach(function (x) { x.remove(); });
                var btn = f.querySelector('button'); if (btn) { btn.disabled = d[1] < 0 || d[1] >= ids.length; }
                if (d[1] < 0 || d[1] >= ids.length) { return; }
                var o = ids.slice(); var t = o[i]; o[i] = o[d[1]]; o[d[1]] = t;
                o.forEach(function (id) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'lines[]'; h.value = id; f.insertBefore(h, btn); });
            });
        });
    }
    function initSortable(root) {
        if (!window.Sortable) { return; }
        [].forEach.call((root || document).querySelectorAll('.sheet-lines'), function (list) {
            if (list.__sortable) { return; }
            list.__sortable = true;
            window.Sortable.create(list, {
                handle: '.sheet-handle', draggable: '.sheet-line', animation: 120, forceFallback: true,
                onEnd: function () {
                    var body = new URLSearchParams();
                    body.set('sheet', list.getAttribute('data-sheet')); body.set('area', list.getAttribute('data-area'));
                    [].forEach.call(list.querySelectorAll('.sheet-line'), function (n) { body.append('lines[]', n.getAttribute('data-line')); });
                    refreshMoves(list);
                    fetch('/counts/sheets/reorder.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'HX-Request': 'true' }, body: body.toString() })
                        .then(function (r) { list.setAttribute('data-saved', r.status === 204 ? 'yes' : 'no'); })
                        .catch(function () { list.setAttribute('data-saved', 'no'); });
                }
            });
        });
    }
    document.addEventListener('DOMContentLoaded', boot);
    if (document.body) { document.body.addEventListener('htmx:afterSwap', function () { boot(); }); }
    boot();
})();
