// static/js/student-portal-shell.js
//
// Shared Student Portal shell behavior: the mobile sidebar toggle and the
// topbar profile dropdown (My Profile / Logout). Previously each portal
// page (dashboard.php, grades.php, tutorial.php, messages.php,
// enrollment.php, profile.php) carried its own copy of the sidebar-toggle
// script inline — one page's copy had even drifted out of sync (missing
// the overlay toggle). One shared file now, included by all six.
//
// The profile dropdown reuses the exact same element IDs and open/close
// behavior as the Admin/Teacher topbar dropdown (includes/header.php +
// includes/footer.php), so the whole system has one dropdown interaction
// instead of a second one reinvented for students.

function toggleSidebar() {
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.toggle('show');
    if (overlay) overlay.classList.toggle('show');
}

// Student portals use the same hover-expand behavior as the Admin/Teacher
// sidebar: icon-only when idle, full labels while the pointer is over it.
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('mainSidebar');
    if (!sidebar || window.innerWidth <= 768) return;

    try { localStorage.removeItem('sidebarCollapsedV2'); } catch (e) {}
    document.body.classList.add('sidebar-collapsed');
    sidebar.addEventListener('mouseenter', function () {
        document.body.classList.add('sidebar-peeking');
    });
    sidebar.addEventListener('mouseleave', function () {
        document.body.classList.remove('sidebar-peeking');
    });
});

window.addEventListener('resize', function () {
    if (window.innerWidth <= 768) {
        document.body.classList.remove('sidebar-collapsed', 'sidebar-peeking');
    } else {
        document.body.classList.add('sidebar-collapsed');
    }
});

document.addEventListener('click', function (e) {
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var btn = document.querySelector('.mobile-menu-btn');
    if (!sidebar) return;
    if (!sidebar.contains(e.target) && !(btn && btn.contains(e.target)) && window.innerWidth <= 768) {
        sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
    }
});

window.addEventListener('resize', function () {
    if (window.innerWidth > 768) {
        document.querySelector('.sidebar')?.classList.remove('show');
        document.getElementById('sidebarOverlay')?.classList.remove('show');
    }
});

// ESC closes the mobile sidebar when it's open (matches the Admin/Teacher
// sidebar's ESC behavior in includes/footer.php).
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var sidebar = document.querySelector('.sidebar');
        if (sidebar && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            document.getElementById('sidebarOverlay')?.classList.remove('show');
        }
    }
});

// ---- Topbar profile dropdown (My Profile / Logout) ----
function toggleProfileMenu() {
    var wrap = document.getElementById('appTopbarProfile');
    var btn = document.getElementById('appTopbarProfileBtn');
    if (!wrap) return;
    var isOpen = wrap.classList.toggle('open');
    if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

document.addEventListener('click', function (e) {
    var wrap = document.getElementById('appTopbarProfile');
    if (!wrap || !wrap.classList.contains('open')) return;
    if (!wrap.contains(e.target)) {
        wrap.classList.remove('open');
        var btn = document.getElementById('appTopbarProfileBtn');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var wrap = document.getElementById('appTopbarProfile');
        if (wrap && wrap.classList.contains('open')) {
            wrap.classList.remove('open');
            var btn = document.getElementById('appTopbarProfileBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    }
});
