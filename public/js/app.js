/**
 * Pantone Predictor — Frontend
 */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function api(url, opts = {}) {
        const defaults = { headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json' } };
        if (opts.body && typeof opts.body === 'object') opts.body = JSON.stringify(opts.body);
        return fetch(url, { ...defaults, ...opts }).then(async r => {
            const d = await r.json(); if (!r.ok) throw new Error(d.error || `HTTP ${r.status}`); return d;
        });
    }

    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function show(el) { el?.classList.remove('hidden'); }
    function hide(el) { el?.classList.add('hidden'); }

    // ══════════════════════════════════════════════════════════
    //  PREDICTIONS PAGE
    // ══════════════════════════════════════════════════════════

    const seriesInput = document.getElementById('seriesInput');
    if (seriesInput) initPredictionsPage();

    function initPredictionsPage() {
        const formulasCard = document.getElementById('formulasCard');
        const generateCard = document.getElementById('generateCard');
        const resultsCard  = document.getElementById('resultsCard');
        const formulasBody = document.getElementById('formulasBody');
        const generateBtn  = document.getElementById('generateBtn');
        const syncBtn      = document.getElementById('syncBtn');
        const matchCount   = document.getElementById('seriesMatchCount');

        let formulasData = [];
        let predictionsData = [];
        let selectedSeries = '';

        // ── Sync from CMS ────────────────────────────────────
        syncBtn?.addEventListener('click', async function () {
            this.disabled = true;
            this.textContent = 'Syncing...';
            try {
                const data = await api('/api/sync', { method: 'POST' });
                alert(data.message || `Synced ${data.synced} formulas.`);
                location.reload();
            } catch (e) {
                alert('Sync failed: ' + e.message);
                this.disabled = false;
                this.textContent = 'Sync from CMS';
            }
        });

        // ── Series text input ────────────────────────────────
        seriesInput.addEventListener('input', debounce(async function () {
            selectedSeries = seriesInput.value.trim();
            hide(resultsCard);
            if (selectedSeries.length < 2) {
                hide(formulasCard); hide(generateCard); hide(matchCount);
                return;
            }

            show(formulasCard);
            show(generateCard);
            show(document.getElementById('formulasLoading'));

            try {
                const data = await api(`/api/series/formulas?series=${encodeURIComponent(selectedSeries)}`);
                formulasData = data.formulas || [];
                matchCount.textContent = `${data.total} Pantone formulas found matching "${selectedSeries}"`;
                show(matchCount);
                renderFormulas(formulasData);
                if (data.total === 0) { hide(formulasCard); hide(generateCard); }
            } catch (e) {
                formulasBody.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(e.message)}</td></tr>`;
            }
            hide(document.getElementById('formulasLoading'));
        }, 500));

        // ── Formula table ────────────────────────────────────
        function renderFormulas(formulas) {
            const filter = (document.getElementById('formulaFilter')?.value || '').toLowerCase();
            const filtered = formulas.filter(f =>
                !filter ||
                f.itemCode.toLowerCase().includes(filter) ||
                f.description.toLowerCase().includes(filter) ||
                f.detectedPms.toLowerCase().includes(filter) ||
                f.userPms.toLowerCase().includes(filter)
            );

            formulasBody.innerHTML = filtered.map(f => {
                const disabled = !f.hasPigments;
                return `<tr class="${disabled ? 'disabled-row' : ''}">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="formula-cb" data-id="${f.id}"
                               ${f.isAnchor ? 'checked' : ''}
                               ${disabled ? 'disabled title="No pigment components"' : ''}>
                    </td>
                    <td><strong>${esc(f.itemCode)}</strong></td>
                    <td style="font-size:0.85rem">${esc(f.description)}</td>
                    <td>
                        <input type="text" class="pms-input" data-id="${f.id}"
                               value="${esc(f.userPms)}"
                               placeholder="e.g. 286"
                               style="width:100%;padding:0.3rem 0.5rem;font-size:0.85rem"
                               ${disabled ? 'disabled' : ''}>
                    </td>
                    <td style="font-size:0.8rem">${esc(f.pigmentSummary) || '<span class="text-muted">none</span>'}</td>
                    <td class="text-center">${f.pigmentTotal}%</td>
                </tr>`;
            }).join('');

            updateSelection();
        }

        document.getElementById('formulaFilter')?.addEventListener('input', debounce(() => renderFormulas(formulasData), 200));

        // ── Selection tracking ───────────────────────────────
        function getSelectedAnchors() {
            const anchors = [];
            formulasBody.querySelectorAll('.formula-cb:checked').forEach(cb => {
                const id = parseInt(cb.dataset.id);
                const pms = formulasBody.querySelector(`.pms-input[data-id="${id}"]`)?.value.trim() || '';
                anchors.push({ id, pms });
            });
            return anchors;
        }

        function updateSelection() {
            const anchors = getSelectedAnchors();
            const withPms = anchors.filter(a => a.pms !== '').length;
            document.getElementById('selectedCount').textContent = anchors.length;
            document.getElementById('generateInfo').textContent =
                anchors.length > 0 ? `${withPms} with PMS numbers` : '';
            generateBtn.disabled = withPms === 0;
        }

        formulasBody.addEventListener('change', e => { if (e.target.classList.contains('formula-cb')) updateSelection(); });
        formulasBody.addEventListener('input', e => { if (e.target.classList.contains('pms-input')) updateSelection(); });

        document.getElementById('headerCb')?.addEventListener('change', function () {
            formulasBody.querySelectorAll('.formula-cb:not(:disabled)').forEach(cb => cb.checked = this.checked);
            updateSelection();
        });
        document.getElementById('selectAllBtn')?.addEventListener('click', () => {
            formulasBody.querySelectorAll('.formula-cb:not(:disabled)').forEach(cb => cb.checked = true);
            updateSelection();
        });
        document.getElementById('deselectAllBtn')?.addEventListener('click', () => {
            formulasBody.querySelectorAll('.formula-cb').forEach(cb => cb.checked = false);
            updateSelection();
        });

        // ── Generate ─────────────────────────────────────────
        generateBtn?.addEventListener('click', async function () {
            const anchors = getSelectedAnchors().filter(a => a.pms !== '');
            if (!anchors.length) { alert('Select anchors and enter PMS numbers.'); return; }

            // Save anchor state first
            const updates = getSelectedAnchors().map(a => ({ id: a.id, pms: a.pms, anchor: true }));
            // Also mark unchecked ones as not anchor
            formulasBody.querySelectorAll('.formula-cb:not(:checked)').forEach(cb => {
                updates.push({ id: parseInt(cb.dataset.id), pms: formulasBody.querySelector(`.pms-input[data-id="${cb.dataset.id}"]`)?.value.trim() || '', anchor: false });
            });
            try { await api('/api/anchors/save', { method: 'POST', body: { updates } }); } catch (e) { /* non-critical */ }

            show(resultsCard);
            show(document.getElementById('resultsLoading'));

            try {
                const data = await api('/api/predictions/generate', {
                    method: 'POST',
                    body: {
                        series: selectedSeries,
                        anchors: anchors,
                        k: parseInt(document.getElementById('kValue').value) || 5,
                        noiseThreshold: parseInt(document.getElementById('noiseValue').value) || 2,
                    },
                });

                predictionsData = data.predictions || [];

                document.getElementById('resultsSummary').innerHTML =
                    `<span><strong>${data.total}</strong> pigment-only predictions from <strong>${data.anchorCount}</strong> anchors in <strong>${data.elapsed_ms}ms</strong></span>` +
                    (data.skippedColors > 0 ? `<span class="text-muted">${data.skippedColors} skipped</span>` : '');

                document.getElementById('resultsWarnings').innerHTML = (data.warnings || []).map(w =>
                    `<div class="warning-banner"><span class="warning-banner-icon">&#9888;</span> ${esc(w)}</div>`).join('');

                const skippedDiv = document.getElementById('skippedAnchorsInfo');
                if (data.skippedAnchors?.length > 0) {
                    skippedDiv.innerHTML = '<div class="alert alert-warning" style="font-size:0.85rem"><strong>Skipped anchors:</strong><ul style="margin:0.3rem 0 0 1rem">' +
                        data.skippedAnchors.map(s => `<li>ID ${s.id}${s.pms ? ' (PMS ' + esc(s.pms) + ')' : ''}: ${esc(s.reason)}</li>`).join('') + '</ul></div>';
                    show(skippedDiv);
                } else { hide(skippedDiv); }

                renderResults(predictionsData);
            } catch (e) {
                document.getElementById('resultsSummary').innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
            }
            hide(document.getElementById('resultsLoading'));

            // Scroll to results
            resultsCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        // ── Results ──────────────────────────────────────────
        function renderResults(predictions) {
            const filter = (document.getElementById('resultsFilter')?.value || '').toLowerCase();
            const filtered = predictions.filter(p => !filter || p.pmsNumber.toLowerCase().includes(filter) || p.pmsName.toLowerCase().includes(filter));
            const body = document.getElementById('resultsBody');

            body.innerHTML = filtered.map((p, idx) => {
                const cls = p.confidence >= 70 ? 'high' : (p.confidence >= 40 ? 'medium' : 'low');
                const compStr = p.components.slice(0, 3).map(c => `${c.code} (${(c.percentage * 100).toFixed(1)}%)`).join(', ');

                return `<tr>
                    <td class="checkbox-cell"><input type="checkbox" class="result-cb" data-idx="${idx}"></td>
                    <td><span class="color-swatch" style="background:${p.hex || '#ccc'}"></span></td>
                    <td><strong>${esc(p.pmsNumber)}</strong></td>
                    <td style="font-size:0.85rem">${esc(p.pmsName)}</td>
                    <td><div class="confidence-bar"><div class="confidence-bar-track"><div class="confidence-bar-fill ${cls}" style="width:${p.confidence}%"></div></div><span class="confidence-value">${p.confidence.toFixed(1)}%</span></div></td>
                    <td style="font-size:0.8rem">${esc(compStr)}</td>
                    <td><button class="expand-toggle" data-ridx="${idx}">&#9654;</button></td>
                </tr>
                <tr class="detail-row" id="rdetail-${idx}"><td colspan="7"><div class="detail-content">
                    <table class="component-table"><thead><tr><th>#</th><th>Code</th><th>Description</th><th class="text-right">Wt%</th></tr></thead><tbody>
                    ${p.components.map((c, ci) => `<tr><td>${ci + 1}</td><td><strong>${esc(c.code)}</strong></td><td>${esc(c.description)}</td><td class="text-right">${(c.percentage * 100).toFixed(2)}%</td></tr>`).join('')}
                    </tbody><tfoot><tr><th colspan="3" class="text-right">Total:</th><th class="text-right">${(p.components.reduce((s, c) => s + c.percentage, 0) * 100).toFixed(2)}%</th></tr></tfoot></table>
                    <div class="mt-1" style="font-size:0.8rem;color:var(--text-muted)"><strong>Nearest anchors:</strong> ${p.nearestAnchors.map(a => `${a.pmsNumber || a.itemCode} (&#916;E ${a.distance})`).join(', ')}</div>
                </div></td></tr>`;
            }).join('');
        }

        document.getElementById('resultsFilter')?.addEventListener('input', debounce(() => renderResults(predictionsData), 200));

        // Expand/collapse
        document.addEventListener('click', e => {
            const t = e.target.closest('.expand-toggle');
            if (t) {
                const row = document.getElementById(t.dataset.id ? `detail-${t.dataset.id}` : `rdetail-${t.dataset.ridx}`);
                if (row) { row.classList.toggle('open'); t.classList.toggle('open'); }
            }
            const del = e.target.closest('.delete-prediction-btn');
            if (del && confirm('Delete this prediction?')) {
                api('/api/predictions/delete', { method: 'POST', body: { id: del.dataset.id } }).then(() => location.reload()).catch(e => alert(e.message));
            }
        });

        // Result checkboxes
        document.getElementById('resultSelectAll')?.addEventListener('click', () => document.querySelectorAll('.result-cb').forEach(cb => cb.checked = true));
        document.getElementById('resultDeselectAll')?.addEventListener('click', () => document.querySelectorAll('.result-cb').forEach(cb => cb.checked = false));
        document.getElementById('resultHeaderCb')?.addEventListener('change', function () { document.querySelectorAll('.result-cb').forEach(cb => cb.checked = this.checked); });

        // Save
        document.getElementById('saveAllBtn')?.addEventListener('click', () => predictionsData.length && saveBatch(predictionsData));
        document.getElementById('saveSelectedBtn')?.addEventListener('click', () => {
            const sel = [...document.querySelectorAll('.result-cb:checked')].map(cb => predictionsData[parseInt(cb.dataset.idx)]).filter(Boolean);
            sel.length ? saveBatch(sel) : alert('No predictions selected.');
        });

        async function saveBatch(items) {
            try {
                const data = await api('/api/predictions/save-batch', { method: 'POST', body: { series: selectedSeries, predictions: items } });
                alert(data.message || `${data.saved} saved, ${data.skipped} skipped.`);
            } catch (e) { alert('Save failed: ' + e.message); }
        }

        // Export CSV
        document.getElementById('exportCsvBtn')?.addEventListener('click', () => {
            if (!predictionsData.length) return;
            const h = ['PMS Number', 'Name', 'L*', 'a*', 'b*', 'Confidence', 'Pigment Components'];
            const rows = predictionsData.map(p => [p.pmsNumber, p.pmsName, p.lab.L, p.lab.a, p.lab.b, p.confidence,
                p.components.map(c => `${c.code} ${(c.percentage * 100).toFixed(2)}%`).join('; ')]);
            const csv = [h, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
            a.download = `predictions_${selectedSeries.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
        });
    }

    // ══════════════════════════════════════════════════════════
    //  CUSTOM LAB PAGE
    // ══════════════════════════════════════════════════════════

    const labL = document.getElementById('labL');
    if (labL) initCustomLabPage();

    function initCustomLabPage() {
        const labA = document.getElementById('labA'), labB = document.getElementById('labB');
        const swatch = document.getElementById('labSwatch'), valDiv = document.getElementById('labValues');
        const matchBtn = document.getElementById('customMatchBtn');
        const seriesInput = document.getElementById('customSeriesSearch');
        const dropdown = document.getElementById('customSeriesDropdown');
        const info = document.getElementById('customSeriesInfo');
        const resCard = document.getElementById('customResultsCard');
        let selectedSeries = '', currentResult = null;

        function updatePreview() {
            const L = parseFloat(labL.value) || 0, a = parseFloat(labA.value) || 0, b = parseFloat(labB.value) || 0;
            const hex = labToHex(L, a, b);
            swatch.style.background = hex;
            valDiv.innerHTML = `L*: <strong>${L}</strong> &nbsp; a*: <strong>${a}</strong> &nbsp; b*: <strong>${b}</strong> &nbsp; Hex: <strong>${hex}</strong>`;
            matchBtn.disabled = !selectedSeries || labL.value === '' || labA.value === '' || labB.value === '';
        }
        [labL, labA, labB].forEach(el => el.addEventListener('input', updatePreview));

        seriesInput?.addEventListener('input', debounce(async function () {
            const q = this.value.trim(); if (q.length < 2) { hide(dropdown); return; }
            document.getElementById('customSeriesSpinner')?.classList.add('active');
            try {
                const db = await fetch('/api/series/list').then(r => r.json());
                const filtered = db.filter(s => s.series_prefix.toLowerCase().includes(q.toLowerCase()));
                dropdown.innerHTML = filtered.slice(0, 20).map(r =>
                    `<div class="autocomplete-item" data-series="${esc(r.series_prefix)}"><span>${esc(r.series_prefix)}</span><span class="count">${r.formula_count}</span></div>`
                ).join('') || '<div class="autocomplete-item text-muted">No series found</div>';
                show(dropdown);
            } catch (e) { dropdown.innerHTML = `<div class="autocomplete-item text-danger">${esc(e.message)}</div>`; show(dropdown); }
            document.getElementById('customSeriesSpinner')?.classList.remove('active');
        }, 200));

        dropdown?.addEventListener('click', e => {
            const item = e.target.closest('.autocomplete-item');
            if (!item?.dataset.series) return;
            selectedSeries = item.dataset.series; seriesInput.disabled = true; hide(dropdown);
            document.getElementById('customSelectedSeries').textContent = selectedSeries; show(info); updatePreview();
        });
        document.getElementById('customChangeSeries')?.addEventListener('click', () => {
            selectedSeries = ''; seriesInput.value = ''; seriesInput.disabled = false; hide(info); hide(resCard); updatePreview(); seriesInput.focus();
        });
        document.addEventListener('click', e => { if (!e.target.closest('.search-wrapper')) hide(dropdown); });

        matchBtn?.addEventListener('click', async () => {
            show(resCard); show(document.getElementById('customLoading'));
            try {
                const data = await api('/api/custom-lab/match', { method: 'POST',
                    body: { L: parseFloat(labL.value), a: parseFloat(labA.value), b: parseFloat(labB.value), series: selectedSeries } });
                currentResult = data;
                document.getElementById('customResultsSummary').innerHTML = `<span>Confidence: <strong>${data.confidence.toFixed(1)}%</strong> | ${data.anchorCount} anchors | Pigment-only</span>`;
                document.getElementById('customWarnings').innerHTML = (data.warnings || []).map(w => `<div class="warning-banner"><span class="warning-banner-icon">&#9888;</span> ${esc(w)}</div>`).join('');
                document.getElementById('customAnchorsInfo').innerHTML = (data.nearestAnchors || []).map(a => `<span class="badge badge-info" style="margin-right:0.3rem">${esc(a.pmsNumber || a.itemCode)} (&#916;E ${a.distance})</span>`).join('');
                let total = 0;
                document.getElementById('customComponentsBody').innerHTML = data.components.map((c, i) => {
                    const pct = c.percentage * 100; total += pct;
                    return `<tr><td>${i + 1}</td><td><strong>${esc(c.code)}</strong></td><td>${esc(c.description)}</td><td class="text-right">${pct.toFixed(2)}%</td></tr>`;
                }).join('');
                document.getElementById('customTotalPct').textContent = total.toFixed(2) + '%';
            } catch (e) { document.getElementById('customResultsSummary').innerHTML = `<span class="text-danger">${esc(e.message)}</span>`; }
            hide(document.getElementById('customLoading'));
        });

        document.getElementById('customSaveBtn')?.addEventListener('click', async () => {
            if (!currentResult) return;
            try {
                await api('/api/predictions/save', { method: 'POST', body: { series: selectedSeries, pmsNumber: `CUSTOM_${Date.now()}`,
                    pmsName: `Custom L${labL.value} a${labA.value} b${labB.value}`,
                    lab: { L: parseFloat(labL.value), a: parseFloat(labA.value), b: parseFloat(labB.value) },
                    confidence: currentResult.confidence, components: currentResult.components, nearestAnchors: currentResult.nearestAnchors, source: 'custom_lab' } });
                alert('Saved.');
            } catch (e) { alert(e.message.includes('exists') ? 'Already exists.' : 'Failed: ' + e.message); }
        });
    }

    // ── Settings ──────────────────────────────────────────────
    const testBtn = document.getElementById('testConnectionBtn');
    if (testBtn) testBtn.addEventListener('click', async () => {
        const r = document.getElementById('connectionResult'); show(r);
        r.className = 'alert alert-info mb-2'; r.textContent = 'Testing...';
        try {
            const d = await api('/api/cms/test');
            r.className = d.success ? 'alert alert-success mb-2' : 'alert alert-error mb-2';
            r.textContent = d.success ? 'Connected.' : 'Failed: ' + d.message;
        } catch (e) { r.className = 'alert alert-error mb-2'; r.textContent = 'Failed: ' + e.message; }
    });

    // ── Lab to Hex ───────────────────────────────────────────
    function labToHex(L, a, b) {
        const fy = (L + 16) / 116, fx = a / 500 + fy, fz = fy - b / 200, d = 6 / 29;
        const xr = fx > d ? fx ** 3 : 3 * d * d * (fx - 4 / 29), yr = fy > d ? fy ** 3 : 3 * d * d * (fy - 4 / 29), zr = fz > d ? fz ** 3 : 3 * d * d * (fz - 4 / 29);
        const x = xr * 0.95047, y = yr, z = zr * 1.08883;
        let r = x * 3.2406 + y * -1.5372 + z * -0.4986, g = x * -0.9689 + y * 1.8758 + z * 0.0415, bl = x * 0.0557 + y * -0.2040 + z * 1.0570;
        const gm = v => v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055;
        r = Math.max(0, Math.min(255, Math.round(gm(r) * 255))); g = Math.max(0, Math.min(255, Math.round(gm(g) * 255))); bl = Math.max(0, Math.min(255, Math.round(gm(bl) * 255)));
        return '#' + [r, g, bl].map(c => c.toString(16).padStart(2, '0')).join('');
    }
})();
