</div>
    </div>
</div>

<!-- Sidebar Overlay (mobile only) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Global Bootstrap tooltip initializer.
// Any element on any page with data-bs-toggle="tooltip" (e.g. the
// High/Medium/Low risk badges) will show its `title` text on hover,
// without every page needing to add its own init script.
document.addEventListener('DOMContentLoaded', function () {
    var tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(function (el) {
        new bootstrap.Tooltip(el, { html: true });
    });

    // The body class itself was already applied pre-render (see the inline
    // script right after <body>, in header.php) so there's no visible
    // flash — this just syncs the logo toggle's aria-expanded to match.
    var logoToggle = document.getElementById('sidebarLogoToggle');
    if (logoToggle) {
        logoToggle.setAttribute('aria-expanded', document.body.classList.contains('sidebar-collapsed') ? 'false' : 'true');
    }
});

// ============================================================
// MOBILE SIDEBAR — off-canvas, overlay, body scroll lock, ESC
// close, close-on-nav-click. Desktop (>768px) never calls this;
// the sidebar stays permanently visible there via CSS alone.
// ============================================================
function setSidebarOpen(open) {
    var sidebar = document.getElementById('mainSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var btn = document.getElementById('mobileMenuBtn');
    if (!sidebar) return;
    sidebar.classList.toggle('show', open);
    if (overlay) overlay.classList.toggle('show', open);
    document.body.classList.toggle('sidebar-open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function toggleSidebar() {
    var sidebar = document.getElementById('mainSidebar');
    if (!sidebar) return;
    setSidebarOpen(!sidebar.classList.contains('show'));
}

// ============================================================
// DESKTOP SIDEBAR COLLAPSE — the logo click. A completely separate
// concept from the mobile drawer above: on desktop it toggles the
// sidebar between its full width and a compact icon-only rail (see
// the body.sidebar-collapsed rules in static/css/style.css), while
// on a small screen the logo just reuses the existing drawer toggle
// instead of inventing a second mobile behavior.
// ============================================================
function toggleSidebarCollapse() {
    if (window.innerWidth <= 768) {
        toggleSidebar();
        return;
    }
    // Desktop navigation is hover-only: never persist or pin the expanded
    // state when the logo is clicked.
    document.body.classList.add('sidebar-collapsed');
    var logoToggle = document.getElementById('sidebarLogoToggle');
    if (logoToggle) logoToggle.setAttribute('aria-expanded', 'false');
}

// HOVER-TO-EXPAND (desktop, collapsed rail only) — static/css/style.css
// already handles the actual widening/label-reveal purely with a
// `body.sidebar-collapsed .sidebar:hover` rule; no JS is needed for that
// part. The one thing CSS can't do is stop each link's native
// title="..." browser tooltip from popping up once the mouse lingers —
// now that the real label text is visible again, that tooltip is just
// redundant clutter on top of it. So: while the mouse is resting on the
// collapsed sidebar, temporarily park every nav-link's title in a data
// attribute (title becomes absent, so the browser has nothing to show),
// then put it straight back the moment the mouse leaves — nothing is
// ever lost, and screen readers / keyboard users (who don't trigger this
// hover path) still get the title exactly as before.
(function () {
    var sidebarEl = document.getElementById('mainSidebar');
    if (!sidebarEl) { return; }

    function parkTitles() {
        if (!document.body.classList.contains('sidebar-collapsed')) { return; }
        sidebarEl.querySelectorAll('.nav-link[title]').forEach(function (link) {
            link.setAttribute('data-title-cache', link.getAttribute('title'));
            link.removeAttribute('title');
        });
    }

    function restoreTitles() {
        sidebarEl.querySelectorAll('.nav-link[data-title-cache]').forEach(function (link) {
            link.setAttribute('title', link.getAttribute('data-title-cache'));
            link.removeAttribute('data-title-cache');
        });
    }

    sidebarEl.addEventListener('mouseenter', parkTitles);
    sidebarEl.addEventListener('mouseleave', restoreTitles);
})();

// HOVER-PEEK REFLOW (desktop, collapsed rail only) — toggles .sidebar-peeking
// on <body> while the mouse rests on the collapsed sidebar. static/css/style.css
// uses that class to widen --sidebar-width itself while peeking, which is what
// makes .main-content (a sibling of .sidebar, not reachable by a plain :hover
// selector) re-flow in step with the sidebar instead of the sidebar overlaying
// it — see the REFLOW ON HOVER comment there. (This class used to also drive a
// dimming scrim over .main-content, back when the peek was an overlay that
// covered the page instead of shifting it; that scrim is now disabled since
// there's nothing left to dim.) Same collapsed-only gating as
// parkTitles/restoreTitles above.
(function () {
    var sidebarEl = document.getElementById('mainSidebar');
    if (!sidebarEl) { return; }

    function showPeekScrim() {
        if (!document.body.classList.contains('sidebar-collapsed')) { return; }
        document.body.classList.add('sidebar-peeking');
    }

    function hidePeekScrim() {
        document.body.classList.remove('sidebar-peeking');
    }

    sidebarEl.addEventListener('mouseenter', showPeekScrim);
    sidebarEl.addEventListener('mouseleave', hidePeekScrim);
})();

// A collapsed desktop rail dragged down to mobile width (or vice versa)
// via a resize/rotate shouldn't get stuck — the mobile drawer and the
// desktop rail are two different mechanisms, so crossing the 769px line
// resets to each mode's normal starting state.
window.addEventListener('resize', function () {
    if (window.innerWidth <= 768) {
        document.body.classList.remove('sidebar-collapsed');
    }
});

// Close sidebar when clicking the overlay or a nav link inside it (mobile only)
document.addEventListener('click', function(e) {
    var sidebar = document.getElementById('mainSidebar');
    if (!sidebar || window.innerWidth > 768) return;
    if (e.target.closest('#sidebarOverlay')) {
        setSidebarOpen(false);
        return;
    }
    var navLink = e.target.closest('#mainSidebar .nav-link');
    if (navLink) {
        // Let the click navigate; just close the panel so the next
        // page doesn't render with the sidebar stuck open.
        setSidebarOpen(false);
    }
});

// ESC closes the sidebar when it's open
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var sidebar = document.getElementById('mainSidebar');
        if (sidebar && sidebar.classList.contains('show')) {
            setSidebarOpen(false);
        }
    }
});

// Close sidebar on resize to desktop (avoids a stuck-open panel
// + locked body scroll if the viewport is resized/rotated)
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        setSidebarOpen(false);
    }
});

// ============================================================
// APP TOPBAR — profile dropdown (open/close) + live date/time.
// The bell/profile markup itself lives in includes/header.php.
// ============================================================
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

(function () {
    var el = document.getElementById('appTopbarDatetime');
    if (!el) return;
    function renderClock() {
        var now = new Date();
        var dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
        var timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        el.textContent = dateStr + ' • ' + timeStr;
    }
    renderClock();
    setInterval(renderClock, 30000);
})();
</script>
</body>
</html>