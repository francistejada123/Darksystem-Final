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

// ---- Mobile app shell (phones): top app bar, bottom navigation, full drawer menu ----
// Desktop keeps the sidebar layout; everything below is shown only on small
// screens by static/css/student-portal-classroom-ui.css (.sp-appbar / .sp-bottomnav).
(function () {
    // Sidebar / drawer. My Classes, Tasks and Announcements are in the bottom navigation (phones).
    var NAV = [
        ['dashboard.php', 'fa-th-large', 'Dashboard'],
        ['grades.php', 'fa-chart-bar', 'My Grades'],
        ['attendance.php', 'fa-calendar-check', 'Attendance'],
        ['updates.php?tab=messages', 'fa-envelope', 'Messages'],
        ['tutorial.php', 'fa-play-circle', 'My Tutorials'],
        ['enrollment.php', 'fa-clipboard-check', 'Enrollment'],
        ['profile.php', 'fa-user', 'My Profile']
    ];
    var BOTTOM = [
        ['dashboard.php', 'fa-house', 'Home'],
        ['classes.php', 'fa-chalkboard', 'Classes'],
        ['tasks.php', 'fa-clipboard-list', 'Tasks'],
        ['updates.php', 'fa-bell', 'Updates'],
        ['profile.php', 'fa-user', 'Profile']
    ];
    var page = (location.pathname.split('/').pop() || 'dashboard.php').toLowerCase();
    var messagesTab = /[?&]tab=messages/.test(location.search);

    function active(href, bottom) {
        var file = href.split('?')[0];
        if (file === 'classes.php' && page === 'subject.php') return true;
        if (file === 'updates.php') {
            if (page !== 'updates.php') return false;
            if (bottom) return true;
            return href.indexOf('tab=messages') > -1 ? messagesTab : !messagesTab;
        }
        return file === page;
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('mainSidebar')) return;   // not a Student Portal page
        // On phones the drawer shows full labels (the icon-only rail is for desktop).
        if (window.innerWidth <= 768) document.body.classList.remove('sidebar-collapsed', 'sidebar-peeking');

        // The drawer / sidebar carries the complete navigation.
        var nav = document.querySelector('#mainSidebar nav');
        if (nav) {
            nav.innerHTML = NAV.map(function (n) {
                return '<a href="' + n[0] + '" class="nav-link' + (active(n[0]) ? ' active' : '') + '"><i class="fas ' + n[1] + '"></i> ' + esc(n[2]) + '</a>';
            }).join('') + '<div class="nav-divider"></div><a href="../../../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>';
        }

        // Top app bar: menu + brand (Updates and Profile are in the bottom navigation).
        var bar = document.createElement('header');
        bar.className = 'sp-appbar';
        bar.innerHTML = '<button type="button" class="sp-appbar-btn" aria-label="Open menu"><i class="fas fa-bars"></i></button>'
            + '<a class="sp-appbar-brand" href="dashboard.php"><img src="../../../static/images/logo.png" alt=""><span>EGradePro</span></a>';
        document.body.insertBefore(bar, document.body.firstChild);

        // Phones: the dark / light theme button sits in the top bar next to "EGradePro";
        // on wider screens it goes back to its place in the page topbar.
        var themeBtn = document.getElementById('themeToggleBtn');
        var themeHome = themeBtn ? themeBtn.parentNode : null;
        var themeNext = themeBtn ? themeBtn.nextSibling : null;
        var phoneMq = window.matchMedia ? window.matchMedia('(max-width: 767.98px)') : null;
        function placeTheme() {
            if (!themeBtn) return;
            if (phoneMq ? phoneMq.matches : window.innerWidth <= 767) {
                if (themeBtn.parentNode !== bar) bar.appendChild(themeBtn);
            } else if (themeBtn.parentNode !== themeHome && themeHome) {
                themeHome.insertBefore(themeBtn, themeNext);
            }
        }
        placeTheme();
        window.addEventListener('resize', placeTheme);
        if (phoneMq) {
            if (phoneMq.addEventListener) phoneMq.addEventListener('change', placeTheme);
            else if (phoneMq.addListener) phoneMq.addListener(placeTheme);
        }
        bar.querySelector('button').addEventListener('click', function (e) { e.stopPropagation(); toggleSidebar(); });

        // Bottom navigation.
        var bn = document.createElement('nav');
        bn.className = 'sp-bottomnav';
        bn.setAttribute('aria-label', 'Main');
        bn.innerHTML = BOTTOM.map(function (n) {
            return '<a href="' + n[0] + '" class="' + (active(n[0], true) ? 'active' : '') + '"><i class="fas ' + n[1] + '"></i><span>' + esc(n[2]) + '</span></a>';
        }).join('');
        document.body.appendChild(bn);
        document.body.classList.add('sp-mobile-shell');

        // Unread messages: a badge on Updates in the bottom navigation.
        if (window.fetch) {
            fetch('get_unread_message_count.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var n = parseInt(d && d.unread_count, 10) || 0;
                    var up = bn.querySelector('a[href="updates.php"]');
                    if (n > 0 && up) {
                        var b = document.createElement('span');
                        b.className = 'sp-badge';
                        b.textContent = n > 9 ? '9+' : n;
                        up.appendChild(b);
                    }
                }).catch(function () {});
        }
    });
})();
