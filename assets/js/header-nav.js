/**
 * Site header — dropdown toggles and mobile drawer.
 */
document.addEventListener('DOMContentLoaded', function () {
    function closeAllDropdowns() {
        document.querySelectorAll('.nav-dropdown.is-open').forEach(function (el) {
            el.classList.remove('is-open');
            el.querySelector('.nav-dropdown-toggle, .nav-profile-toggle')?.setAttribute('aria-expanded', 'false');
        });
    }

    document.querySelectorAll('.nav-dropdown-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var parent = btn.closest('.nav-dropdown');
            var open = parent.classList.contains('is-open');
            closeAllDropdowns();
            if (!open) {
                parent.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    var profileToggle = document.querySelector('.nav-profile-toggle');
    var profileDropdown = document.querySelector('.nav-profile-dropdown');
    if (profileToggle && profileDropdown) {
        profileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = profileDropdown.classList.contains('is-open');
            closeAllDropdowns();
            if (!open) {
                profileDropdown.classList.add('is-open');
                profileToggle.setAttribute('aria-expanded', 'true');
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.nav-dropdown')) {
            return;
        }
        closeAllDropdowns();
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

    document.querySelectorAll('.mobile-nav-drawer-foot a, .mobile-nav a, .mobile-hub-switcher a').forEach(function (link) {
        link.addEventListener('click', closeMobileNav);
    });
});
