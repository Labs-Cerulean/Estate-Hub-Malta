/**
 * Searchable entity select — enhances native <select> elements.
 * Supports data-subtitle on options and client→project cascade pairs.
 */
(function () {
    'use strict';

    var RECENT_KEY = 'eh_recent_entities';
    var RECENT_MAX = 5;
    var SEARCH_THRESHOLD = 15;

    function loadRecent(kind) {
        try {
            var all = JSON.parse(localStorage.getItem(RECENT_KEY) || '{}');
            return Array.isArray(all[kind]) ? all[kind] : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecent(kind, value, label) {
        if (!value) return;
        try {
            var all = JSON.parse(localStorage.getItem(RECENT_KEY) || '{}');
            var list = Array.isArray(all[kind]) ? all[kind].filter(function (x) { return x.value !== value; }) : [];
            list.unshift({ value: value, label: label });
            all[kind] = list.slice(0, RECENT_MAX);
            localStorage.setItem(RECENT_KEY, JSON.stringify(all));
        } catch (e) {}
    }

    function optionMeta(option) {
        return {
            value: option.value,
            text: option.textContent.trim(),
            subtitle: option.getAttribute('data-subtitle') || '',
            clientId: option.getAttribute('data-client-id') || '',
            search: (option.textContent.trim() + ' ' + (option.getAttribute('data-subtitle') || '')).toLowerCase()
        };
    }

    function buildEnhancer(select) {
        if (select.dataset.entityEnhanced === '1') return;
        select.dataset.entityEnhanced = '1';

        var wrap = document.createElement('div');
        wrap.className = 'entity-select-wrap';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        var optionCount = select.options.length;
        var searchable = select.classList.contains('entity-select-search') || optionCount > SEARCH_THRESHOLD;
        if (!searchable) return;

        select.classList.add('entity-select-native');

        var ui = document.createElement('div');
        ui.className = 'entity-select-ui';
        ui.innerHTML =
            '<button type="button" class="entity-select-trigger" aria-haspopup="listbox">' +
                '<span class="entity-select-value"></span>' +
                '<span class="entity-select-chevron">▾</span>' +
            '</button>' +
            '<div class="entity-select-panel" hidden>' +
                '<input type="text" class="entity-select-search-input" placeholder="Type to search…" autocomplete="off">' +
                '<ul class="entity-select-list" role="listbox"></ul>' +
            '</div>';
        wrap.appendChild(ui);

        var trigger = ui.querySelector('.entity-select-trigger');
        var valueEl = ui.querySelector('.entity-select-value');
        var panel = ui.querySelector('.entity-select-panel');
        var searchInput = ui.querySelector('.entity-select-search-input');
        var list = ui.querySelector('.entity-select-list');
        var kind = select.dataset.recentKind || select.name || 'entity';
        var allOptions = [];

        function rebuildOptions() {
            allOptions = Array.from(select.options)
                .filter(function (opt) { return opt.value !== ''; })
                .map(optionMeta);
        }

        function renderList(filter) {
            var q = (filter || '').trim().toLowerCase();
            list.innerHTML = '';
            var matches = allOptions.filter(function (opt) {
                return !q || opt.search.indexOf(q) !== -1;
            });

            if (!q) {
                var recent = loadRecent(kind);
                recent.forEach(function (rec) {
                    var found = allOptions.find(function (opt) { return opt.value === rec.value; });
                    if (found) {
                        list.appendChild(buildRow(found, true));
                    }
                });
            }

            if (matches.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'entity-select-empty';
                empty.textContent = 'No matches';
                list.appendChild(empty);
                return;
            }

            matches.forEach(function (opt) {
                if (list.querySelector('[data-value="' + CSS.escape(opt.value) + '"]')) return;
                list.appendChild(buildRow(opt, false));
            });
        }

        function buildRow(opt, isRecent) {
            var li = document.createElement('li');
            li.className = 'entity-select-option' + (select.value === opt.value ? ' is-selected' : '');
            li.dataset.value = opt.value;
            li.setAttribute('role', 'option');
            li.innerHTML =
                '<span class="entity-select-label">' + escapeHtml(opt.text) +
                (isRecent ? ' <em class="entity-select-recent">Recent</em>' : '') + '</span>' +
                (opt.subtitle ? '<span class="entity-select-subtitle">' + escapeHtml(opt.subtitle) + '</span>' : '');
            li.addEventListener('click', function () {
                select.value = opt.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncValue();
                saveRecent(kind, opt.value, opt.text);
                closePanel();
            });
            return li;
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function syncValue() {
            var selected = select.options[select.selectedIndex];
            if (!selected || !selected.value) {
                valueEl.textContent = select.options[0] ? select.options[0].textContent.trim() : 'Select…';
                valueEl.classList.add('is-placeholder');
            } else {
                var sub = selected.getAttribute('data-subtitle');
                valueEl.innerHTML = escapeHtml(selected.textContent.trim()) +
                    (sub ? '<span class="entity-select-subtitle">' + escapeHtml(sub) + '</span>' : '');
                valueEl.classList.remove('is-placeholder');
            }
        }

        function openPanel() {
            panel.hidden = false;
            wrap.classList.add('is-open');
            rebuildOptions();
            renderList('');
            searchInput.value = '';
            setTimeout(function () { searchInput.focus(); }, 0);
        }

        function closePanel() {
            panel.hidden = true;
            wrap.classList.remove('is-open');
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (panel.hidden) openPanel();
            else closePanel();
        });

        searchInput.addEventListener('input', function () {
            renderList(searchInput.value);
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) closePanel();
        });

        select.addEventListener('change', syncValue);
        rebuildOptions();
        syncValue();

        select._entitySelectRefresh = function () {
            rebuildOptions();
            syncValue();
        };
    }

    function initCascade() {
        document.querySelectorAll('[data-entity-cascade="client-project"]').forEach(function (root) {
            if (root.dataset.cascadeInitialized === '1') return;
            root.dataset.cascadeInitialized = '1';
            var clientSelect = root.querySelector('[data-entity-role="client"]');
            var projectSelect = root.querySelector('[data-entity-role="project"]');
            if (!clientSelect || !projectSelect) return;

            var allProjectOptions = Array.from(projectSelect.options).map(function (opt) {
                return {
                    element: opt.cloneNode(true),
                    clientId: opt.getAttribute('data-client-id') || ''
                };
            });

            function filterProjects() {
                var clientId = clientSelect.value;
                var current = projectSelect.value;
                projectSelect.innerHTML = '';
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = projectSelect.dataset.placeholder || '-- Select Project --';
                projectSelect.appendChild(placeholder);

                allProjectOptions.forEach(function (row) {
                    if (!row.element.value) return;
                    if (!clientId || row.clientId === clientId) {
                        projectSelect.appendChild(row.element.cloneNode(true));
                    }
                });

                if (current && Array.from(projectSelect.options).some(function (o) { return o.value === current; })) {
                    projectSelect.value = current;
                }

                if (projectSelect._entitySelectRefresh) {
                    projectSelect._entitySelectRefresh();
                }
            }

            clientSelect.addEventListener('change', filterProjects);
            filterProjects();
        });
    }

    function init() {
        document.querySelectorAll('select.entity-select').forEach(buildEnhancer);
        initCascade();
    }

    window.EntitySelect = {
        refresh: function (select) {
            if (select && select._entitySelectRefresh) select._entitySelectRefresh();
        },
        init: init
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
