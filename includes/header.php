<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    // Dark Mode / Light Mode — decide the theme before any CSS paints so
    // there is never a flash of the wrong theme. Priority: the visitor's
    // own saved choice > their OS preference (first visit only) > light.
    // Reused verbatim on every page with the toggle — see static/js/theme.js.
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) { /* localStorage unavailable — falls back to light */ }
    })();
    </script>
    <title><?php echo $page_title ?? 'EGRADEPRO'; ?></title>
    <?php
    // Cache-busting: browsers cache .css/.js aggressively, so an edit to
    // these shared files (e.g. a dark-mode fix) can silently keep showing
    // the old version until the visitor does a hard refresh. Appending the
    // file's own last-modified time as ?v= makes the browser re-fetch it
    // automatically the moment the file actually changes — no manual cache
    // clearing needed, ever again.
    $__style_v = @filemtime(__DIR__ . '/../static/css/style.css') ?: time();
    $__theme_css_v = @filemtime(__DIR__ . '/../static/css/theme.css') ?: time();
    $__theme_js_v = @filemtime(__DIR__ . '/../static/js/theme.js') ?: time();
    $__dash_anim_v = @filemtime(__DIR__ . '/../static/js/dashboard-animations.js') ?: time();
    ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>static/css/style.css?v=<?php echo $__style_v; ?>" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>static/css/theme.css?v=<?php echo $__theme_css_v; ?>" rel="stylesheet">
    <script src="<?php echo BASE_URL; ?>static/js/dashboard-animations.js?v=<?php echo $__dash_anim_v; ?>"></script>
    <script src="<?php echo BASE_URL; ?>static/js/theme.js?v=<?php echo $__theme_js_v; ?>"></script>
</head>

