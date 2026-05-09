(function () {
    'use strict';

    if (!document.getElementById('oe-app')) return;

    var SVG_NS = 'http://www.w3.org/2000/svg';

    var state = {
        floors: [],             // [{b, f, floor_img, aparts:[...]}]
        outlines: {},           // { "<key>": { polygon:[[x,y],...], raster_url } }
        currentFloorKey: null,  // "1-16"
        selectedAptKey: null,   // "1-16-17101"
        mode: 'pan',            // 'pan' | 'draw'
        draftPoints: [],        // current polygon being drawn, [[x,y]] in 0..100
        dirty: false,           // unsaved changes
    };

    var els = {
        floorSelect:  document.getElementById('oe-floor-select'),
        modePanBtn:   document.getElementById('oe-mode-pan'),
        modeDrawBtn:  document.getElementById('oe-mode-draw'),
        finishBtn:    document.getElementById('oe-finish'),
        undoBtn:      document.getElementById('oe-undo'),
        deleteBtn:    document.getElementById('oe-delete'),
        saveBtn:      document.getElementById('oe-save'),
        statusEl:     document.getElementById('oe-status'),
        stage:        document.getElementById('oe-stage'),
        raster:       document.getElementById('oe-raster'),
        svg:          document.getElementById('oe-svg'),
        apartList:    document.getElementById('oe-apart-list'),
    };

    // -- bootstrap ------------------------------------------------------------

    function api(action, body) {
        var url = window.OE_API_URL + '?action=' + encodeURIComponent(action);
        var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin' };
        if (body) {
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body    = JSON.stringify(body);
        }
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    function status(msg, kind) {
        els.statusEl.textContent = msg || '';
        els.statusEl.className = 'oe-status' + (kind ? ' oe-' + kind : '');
    }

    function load() {
        status('Загрузка…');
        api('load').then(function (resp) {
            if (!resp.ok) throw new Error(resp.error || 'load failed');
            state.floors   = resp.floors || [];
            state.outlines = (resp.outlines && resp.outlines.outlines) || {};
            populateFloorSelect();
            if (state.floors.length) selectFloor(floorKeyOf(state.floors[0]));
            status('Готов', 'ok');
        }).catch(function (e) {
            status('Ошибка загрузки: ' + e.message, 'err');
        });
    }

    function floorKeyOf(fl) { return fl.b + '-' + fl.f; }

    function populateFloorSelect() {
        els.floorSelect.innerHTML = '';
        var BLD = { 1: 'Madison', 2: 'Manhattan', 3: 'Soho' };
        state.floors.forEach(function (fl) {
            var opt = document.createElement('option');
            opt.value = floorKeyOf(fl);
            var bld = BLD[fl.b] || ('Корпус ' + fl.b);
            opt.textContent = bld + ' — этаж ' + fl.f + ' (' + fl.aparts.length + ' квартир)';
            els.floorSelect.appendChild(opt);
        });
    }

    function findFloor(key) {
        return state.floors.find(function (fl) { return floorKeyOf(fl) === key; });
    }

    function selectFloor(key) {
        if (state.dirty && !confirm('Несохранённые изменения будут потеряны. Продолжить?')) {
            els.floorSelect.value = state.currentFloorKey;
            return;
        }
        state.currentFloorKey = key;
        state.selectedAptKey = null;
        state.draftPoints = [];
        state.mode = 'pan';
        state.dirty = false;
        var fl = findFloor(key);
        if (fl && fl.floor_img) {
            els.raster.src = fl.floor_img;
            els.stage.classList.add('oe-has-img');
        } else {
            els.raster.removeAttribute('src');
            els.stage.classList.remove('oe-has-img');
        }
        renderApartList();
        renderSvg();
        updateButtons();
        els.floorSelect.value = key;
    }

    // -- list -----------------------------------------------------------------

    function renderApartList() {
        els.apartList.innerHTML = '';
        var fl = findFloor(state.currentFloorKey);
        if (!fl) return;
        fl.aparts.forEach(function (a) {
            var li = document.createElement('li');
            li.className = 'oe-apart-item';
            if (state.outlines[a.key]) li.classList.add('oe-apart-item--has-outline');
            if (state.selectedAptKey === a.key) li.classList.add('oe-apart-item--selected');
            li.dataset.key = a.key;
            li.innerHTML =
                '<span class="oe-apart-item__name">' + escapeHtml(a.tr_n || a.key) + '</span>' +
                '<span class="oe-apart-item__sq">' + a.sq.toFixed(1) + ' м²</span>' +
                '<span class="oe-apart-item__mark">' + (state.outlines[a.key] ? '✓' : '') + '</span>';
            li.addEventListener('click', function () { selectApt(a.key); });
            els.apartList.appendChild(li);
        });
    }

    function selectApt(key) {
        if (state.draftPoints.length > 0) {
            if (!confirm('Незавершённый полигон будет отброшен. Продолжить?')) return;
            state.draftPoints = [];
        }
        state.selectedAptKey = key;
        renderApartList();
        renderSvg();
        updateButtons();
    }

    // -- svg / drawing --------------------------------------------------------

    function clientToPct(evt) {
        var rect = els.svg.getBoundingClientRect();
        var x = ((evt.clientX - rect.left) / rect.width)  * 100;
        var y = ((evt.clientY - rect.top)  / rect.height) * 100;
        return [Math.max(0, Math.min(100, x)), Math.max(0, Math.min(100, y))];
    }

    function pointsToAttr(points) {
        return points.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
    }

    function renderSvg() {
        els.svg.innerHTML = '';
        els.svg.setAttribute('viewBox', '0 0 100 100');
        els.svg.setAttribute('preserveAspectRatio', 'none');
        els.svg.classList.toggle('oe-mode-pan', state.mode !== 'draw');

        // saved polygons for current floor
        var fl = findFloor(state.currentFloorKey);
        if (fl) {
            var keys = new Set(fl.aparts.map(function (a) { return a.key; }));
            Object.keys(state.outlines).forEach(function (k) {
                if (!keys.has(k)) return;
                var o = state.outlines[k];
                if (!o.polygon || o.polygon.length < 3) return;
                var poly = document.createElementNS(SVG_NS, 'polygon');
                poly.setAttribute('points', pointsToAttr(o.polygon));
                poly.setAttribute('class', 'oe-poly' + (k === state.selectedAptKey ? ' oe-poly--selected' : ''));
                poly.dataset.key = k;
                poly.addEventListener('click', function (e) {
                    e.stopPropagation();
                    selectApt(k);
                });
                els.svg.appendChild(poly);

                // label
                var c = centroid(o.polygon);
                var label = document.createElementNS(SVG_NS, 'text');
                label.setAttribute('x', c[0]); label.setAttribute('y', c[1]);
                label.setAttribute('class', 'oe-poly-label');
                var tr_n = (fl.aparts.find(function (a) { return a.key === k; }) || {}).tr_n || k;
                label.textContent = tr_n;
                els.svg.appendChild(label);
            });
        }

        // draft polygon (currently being drawn)
        if (state.draftPoints.length > 0) {
            if (state.draftPoints.length >= 2) {
                var draft = document.createElementNS(SVG_NS, 'polyline');
                draft.setAttribute('points', pointsToAttr(state.draftPoints));
                draft.setAttribute('class', 'oe-poly oe-poly--draft');
                draft.setAttribute('fill', 'none');
                els.svg.appendChild(draft);
            }
            state.draftPoints.forEach(function (p) {
                var dot = document.createElementNS(SVG_NS, 'circle');
                dot.setAttribute('cx', p[0]); dot.setAttribute('cy', p[1]);
                dot.setAttribute('r', '0.6');
                dot.setAttribute('class', 'oe-vertex');
                els.svg.appendChild(dot);
            });
        }
    }

    function centroid(pts) {
        var sx = 0, sy = 0;
        pts.forEach(function (p) { sx += p[0]; sy += p[1]; });
        return [sx / pts.length, sy / pts.length];
    }

    // -- mode + actions -------------------------------------------------------

    function setMode(m) {
        state.mode = m;
        els.modePanBtn.classList.toggle('oe-btn--active', m === 'pan');
        els.modeDrawBtn.classList.toggle('oe-btn--active', m === 'draw');
        renderSvg();
        updateButtons();
    }

    function updateButtons() {
        var canDraw   = state.mode === 'draw' && state.selectedAptKey;
        var canFinish = canDraw && state.draftPoints.length >= 3;
        var canUndo   = canDraw && state.draftPoints.length > 0;
        var canDelete = !!(state.selectedAptKey && state.outlines[state.selectedAptKey]);
        els.finishBtn.disabled = !canFinish;
        els.undoBtn.disabled   = !canUndo;
        els.deleteBtn.disabled = !canDelete;
        els.modeDrawBtn.disabled = !state.selectedAptKey;
    }

    function commitDraft() {
        if (state.draftPoints.length < 3 || !state.selectedAptKey) return;
        var fl = findFloor(state.currentFloorKey);
        state.outlines[state.selectedAptKey] = {
            polygon:    state.draftPoints.slice(),
            raster_url: (fl && fl.floor_img) || '',
        };
        state.draftPoints = [];
        state.dirty = true;
        setMode('pan');
        renderApartList();
        renderSvg();
        status('Полигон сохранён в черновике (не забудь нажать «Сохранить»)', 'ok');
    }

    function deleteSelected() {
        if (!state.selectedAptKey || !state.outlines[state.selectedAptKey]) return;
        if (!confirm('Удалить обводку для ' + state.selectedAptKey + '?')) return;
        delete state.outlines[state.selectedAptKey];
        state.dirty = true;
        renderApartList();
        renderSvg();
        updateButtons();
    }

    function save() {
        var payload = { outlines: state.outlines };
        status('Сохранение…');
        api('save', payload).then(function (resp) {
            if (!resp.ok) throw new Error(resp.error || 'save failed');
            state.dirty = false;
            status('Сохранено: ' + resp.count + ' обводок (' + resp.updated_at + ')', 'ok');
        }).catch(function (e) {
            status('Ошибка сохранения: ' + e.message, 'err');
        });
    }

    // -- events ---------------------------------------------------------------

    els.floorSelect.addEventListener('change', function () { selectFloor(els.floorSelect.value); });

    els.svg.addEventListener('click', function (e) {
        if (state.mode !== 'draw' || !state.selectedAptKey) return;
        var p = clientToPct(e);
        state.draftPoints.push(p);
        renderSvg();
        updateButtons();
    });
    els.svg.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        if (state.mode !== 'draw' || state.draftPoints.length === 0) return;
        state.draftPoints.pop();
        renderSvg();
        updateButtons();
    });
    document.addEventListener('keydown', function (e) {
        if (state.mode !== 'draw') return;
        if (e.key === 'Enter' || e.key === ' ') {
            if (state.draftPoints.length >= 3) { e.preventDefault(); commitDraft(); }
        } else if (e.key === 'Escape') {
            state.draftPoints = []; renderSvg(); updateButtons();
        } else if (e.key === 'Backspace') {
            if (state.draftPoints.length > 0) {
                state.draftPoints.pop(); renderSvg(); updateButtons();
                e.preventDefault();
            }
        }
    });

    els.modePanBtn.addEventListener('click',  function () { setMode('pan'); });
    els.modeDrawBtn.addEventListener('click', function () { setMode('draw'); });
    els.finishBtn.addEventListener('click',   commitDraft);
    els.undoBtn.addEventListener('click',     function () {
        if (state.draftPoints.length > 0) { state.draftPoints.pop(); renderSvg(); updateButtons(); }
    });
    els.deleteBtn.addEventListener('click',   deleteSelected);
    els.saveBtn.addEventListener('click',     save);

    window.addEventListener('beforeunload', function (e) {
        if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // -- helpers --------------------------------------------------------------

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    if (window.OE_DEBUG) window._oe = { state: state, els: els };

    load();
})();
