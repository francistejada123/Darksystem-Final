// static/js/dashboard-animations.js
//
// Shared, reusable dashboard UI animation helpers — used by the
// Admin/Teacher dashboard (index.php, via includes/header.php) and the
// Student Portal dashboard (modules/students/portal/dashboard.php).
//
// No dependencies (no jQuery, no animation library) — plain DOM +
// requestAnimationFrame, matching the rest of the codebase.
//
// IMPORTANT: every number these helpers animate comes from a
// `data-target` attribute that PHP already filled in with the real,
// current value from the database. Nothing in this file ever invents,
// hard-codes, or guesses a number — it only animates the count that
// was already rendered server-side.

(function (global) {
    'use strict';

    function prefersReducedMotion() {
        try {
            return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    function easeOutQuad(t) {
        return 1 - (1 - t) * (1 - t);
    }

    // Same thousands-separator convention as PHP's number_format().
    function formatCounterValue(value, decimals, prefix, suffix) {
        var n = Number(value);
        if (isNaN(n)) n = 0;
        var text = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
        var parts = text.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        text = parts.join('.');
        return (prefix || '') + text + (suffix || '');
    }

    /**
     * Animate a single element's text content from 0 up to targetValue
     * over `duration` ms, ease-out. Formatting hints (decimal places,
     * prefix, suffix — e.g. a trailing "%") are read from the element's
     * own data-* attributes so the required signature stays exactly
     * (element, targetValue, duration):
     *   data-decimals="2"   number of decimal places (default 0)
     *   data-prefix="$"     text before the number (default none)
     *   data-suffix="%"     text after the number (default none)
     *
     * Respects prefers-reduced-motion by jumping straight to the final
     * value instead of animating.
     */
    function animateCounter(element, targetValue, duration) {
        if (!element) return;
        var target = Number(targetValue);
        if (isNaN(target)) target = 0;
        duration = (typeof duration === 'number' && duration > 0) ? duration : 1000;

        var decimals = parseInt(element.getAttribute('data-decimals') || '0', 10) || 0;
        var prefix = element.getAttribute('data-prefix') || '';
        var suffix = element.getAttribute('data-suffix') || '';

        if (prefersReducedMotion()) {
            element.textContent = formatCounterValue(target, decimals, prefix, suffix);
            return;
        }

        var startTime = null;

        function step(timestamp) {
            if (startTime === null) startTime = timestamp;
            var elapsed = timestamp - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var eased = easeOutQuad(progress);
            element.textContent = formatCounterValue(target * eased, decimals, prefix, suffix);
            if (progress < 1) {
                global.requestAnimationFrame(step);
            } else {
                // Land exactly on the real value — no rounding drift.
                element.textContent = formatCounterValue(target, decimals, prefix, suffix);
            }
        }

        global.requestAnimationFrame(step);
    }

    /**
     * Zero out every `[data-counter]` element's visible text BEFORE the
     * page loader is hidden, so the eventual count-up always starts
     * from 0 and the real number (already sitting in the HTML, printed
     * by PHP) never flashes on screen before resetting.
     * Skipped entirely under prefers-reduced-motion — those visitors
     * just see the real, final values immediately, as rendered.
     */
    function primeDashboardCounters(container) {
        if (prefersReducedMotion()) return;
        var root = container || document;
        var nodes = root.querySelectorAll('[data-counter]');
        nodes.forEach(function (el) {
            var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10) || 0;
            var prefix = el.getAttribute('data-prefix') || '';
            var suffix = el.getAttribute('data-suffix') || '';
            el.textContent = formatCounterValue(0, decimals, prefix, suffix);
        });
    }

    /**
     * Animate every `[data-counter]` element inside `container` (default:
     * the whole document) up to its own data-target value.
     */
    function runDashboardCounters(container, duration) {
        var root = container || document;
        var nodes = root.querySelectorAll('[data-counter]');
        nodes.forEach(function (el) {
            var raw = el.getAttribute('data-target');
            if (raw === null) return;
            try {
                animateCounter(el, parseFloat(raw), duration || 1200);
            } catch (e) {
                // Never leave a counter stuck at 0 because of a script
                // error — fall back to the real value immediately.
                var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10) || 0;
                var prefix = el.getAttribute('data-prefix') || '';
                var suffix = el.getAttribute('data-suffix') || '';
                el.textContent = formatCounterValue(parseFloat(raw), decimals, prefix, suffix);
            }
        });
    }

    /**
     * Subtle staggered entrance for dashboard cards — fade + slight
     * upward move + scale, played ~80ms apart. Purely additive: a card
     * is fully visible/functional in the markup with no JS at all; this
     * only adds a short CSS animation via the `dash-anim-in` class
     * (see the "DASHBOARD LOADING & COUNT-UP ANIMATIONS" section of
     * static/css/style.css). Skipped under prefers-reduced-motion.
     */
    function staggerCardEntrance(selector, staggerMs) {
        if (prefersReducedMotion()) return;
        var nodes = document.querySelectorAll(selector);
        nodes.forEach(function (el, i) {
            el.style.animationDelay = (i * (staggerMs || 80)) + 'ms';
            el.classList.add('dash-anim-in');
        });
    }

    function showPageLoader() {
        var el = document.getElementById('pageLoader');
        if (!el) return;
        el.classList.remove('page-loader-hidden');
        el.style.removeProperty('display');
    }

    function hidePageLoader() {
        var el = document.getElementById('pageLoader');
        if (!el) return;
        if (prefersReducedMotion()) {
            el.style.display = 'none';
            return;
        }
        el.classList.add('page-loader-hidden');
        // Remove it from layout/the accessibility tree only once the
        // fade finishes, so it can never sit invisibly on top of the
        // page and block a click.
        global.setTimeout(function () {
            el.style.display = 'none';
        }, 450);
    }

    /**
     * One-call wiring for a dashboard page. This app renders every
     * dashboard fully server-side — by the time the browser has the
     * HTML, the real database values are already in it — so `minDisplay`
     * is purely the brief branded transition the design calls for, never
     * a real network wait, and there is nothing here that can hang: the
     * loader always clears on its own timer, and every counter always
     * ends on the value PHP gave it.
     *
     * Call once, after DOMContentLoaded, from the dashboard page itself:
     *   initDashboardLoad({ cardSelector: '.dash-anim-card' });
     */
    function initDashboardLoad(options) {
        options = options || {};
        var minDisplay = (typeof options.minDisplay === 'number') ? options.minDisplay : 900;
        var counterDuration = options.counterDuration || 1200;
        var cardSelector = options.cardSelector || '.dash-anim-card';
        var stagger = options.stagger || 80;

        primeDashboardCounters();

        function reveal() {
            hidePageLoader();
            runDashboardCounters(document, counterDuration);
            staggerCardEntrance(cardSelector, stagger);
        }

        if (prefersReducedMotion()) {
            reveal();
        } else {
            global.setTimeout(reveal, minDisplay);
        }
    }

    global.animateCounter = animateCounter;
    global.showPageLoader = showPageLoader;
    global.hidePageLoader = hidePageLoader;
    global.initDashboardLoad = initDashboardLoad;
    global.runDashboardCounters = runDashboardCounters;
    global.primeDashboardCounters = primeDashboardCounters;
    global.staggerCardEntrance = staggerCardEntrance;
})(window);