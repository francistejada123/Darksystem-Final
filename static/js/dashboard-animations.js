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

    /**
     * Show a "Successful Login" style confirmation. On the Admin/Teacher
     * side (includes/header.php), a richer corner-toast `showAppToast()`
     * already exists — this reuses it instead of duplicating it. The
     * Student Portal has no such shared toast (it doesn't include
     * header.php), so there this builds one small, self-contained toast
     * using the same visual language (Inter font, --ink/--success tokens
     * from static/css/style.css), auto-dismissed after 4.5s.
     */
    function notifyLoginSuccess(message) {
        if (!message) return;
        if (typeof global.showAppToast === 'function') {
            global.showAppToast(message, 'success');
            return;
        }
        var container = document.getElementById('__dashAnimToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = '__dashAnimToastContainer';
            container.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2100;display:flex;flex-direction:column;gap:8px;';
            document.body.appendChild(container);
        }
        var toast = document.createElement('div');
        toast.style.cssText = 'display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border,#E5E7EB);' +
            'border-left:3px solid var(--success,#238863);border-radius:8px;padding:10px 14px;' +
            'font-family:var(--font-primary,\'Inter\',sans-serif);font-size:13px;font-weight:500;color:var(--text,#172033);' +
            'box-shadow:0 4px 12px rgba(16,24,40,0.12);opacity:0;transform:translateX(12px);transition:opacity 0.25s ease,transform 0.25s ease;';
        toast.innerHTML = '<i class="fas fa-circle-check" style="color:var(--success,#238863);"></i><span>' +
            String(message).replace(/</g, '&lt;') + '</span>';
        container.appendChild(toast);
        global.requestAnimationFrame(function () {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });
        global.setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(12px)';
            global.setTimeout(function () { toast.remove(); }, 250);
        }, 4500);
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
    /**
     * Sequential dashboard entrance: every element carrying
     * `data-dashboard-order="N"` reveals in ascending order — left to
     * right, then the next row, since that reading order is exactly what
     * the number encodes — with a short stagger between each, instead of
     * the whole dashboard popping in at once.
     *
     * Purely additive/progressive-enhancement: every element already sits
     * in the markup, fully visible and functional, with no JS at all. The
     * "start hidden" state only exists under the `.dashboard-js-ready`
     * class this function adds to <html> right before it starts — see the
     * "DASHBOARD LOADING & COUNT-UP ANIMATIONS" section of style.css — so
     * a script error before this point can never leave the page blank.
     *
     * Per element, once its turn comes:
     *   - any [data-counter] found INSIDE it starts counting up shortly
     *     after (runDashboardCounters already scopes to a given root);
     *   - any [data-dashboard-subitem] children (System Overview's rows,
     *     a table's rows) reveal with their own small stagger, read from
     *     that element's own data-dashboard-substagger (ms);
     *   - a `dashboardWidgetRevealed` CustomEvent fires with {order}, so
     *     unrelated code (e.g. index.php's Chart.js setup) can hook a
     *     specific step without this file needing to know charts exist.
     *
     * Returns false (and does nothing) when the page has no
     * data-dashboard-order markup at all — e.g. the Student Portal
     * dashboard — so the caller can fall back to the older
     * "reveal everything at once" behavior unchanged.
     */
    function runSequentialReveal(options) {
        options = options || {};
        var stagger = (typeof options.stagger === 'number') ? options.stagger : 140;
        var counterDuration = (typeof options.counterDuration === 'number') ? options.counterDuration : 650;
        var counterLag = (typeof options.counterLag === 'number') ? options.counterLag : 80;

        var items = Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-order]'));
        if (!items.length) return false;

        document.documentElement.classList.add('dashboard-js-ready');

        items.sort(function (a, b) {
            return (parseFloat(a.getAttribute('data-dashboard-order')) || 0) -
                   (parseFloat(b.getAttribute('data-dashboard-order')) || 0);
        });

        function revealSubitems(el) {
            var subStagger = parseInt(el.getAttribute('data-dashboard-substagger') || '0', 10);
            var subs = el.querySelectorAll('[data-dashboard-subitem]');
            if (!subs.length) return;
            if (subStagger > 0) {
                subs.forEach(function (sub, j) {
                    // Cap the stagger so a long table never turns into a
                    // multi-second reveal — only the first several rows
                    // get a visibly distinct delay.
                    sub.style.transitionDelay = (subStagger * Math.min(j, 8)) + 'ms';
                });
            }
            global.requestAnimationFrame(function () {
                subs.forEach(function (sub) { sub.classList.add('dashboard-animate-visible'); });
            });
        }

        function fireRevealed(order) {
            try {
                document.dispatchEvent(new CustomEvent('dashboardWidgetRevealed', { detail: { order: order } }));
            } catch (e) { /* older browser without CustomEvent — nothing critical depends on it */ }
        }

        if (prefersReducedMotion()) {
            items.forEach(function (el) {
                el.classList.add('dashboard-animate-visible');
                revealSubitems(el);
                runDashboardCounters(el, 1);
                fireRevealed(parseFloat(el.getAttribute('data-dashboard-order')));
            });
            return true;
        }

        items.forEach(function (el, i) {
            el.style.transitionDelay = (i * stagger) + 'ms';
        });
        global.requestAnimationFrame(function () {
            items.forEach(function (el) { el.classList.add('dashboard-animate-visible'); });
        });

        items.forEach(function (el, i) {
            var order = parseFloat(el.getAttribute('data-dashboard-order'));
            global.setTimeout(function () {
                runDashboardCounters(el, counterDuration);
                revealSubitems(el);
                fireRevealed(order);
            }, i * stagger + counterLag);
        });

        return true;
    }

    function initDashboardLoad(options) {
        options = options || {};
        // Tuned so the whole sequence (loader shown -> fades out -> counters
        // finish landing on the real value) lands at roughly 2-2.6 seconds
        // total, start to finish.
        var minDisplay = (typeof options.minDisplay === 'number') ? options.minDisplay : 1100;
        var counterDuration = options.counterDuration || 1000;
        var cardSelector = options.cardSelector || '.dash-anim-card';
        var stagger = options.stagger || 80;
        var fadeOutMs = 450; // matches the .page-loader CSS opacity transition
        var onRevealed = typeof options.onRevealed === 'function' ? options.onRevealed : null;

        // includes/header.php only renders #pageLoader right after a fresh
        // login (see the $login_success gate there) — on every other
        // dashboard visit/refresh/repeated click there is no loader element
        // at all, so there's nothing to wait on. Reveal immediately instead
        // of sitting through the ~1.5s loader timing meant to cover it.
        var loaderEl = document.getElementById('pageLoader');

        primeDashboardCounters();

        var done = false;
        function playCountUp() {
            if (done) return;
            done = true;
            // Pages built with data-dashboard-order (currently index.php)
            // get the full left-to-right, row-by-row sequential entrance;
            // everything else (e.g. the Student Portal dashboard) keeps
            // the original "reveal every card together" behavior.
            var sequenced = runSequentialReveal({
                stagger: options.orderStagger,
                counterDuration: (typeof options.counterDuration === 'number') ? options.counterDuration : undefined,
                counterLag: options.counterLag
            });
            if (!sequenced) {
                runDashboardCounters(document, counterDuration);
                staggerCardEntrance(cardSelector, stagger);
            }
            // e.g. the one-time "Successful Login" toast — fired only
            // once the dashboard has actually finished revealing itself,
            // never underneath the fading loader.
            if (onRevealed) onRevealed();
        }

        if (prefersReducedMotion()) {
            hidePageLoader();
            playCountUp();
            return;
        }

        if (!loaderEl) {
            playCountUp();
            return;
        }

        // The loader stays fully visible for `minDisplay`, THEN fades out;
        // the count-up only starts once that fade has actually finished.
        // Starting them at the same time (as before) made a total look
        // like it "disappeared" — the number would already be climbing up
        // from 0 while it was still half-hidden under the fading overlay,
        // so a real value would flash and then seem to drop back down.
        // Sequencing them one after another removes that flicker entirely.
        global.setTimeout(function () {
            hidePageLoader();
            global.setTimeout(playCountUp, fadeOutMs);
        }, minDisplay);

        // Hard failsafe: no matter what happens elsewhere on the page
        // (a slow device, an unrelated script error), the dashboard is
        // guaranteed to reveal its real numbers and never stay stuck
        // behind the loader.
        global.setTimeout(function () {
            hidePageLoader();
            playCountUp();
        }, minDisplay + fadeOutMs + 1500);
    }

    global.animateCounter = animateCounter;
    global.showPageLoader = showPageLoader;
    global.hidePageLoader = hidePageLoader;
    global.initDashboardLoad = initDashboardLoad;
    global.runDashboardCounters = runDashboardCounters;
    global.primeDashboardCounters = primeDashboardCounters;
    global.staggerCardEntrance = staggerCardEntrance;
    global.runSequentialReveal = runSequentialReveal;
    global.notifyLoginSuccess = notifyLoginSuccess;
})(window);