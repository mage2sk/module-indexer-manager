/**
 * Panth_IndexerManager - augments Magento's native Index Management grid.
 *  - Per-row Reindex / View buttons (rendered server-side by our renderers)
 *  - Top-row mass buttons (Reindex Selected / All / Invalid) injected next to
 *    the native Actions dropdown's Submit button
 *  - Live polling toggle (with pulsing indicator), manual Refresh, Open Run Log
 *  - Click-to-toggle on each Mode cell (Update by Schedule ⇄ Update on Save)
 *  - View Details modal with recent run history
 *  - Inline button spinner while a reindex is in flight
 *  - Top-right toast for action feedback
 *
 * Pure vanilla JS, runs from a deferred <script>.
 */
(function () {
    'use strict';

    var enhancer = document.getElementById('panth-indexer-enhancer');
    if (!enhancer) { return; }

    var config;
    try {
        config = JSON.parse(enhancer.getAttribute('data-panth-config') || '{}');
    } catch (e) {
        console.error('[Panth IndexerManager] bad config json', e);
        return;
    }

    var labels = config.labels || {};

    var els = {
        modal: enhancer.querySelector('[data-panth-role="modal"]'),
        modalTitle: enhancer.querySelector('[data-panth-role="modal-title"]'),
        modalBody: enhancer.querySelector('[data-panth-role="modal-body"]'),
        toast: enhancer.querySelector('[data-panth-role="toast"]'),
        liveToggle: null,
        liveLabel: null,
        lastRefresh: null,
        refreshBtn: null
    };

    var state = {
        polling: true,
        pollTimer: null,
        currentIndexerId: null,
        cellMap: null,
        injected: false,
        modeBindings: new WeakSet()
    };

    /* ------------------------------------------------------------------ */
    /* helpers                                                             */

    function postForm(url, payload) {
        var data = new FormData();
        data.append('form_key', config.formKey);
        Object.keys(payload || {}).forEach(function (k) {
            if (Array.isArray(payload[k])) {
                payload[k].forEach(function (v) { data.append(k + '[]', v); });
            } else {
                data.append(k, payload[k]);
            }
        });
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) {
                return r.json().then(function (json) { return { ok: r.ok, body: json }; })
                    .catch(function () { return { ok: r.ok, body: null }; });
            });
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function toast(msg, type) {
        if (!els.toast) { return; }
        els.toast.className = 'panth-im__toast' + (type ? ' panth-im__toast--' + type : '');
        els.toast.textContent = msg;
        els.toast.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { els.toast.hidden = true; }, 4500);
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function setBtnLoading(btn, loading, label) {
        if (!btn) { return; }
        if (loading) {
            btn.disabled = true;
            btn.classList.add('disabled', 'is-loading');
            btn.dataset._origHtml = btn.dataset._origHtml || btn.innerHTML;
            btn.innerHTML = '<span class="panth-im__btn-spinner" aria-hidden="true"></span><span>' + esc(label || 'Working...') + '</span>';
        } else {
            btn.disabled = false;
            btn.classList.remove('disabled', 'is-loading');
            if (btn.dataset._origHtml) {
                btn.innerHTML = btn.dataset._origHtml;
                delete btn.dataset._origHtml;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* native grid discovery                                               */

    function buildCellMap(force) {
        if (state.cellMap && !force) { return state.cellMap; }
        var map = {};
        var checkboxes = document.querySelectorAll('input[type="checkbox"][data-role="select-row"]');
        for (var i = 0; i < checkboxes.length; i++) {
            var cb = checkboxes[i];
            var tr = cb.closest('tr');
            if (!tr) { continue; }
            map[cb.value] = {
                tr: tr,
                modeCell: tr.querySelector('.indexer-mode'),
                statusCell: tr.querySelector('.indexer-status'),
                scheduleCell: tr.querySelector('.indexer-schedule-status'),
                updatedCell: tr.querySelector('.col-date'),
                lastRunCell: tr.querySelector('.panth-im__col-last-run')
            };
        }
        state.cellMap = map;
        return map;
    }

    function getAllIds() {
        return Array.from(document.querySelectorAll('input[type="checkbox"][data-role="select-row"]'))
            .map(function (cb) { return cb.value; });
    }

    function getSelectedIds() {
        return Array.from(document.querySelectorAll('input[type="checkbox"][data-role="select-row"]:checked'))
            .map(function (cb) { return cb.value; });
    }

    function getInvalidIds() {
        var ids = [];
        document.querySelectorAll('input[type="checkbox"][data-role="select-row"]').forEach(function (cb) {
            var tr = cb.closest('tr');
            if (!tr) { return; }
            var statusCell = tr.querySelector('.indexer-status');
            if (!statusCell) { return; }
            if (statusCell.querySelector('.grid-severity-critical')
                || /reindex required/i.test(statusCell.textContent || '')) {
                ids.push(cb.value);
            }
        });
        return ids;
    }

    /* ------------------------------------------------------------------ */
    /* Mode cell - click to toggle scheduled/realtime                      */

    function bindModeCells() {
        var map = buildCellMap(true);
        Object.keys(map).forEach(function (id) {
            var cell = map[id].modeCell;
            if (!cell || state.modeBindings.has(cell)) { return; }
            state.modeBindings.add(cell);
            cell.classList.add('panth-im__mode-cell');
            cell.title = 'Click to toggle Update on Save / by Schedule';
            cell.addEventListener('click', function (e) {
                e.preventDefault();
                toggleMode(id, cell);
            });
        });
    }

    function readCurrentMode(cell) {
        var text = (cell.textContent || '').trim().toLowerCase();
        if (text.indexOf('schedule') !== -1) { return 'schedule'; }
        if (text.indexOf('save') !== -1) { return 'realtime'; }
        var span = cell.querySelector('.grid-severity-notice');
        return span ? 'schedule' : 'realtime';
    }

    function renderModeCell(cell, mode) {
        var cls = mode === 'schedule' ? 'grid-severity-notice' : 'grid-severity-major';
        var label = mode === 'schedule' ? 'Update by Schedule' : 'Update on Save';
        cell.innerHTML = '<span class="' + cls + '"><span>' + esc(label) + '</span></span>';
    }

    function toggleMode(indexerId, cell) {
        var current = readCurrentMode(cell);
        var next = current === 'schedule' ? 'realtime' : 'schedule';

        cell.classList.add('is-saving');
        toast('Updating mode for ' + indexerId + '...', 'info');

        postForm(config.modeUrl, { indexer_id: indexerId, mode: next })
            .then(function (res) {
                if (res.ok && res.body && res.body.success) {
                    renderModeCell(cell, next);
                    toast(res.body.message || 'Mode updated', 'success');
                    poll();
                } else {
                    toast((res.body && res.body.message) || 'Mode change failed', 'error');
                }
            })
            .catch(function (e) { toast('Network error: ' + e.message, 'error'); })
            .finally(function () { cell.classList.remove('is-saving'); });
    }

    /* ------------------------------------------------------------------ */
    /* polling                                                             */

    function applyRowUpdate(row) {
        var map = buildCellMap();
        var cells = map[row.id];
        if (!cells) { return; }

        if (cells.modeCell) {
            renderModeCell(cells.modeCell, row.mode === 'schedule' ? 'schedule' : 'realtime');
        }
        if (cells.statusCell) {
            cells.statusCell.innerHTML = '<span class="' + row.status_class + '"><span>'
                + esc(row.status_label) + '</span></span>'
                + (row.is_working ? '<span class="panth-im__spinner" aria-hidden="true"></span>' : '');
        }
        if (cells.scheduleCell) {
            if (row.schedule && row.schedule.available) {
                cells.scheduleCell.innerHTML = '<span class="' + row.schedule.class + '"><span>'
                    + esc(row.schedule.label) + '</span></span>';
            } else {
                cells.scheduleCell.innerHTML = '';
            }
        }
        if (cells.updatedCell) { cells.updatedCell.textContent = row.updated || ''; }
        if (cells.lastRunCell) {
            if (row.latest_run) {
                cells.lastRunCell.innerHTML = '<span class="panth-im__last-run panth-im__last-run--'
                    + esc(row.latest_run.status) + '">'
                    + esc(row.latest_run.started_at)
                    + (row.latest_run.duration_ms ? ' <small>(' + row.latest_run.duration_ms + ' ms)</small>' : '')
                    + '</span>';
            } else {
                cells.lastRunCell.innerHTML = '<span class="panth-im__muted">-</span>';
            }
        }
    }

    function poll(opts) {
        opts = opts || {};
        if (opts.fromButton && els.refreshBtn) {
            els.refreshBtn.classList.add('is-spinning');
        }
        return getJson(config.statusUrl)
            .then(function (json) {
                if (!json || !json.success) {
                    if (opts.fromButton) { toast('Refresh failed', 'error'); }
                    return;
                }
                (json.indexers || []).forEach(applyRowUpdate);
                bindModeCells();
                if (els.lastRefresh) {
                    els.lastRefresh.textContent = 'Updated ' + new Date().toLocaleTimeString();
                }
                if (opts.fromButton) { toast('Refreshed', 'success'); }
            })
            .catch(function (e) {
                if (opts.fromButton) { toast('Refresh error: ' + e.message, 'error'); }
            })
            .finally(function () {
                if (opts.fromButton && els.refreshBtn) {
                    setTimeout(function () { els.refreshBtn.classList.remove('is-spinning'); }, 200);
                }
            });
    }

    function schedulePoll() {
        clearTimeout(state.pollTimer);
        if (!state.polling) { return; }
        state.pollTimer = setTimeout(function () {
            poll();
            schedulePoll();
        }, config.pollInterval || 5000);
    }

    function setLiveUi(on) {
        if (els.liveLabel) {
            els.liveLabel.classList.toggle('panth-im__live--off', !on);
        }
    }

    /* ------------------------------------------------------------------ */
    /* actions                                                             */

    function markProcessing(ids) {
        var map = buildCellMap();
        (ids || []).forEach(function (id) {
            var cells = map[id];
            if (cells && cells.statusCell) {
                cells.statusCell.innerHTML =
                    '<span class="grid-severity-minor"><span>Processing</span></span>'
                    + '<span class="panth-im__spinner" aria-hidden="true"></span>';
            }
        });
    }

    function runOne(indexerId, btn) {
        markProcessing([indexerId]);
        setBtnLoading(btn, true, 'Reindexing...');
        toast('Reindexing ' + indexerId + '...', 'info');
        return postForm(config.runUrl, { indexer_id: indexerId })
            .then(function (res) {
                if (res.ok && res.body && res.body.success) {
                    toast(res.body.message || 'Done', 'success');
                } else {
                    toast((res.body && res.body.message) || 'Reindex failed', 'error');
                }
                poll();
            })
            .catch(function (e) { toast('Network error: ' + e.message, 'error'); })
            .finally(function () { setBtnLoading(btn, false); });
    }

    function runMass(ids, btn, label) {
        if (!ids || !ids.length) {
            toast(labels.noSelection || 'Nothing to reindex', 'error');
            return Promise.resolve();
        }
        markProcessing(ids);
        setBtnLoading(btn, true, label || 'Reindexing...');
        toast('Reindexing ' + ids.length + ' indexer(s)...', 'info');
        return postForm(config.massRunUrl, { indexer_ids: ids })
            .then(function (res) {
                if (res.body && res.body.summary) {
                    toast(res.body.summary, res.body.success ? 'success' : 'error');
                } else if (res.ok) {
                    toast('Done', 'success');
                } else {
                    toast('Mass reindex failed', 'error');
                }
                poll();
            })
            .catch(function (e) { toast('Network error: ' + e.message, 'error'); })
            .finally(function () { setBtnLoading(btn, false); });
    }

    /* ------------------------------------------------------------------ */
    /* modal                                                               */

    function openModal(indexerId) {
        state.currentIndexerId = indexerId;
        els.modalTitle.textContent = indexerId;
        els.modalBody.innerHTML = '<div class="panth-im__modal-loader">Loading...</div>';
        els.modal.hidden = false;

        getJson(config.detailsUrl + '?indexer_id=' + encodeURIComponent(indexerId))
            .then(function (json) {
                if (!json || !json.success) {
                    els.modalBody.innerHTML = '<p>' + esc((json && json.message) || 'Failed to load.') + '</p>';
                    return;
                }
                els.modalTitle.textContent = json.indexer.title + ' (' + json.indexer.id + ')';
                els.modalBody.innerHTML = renderModal(json.indexer, json.recent_runs);
            })
            .catch(function (e) {
                els.modalBody.innerHTML = '<p>Error: ' + esc(e.message) + '</p>';
            });
    }

    function closeModal() {
        els.modal.hidden = true;
        state.currentIndexerId = null;
    }

    function renderModal(idx, runs) {
        var sched = idx.schedule || {};
        var html = ''
            + '<dl class="panth-im__details-grid">'
            +   '<dt>ID</dt><dd><code>' + esc(idx.id) + '</code></dd>'
            +   '<dt>Description</dt><dd>' + esc(idx.description || '-') + '</dd>'
            +   '<dt>Mode</dt><dd>' + esc(idx.mode_label) + '</dd>'
            +   '<dt>Status</dt><dd><span class="' + idx.status_class + '"><span>'
            +     esc(idx.status_label) + '</span></span>'
            +     (idx.is_working ? ' <span class="panth-im__spinner"></span>' : '') + '</dd>'
            +   '<dt>Schedule</dt><dd>'
            +     (sched.available
                    ? '<span class="' + sched.class + '"><span>' + esc(sched.label) + '</span></span>'
                    : '<span class="panth-im__muted">N/A (real-time)</span>')
            +   '</dd>'
            +   '<dt>Backlog</dt><dd>' + (sched.available ? sched.backlog : '-') + '</dd>'
            +   '<dt>Last update</dt><dd>' + esc(idx.updated || '-') + '</dd>'
            + '</dl>'
            + '<h3 style="margin:16px 0 6px">Recent runs (last 10)</h3>';

        if (!runs || !runs.length) {
            html += '<p class="panth-im__muted">No tracked runs yet. Enable tracking under '
                  + 'Stores -> Configuration -> Panth -> Indexer Manager.</p>';
        } else {
            html += '<table class="panth-im__runs-table"><thead><tr>'
                  + '<th>Started</th><th>Status</th><th>Duration</th><th>Context</th><th>User</th><th>Message</th>'
                  + '</tr></thead><tbody>';
            runs.forEach(function (r) {
                var sevClass = r.status === 'success' ? 'grid-severity-notice'
                             : r.status === 'error'   ? 'grid-severity-critical'
                             : 'grid-severity-minor';
                html += '<tr>'
                      + '<td>' + esc(r.started_at) + '</td>'
                      + '<td><span class="' + sevClass + '"><span>' + esc(r.status) + '</span></span></td>'
                      + '<td>' + (r.duration_ms ? r.duration_ms + ' ms' : '-') + '</td>'
                      + '<td>' + esc(r.context || '-') + '</td>'
                      + '<td>' + esc(r.admin_user || '-') + '</td>'
                      + '<td>' + (r.message ? '<code class="panth-im__msg">' + esc(r.message) + '</code>' : '-') + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
        }
        return html;
    }

    /* ------------------------------------------------------------------ */
    /* inject controls into the native mass-action toolbar                 */

    function injectMassControls() {
        if (state.injected) { return; }
        var massDiv = document.querySelector('.admin__grid-massaction');
        if (!massDiv) { return; }
        if (massDiv.querySelector('.panth-im__mass-extras')) { return; }

        var extras = document.createElement('span');
        extras.className = 'panth-im__mass-extras';
        extras.innerHTML = ''
            + '<button type="button" class="action-default scalable panth-im__btn panth-im__btn--mass-selected" data-panth-action="run-selected">'
            +   '<span>' + esc(labels.reindexSelected || 'Reindex Selected') + '</span>'
            + '</button>'
            + '<button type="button" class="action-default scalable panth-im__btn panth-im__btn--mass-all" data-panth-action="run-all">'
            +   '<span>' + esc(labels.reindexAll || 'Reindex All') + '</span>'
            + '</button>'
            + '<button type="button" class="action-default scalable panth-im__btn panth-im__btn--mass-invalid" data-panth-action="run-invalid">'
            +   '<span>' + esc(labels.reindexInvalid || 'Reindex Invalid') + '</span>'
            + '</button>';

        var rightWrap = document.createElement('span');
        rightWrap.className = 'panth-im__mass-right';
        rightWrap.innerHTML = ''
            + '<label class="panth-im__live" data-panth-role="live-label">'
            +   '<span class="panth-im__live-dot" aria-hidden="true"></span>'
            +   '<input type="checkbox" data-panth-role="toggle-live" checked />'
            +   '<span>' + esc(labels.livePolling || 'Live polling (5s)') + '</span>'
            + '</label>'
            + '<button type="button" class="action-default scalable panth-im__refresh-btn" data-panth-action="refresh-now">'
            +   '<span>' + esc(labels.refreshNow || 'Refresh now') + '</span>'
            + '</button>'
            + '<span class="panth-im__last-refresh" data-panth-role="last-refresh"></span>'
            + '<a class="action-default scalable panth-im__btn panth-im__btn--view" href="' + esc(config.logUrl) + '">'
            +   '<span>' + esc(labels.openRunLog || 'Open Run Log') + '</span>'
            + '</a>';

        var formDiv = massDiv.querySelector('.admin__grid-massaction-form') || massDiv;
        var submit = formDiv.querySelector('button[id*="apply"], button.action-default.action-secondary');
        if (submit && submit.parentNode) {
            submit.parentNode.insertBefore(extras, submit.nextSibling);
        } else {
            formDiv.appendChild(extras);
        }
        massDiv.appendChild(rightWrap);

        els.liveToggle = rightWrap.querySelector('[data-panth-role="toggle-live"]');
        els.liveLabel = rightWrap.querySelector('[data-panth-role="live-label"]');
        els.lastRefresh = rightWrap.querySelector('[data-panth-role="last-refresh"]');
        els.refreshBtn = rightWrap.querySelector('[data-panth-action="refresh-now"]');

        if (els.liveToggle) {
            els.liveToggle.addEventListener('change', function () {
                state.polling = els.liveToggle.checked;
                setLiveUi(state.polling);
                schedulePoll();
            });
        }

        state.injected = true;
        setLiveUi(state.polling);
    }

    /* ------------------------------------------------------------------ */
    /* event wiring                                                        */

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-panth-action]');
        if (!t) { return; }
        var action = t.getAttribute('data-panth-action');
        if (action === 'run') {
            e.preventDefault();
            runOne(t.getAttribute('data-panth-id'), t);
        } else if (action === 'view') {
            e.preventDefault();
            openModal(t.getAttribute('data-panth-id'));
        } else if (action === 'run-selected') {
            e.preventDefault();
            runMass(getSelectedIds(), t, 'Reindexing...');
        } else if (action === 'run-all') {
            e.preventDefault();
            runMass(getAllIds(), t, 'Reindexing all...');
        } else if (action === 'run-invalid') {
            e.preventDefault();
            var invalid = getInvalidIds();
            if (!invalid.length) {
                toast(labels.noInvalid || 'No invalid indexers.', 'success');
                return;
            }
            runMass(invalid, t, 'Reindexing invalid...');
        } else if (action === 'refresh-now') {
            e.preventDefault();
            poll({ fromButton: true });
        } else if (action === 'close-modal') {
            closeModal();
        } else if (action === 'modal-run') {
            if (state.currentIndexerId) { runOne(state.currentIndexerId, t); }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !els.modal.hidden) { closeModal(); }
    });

    /* ------------------------------------------------------------------ */
    /* boot                                                                */

    function boot() {
        injectMassControls();
        if (!state.injected) {
            var tries = 0;
            var iv = setInterval(function () {
                injectMassControls();
                if (state.injected || ++tries > 20) { clearInterval(iv); }
            }, 150);
        }
        bindModeCells();
        // Immediate first poll so the timestamp + Last Run column populate
        // straight away instead of after the 5-second interval.
        poll();
        schedulePoll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
