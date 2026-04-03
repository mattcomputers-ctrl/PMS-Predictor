/**
 * Pantone Predictor — Frontend Application
 */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function api(url, options = {}) {
        const defaults = {
            headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json' },
        };
        if (options.body && typeof options.body === 'object') {
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, { ...defaults, ...options }).then(async r => {
            const data = await r.json();
            if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
            return data;
        });
    }

    function debounce(fn, ms) {
        let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function show(el) { el?.classList.remove('hidden'); }
    function hide(el) { el?.classList.add('hidden'); }

    // ── Predictions Page ─────────────────────────────────────

    const seriesSearch = document.getElementById('seriesSearch');
    if (seriesSearch) initPredictionsPage();

    function initPredictionsPage() {
        const dropdown = document.getElementById('seriesDropdown');
        const spinner = document.getElementById('seriesSpinner');
        const seriesInfo = document.getElementById('seriesInfo');
        const anchorsCard = document.getElementById('anchorsCard');
        const optionsCard = document.getElementById('optionsCard');
        const resultsCard = document.getElementById('resultsCard');
        const anchorsBody = document.getElementById('anchorsBody');
        const generateBtn = document.getElementById('generateBtn');

        let selectedSeries = '';
        let formulasData = [];
        let predictionsData = [];

        // ── Series autocomplete ──────────────────────────────
        seriesSearch.addEventListener('input', debounce(async function () {
            const q = this.value.trim();
            if (q.length < 2) { hide(dropdown); return; }
            spinner.classList.add('active');
            try {
                const results = await api(`/api/series/search?q=${encodeURIComponent(q)}`);
                dropdown.innerHTML = results.map(r =>
                    `<div class="autocomplete-item" data-series="${esc(r.series_name)}">
                        <span>${esc(r.series_name)}</span>
                        <span class="count">${r.formula_count} formulas</span>
                    </div>`
                ).join('') || '<div class="autocomplete-item" style="color:var(--text-muted)">No series found</div>';
                show(dropdown);
            } catch (e) {
                dropdown.innerHTML = `<div class="autocomplete-item text-danger">${esc(e.message)}</div>`;
                show(dropdown);
            }
            spinner.classList.remove('active');
        }, 300));

        dropdown.addEventListener('click', function (e) {
            const item = e.target.closest('.autocomplete-item');
            if (!item?.dataset.series) return;
            selectSeries(item.dataset.series);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.search-wrapper')) {
                hide(dropdown);
                hide(document.getElementById('customSeriesDropdown'));
            }
        });

        document.getElementById('changeSeries')?.addEventListener('click', function () {
            selectedSeries = '';
            seriesSearch.value = '';
            seriesSearch.disabled = false;
            hide(seriesInfo); hide(anchorsCard); hide(optionsCard); hide(resultsCard);
            seriesSearch.focus();
        });

        async function selectSeries(name) {
            selectedSeries = name;
            seriesSearch.disabled = true;
            hide(dropdown);
            document.getElementById('selectedSeriesName').textContent = name;
            show(seriesInfo);
            show(anchorsCard);
            show(document.getElementById('anchorsLoading'));

            try {
                const data = await api(`/api/series/formulas?series=${encodeURIComponent(name)}`);
                formulasData = data.formulas || [];
                document.getElementById('seriesFormulaCount').textContent =
                    `${data.total} formulas, ${data.withPigments} with pigments`;
                renderAnchors(formulasData);
                show(optionsCard);
            } catch (e) {
                anchorsBody.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(e.message)}</td></tr>`;
            }
            hide(document.getElementById('anchorsLoading'));
        }

        // ── Anchor table ─────────────────────────────────────
        function renderAnchors(formulas) {
            const filter = (document.getElementById('anchorFilter')?.value || '').toLowerCase();
            const filtered = formulas.filter(f =>
                !filter ||
                f.itemCode.toLowerCase().includes(filter) ||
                f.description.toLowerCase().includes(filter) ||
                f.detectedPms.toLowerCase().includes(filter)
            );

            anchorsBody.innerHTML = filtered.map((f, i) => {
                const disabled = !f.hasPigments;
                return `<tr class="${disabled ? 'disabled-row' : ''}" data-idx="${i}">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="anchor-cb" value="${esc(f.itemCode)}"
                               ${disabled ? 'disabled title="No pigment components"' : ''}>
                    </td>
                    <td><strong>${esc(f.itemCode)}</strong></td>
                    <td style="font-size:0.85rem">${esc(f.description)}</td>
                    <td>
                        <input type="text" class="pms-input" data-item="${esc(f.itemCode)}"
                               value="${esc(f.detectedPms)}"
                               placeholder="e.g. 286"
                               style="width:100%;padding:0.3rem 0.5rem;font-size:0.85rem;"
                               ${disabled ? 'disabled' : ''}>
                    </td>
                    <td style="font-size:0.8rem">${esc(f.pigmentSummary) || '<span class="text-muted">none</span>'}</td>
                    <td class="text-center">${f.pigmentTotal}%</td>
                </tr>`;
            }).join('');

            updateSelectionCount();
        }

        document.getElementById('anchorFilter')?.addEventListener('input', debounce(function () {
            renderAnchors(formulasData);
        }, 200));

        function getSelectedAnchors() {
            const anchors = [];
            anchorsBody.querySelectorAll('.anchor-cb:checked').forEach(cb => {
                const row = cb.closest('tr');
                const pmsInput = row.querySelector('.pms-input');
                const pms = pmsInput?.value.trim() || '';
                anchors.push({ itemCode: cb.value, pmsNumber: pms });
            });
            return anchors;
        }

        function updateSelectionCount() {
            const anchors = getSelectedAnchors();
            const withPms = anchors.filter(a => a.pmsNumber !== '').length;
            document.getElementById('selectedCount').textContent = anchors.length;
            generateBtn.disabled = withPms === 0;

            const warning = document.getElementById('anchorWarning');
            if (anchors.length > 0 && anchors.length < 20) {
                const noPms = anchors.filter(a => a.pmsNumber === '').length;
                let msg = `${anchors.length} anchors selected`;
                if (noPms > 0) msg += ` (${noPms} missing PMS numbers — will be skipped)`;
                if (anchors.length < 20) msg += '. More anchors = better predictions.';
                document.getElementById('anchorWarningText').textContent = msg;
                show(warning);
            } else {
                hide(warning);
            }
        }

        anchorsBody.addEventListener('change', function (e) {
            if (e.target.classList.contains('anchor-cb')) updateSelectionCount();
        });
        anchorsBody.addEventListener('input', function (e) {
            if (e.target.classList.contains('pms-input')) updateSelectionCount();
        });

        document.getElementById('selectAll')?.addEventListener('click', function () {
            anchorsBody.querySelectorAll('tr:not(.disabled-row)').forEach(row => {
                const cb = row.querySelector('.anchor-cb');
                const pms = row.querySelector('.pms-input');
                if (cb && pms && pms.value.trim() !== '') cb.checked = true;
            });
            updateSelectionCount();
        });
        document.getElementById('deselectAll')?.addEventListener('click', function () {
            anchorsBody.querySelectorAll('.anchor-cb').forEach(cb => cb.checked = false);
            updateSelectionCount();
        });

        // ── Generate ─────────────────────────────────────────
        generateBtn?.addEventListener('click', async function () {
            const anchors = getSelectedAnchors().filter(a => a.pmsNumber !== '');
            if (anchors.length === 0) { alert('Select anchors and assign PMS numbers.'); return; }

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

                const warnDiv = document.getElementById('resultsWarnings');
                warnDiv.innerHTML = (data.warnings || []).map(w =>
                    `<div class="warning-banner"><span class="warning-banner-icon">&#9888;</span> ${esc(w)}</div>`
                ).join('');

                // Show skipped anchors
                const skippedDiv = document.getElementById('skippedAnchorsInfo');
                if (data.skippedAnchors?.length > 0) {
                    skippedDiv.innerHTML = '<div class="alert alert-warning" style="font-size:0.85rem"><strong>Skipped anchors:</strong><ul style="margin:0.3rem 0 0 1rem">' +
                        data.skippedAnchors.map(s =>
                            `<li>${esc(s.itemCode)}${s.pms ? ' (PMS ' + esc(s.pms) + ')' : ''}: ${esc(s.reason)}</li>`
                        ).join('') + '</ul></div>';
                    show(skippedDiv);
                } else {
                    hide(skippedDiv);
                }

                renderResults(predictionsData);
            } catch (e) {
                document.getElementById('resultsSummary').innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
            }
            hide(document.getElementById('resultsLoading'));
        });

        // ── Results table ────────────────────────────────────
        function renderResults(predictions) {
            const filter = (document.getElementById('resultsFilter')?.value || '').toLowerCase();
            const filtered = predictions.filter(p =>
                !filter || p.pmsNumber.toLowerCase().includes(filter) || p.pmsName.toLowerCase().includes(filter)
            );

            const body = document.getElementById('resultsBody');
            body.innerHTML = filtered.map((p, idx) => {
                const confClass = p.confidence >= 70 ? 'high' : (p.confidence >= 40 ? 'medium' : 'low');
                const compSummary = p.components.slice(0, 3).map(c =>
                    `${c.code} (${(c.percentage * 100).toFixed(1)}%)`
                ).join(', ');

                return `<tr data-idx="${idx}">
                    <td class="checkbox-cell"><input type="checkbox" class="result-cb" data-idx="${idx}"></td>
                    <td><span class="color-swatch" style="background:${p.hex || '#ccc'}"></span></td>
                    <td><strong>${esc(p.pmsNumber)}</strong></td>
                    <td style="font-size:0.85rem">${esc(p.pmsName)}</td>
                    <td>
                        <div class="confidence-bar">
                            <div class="confidence-bar-track">
                                <div class="confidence-bar-fill ${confClass}" style="width:${p.confidence}%"></div>
                            </div>
                            <span class="confidence-value">${p.confidence.toFixed(1)}%</span>
                        </div>
                    </td>
                    <td style="font-size:0.8rem">${esc(compSummary)}</td>
                    <td><button class="expand-toggle" data-ridx="${idx}">&#9654;</button></td>
                </tr>
                <tr class="detail-row" id="rdetail-${idx}">
                    <td colspan="7">
                        <div class="detail-content">
                            <table class="component-table">
                                <thead><tr><th>#</th><th>Code</th><th>Description</th><th class="text-right">Wt%</th></tr></thead>
                                <tbody>
                                ${p.components.map((c, ci) =>
                                    `<tr><td>${ci + 1}</td><td><strong>${esc(c.code)}</strong></td><td>${esc(c.description)}</td><td class="text-right">${(c.percentage * 100).toFixed(2)}%</td></tr>`
                                ).join('')}
                                </tbody>
                                <tfoot><tr><th colspan="3" class="text-right">Total:</th><th class="text-right">${(p.components.reduce((s, c) => s + c.percentage, 0) * 100).toFixed(2)}%</th></tr></tfoot>
                            </table>
                            <div class="mt-1" style="font-size:0.8rem;color:var(--text-muted)">
                                <strong>Nearest anchors:</strong>
                                ${p.nearestAnchors.map(a => `${a.pmsNumber || a.itemCode} (&#916;E ${a.distance})`).join(', ')}
                            </div>
                            ${p.warnings.length ? `<div class="mt-1 text-warning" style="font-size:0.8rem">${p.warnings.join('; ')}</div>` : ''}
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('resultsFilter')?.addEventListener('input', debounce(function () {
            renderResults(predictionsData);
        }, 200));

        // Expand/collapse
        document.addEventListener('click', function (e) {
            const toggle = e.target.closest('.expand-toggle');
            if (toggle) {
                const id = toggle.dataset.id;
                const ridx = toggle.dataset.ridx;
                const row = id ? document.getElementById(`detail-${id}`) : document.getElementById(`rdetail-${ridx}`);
                if (row) { row.classList.toggle('open'); toggle.classList.toggle('open'); }
            }
            const delBtn = e.target.closest('.delete-prediction-btn');
            if (delBtn) {
                if (confirm('Delete this prediction?')) {
                    api('/api/predictions/delete', { method: 'POST', body: { id: delBtn.dataset.id } })
                        .then(() => location.reload()).catch(err => alert(err.message));
                }
            }
        });

        // Result checkboxes
        document.getElementById('resultSelectAll')?.addEventListener('click', () => {
            document.querySelectorAll('.result-cb').forEach(cb => cb.checked = true);
        });
        document.getElementById('resultDeselectAll')?.addEventListener('click', () => {
            document.querySelectorAll('.result-cb').forEach(cb => cb.checked = false);
        });
        document.getElementById('resultHeaderCheckbox')?.addEventListener('change', function () {
            document.querySelectorAll('.result-cb').forEach(cb => cb.checked = this.checked);
        });

        // Save
        document.getElementById('saveAllBtn')?.addEventListener('click', () => {
            if (predictionsData.length === 0) return;
            saveBatch(predictionsData);
        });
        document.getElementById('saveSelectedBtn')?.addEventListener('click', () => {
            const sel = [...document.querySelectorAll('.result-cb:checked')].map(cb => predictionsData[parseInt(cb.dataset.idx)]).filter(Boolean);
            if (!sel.length) { alert('No predictions selected.'); return; }
            saveBatch(sel);
        });

        async function saveBatch(items) {
            try {
                const data = await api('/api/predictions/save-batch', {
                    method: 'POST',
                    body: { series: selectedSeries, predictions: items },
                });
                alert(data.message || `${data.saved} saved, ${data.skipped} skipped.`);
            } catch (e) { alert('Save failed: ' + e.message); }
        }

        // Export CSV
        document.getElementById('exportCsvBtn')?.addEventListener('click', () => {
            if (!predictionsData.length) return;
            const header = ['PMS Number', 'Name', 'L*', 'a*', 'b*', 'Confidence', 'Pigment Components'];
            const rows = predictionsData.map(p => [
                p.pmsNumber, p.pmsName, p.lab.L, p.lab.a, p.lab.b, p.confidence,
                p.components.map(c => `${c.code} ${(c.percentage * 100).toFixed(2)}%`).join('; '),
            ]);
            const csv = [header, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `predictions_${selectedSeries.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
        });
    }

    // ── Custom Lab Page ──────────────────────────────────────

    const labL = document.getElementById('labL');
    if (labL) initCustomLabPage();

    function initCustomLabPage() {
        const labA = document.getElementById('labA');
        const labB = document.getElementById('labB');
        const swatch = document.getElementById('labSwatch');
        const valuesDiv = document.getElementById('labValues');
        const matchBtn = document.getElementById('customMatchBtn');
        const seriesInput = document.getElementById('customSeriesSearch');
        const seriesDropdown = document.getElementById('customSeriesDropdown');
        const seriesInfo = document.getElementById('customSeriesInfo');
        const resultsCard = document.getElementById('customResultsCard');

        let selectedSeries = '';
        let currentResult = null;

        function updatePreview() {
            const L = parseFloat(labL.value) || 0;
            const a = parseFloat(labA.value) || 0;
            const b = parseFloat(labB.value) || 0;
            const hex = labToHex(L, a, b);
            swatch.style.background = hex;
            valuesDiv.innerHTML = `L*: <strong>${L}</strong> &nbsp; a*: <strong>${a}</strong> &nbsp; b*: <strong>${b}</strong> &nbsp; Hex: <strong>${hex}</strong>`;
            updateMatchBtnState();
        }

        [labL, labA, labB].forEach(el => el.addEventListener('input', updatePreview));

        function updateMatchBtnState() {
            matchBtn.disabled = !selectedSeries || labL.value === '' || labA.value === '' || labB.value === '';
        }

        seriesInput.addEventListener('input', debounce(async function () {
            const q = this.value.trim();
            if (q.length < 2) { hide(seriesDropdown); return; }
            document.getElementById('customSeriesSpinner').classList.add('active');
            try {
                const results = await api(`/api/series/search?q=${encodeURIComponent(q)}`);
                seriesDropdown.innerHTML = results.map(r =>
                    `<div class="autocomplete-item" data-series="${esc(r.series_name)}">
                        <span>${esc(r.series_name)}</span><span class="count">${r.formula_count}</span>
                    </div>`
                ).join('') || '<div class="autocomplete-item text-muted">No series found</div>';
                show(seriesDropdown);
            } catch (e) {
                seriesDropdown.innerHTML = `<div class="autocomplete-item text-danger">${esc(e.message)}</div>`;
                show(seriesDropdown);
            }
            document.getElementById('customSeriesSpinner').classList.remove('active');
        }, 300));

        seriesDropdown.addEventListener('click', function (e) {
            const item = e.target.closest('.autocomplete-item');
            if (!item?.dataset.series) return;
            selectedSeries = item.dataset.series;
            seriesInput.disabled = true;
            hide(seriesDropdown);
            document.getElementById('customSelectedSeries').textContent = selectedSeries;
            show(seriesInfo);
            updateMatchBtnState();
        });

        document.getElementById('customChangeSeries')?.addEventListener('click', function () {
            selectedSeries = '';
            seriesInput.value = '';
            seriesInput.disabled = false;
            hide(seriesInfo); hide(resultsCard);
            updateMatchBtnState();
            seriesInput.focus();
        });

        matchBtn.addEventListener('click', async function () {
            show(resultsCard);
            show(document.getElementById('customLoading'));
            try {
                const data = await api('/api/custom-lab/match', {
                    method: 'POST',
                    body: { L: parseFloat(labL.value), a: parseFloat(labA.value), b: parseFloat(labB.value), series: selectedSeries },
                });
                currentResult = data;
                document.getElementById('customResultsSummary').innerHTML =
                    `<span>Confidence: <strong>${data.confidence.toFixed(1)}%</strong> | ${data.anchorCount} anchors in series | Pigment-only formula</span>`;
                document.getElementById('customWarnings').innerHTML = (data.warnings || []).map(w =>
                    `<div class="warning-banner"><span class="warning-banner-icon">&#9888;</span> ${esc(w)}</div>`
                ).join('');
                document.getElementById('customAnchorsInfo').innerHTML = (data.nearestAnchors || []).map(a =>
                    `<span class="badge badge-info" style="margin-right:0.3rem">${esc(a.pmsNumber || a.itemCode)} (&#916;E ${a.distance})</span>`
                ).join('');
                const tbody = document.getElementById('customComponentsBody');
                let totalPct = 0;
                tbody.innerHTML = data.components.map((c, i) => {
                    const pct = c.percentage * 100; totalPct += pct;
                    return `<tr><td>${i + 1}</td><td><strong>${esc(c.code)}</strong></td><td>${esc(c.description)}</td><td class="text-right">${pct.toFixed(2)}%</td></tr>`;
                }).join('');
                document.getElementById('customTotalPct').textContent = totalPct.toFixed(2) + '%';
            } catch (e) {
                document.getElementById('customResultsSummary').innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
            }
            hide(document.getElementById('customLoading'));
        });

        document.getElementById('customSaveBtn')?.addEventListener('click', async function () {
            if (!currentResult) return;
            try {
                await api('/api/predictions/save', {
                    method: 'POST',
                    body: {
                        series: selectedSeries,
                        pmsNumber: `CUSTOM_${Date.now()}`,
                        pmsName: `Custom L${labL.value} a${labA.value} b${labB.value}`,
                        lab: { L: parseFloat(labL.value), a: parseFloat(labA.value), b: parseFloat(labB.value) },
                        confidence: currentResult.confidence,
                        components: currentResult.components,
                        nearestAnchors: currentResult.nearestAnchors,
                        source: 'custom_lab',
                    },
                });
                alert('Prediction saved.');
            } catch (e) { alert(e.message.includes('exists') ? 'Already exists.' : 'Save failed: ' + e.message); }
        });
    }

    // ── Settings Page ────────────────────────────────────────

    const testBtn = document.getElementById('testConnectionBtn');
    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            const result = document.getElementById('connectionResult');
            show(result);
            result.className = 'alert alert-info mb-2';
            result.textContent = 'Testing connection...';
            try {
                const data = await api('/api/cms/test');
                result.className = data.success ? 'alert alert-success mb-2' : 'alert alert-error mb-2';
                result.textContent = data.success ? 'Connection successful.' : 'Failed: ' + data.message;
            } catch (e) {
                result.className = 'alert alert-error mb-2';
                result.textContent = 'Test failed: ' + e.message;
            }
        });
    }

    // ── Lab to Hex (client-side) ─────────────────────────────

    function labToHex(L, a, b) {
        const fy = (L + 16) / 116, fx = a / 500 + fy, fz = fy - b / 200;
        const d = 6 / 29;
        const xr = fx > d ? fx ** 3 : 3 * d * d * (fx - 4 / 29);
        const yr = fy > d ? fy ** 3 : 3 * d * d * (fy - 4 / 29);
        const zr = fz > d ? fz ** 3 : 3 * d * d * (fz - 4 / 29);
        const x = xr * 0.95047, y = yr, z = zr * 1.08883;
        let r = x * 3.2406 + y * -1.5372 + z * -0.4986;
        let g = x * -0.9689 + y * 1.8758 + z * 0.0415;
        let bl = x * 0.0557 + y * -0.2040 + z * 1.0570;
        const gamma = v => v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055;
        r = Math.max(0, Math.min(255, Math.round(gamma(r) * 255)));
        g = Math.max(0, Math.min(255, Math.round(gamma(g) * 255)));
        bl = Math.max(0, Math.min(255, Math.round(gamma(bl) * 255)));
        return '#' + [r, g, bl].map(c => c.toString(16).padStart(2, '0')).join('');
    }
})();
