// static/js/student-unread-badge.js
//
// Real-time "unread messages" badge for the Student Portal topbar.
// Included on every Student Portal page (dashboard.php, grades.php,
// tutorial.php, messages.php, enrollment.php, profile.php) — one shared
// script instead of duplicating this logic six times, since the Student
// Portal's sidebar/topbar markup is duplicated per-page rather than a
// single shared PHP include.
//
// It finds the topbar Messages icon (Messages moved out of the sidebar
// into the topbar, next to the profile dropdown, so the sidebar only
// lists Dashboard/My Grades/My Tutorials/Enrollment), injects a small
// badge on it, and keeps that badge in sync with the real, live unread
// count from modules/students/portal/get_unread_message_count.php.
// Nothing here ever invents, guesses, or hard-codes a number — every
// value shown comes straight from that endpoint, which itself reads
// student_support_messages.is_seen from the database.

(function () {
    'use strict';

    var POLL_MS = 7000; // within the requested 5-10 second range
    var ENDPOINT = 'get_unread_message_count.php'; // same folder as every portal page

    function prefersReducedMotion() {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    function findMessagesLink() {
        // The topbar Messages icon has a stable id; fall back to the old
        // sidebar selector too, in case any page still has it, so this
        // never silently breaks.
        return document.getElementById('appTopbarMessagesLink') ||
               document.querySelector('.sidebar a.nav-link[href="messages.php"]') ||
               document.querySelector('.sidebar a.nav-link[href$="/messages.php"]');
    }

    function getOrCreateBadge(link) {
        if (!link) return null;
        var badge = link.querySelector('.sidebar-msg-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'sidebar-msg-badge';
            badge.setAttribute('aria-live', 'polite');
            link.appendChild(badge);
        }
        return badge;
    }

    function formatCount(n) {
        return n > 99 ? '99+' : String(n);
    }

    var lastCount = null; // null = "we don't know yet" (no false pulse on first load)

    function applyCount(count) {
        var link = findMessagesLink();
        if (!link) return;

        if (!count || count <= 0) {
            var existing = link.querySelector('.sidebar-msg-badge');
            if (existing) existing.remove();
            lastCount = 0;
            return;
        }

        var badge = getOrCreateBadge(link);
        if (!badge) return;
        badge.textContent = formatCount(count);

        // Only pulse when the count actually went UP from what we last
        // knew (a genuinely new message arriving) — never on the routine
        // poll that simply confirms the same count, and never on the
        // very first check after a page load/refresh.
        if (lastCount !== null && count > lastCount && !prefersReducedMotion()) {
            badge.classList.remove('badge-pulse');
            void badge.offsetWidth; // force reflow so the animation can replay
            badge.classList.add('badge-pulse');
        }
        lastCount = count;
    }

    function poll() {
        fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) throw new Error('bad response');
                return res.json();
            })
            .then(function (data) {
                applyCount(parseInt(data && data.unread_count, 10) || 0);
            })
            .catch(function () {
                // A network hiccup or an expired session — never invent a
                // number here; just leave the badge as it last knew and
                // try again on the next poll.
            });
    }

    function start() {
        if (!findMessagesLink()) return; // not a page with this sidebar
        poll(); // check immediately — no need to wait a full interval for first paint
        window.setInterval(poll, POLL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();