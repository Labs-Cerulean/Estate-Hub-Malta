/**
 * Site header — dropdown toggles and mobile drawer.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.nav-dropdown-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var parent = btn.closest('.nav-dropdown');
            var open = parent.classList.contains('is-open');
            document.querySelectorAll('.nav-dropdown.is-open').forEach(function (el) {
                el.classList.remove('is-open');
                el.querySelector('.nav-dropdown-toggle')?.setAttribute('aria-expanded', 'false');
            });
            if (!open) {
                parent.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', function () {
        document.querySelectorAll('.nav-dropdown.is-open').forEach(function (el) {
            el.classList.remove('is-open');
            el.querySelector('.nav-dropdown-toggle')?.setAttribute('aria-expanded', 'false');
        });
    });

    var overlay = document.getElementById('mobileNavOverlay');
    var toggle = document.getElementById('mobileNavToggle');
    var closeBtn = document.getElementById('mobileNavClose');

    function openMobileNav() {
        if (!overlay) return;
        overlay.hidden = false;
        document.body.classList.add('mobile-nav-open');
        toggle?.setAttribute('aria-expanded', 'true');
    }

    function closeMobileNav() {
        if (!overlay) return;
        overlay.hidden = true;
        document.body.classList.remove('mobile-nav-open');
        toggle?.setAttribute('aria-expanded', 'false');
    }

    toggle?.addEventListener('click', function (e) {
        e.stopPropagation();
        if (overlay?.hidden) openMobileNav();
        else closeMobileNav();
    });

    closeBtn?.addEventListener('click', closeMobileNav);
    overlay?.addEventListener('click', function (e) {
        if (e.target === overlay) closeMobileNav();
    });

    document.querySelectorAll('.mobile-nav a').forEach(function (link) {
        link.addEventListener('click', closeMobileNav);
    });
});
