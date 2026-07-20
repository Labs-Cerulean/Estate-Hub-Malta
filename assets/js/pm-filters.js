/**
 * PM Filters — auto-submit on change, preserve URL state.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form.pm-auto-filter').forEach(function (form) {
        form.querySelectorAll('select, input[type="checkbox"]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (form.id === 'dashboardFilters' && typeof window.syncIslandFilter === 'function') {
                    window.syncIslandFilter();
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    });
});
