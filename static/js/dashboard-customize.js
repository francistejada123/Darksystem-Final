// static/js/dashboard-customize.js
//
// "Customize Dashboard" — Administrator-only. Lets the Administrator show/
// hide and reorder the EXISTING Admin/Registrar dashboard widgets on
// index.php and save that layout per-account (see save_dashboard_preferences.php
// and includes/dashboard_helpers.php). This file never computes, fetches,
// or alters a single dashboard statistic — it only rearranges/hides the
// widget wrappers that PHP already rendered, and persists that
// presentation choice.
//
// Everything lives in ONE modal (opened immediately when "Customize
// Dashboard" is clicked — no extra step): a single sortable checklist
// (drag a row to reorder, check/uncheck to show/hide) plus Cancel / Reset
// Default / Save Layout right there in its footer. The dashboard grid
// behind it live-previews every change immediately; there is no separate
// drag handle or toggle on the widget cards themselves, and no toolbar
// hidden behind the modal backdrop to fight with.
//
// Closing the modal any way (Save, Cancel, the X button, Escape, or a
// backdrop click) is handled through one shared 'hidden.bs.modal'
// listener: unless a just-completed Save/Reset already committed the
// change, closing the modal reverts to the last saved layout and exits
// edit mode. That keeps every exit path consistent without duplicating
// revert logic per button.
//
// Server-side authorization is the real boundary (save_dashboard_preferences.php
// checks hasRole('Administrator') on every request); this script only
// exists at all when index.php decided to print it, which it only does
// for an Administrator.
//
// Layout is driven by each widget's inline CSS `order` (grid-item order),
// not DOM position — the checklist keeps `order` in sync with its own row
// sequence on every drop, and Cancel/Reset only ever rewrite `order` + a
// visibility class. Widget DOM nodes on the dashboard are never destroyed
// or recreated by this file, so any live content inside them (e.g. the
// Chart.js canvases in Enrollment Status / Enrollment Trend) is never
// disturbed by editing, checking, cancelling, or resetting.

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var grid = document.getElementById('dashboardWidgetGrid');
        var cfg = window.__dashboardCustomize;
        if (!grid || !cfg || grid.getAttribute('data-customizable') !== '1') {
            return; // Not an Administrator, or the page has nothing to customize.
        }

        var customizeBtn = document.getElementById('dashCustomizeBtn');
        var saveBtn = document.getElementById('dwSaveBtn');
        var resetBtn = document.getElementById('dwResetBtn');
        var cancelBtn = document.getElementById('dwCancelBtn');
        var modalEl = document.getElementById('dwWidgetModal');
        var checklistEl = document.getElementById('dwWidgetChecklist');
        var selectAllChk = document.getElementById('dwSelectAllChk');

        if (!customizeBtn || !modalEl || !checklistEl || !saveBtn || !resetBtn || !cancelBtn) return;

        var widgets = Array.prototype.slice.call(grid.querySelectorAll('.dashboard-widget[data-widget-key]'));
        var checklistSortable = null;
        var sortableLoading = null;
        var suppressRevertOnClose = false;
        var committedSnapshot = readSnapshot();

        function getWidget(key) {
            return grid.querySelector('.dashboard-widget[data-widget-key="' + cssEscape(key) + '"]');
        }

        function cssEscape(s) {
            return String(s).replace(/[^a-zA-Z0-9_\-]/g, '');
        }

        function isHidden(el) {
            return el.classList.contains('dw-pending-hidden') || el.style.display === 'none';
        }

        function readSnapshot() {
            var snap = {};
            widgets.forEach(function (el, i) {
                var key = el.getAttribute('data-widget-key');
                var ord = parseInt(el.style.order, 10);
                snap[key] = {
                    order: isNaN(ord) ? (i + 1) : ord,
                    visible: !isHidden(el)
                };
            });
            return snap;
        }

        function applySnapshot(snap) {
            widgets.forEach(function (el) {
                var key = el.getAttribute('data-widget-key');
                var s = snap[key];
                if (!s) return;
                el.style.order = s.order;
                el.classList.remove('dw-fading-out', 'dw-fading-in');
                if (s.visible) {
                    el.classList.remove('dw-pending-hidden');
                    el.style.removeProperty('display');
                } else {
                    el.classList.add('dw-pending-hidden');
                }
            });
        }

        function setWidgetVisible(key, visible, animate) {
            var el = getWidget(key);
            if (!el) return;

            if (visible) {
                el.style.removeProperty('display');
                el.classList.remove('dw-pending-hidden');
                if (animate) {
                    el.classList.remove('dw-fading-out');
                    el.classList.add('dw-fading-in');
                    window.setTimeout(function () { el.classList.remove('dw-fading-in'); }, 260);
                }
            } else {
                if (animate) {
                    el.classList.add('dw-fading-out');
                    window.setTimeout(function () {
                        el.classList.add('dw-pending-hidden');
                        el.classList.remove('dw-fading-out');
                    }, 190);
                } else {
                    el.classList.add('dw-pending-hidden');
                }
            }
        }

        function loadSortable() {
            if (window.Sortable) return Promise.resolve();
            if (sortableLoading) return sortableLoading;
            sortableLoading = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js';
                s.onload = function () { resolve(); };
                s.onerror = function () { reject(new Error('Sortable failed to load')); };
                document.head.appendChild(s);
            });
            return sortableLoading;
        }

        function enterEditMode() {
            committedSnapshot = readSnapshot();
            grid.classList.add('dw-editing');
            customizeBtn.hidden = true;
        }

        function exitEditMode() {
            grid.classList.remove('dw-editing');
            customizeBtn.hidden = false;
        }

        function setBusy(busy) {
            [saveBtn, resetBtn, cancelBtn].forEach(function (b) {
                if (b) b.disabled = !!busy;
            });
        }

        function closeModal() {
            suppressRevertOnClose = true;
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (suppressRevertOnClose) {
                suppressRevertOnClose = false;
            } else {
                applySnapshot(committedSnapshot);
            }
            exitEditMode();
        });

        customizeBtn.addEventListener('click', function () {
            enterEditMode();
            openWidgetModal();
        });

        function openWidgetModal() {
            if (typeof bootstrap === 'undefined') return;
            renderChecklist();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            loadSortable().then(function () {
                if (!window.Sortable) return;
                if (checklistSortable) {
                    checklistSortable.destroy();
                }
                checklistSortable = new window.Sortable(checklistEl, {
                    handle: '.dw-check-drag-handle',
                    animation: 150,
                    ghostClass: 'dw-sortable-ghost',
                    chosenClass: 'dw-sortable-chosen',
                    dragClass: 'dw-sortable-drag',
                    onEnd: syncOrderFromChecklist
                });
            }).catch(function (err) {
                console.warn('Dashboard customize: drag-to-reorder unavailable —', err.message);
            });
        }

        cancelBtn.addEventListener('click', function () {
            closeModal();
        });

        saveBtn.addEventListener('click', function () {
            var payload = widgets.map(function (el, i) {
                var ord = parseInt(el.style.order, 10);
                return {
                    key: el.getAttribute('data-widget-key'),
                    visible: !isHidden(el),
                    order: isNaN(ord) ? (i + 1) : ord
                };
            });

            setBusy(true);
            postJSON({ action: 'save', widgets: payload, csrf_token: cfg.csrfToken })
                .then(function (res) {
                    setBusy(false);
                    if (res && res.success) {
                        committedSnapshot = readSnapshot();
                        closeModal();
                        toast('Dashboard layout saved successfully.', 'success');
                    } else {
                        toast((res && res.message) || 'Could not save your dashboard layout. Please try again.', 'error');
                    }
                })
                .catch(function () {
                    setBusy(false);
                    toast('Could not save your dashboard layout. Please try again.', 'error');
                });
        });

        resetBtn.addEventListener('click', function () {
            suppressRevertOnClose = true;
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            var showResetConfirm = function () {
                showConfirm('Reset your dashboard to the default layout?', {
                    title: 'Reset Dashboard',
                    okText: 'Apply Default',
                    danger: true
                }).then(function (confirmed) {
                    if (!confirmed) {
                        enterEditMode();
                        openWidgetModal();
                        return;
                    }

                    setBusy(true);
                    postJSON({ action: 'reset', csrf_token: cfg.csrfToken })
                        .then(function (res) {
                            setBusy(false);
                            if (res && res.success) {
                                var defaults = {};
                                (cfg.registry || []).forEach(function (w, i) {
                                    defaults[w.key] = { order: i + 1, visible: true };
                                });
                                applySnapshot(defaults);
                                committedSnapshot = readSnapshot();
                                exitEditMode();
                                toast('Dashboard restored to default layout.', 'success');
                            } else {
                                enterEditMode();
                                openWidgetModal();
                                toast((res && res.message) || 'Could not reset your dashboard. Please try again.', 'error');
                            }
                        })
                        .catch(function () {
                            setBusy(false);
                            enterEditMode();
                            openWidgetModal();
                            toast('Could not reset your dashboard. Please try again.', 'error');
                        });
                });
            };

            if (modalEl.classList.contains('show')) {
                modalEl.addEventListener('hidden.bs.modal', showResetConfirm, { once: true });
                modal.hide();
            } else {
                suppressRevertOnClose = false;
                showResetConfirm();
            }
        });

        function updateSelectAllState() {
            if (!selectAllChk) return;
            var boxes = Array.prototype.slice.call(checklistEl.querySelectorAll('input[type="checkbox"]'));
            var total = boxes.length;
            var checkedCount = boxes.filter(function (b) { return b.checked; }).length;
            selectAllChk.checked = total > 0 && checkedCount === total;
            selectAllChk.indeterminate = checkedCount > 0 && checkedCount < total;
        }

        if (selectAllChk) {
            selectAllChk.addEventListener('change', function () {
                var checked = selectAllChk.checked;
                selectAllChk.indeterminate = false;
                var rows = Array.prototype.slice.call(checklistEl.querySelectorAll('.dw-widget-check-row'));
                rows.forEach(function (row) {
                    var input = row.querySelector('input[type="checkbox"]');
                    if (input) input.checked = checked;
                    setWidgetVisible(row.getAttribute('data-widget-key'), checked, true);
                });
            });
        }

        function renderChecklist() {
            checklistEl.innerHTML = '';

            var ordered = widgets.slice().sort(function (a, b) {
                var oa = parseInt(a.style.order, 10) || 0;
                var ob = parseInt(b.style.order, 10) || 0;
                return oa - ob;
            });
            var labelByKey = {};
            (cfg.registry || []).forEach(function (w) { labelByKey[w.key] = w.label; });

            ordered.forEach(function (el) {
                var key = el.getAttribute('data-widget-key');
                var visible = !isHidden(el);

                var row = document.createElement('div');
                row.className = 'dw-widget-check-row';
                row.setAttribute('data-widget-key', key);

                var id = 'dwChk_' + key;
                row.innerHTML =
                    '<span class="dw-check-drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>' +
                    '<input type="checkbox" id="' + id + '"' + (visible ? ' checked' : '') + '>' +
                    '<label for="' + id + '"></label>';
                row.querySelector('label').textContent = labelByKey[key] || key;
                checklistEl.appendChild(row);

                row.querySelector('input').addEventListener('change', function (e) {
                    setWidgetVisible(key, e.target.checked, true);
                    updateSelectAllState();
                });
            });

            updateSelectAllState();
        }

        function syncOrderFromChecklist() {
            var rows = Array.prototype.slice.call(checklistEl.querySelectorAll('.dw-widget-check-row'));
            rows.forEach(function (row, i) {
                var el = getWidget(row.getAttribute('data-widget-key'));
                if (el) el.style.order = i + 1;
            });
        }

        function postJSON(body) {
            return fetch(cfg.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
        }

        function toast(message, type) {
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(message, type);
            }
        }

        function showConfirm(message, opts) {
            if (typeof window.showConfirmModal === 'function') {
                return window.showConfirmModal(message, opts);
            }
            return Promise.resolve(window.confirm(message));
        }
    }
})();
