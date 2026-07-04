/**
 * Documentation vault — split context filter (All / Client Hub / Project).
 */
(function () {
    'use strict';

    function initDocContextFilter() {
        var root = document.getElementById('docContextFilter');
        if (!root) return;

        var typeSelect = root.querySelector('[name="context_type"]');
        var entitySelect = root.querySelector('[name="context_entity"]');
        var hiddenProjectId = root.querySelector('[name="project_id"]');
        if (!typeSelect || !entitySelect || !hiddenProjectId) return;

        var clientOptions = JSON.parse(root.dataset.clients || '[]');
        var projectOptions = JSON.parse(root.dataset.projects || '[]');

        function rebuildEntityOptions() {
            var type = typeSelect.value;
            entitySelect.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = type === 'all' ? 'All folders' : ('Select ' + (type === 'client' ? 'client hub' : 'project') + '…');
            entitySelect.appendChild(placeholder);

            if (type === 'client') {
                clientOptions.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = 'client_' + c.id;
                    opt.textContent = c.name;
                    if (c.subtitle) opt.setAttribute('data-subtitle', c.subtitle);
                    entitySelect.appendChild(opt);
                });
            } else if (type === 'project') {
                projectOptions.forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = String(p.id);
                    opt.textContent = p.name;
                    if (p.subtitle) opt.setAttribute('data-subtitle', p.subtitle);
                    entitySelect.appendChild(opt);
                });
            }

            entitySelect.disabled = type === 'all';

            if (entitySelect._entitySelectRefresh) {
                entitySelect._entitySelectRefresh();
            }
        }

        function syncHidden() {
            if (typeSelect.value === 'all') {
                hiddenProjectId.value = 'all';
            } else {
                hiddenProjectId.value = entitySelect.value || 'all';
            }
        }

        typeSelect.addEventListener('change', function () {
            rebuildEntityOptions();
            syncHidden();
        });

        entitySelect.addEventListener('change', syncHidden);

        var selected = root.dataset.selected || 'all';
        if (selected === 'all') {
            typeSelect.value = 'all';
        } else if (selected.indexOf('client_') === 0) {
            typeSelect.value = 'client';
            rebuildEntityOptions();
            entitySelect.value = selected;
        } else {
            typeSelect.value = 'project';
            rebuildEntityOptions();
            entitySelect.value = selected;
        }
        syncHidden();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocContextFilter);
    } else {
        initDocContextFilter();
    }
})();
