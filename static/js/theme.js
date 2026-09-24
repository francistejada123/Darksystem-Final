/* ============================================================
   EGRADEPRO — DARK MODE / LIGHT MODE TOGGLE
   One shared script, loaded on every page that has a
   `.theme-toggle-btn` (Admin/Teacher/Registrar app, Student Portal,
   Online Enrollment, login pages). Pairs with static/css/theme.css.

   - Persists the choice in localStorage under 'egradepro-theme' only
     — never touches the database/session.
   - The very first paint's theme is already decided by a tiny inline
     script in <head> (before this file loads) so there is no flash
     of the wrong theme; this file only needs to handle later toggles.
   - A short, one-shot circular reveal animation plays on toggle
     (View Transitions API where supported, a CSS clip-path overlay
     fallback everywhere else), always skipped under
     prefers-reduced-motion.
   - A transition lock ignores extra clicks while one toggle is still
     animating, so spam-clicking can't queue up overlapping reveals.
   ============================================================ */
(function () {
    'use strict';

    var STORAGE_KEY = 'egradepro-theme';
    var root = document.documentElement;
    var transitioning = false;

    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function setStoredTheme(theme) {
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* private mode etc. — toggle still works this tab */ }
    }
    function currentTheme() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }
    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function updateToggleButtons(theme) {
        var label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    function playIconFlourish(btn) {
        if (!btn || reducedMotion()) return;
        // Restart the animation even on rapid repeat clicks.
        btn.classList.remove('theme-toggle-flip');
        // eslint-disable-next-line no-unused-expressions
        void btn.offsetWidth; // force reflow so re-adding the class replays the keyframes
        btn.classList.add('theme-toggle-flip');
        window.setTimeout(function () { btn.classList.remove('theme-toggle-flip'); }, 500);
    }

    function commitTheme(theme, btn) {
        if (theme === 'dark') root.setAttribute('data-theme', 'dark');
        else root.removeAttribute('data-theme');
        setStoredTheme(theme);
        updateToggleButtons(theme);
        playIconFlourish(btn);
        document.dispatchEvent(new CustomEvent('egradepro:themechange', { detail: { theme: theme } }));
    }

    function runFallbackReveal(nextTheme, btn) {
        var rect = btn ? btn.getBoundingClientRect() : null;
        var x = rect ? (rect.left + rect.width / 2) : window.innerWidth - 40;
        var y = rect ? (rect.top + rect.height / 2) : 40;
        var maxRadius = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );

        var overlay = document.createElement('div');
        overlay.className = 'theme-transition-overlay';
        overlay.style.setProperty('--theme-reveal-x', x + 'px');
        overlay.style.setProperty('--theme-reveal-y', y + 'px');
        overlay.style.setProperty('--theme-reveal-r', maxRadius + 'px');
        overlay.style.background = nextTheme === 'dark' ? '#0B1220' : '#F1F4F8';
        document.body.appendChild(overlay);

        var done = false;
        function finish() {
            if (done) return;
            done = true;
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            transitioning = false;
        }

        // Flip the real page underneath right away; the overlay is what
        // animates on top of it, so the swap itself is instant and the
        // circle is purely the "premium reveal" flourish.
        commitTheme(nextTheme, btn);
        requestAnimationFrame(function () {
            overlay.classList.add('theme-transition-growing');
        });
        overlay.addEventListener('animationend', finish);
        window.setTimeout(finish, 700); // safety net if animationend never fires
    }

    function runViewTransitionReveal(nextTheme, btn) {
        var rect = btn ? btn.getBoundingClientRect() : null;
        var x = rect ? (rect.left + rect.width / 2) : window.innerWidth - 40;
        var y = rect ? (rect.top + rect.height / 2) : 40;
        var endRadius = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );

        var vt = document.startViewTransition(function () {
            commitTheme(nextTheme, btn);
        });

        vt.ready.then(function () {
            var clipFrom = 'circle(0px at ' + x + 'px ' + y + 'px)';
            var clipTo = 'circle(' + endRadius + 'px at ' + x + 'px ' + y + 'px)';
            root.animate(
                { clipPath: [clipFrom, clipTo] },
                { duration: 480, easing: 'cubic-bezier(.22,.61,.36,1)', pseudoElement: '::view-transition-new(root)' }
            );
        }).catch(function () { /* animate not supported on this pseudo-element in this browser — the instant flip above still worked */ });

        vt.finished.finally(function () { transitioning = false; });
    }

    function toggleTheme(evt) {
        if (transitioning) return; // ignore spam-clicks while a reveal is already playing
        var btn = evt ? evt.currentTarget : document.querySelector('.theme-toggle-btn');
        var next = currentTheme() === 'dark' ? 'light' : 'dark';

        if (reducedMotion()) {
            commitTheme(next, btn);
            return;
        }

        transitioning = true;
        if (btn) btn.disabled = true;
        var reenable = function () { if (btn) btn.disabled = false; };

        if (typeof document.startViewTransition === 'function') {
            runViewTransitionReveal(next, btn);
            // transitioning is cleared by vt.finished above
            var check = setInterval(function () { if (!transitioning) { reenable(); clearInterval(check); } }, 60);
        } else {
            runFallbackReveal(next, btn);
            var check2 = setInterval(function () { if (!transitioning) { reenable(); clearInterval(check2); } }, 60);
        }
    }

    function init() {
        updateToggleButtons(currentTheme());
        document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', toggleTheme);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Keep every open tab in sync if the user switches theme in another
    // tab — reading localStorage, never the database/session.
    window.addEventListener('storage', function (e) {
        if (e.key !== STORAGE_KEY || !e.newValue) return;
        if (e.newValue === currentTheme()) return;
        if (e.newValue === 'dark') root.setAttribute('data-theme', 'dark');
        else root.removeAttribute('data-theme');
        updateToggleButtons(e.newValue);
        document.dispatchEvent(new CustomEvent('egradepro:themechange', { detail: { theme: e.newValue } }));
    });
})();