<body>
    <?php
    // One-time "Successful Login" flash — set by login.php / teacher_login.php
    // right after a successful sign-in, read + immediately consumed (unset)
    // right here, before the page loader below decides whether to show.
    // This is what makes the loader appear ONLY on the page load that comes
    // right after a fresh login — never again on a plain dashboard revisit,
    // refresh, or repeated click on the Dashboard sidebar link, since the
    // flag is already gone by then.
    $login_success = $_SESSION['login_success_message'] ?? null;
    unset($_SESSION['login_success_message']);
    ?>
    <?php if (($current_page ?? '') === 'dashboard' && $login_success): ?>
    <!-- Dashboard page loader — see static/js/dashboard-animations.js.
         Only rendered on the dashboard itself, and only right after login. -->
    <div id="pageLoader" class="page-loader" role="status" aria-live="polite" aria-label="Loading dashboard">
        <div class="page-loader-inner">
            <img src="<?php echo BASE_URL; ?>static/images/logo.png" alt="" class="page-loader-logo" onerror="this.style.display='none';">
            <div class="page-loader-spinner" aria-hidden="true"></div>
            <div class="page-loader-text">Loading Dashboard&hellip;</div>
        </div>
    </div>
    <?php endif; ?>
    <script>
    // Applied before the sidebar is parsed, so every desktop page starts as
    // an icon-only rail. Labels appear only while the sidebar is hovered.
    (function () {
        document.body.classList.add('sidebar-collapsed');
    })();
    </script>
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <div class="col-md-2 sidebar" id="mainSidebar">
                <button type="button" class="sidebar-close-btn" onclick="toggleSidebar()"
                    aria-label="Close navigation menu">
                    <i class="fas fa-times"></i>
                </button>
                <div class="logo" id="sidebarLogoToggle" role="button" tabindex="0"
                    aria-label="Collapse or expand the navigation menu" aria-expanded="false" aria-controls="mainSidebar"
                    onclick="toggleSidebarCollapse()"
                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSidebarCollapse();}">
                    <img src="<?php echo BASE_URL; ?>static/images/logo.png" alt="School Logo" class="sidebar-logo-img"
                        style="width:32px;height:32px;" onerror="this.style.display='none';">
                    <span>EGRADEPRO<br><small
                            style="font-size:12px;font-weight:500;letter-spacing:0.08em;color:rgba(255,255,255,0.5);">GRADING
                            &amp; ENROLLMENT</small></span>
                </div>
                <nav class="nav flex-column">

                    <!-- DASHBOARD -->
                    <a href="<?php echo BASE_URL; ?>index.php"
                        class="nav-link <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>

                    <?php if (hasAnyRole(['Administrator', 'Registrar'])): ?>

                    <!-- ENROLLMENT -->
                    <div class="nav-section-label">Enrollment</div>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/index.php"
                        class="nav-link <?php echo $current_page == 'enrollment' ? 'active' : ''; ?>"
                        title="Walk-in enrollment — Enroll new students, assign sections, and manage enrollment records.">
                        <i class="fas fa-user-plus"></i> Enrollment
                    </a>
                    <?php
                    require_once __DIR__ . '/enrollment_helpers_online.php';
                    $pending_applications = getPendingApplicationCount();
                    ?>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/applications.php"
                        class="nav-link <?php echo $current_page == 'applications' ? 'active' : ''; ?>"
                        title="Review and process online enrollment applications — Approve, reject, and assign sections.">
                        <i class="fas fa-inbox"></i> Online Applications
                        <?php if ($pending_applications > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $pending_applications; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php
                    require_once __DIR__ . '/document_request_helpers.php';
                    $pending_doc_requests = drPendingCount();
                    ?>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/document_requests.php"
                        class="nav-link <?php echo $current_page == 'document_requests' ? 'active' : ''; ?>"
                        title="Official documents requested by students (e.g. Certificate of Enrollment for 4Ps or scholarships).">
                        <i class="fas fa-file-signature"></i> Document Requests
                        <?php if ($pending_doc_requests > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $pending_doc_requests; ?></span>
                        <?php endif; ?>
                    </a>

                    <!-- RECORDS -->
                    <div class="nav-section-label">Records</div>
                    <a href="<?php echo BASE_URL; ?>modules/students/index.php"
                        class="nav-link <?php echo $current_page == 'students' ? 'active' : ''; ?>"
                        title="View, add, edit, and manage all student records.">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/parents/index.php"
                        class="nav-link <?php echo $current_page == 'parents' ? 'active' : ''; ?>"
                        title="Parent Portal accounts and the children they can see.">
                        <i class="fas fa-people-roof"></i> Parents
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/teachers/index.php"
                        class="nav-link <?php echo $current_page == 'teachers' ? 'active' : ''; ?>"
                        title="Manage teacher accounts, qualifications, subject and section assignments.">
                        <i class="fas fa-chalkboard-teacher"></i> Teachers
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/subjects/index.php"
                        class="nav-link <?php echo $current_page == 'subjects' ? 'active' : ''; ?>"
                        title="Add, edit, and assign subjects to sections and teachers.">
                        <i class="fas fa-book"></i> Subjects
                    </a>

                    <!-- ACADEMICS -->
                    <div class="nav-section-label">Academics</div>
                    <a href="<?php echo BASE_URL; ?>modules/grading/index.php"
                        class="nav-link <?php echo $current_page == 'grading' ? 'active' : ''; ?>"
                        title="Encode and manage student grades per subject, section, and term.">
                        <i class="fas fa-calculator"></i> Class Records
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/attendance/index.php"
                        class="nav-link <?php echo $current_page == 'attendance' ? 'active' : ''; ?>"
                        title="Monitor teacher time-in/time-out records and attendance history.">
                        <i class="fas fa-calendar-check"></i> Teacher Attendance
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/reports/index.php"
                        class="nav-link <?php echo $current_page == 'reports' ? 'active' : ''; ?>"
                        title="Generate SF1, SF5, SF9, grade summaries, and at-risk student reports.">
                        <i class="fas fa-file-alt"></i> Reports
                    </a>

                    <!-- MONITORING -->
                    <div class="nav-section-label">Monitoring</div>
                    <a href="<?php echo BASE_URL; ?>modules/tutorial/index.php"
                        class="nav-link <?php echo $current_page == 'tutorial' ? 'active' : ''; ?>"
                        title="Create and monitor tutorial plans for at-risk students with video tracking.">
                        <i class="fas fa-graduation-cap"></i> Tutorial System
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/analytics/index.php"
                        class="nav-link <?php echo $current_page == 'analytics' ? 'active' : ''; ?>"
                        title="View AI-powered student risk analysis, performance insights, and recommendations.">
                        <i class="fas fa-chart-line"></i> AI Analytics
                    </a>

                    <!-- SYSTEM -->
                    <div class="nav-section-label">System</div>
                    <a href="<?php echo BASE_URL; ?>modules/users/index.php"
                        class="nav-link <?php echo $current_page == 'users' ? 'active' : ''; ?>"
                        title="Manage system user accounts, roles, and permissions.">
                        <i class="fas fa-users-cog"></i> Users
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/logs/index.php"
                        class="nav-link <?php echo $current_page == 'logs' ? 'active' : ''; ?>"
                        title="View complete system activity log — login, enrollment, grading, and all changes.">
                        <i class="fas fa-history"></i> Audit Trail
                    </a>

                    <?php endif; ?>

                    <?php if (hasRole('Teacher')): ?>

                    <!-- GRADING -->
                    <div class="nav-section-label">Grading</div>
                    <a href="<?php echo BASE_URL; ?>modules/grading/index.php"
                        class="nav-link <?php echo $current_page == 'grading' ? 'active' : ''; ?>"
                        title="Encode, view, and save student grades for your assigned sections and subjects.">
                        <i class="fas fa-calculator"></i> Grading
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/reports/index.php"
                        class="nav-link <?php echo $current_page == 'reports' ? 'active' : ''; ?>"
                        title="Generate grade reports, class summaries, and student report cards.">
                        <i class="fas fa-file-alt"></i> Reports
                    </a>

                    <?php
                    // ADVISORY group: only for a teacher who is the class adviser of an
                    // active section (sections.adviser_id). Not an adviser -> no group at all.
                    $nav_is_adviser = (bool) getRecord("SELECT 1 FROM sections WHERE adviser_id = ? AND is_active = 1 LIMIT 1", [(int)($_SESSION['user_id'] ?? 0)]);
                    if ($nav_is_adviser): ?>
                    <!-- ADVISORY (the section this teacher advises -- sections.adviser_id) -->
                    <div class="nav-section-label">Advisory</div>
                    <a href="<?php echo BASE_URL; ?>modules/teachers/advisory_students.php"
                        class="nav-link <?php echo $current_page == 'advisory_students' ? 'active' : ''; ?>"
                        title="Students of the section you advise: enrollment, grade completeness, attendance and at-risk status.">
                        <i class="fas fa-users"></i> My Advisory Students
                    </a>
                    <?php endif; ?>

                    <!-- ATTENDANCE & MONITORING -->
                    <div class="nav-section-label">Attendance & Monitoring</div>
                    <a href="<?php echo BASE_URL; ?>modules/attendance/take.php"
                        class="nav-link <?php echo in_array($current_page, ['student_attendance', 'attendance_monitoring'], true) ? 'active' : ''; ?>"
                        title="Scan your students' QR codes to record their attendance (Present/Late) for a class.">
                        <i class="fas fa-qrcode"></i> Attendance
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/attendance/index.php"
                        class="nav-link <?php echo $current_page == 'attendance' ? 'active' : ''; ?>"
                        title="Time in/out for your daily attendance and view your attendance history.">
                        <i class="fas fa-calendar-check"></i> My Attendance
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/tutorial/index.php"
                        class="nav-link <?php echo $current_page == 'tutorial' ? 'active' : ''; ?>"
                        title="View and manage tutorial plans for at-risk students in your sections.">
                        <i class="fas fa-graduation-cap"></i> Tutorial System
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/ai/index.php"
                        class="nav-link <?php echo $current_page == 'ai' ? 'active' : ''; ?>"
                        title="AI-powered student performance analysis and risk assessment for your classes.">
                        <i class="fas fa-robot"></i> AI Analytics
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/ai/resources.php"
                        class="nav-link <?php echo $current_page == 'ai_resources' ? 'active' : ''; ?>"
                        title="Review what students answered on the activities you sent them -- auto-graded quiz scores, and free-response answers waiting for you to check.">
                        <i class="fas fa-inbox"></i> Student Submissions
                    </a>

                    <?php endif; ?>

                    <?php if (hasRole('Enrollment Officer')): ?>

                    <!-- ENROLLMENT OFFICER — scoped down from the full Administrator/
                         Registrar nav above: only the enrollment pages this role can
                         actually open (see the hasAnyRole() checks in modules/enrollment/*).
                         No Students/Teachers/Subjects/Grading/Reports/Users/Audit Trail. -->
                    <div class="nav-section-label">Enrollment</div>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/index.php"
                        class="nav-link <?php echo $current_page == 'enrollment' ? 'active' : ''; ?>"
                        title="Walk-in enrollment — Enroll new students, assign sections, and manage enrollment records.">
                        <i class="fas fa-user-plus"></i> Walk-in Enrollment
                    </a>
                    <?php
                    require_once __DIR__ . '/enrollment_helpers_online.php';
                    $pending_applications = getPendingApplicationCount();
                    ?>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/applications.php"
                        class="nav-link <?php echo $current_page == 'applications' ? 'active' : ''; ?>"
                        title="Review and process online enrollment applications — Approve, reject, and assign sections.">
                        <i class="fas fa-inbox"></i> Online Applications
                        <?php if ($pending_applications > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $pending_applications; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php
                    require_once __DIR__ . '/document_request_helpers.php';
                    $pending_doc_requests = drPendingCount();
                    ?>
                    <a href="<?php echo BASE_URL; ?>modules/enrollment/document_requests.php"
                        class="nav-link <?php echo $current_page == 'document_requests' ? 'active' : ''; ?>"
                        title="Official documents requested by students (e.g. Certificate of Enrollment for 4Ps or scholarships).">
                        <i class="fas fa-file-signature"></i> Document Requests
                        <?php if ($pending_doc_requests > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $pending_doc_requests; ?></span>
                        <?php endif; ?>
                    </a>

                    <?php endif; ?>

                    <!-- Unread notifications count feeds the topbar bell below (Account
                         section — My Profile/Logout — now lives in the topbar profile
                         dropdown instead of the sidebar; see .app-topbar just below). -->
                    <?php
                    $unread_notifications = 0;
                    if (isset($_SESSION['user_id'])) {
                        $unread_row = getRecord("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$_SESSION['user_id']]);
                        $unread_notifications = intval($unread_row['cnt'] ?? 0);
                    }
                    $__topbar_user = isset($_SESSION['user_id']) ? getCurrentUser() : null;
                    ?>
                </nav>
            </div>
            <!-- Legacy scrim element for the collapsed sidebar's hover-peek —
                 now disabled (see "PEEK SCRIM" in static/css/style.css) since
                 the hover-peek re-flows .main-content instead of overlaying
                 it, so there's nothing left to dim. Left in the DOM because
                 the "REFLOW ON HOVER" rule in style.css still relies on the
                 same .sidebar-peeking body class this element's JS toggle
                 (includes/footer.php) was originally built for. -->
            <div class="sidebar-peek-scrim" id="sidebarPeekScrim" aria-hidden="true"></div>
            <!-- MAIN CONTENT -->
            <div class="col-md-10 main-content">

                <!-- App Topbar (all breakpoints) — hamburger/collapse toggle, page
                     title, live date/time, notifications bell, profile dropdown
                     (My Profile + Logout). Replaces the old mobile-only bar. -->
                <div class="app-topbar">
                    <div class="app-topbar-left">
                        <button type="button" class="app-topbar-toggle" id="mobileMenuBtn" onclick="toggleSidebarCollapse()"
                            aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mainSidebar">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="app-topbar-title">
                            <h1><?php echo htmlspecialchars($page_title ?? 'EGRADEPRO'); ?></h1>
                            <?php if (!empty($page_subtitle)): ?><small><?php echo htmlspecialchars($page_subtitle); ?></small><?php endif; ?>
                        </div>
                    </div>
                    <div class="app-topbar-right">
                        <div class="app-topbar-datetime" id="appTopbarDatetime"></div>
                        <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                            <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                            <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                        </button>
                        <a href="<?php echo BASE_URL; ?>modules/notifications/index.php" class="app-topbar-bell" id="appTopbarBell" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <span class="app-topbar-badge" id="appTopbarBellBadge" <?php echo $unread_notifications > 0 ? '' : 'style="display:none;"'; ?>><?php echo $unread_notifications > 99 ? '99+' : $unread_notifications; ?></span>
                        </a>
                        <?php if (isset($_SESSION['user_id'])): ?>
                        <script>
                        // Lightweight "near real-time" bell: the badge itself is still
                        // rendered server-side on every full page load (above) — this
                        // just polls a tiny count endpoint every 30s so a notification
                        // created by a background sync (e.g. a quiz getting auto-graded
                        // while the teacher is sitting on some other page) shows up
                        // without them having to navigate/refresh. No page content is
                        // touched — only this one badge.
                        (function () {
                            var badge = document.getElementById('appTopbarBellBadge');
                            if (!badge) return;
                            function poll() {
                                fetch('<?php echo BASE_URL; ?>modules/notifications/unread_count.php', { credentials: 'same-origin' })
                                    .then(function (r) { return r.json(); })
                                    .then(function (data) {
                                        var count = parseInt(data.count, 10) || 0;
                                        if (count > 0) {
                                            badge.textContent = count > 99 ? '99+' : String(count);
                                            badge.style.display = '';
                                        } else {
                                            badge.style.display = 'none';
                                        }
                                    })
                                    .catch(function () { /* silent — badge just keeps its last known value */ });
                            }
                            setInterval(poll, 30000);
                        })();
                        </script>
                        <?php endif; ?>
                        <?php if ($__topbar_user): ?>
                        <div class="app-topbar-profile" id="appTopbarProfile">
                            <button type="button" class="app-topbar-profile-btn" id="appTopbarProfileBtn"
                                onclick="toggleProfileMenu()" aria-haspopup="true" aria-expanded="false">
                                <div class="app-topbar-avatar">
                                    <?php if (!empty($__topbar_user['profile_image'])): ?>
                                        <img src="<?php echo BASE_URL . 'static/uploads/profiles/' . rawurlencode($__topbar_user['profile_image']); ?>" alt=""
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                        <i class="fas fa-user" style="display:none;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-user"></i>
                                    <?php endif; ?>
                                </div>
                                <span class="app-topbar-profile-text">
                                    <strong><?php echo htmlspecialchars(trim(($__topbar_user['first_name'] ?? '') . ' ' . ($__topbar_user['last_name'] ?? ''))); ?></strong>
                                    <small><?php echo htmlspecialchars($__topbar_user['role_name'] ?? ''); ?></small>
                                </span>
                                <i class="fas fa-chevron-down app-topbar-chevron"></i>
                            </button>
                            <div class="app-topbar-dropdown" id="appTopbarDropdown">
                                <a href="<?php echo BASE_URL; ?>profile.php"><i class="fas fa-user"></i> My Profile</a>
                                <?php if (hasRole('Administrator')): ?>
                                <a href="<?php echo BASE_URL; ?>modules/settings/index.php"><i class="fas fa-gear"></i> Settings</a>
                                <?php endif; ?>
                                <div class="app-topbar-dropdown-divider"></div>
                                <a href="<?php echo BASE_URL; ?>logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // ============================================================
                // GLOBAL FLASH MESSAGES
                // Reads one-time session messages set by add/edit/delete actions
                // across all modules and clears them after showing — now as a
                // corner toast instead of an inline banner.
                // ============================================================
                $flash_success = $_SESSION['success_message'] ?? null;
                $flash_error = $_SESSION['error_message'] ?? null;
                unset($_SESSION['success_message'], $_SESSION['error_message']);

                // One-time "Successful Login" flash — set by login.php /
                // teacher_login.php right after a successful sign-in.
                // On the dashboard (which has the page loader) it waits and
                // fires from initDashboardLoad's onRevealed callback below,
                // so it appears right after the loading screen fades instead
                // of popping up underneath it. On any other page (e.g. the
                // Teacher's post-login landing page, Teacher Management —
                // which has no loader) it just shows right away, like any
                // other flash message.
                $login_success = $_SESSION['login_success_message'] ?? null;
                unset($_SESSION['login_success_message']);
                $is_dashboard_page = (($current_page ?? '') === 'dashboard');
                ?>
                <div id="appToastContainer"></div>
                <?php if ($flash_success): ?>
                    <script>document.addEventListener('DOMContentLoaded', function () {
                        showAppToast(<?php echo json_encode(strip_tags($flash_success)); ?>, 'success');
                    });</script>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <script>document.addEventListener('DOMContentLoaded', function () {
                        showAppToast(<?php echo json_encode(strip_tags($flash_error)); ?>, 'error');
                    });</script>
                <?php endif; ?>
                <?php if ($login_success): ?>
                    <script>
                    var __loginSuccessMessage = <?php echo json_encode(strip_tags($login_success)); ?>;
                    <?php if (!$is_dashboard_page): ?>
                    document.addEventListener('DOMContentLoaded', function () {
                        if (typeof notifyLoginSuccess === 'function') {
                            notifyLoginSuccess(__loginSuccessMessage);
                        } else {
                            showAppToast(__loginSuccessMessage, 'success');
                        }
                    });
                    <?php endif; ?>
                    </script>
                <?php endif; ?>

<!-- ============================================================
     SHARED CONFIRM MODAL (used by appConfirm() across the whole app)
============================================================ -->
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" style="padding: 26px 24px 6px 24px;">
                <div class="app-confirm-icon"><i class="fas fa-question" id="appConfirmIconInner"></i></div>
                <h5 class="modal-title" id="appConfirmTitle">Please Confirm</h5>
                <p class="app-confirm-message" id="appConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-app-cancel" data-bs-dismiss="modal" id="appConfirmCancelBtn">Cancel</button>
                <button type="button" class="btn-app-ok" id="appConfirmOkBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// GLOBAL CONFIRM MODAL — replaces native confirm() everywhere.
// Usage in markup:  onclick="return appConfirm(this, 'Delete this item?')"
//   Optional 3rd arg: {danger: true, okText: 'Delete', title: 'Delete Item'}
// Works for <a href="..."> links and <button type="submit"> inside a <form>.
// ============================================================
function showConfirmModal(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
        var modalEl = document.getElementById('appConfirmModal');
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        document.getElementById('appConfirmTitle').textContent = opts.title || 'Please Confirm';
        document.getElementById('appConfirmMessage').textContent = message;
        document.getElementById('appConfirmIconInner').className = 'fas ' + (opts.icon || (opts.danger ? 'fa-triangle-exclamation' : 'fa-question'));
        document.getElementById('appConfirmOkBtn').textContent = opts.okText || (opts.danger ? 'Delete' : 'Confirm');
        modalEl.classList.toggle('confirm-danger', !!opts.danger);

        var okBtn = document.getElementById('appConfirmOkBtn');
        var settled = false;

        function cleanup(result) {
            if (settled) return;
            settled = true;
            okBtn.removeEventListener('click', onOk);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            resolve(result);
        }
        function onOk() { modal.hide(); cleanup(true); }
        function onHidden() { cleanup(false); }

        okBtn.addEventListener('click', onOk);
        modalEl.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

function appConfirm(el, message, opts) {
    showConfirmModal(message, opts).then(function (confirmed) {
        if (!confirmed) return;
        if (el.tagName === 'A' && el.getAttribute('href')) {
            window.location.href = el.getAttribute('href');
        } else if (el.type === 'submit') {
            var form = el.form || el.closest('form'); // el.form also follows a button's form="..." attribute
            if (form) {
                // form.submit() doesn't include the clicked button's name/value
                // pair on its own — add it back as a hidden field so
                // $_POST['action'] (approve/reject/etc.) still arrives correctly.
                if (el.name) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = el.name;
                    hidden.value = el.value || '1';
                    form.appendChild(hidden);
                }
                HTMLFormElement.prototype.submit.call(form);
            }
        } else if (typeof el.dataset.confirmCallback === 'string' && window[el.dataset.confirmCallback]) {
            window[el.dataset.confirmCallback](el);
        }
    });
    return false; // always block the default action; appConfirm resumes it manually above
}

// ============================================================
// GLOBAL TOAST — replaces the old inline flash banner.
// Usage: showAppToast('Saved successfully.', 'success' | 'error')
// ============================================================
function showAppToast(message, type) {
    type = type === 'error' ? 'error' : 'success';
    var container = document.getElementById('appToastContainer');
    if (!container) return;

    var toast = document.createElement('div');
    toast.className = 'app-toast toast-' + type;
    var icon = type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation';
    toast.innerHTML = '<i class="fas ' + icon + ' app-toast-icon"></i>' +
        '<span>' + message.replace(/</g, '&lt;') + '</span>' +
        '<button type="button" class="app-toast-close">&times;</button>';

    container.appendChild(toast);

    function remove() {
        toast.classList.add('app-toast-out');
        setTimeout(function () { toast.remove(); }, 200);
    }
    toast.querySelector('.app-toast-close').addEventListener('click', remove);
    setTimeout(remove, 4500);
}
</script>
<?php
// Separate general Teacher AI drawer. It is loaded once through the shared
// layout and is intentionally independent from the existing Ask AI feature.
if (hasRole('Teacher') && ($current_page ?? '') !== 'profile') {
    require_once __DIR__ . '/teacher_ai.php';
}
?>