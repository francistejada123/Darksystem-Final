<?php
// modules/students/portal/dashboard.php
// Student Portal Dashboard

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/risk_helpers.php';
require_once '../../../includes/grading_helpers.php';
require_once '../../../includes/student_portal_ui.php'; // spSubjectColor(), spInitial() — for the Classes grid below
require_once '../../../includes/assessment_material_helpers.php'; // aim_getStudentTasks() (mobile: pending / upcoming)
require_once '../../../includes/student_portal_data.php';         // mobile dashboard summary
require_once '../../../includes/student_password.php';            // spwMustChangePassword()

// Check if student is logged in
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

// First login: the account still holds the temporary password issued when the
// Admin approved the enrollment. The portal may not be used until it is changed,
// so send the student to the Change Password page. This is the portal-wide guard
// for the dashboard; the other portal pages ask the same question through
// spwMustChangePassword() (see spwRequireChangedPassword()).
$spw_gate = getRecord("SELECT portal_password_temp FROM students WHERE student_id = ? AND is_active = 1", [$_SESSION['student_id']]);
if (spwMustChangePassword($spw_gate ?: [])) {
    header('Location: ../../../student/change_password.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$lrn = $_SESSION['student_lrn'];
$student_name = $_SESSION['student_name'];

// One-time "Successful Login" flash — set by student_login.php right
// after a successful sign-in. Shown once, right after the loading
// screen fades (see the onRevealed callback near the bottom of this
// file), then cleared here so a later refresh will not show it again.
$login_success = $_SESSION['login_success_message'] ?? null;
unset($_SESSION['login_success_message']);

// Shown once right after the first-login password change, then cleared.
$pw_changed = $_SESSION['password_changed_message'] ?? null;
unset($_SESSION['password_changed_message']);

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// Get student data including profile image
$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);

// Get student grades (filtered by active school year)
$grades = getRecords("
    SELECT g.*, sub.subject_name, sub.subject_code
    FROM grades g
    JOIN subjects sub ON g.subject_code = sub.subject_code
    WHERE g.student_id = ? AND g.school_year_id = ?
      AND " . gh_validTermSql('g') . "
    ORDER BY g.term
", [$student_id, $active_sy['sy_id'] ?? 0]);

// Calculate average (exclude 0 grades from incomplete data)
$average = 0;
if ($grades && count($grades) > 0) {
    $total = 0;
    $count = 0;
    foreach ($grades as $g) {
        if ($g['final_grade'] > 0) {
            $total += $g['final_grade'];
            $count++;
        }
    }
    $average = $count > 0 ? $total / $count : 0;
}

// Get enrollment info
$enrollment = getRecord("
    SELECT e.*, sec.section_name, sy.sy_name
    FROM enrollments e
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id
    WHERE e.student_id = ? AND sy.is_active = 1
", [$student_id]);

// ---- Classes grid (Google-Classroom-style) ----
// Every subject a teacher actually teaches this student's section this
// school year — the same source of truth link_resource_item.php and the
// rest of the AI-resource feature already use for "is this teacher really
// assigned to this student's subject". Deliberately NOT derived from
// $grades above: a subject with no grade recorded yet (too early in the
// term) should still show up as a class the student can open.
// Curriculum subjects of the student's grade / strand / section, each matched
// with the teacher assigned in Teacher Management (or "To Be Assigned").
$classes = spdCurriculumClasses($student_id, $active_sy['sy_id'] ?? 0);

// Unseen-message count per subject — powers each class card's small "New"
// badge WITHOUT showing any message content on the dashboard itself; the
// student has to actually open that subject to read it (see
// modules/students/portal/subject.php, which is what marks these seen).
$unread_by_subject = [];
$unread_rows = getRecords(
    "SELECT subject_code, COUNT(*) AS cnt FROM student_support_messages
     WHERE student_id = ? AND status = 'Sent' AND is_seen = 0
     GROUP BY subject_code",
    [$student_id]
);
foreach ($unread_rows as $r) {
    $unread_by_subject[$r['subject_code']] = (int) $r['cnt'];
}

// Classroom classwork still to turn in, per subject ("N work to do").
$todo_by_subject = [];
$todo_table = getRecord("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classwork_assignment_students'");
if ($todo_table && (int) $todo_table['n'] > 0) {
    foreach (getRecords(
        "SELECT ca.subject_code, COUNT(*) AS cnt FROM classwork_assignment_students cs
         INNER JOIN classwork_assignments ca ON ca.assignment_id = cs.assignment_id AND ca.status = 'Published' AND ca.school_year_id = ?
         WHERE cs.student_id = ? AND cs.status = 'Assigned'
         GROUP BY ca.subject_code",
        [$active_sy['sy_id'] ?? 0, $student_id]
    ) as $r) {
        $todo_by_subject[$r['subject_code']] = (int) $r['cnt'];
    }
}

// Risk analysis — computed live from actual grade data (see
// includes/risk_helpers.php). Scoped to this logged-in student only.
$risk = calculateStudentRisk($student_id);

// Get AI chatbot recommendations (from teacher's AI Assistant conversations)
$chat_recommendations = getRecords("
    SELECT * FROM ai_chat_recommendations
    WHERE student_id = ?
    ORDER BY created_at DESC
    LIMIT 10
", [$student_id]);

// Check profile image
$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

$page_title = 'Student Dashboard';
// Phone layout only: pending tasks, attendance and the next activities.
$sp_ctx = spdContext($student_id);
$sp_tasks = spdTasks($student_id, $sp_ctx);
$sp_pending = count(array_filter($sp_tasks, function ($t) { return in_array($t['state'], ['To do', 'Active'], true); }));
$sp_upcoming = spdUpcoming($sp_tasks, 5);
$sp_att = spdAttendance($student_id, $enrollment['section_id'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('egradepro-theme');
            if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (e) {}
    })();
    </script>
    <title>Student Dashboard - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../../static/css/style.css" rel="stylesheet">
    <link href="../../../static/css/portal-theme.css" rel="stylesheet">
    <link href="../../../static/css/student-portal-classroom-ui.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/student-portal-classroom-ui.css'); ?>" rel="stylesheet">
    <link href="../../../static/css/theme.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/theme.css'); ?>" rel="stylesheet">
    <script src="../../../static/js/dashboard-animations.js"></script>
    <script src="../../../static/js/student-portal-shell.js"></script>
    <script src="../../../static/js/theme.js"></script>
    <style>
        :root {
            --portal-bg: #F1F4F8;
            --portal-primary: #14213D;
            --portal-card: #ffffff;
            --portal-border: #e5e7eb;
            --portal-green: #238863;
            --portal-red: #C94A4A;
            --portal-yellow: #B7791F;
            --portal-gray: #667085;
        }
        * { box-sizing: border-box; }
        body { background: var(--portal-bg); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; }

        .sidebar {
            background: var(--portal-primary) !important;
            min-height: 100vh;
            padding: 0;
            display: flex;
            flex-direction: column;
        }
        .sidebar .logo {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar .logo img { height: 36px; width: 36px; border-radius: 8px; object-fit: contain; }
        .sidebar .logo span { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: 0.02em; line-height: 1.3; }
        .sidebar .logo small { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }

        .sidebar .nav { padding: 14px 0; flex: 1; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.55);
            font-size: 15px; font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-link i { width: 20px; text-align: center; font-size: 17px; flex-shrink: 0; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #fff; }
        .sidebar .nav-link.text-danger { color: rgba(201,74,74,0.7); }
        .sidebar .nav-link.text-danger:hover { color: #C94A4A; background: rgba(201,74,74,0.08); }
        .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 8px 20px; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 12.5px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }

        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--portal-border);
        }
        .topbar-avatar-placeholder { width: 44px; height: 44px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: var(--portal-gray); font-size: 20px; }
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 13.5px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .btn-portal-logout {
            padding: 7px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 500;
            border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray);
            text-decoration: none; transition: all 0.15s;
        }
        .btn-portal-logout:hover { background: #f1f5f9; color: var(--portal-primary); }
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 20px; font-size: 13.5px; font-weight: 600;
        }
        .status-badge.approved { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .status-badge.pending { background: rgba(183,121,31,0.1); color: var(--portal-yellow); }

        .stat-card {
            background: var(--portal-card); border: 1px solid var(--portal-border);
            border-radius: 8px; padding: 20px;
        }
        .stat-card h6 { margin: 0 0 6px; font-size: 13.5px; font-weight: 500; color: var(--portal-gray); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: var(--portal-primary); line-height: 1; }
        .stat-card .stat-value.green { color: var(--portal-green); }
        .stat-card .stat-value.red { color: var(--portal-red); }

        .card-portal {
            background: var(--portal-card); border: 1px solid var(--portal-border);
            border-radius: 8px; margin-bottom: 20px;
        }
        .card-portal-header {
            padding: 16px 20px; border-bottom: 1px solid var(--portal-border);
            font-size: 15px; font-weight: 600; color: var(--portal-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .card-portal-header i { color: var(--portal-primary); }
        .card-portal-body { padding: 20px; }

        .risk-badge { padding: 4px 10px; border-radius: 4px; font-size: 13.5px; font-weight: 600; }
        .risk-low { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .risk-medium { background: rgba(183,121,31,0.1); color: var(--portal-yellow); }
        .risk-high { background: rgba(201,74,74,0.08); color: var(--portal-red); }

        .grade-table { width: 100%; border-collapse: collapse; }
        .grade-table th {
            text-align: left; padding: 10px 16px; font-size: 12.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--portal-gray); border-bottom: 1px solid var(--portal-border);
            background: #f9fafb;
        }
        .grade-table td { padding: 12px 16px; font-size: 14.5px; color: #374151; border-bottom: 1px solid #f3f4f6; }
        .grade-table tr:last-child td { border-bottom: none; }
        .grade-table tr:hover td { background: #f9fafb; }
        .remark-pass { background: rgba(35,136,99,0.08); color: var(--portal-green); padding: 3px 10px; border-radius: 4px; font-size: 13.5px; font-weight: 600; }
        .remark-fail { background: rgba(201,74,74,0.08); color: var(--portal-red); padding: 3px 10px; border-radius: 4px; font-size: 13.5px; font-weight: 600; }

        .rec-item { display: flex; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f3f4f6; }
        .rec-item:last-child { border-bottom: none; }
        .rec-icon { width: 32px; height: 32px; border-radius: 50%; background: rgba(20,33,61,0.06); color: var(--portal-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14.5px; }
        .rec-text { font-size: 14.5px; color: #374151; line-height: 1.6; white-space: pre-line; }
        .rec-date { font-size: 12.5px; color: var(--portal-gray); margin-top: 4px; }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: #9ca3af; }

        /* ---- Classes grid (Google-Classroom-style) ---- */
        .section-heading { font-size: 15px; font-weight: 700; color: var(--portal-primary); margin: 4px 0 14px; display: flex; align-items: center; gap: 8px; }
        .classes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 18px; margin-bottom: 28px; }
        .class-card { display: block; text-decoration: none; background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 10px; overflow: hidden; transition: box-shadow 0.15s, transform 0.15s; }
        .class-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .class-card-head { background-color: #123B6D; background-image: linear-gradient(110deg, rgba(8,31,61,0.92), rgba(18,59,109,0.72)), url('../../../static/images/school%20bldg%20bg.png'); background-size: cover; background-position: center 42%; padding: 16px 16px 32px; position: relative; color: #fff; }
        .class-card-badge { position: absolute; top: 10px; right: 14px; background: rgba(255,255,255,0.92); color: #123B6D; font-size: 12px; font-weight: 800; padding: 3px 9px; border-radius: 99px; }
        .class-card-title { font-size: 16px; font-weight: 800; line-height: 1.3; margin-bottom: 4px; padding-right: 46px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.55); }
        .class-card-teacher { font-size: 13.5px; opacity: 1; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.55); }
        .class-card-avatar { position: absolute; bottom: -22px; right: 16px; width: 52px; height: 52px; border-radius: 50%; background: #fff; color: var(--card-color); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; border: 3px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
        .class-card-body { padding: 30px 16px 14px; min-height: 20px; }
        .class-card-update { font-size: 14px; color: var(--portal-primary); font-weight: 600; }
        .class-card-update.muted { color: var(--portal-gray); font-weight: 500; }
        .class-card-footer { padding: 10px 16px; border-top: 1px solid var(--portal-border); font-size: 13.5px; font-weight: 600; color: var(--portal-gray); display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
        .class-card:hover .class-card-footer { color: var(--portal-primary); }

        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .topbar h4 { font-size: 16px !important; }
            .stat-card .stat-value { font-size: 22px; }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar { display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: var(--sidebar-width, 240px) !important; height: 100vh !important; overflow-y: auto !important; }
            .main-content { margin-left: var(--sidebar-width, 240px) !important; width: calc(100% - var(--sidebar-width, 240px)) !important; max-width: calc(100% - var(--sidebar-width, 240px)) !important; flex: 0 0 auto !important; }
        }
    </style>
    <?php include __DIR__ . '/../../../includes/pwa_head.php'; ?>
</head>
<body>
<?php if ($login_success || $pw_changed): ?>
<div id="pageLoader" class="page-loader" role="status" aria-live="polite" aria-label="Loading dashboard">
    <div class="page-loader-inner">
        <img src="../../../static/images/logo.png" alt="" class="page-loader-logo" onerror="this.style.display='none';">
        <div class="page-loader-spinner" aria-hidden="true"></div>
        <div class="page-loader-text">Loading Dashboard&hellip;</div>
    </div>
</div>
<?php endif; ?>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link active"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <!-- Top Bar -->
            <div class="topbar">
                <div class="topbar-left sp-desktop-welcome">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4>Welcome, <?php echo htmlspecialchars($student_name); ?></h4>
                        <small>LRN: <?php echo htmlspecialchars($lrn); ?></small>
                    </div>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
                    <span class="status-badge <?php echo ($enrollment && $enrollment['enrollment_status'] === 'Approved') ? 'approved' : 'pending'; ?>">
                        <i class="fas fa-circle" style="font-size:6px;"></i> <?php echo $enrollment ? htmlspecialchars($enrollment['enrollment_status']) : 'Not Enrolled'; ?>
                    </span>
                    <div class="app-topbar-profile" id="appTopbarProfile">
                        <button type="button" class="app-topbar-profile-btn" id="appTopbarProfileBtn" onclick="toggleProfileMenu()" aria-haspopup="true" aria-expanded="false">
                            <div class="app-topbar-avatar">
                                <?php if ($profile_image): ?>
                                    <img src="<?php echo $profile_image; ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <span class="app-topbar-profile-text">
                                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                                <small>Student</small>
                            </span>
                            <i class="fas fa-chevron-down app-topbar-chevron"></i>
                        </button>
                        <div class="app-topbar-dropdown" id="appTopbarDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <div class="app-topbar-dropdown-divider"></div>
                            <a href="../../../logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Phone: welcome card + summary (desktop keeps the stats row below) -->
            <div class="sp-welcome">
                <small>Welcome back,</small>
                <h5><?php echo htmlspecialchars($student_name); ?></h5>
                <span class="sp-chip"><i class="fas fa-layer-group me-1"></i><?php echo htmlspecialchars($enrollment['grade_level'] ?? 'Grade —'); ?></span>
                <span class="sp-chip"><i class="fas fa-users me-1"></i><?php echo htmlspecialchars($enrollment['section_name'] ?? 'No section yet'); ?></span>
                <span class="sp-chip">LRN <?php echo htmlspecialchars($lrn); ?></span>
            </div>
            <div class="sp-summary">
                <a href="tasks.php"><i class="fas fa-clipboard-list"></i><strong><?php echo (int) $sp_pending; ?></strong><span>Pending Tasks</span></a>
                <a href="grades.php"><i class="fas fa-chart-line"></i><strong><?php echo $average > 0 ? number_format($average, 1) : '—'; ?></strong><span>Average Grade</span></a>
                <a href="attendance.php"><i class="fas fa-calendar-check"></i><strong><?php echo $sp_att['rate'] !== null ? $sp_att['rate'] . '%' : '—'; ?></strong><span>Attendance</span></a>
            </div>

            <!-- Stats -->
            <div class="row g-3 mb-4 sp-desktop-stats">
                <div class="col-6 col-md-4">
                    <div class="stat-card dash-anim-card">
                        <h6>Average</h6>
                        <div class="stat-value" id="dashAverage" data-counter data-target="<?php echo (float)$average; ?>" data-decimals="2" data-suffix="%"><?php echo number_format($average, 2); ?>%</div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card dash-anim-card">
                        <h6>Grade Level</h6>
                        <div class="stat-value"><?php echo htmlspecialchars($enrollment['grade_level'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card dash-anim-card">
                        <h6>Section</h6>
                        <div class="stat-value" style="font-size:18px;"><?php echo htmlspecialchars($enrollment['section_name'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Classes (Google-Classroom-style) -->
            <div class="section-heading"><i class="fas fa-chalkboard"></i> Classes</div>
            <?php if (empty($classes)): ?>
                <div class="card-portal">
                    <div class="empty-state">
                        <i class="fas fa-chalkboard"></i>
                        <h5>No classes yet</h5>
                        <p class="mb-0">Your subjects show up here once you are enrolled in a section.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="classes-grid">
                    <?php foreach ($classes as $c): ?>
                        <?php
                            $color = spSubjectColor($c['subject_code']);
                            $teacher_name = spdTeacherName($c);
                            $unread = $unread_by_subject[$c['subject_code']] ?? 0;
                            $class_schedule = empty($c['teacher_id']) ? [] : getRecords("SELECT day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1 ORDER BY day_of_week, start_time", [(int)$c['teacher_id'], $c['subject_code'], (int)$c['section_id'], (int)$c['school_year_id']]);
                            $portal_day_names = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri'];
                            $class_schedule_text = $class_schedule ? date('g:i', strtotime($class_schedule[0]['start_time'])) . '–' . date('g:i A', strtotime($class_schedule[0]['end_time'])) . ' · ' . implode('-', array_map(function ($row) use ($portal_day_names) { return $portal_day_names[(int)$row['day_of_week']] ?? ''; }, $class_schedule)) : '';
                        ?>
                        <a class="class-card" href="subject.php?subject=<?php echo urlencode($c['subject_code']); ?>" style="--card-color: <?php echo htmlspecialchars($color); ?>;">
                            <div class="class-card-head">
                                <?php if ($unread > 0): ?>
                                    <span class="class-card-badge"><?php echo $unread; ?> New</span>
                                <?php endif; ?>
                                <div class="class-card-title"><?php echo htmlspecialchars($c['subject_name']); ?></div>
                                <div class="class-card-teacher"><?php echo htmlspecialchars($teacher_name); ?></div>
                                <div class="class-card-avatar"><?php echo !empty($c['teacher_id']) ? htmlspecialchars(spInitial($c['teacher_first'])) : '<i class="fas fa-user-clock"></i>'; ?></div>
                            </div>
                            <div class="class-card-body">
                                <?php if ($class_schedule_text): ?><div class="class-card-update"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($class_schedule_text); ?></div><?php endif; ?>
                                <?php $todo = $todo_by_subject[$c['subject_code']] ?? 0; ?>
                                <?php if ($todo > 0): ?>
                                    <div class="class-card-update"><i class="fas fa-clipboard-list me-1"></i><?php echo $todo; ?> work to do</div>
                                <?php endif; ?>
                                <?php if ($unread > 0): ?>
                                    <div class="class-card-update"><i class="fas fa-bell me-1"></i>You have <?php echo $unread; ?> new update<?php echo $unread === 1 ? '' : 's'; ?></div>
                                <?php elseif ($todo === 0): ?>
                                    <div class="class-card-update muted">No new updates</div>
                                <?php endif; ?>
                            </div>
                            <div class="class-card-footer">View Class <i class="fas fa-arrow-right"></i></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Phone: upcoming activities below the classes -->
            <div class="sp-upcoming">
                <div class="sp-section-title"><i class="fas fa-calendar-day"></i> Upcoming Activities <a href="tasks.php" class="ms-auto small fw-semibold" style="text-decoration:none;">See all</a></div>
                <?php if (!$sp_upcoming): ?>
                    <div class="sp-item"><span class="sp-item-icon"><i class="fas fa-check"></i></span><span class="sp-item-main"><strong>You're all caught up</strong><small>No activities to do right now.</small></span></div>
                <?php else: ?>
                    <div class="sp-list">
                        <?php foreach ($sp_upcoming as $t): ?>
                            <a class="sp-item" href="<?php echo htmlspecialchars($t['link']); ?>">
                                <span class="sp-item-icon"><i class="fas fa-clipboard-list"></i></span>
                                <span class="sp-item-main"><strong><?php echo htmlspecialchars($t['title']); ?></strong><small><?php echo htmlspecialchars($t['subject']); ?> · <?php echo $t['due'] ? 'Due ' . htmlspecialchars(date('M j, g:i A', strtotime($t['due']))) : 'No due date'; ?></small></span>
                                <span class="sp-tag <?php echo $t['state'] === 'Upcoming' ? 'info' : 'warn'; ?>"><?php echo htmlspecialchars($t['state']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Risk Analysis -->
            <?php if ($risk['status'] === 'ok'): ?>
            <div class="card-portal">
                <div class="card-portal-header"><i class="fas fa-shield-alt"></i> Risk Analysis</div>
                <div class="card-portal-body">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="risk-badge risk-<?php echo strtolower($risk['risk_level']); ?>" title="<?php echo htmlspecialchars(getRiskLevelDescription($risk['risk_level'], $risk['pass_mark'])); ?>">
                            <?php echo htmlspecialchars($risk['risk_level']); ?> Risk
                        </span>
                        <span style="font-size:14.5px;color:#374151;">Average: <strong><?php echo number_format($risk['average'], 2); ?>%</strong></span>
                    </div>
                    <p style="font-size:14.5px;color:#667085;margin:0;"><?php echo htmlspecialchars($risk['reason']); ?></p>
                    <?php if (!empty($risk['failed_subjects'])): ?>
                    <div class="mt-2">
                        <?php foreach ($risk['failed_subjects'] as $fs): ?>
                            <span class="remark-fail me-1 mb-1"><?php echo htmlspecialchars($fs['subject_name']); ?>: <?php echo number_format($fs['grade'], 2); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($risk['status'] === 'insufficient_data'): ?>
            <div class="card-portal">
                <div class="card-portal-body">
                    <span style="font-size:13.5px;font-weight:600;color:var(--portal-gray);background:#f1f5f9;padding:3px 10px;border-radius:4px;">Insufficient Data</span>
                    <p style="font-size:14.5px;color:#667085;margin:8px 0 0;"><?php echo htmlspecialchars($risk['reason']); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recommendations -->
            <?php if ($chat_recommendations && count($chat_recommendations) > 0): ?>
            <div class="card-portal">
                <div class="card-portal-header">
                    <i class="fas fa-lightbulb"></i> Recommendations
                    <span style="margin-left:auto;font-size:12.5px;background:rgba(20,33,61,0.06);padding:2px 8px;border-radius:4px;color:var(--portal-gray);"><?php echo count($chat_recommendations); ?></span>
                </div>
                <div class="card-portal-body">
                    <?php foreach ($chat_recommendations as $rec): ?>
                        <div class="rec-item">
                            <div class="rec-icon"><i class="fas fa-robot"></i></div>
                            <div>
                                <div class="rec-text"><?php echo htmlspecialchars($rec['message']); ?></div>
                                <div class="rec-date"><?php echo date('M d, Y g:i A', strtotime($rec['created_at'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Grades -->
            <div class="card-portal">
                <div class="card-portal-header"><i class="fas fa-chart-bar"></i> My Grades</div>
                <div class="card-portal-body" style="padding:0;">
                    <?php if ($grades && count($grades) > 0): ?>
                    <div class="table-responsive">
                        <table class="grade-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Term</th>
                                    <th>WW</th>
                                    <th>PT</th>
                                    <th>QA</th>
                                    <th>Final</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grades as $g): ?>
                                <tr>
                                    <td style="font-weight:500;"><?php echo htmlspecialchars($g['subject_name']); ?></td>
                                    <td><?php echo $g['term']; ?></td>
                                    <td><?php echo number_format($g['written_work'], 1); ?></td>
                                    <td><?php echo number_format($g['performance_task'], 1); ?></td>
                                    <td><?php echo number_format($g['examinations'], 1); ?></td>
                                    <td style="font-weight:600;"><?php echo number_format($g['final_grade'], 1); ?></td>
                                    <td><span class="<?php echo $g['final_grade'] >= getGradingPassMark() ? 'remark-pass' : 'remark-fail'; ?>"><?php echo $g['remarks']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar d-block"></i>
                        <h5>No grades recorded yet</h5>
                        <p>Check back later for your grades.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle + topbar profile dropdown now live in the shared
// static/js/student-portal-shell.js (linked in <head>), instead of being
// redefined inline on every portal page.

// Dashboard loader + count-up stats — see
// static/js/dashboard-animations.js for the shared, reusable helpers
// (animateCounter, showPageLoader, hidePageLoader) this calls into.
<?php if ($login_success): ?>
var __loginSuccessMessage = <?php echo json_encode(strip_tags($login_success)); ?>;
<?php endif; ?>
<?php if ($pw_changed): ?>
// Shown once after the first-login password change; the student is already
// signed in here, so this confirms the change rather than logging them in.
var __pwChangedMessage = <?php echo json_encode(strip_tags($pw_changed)); ?>;
<?php endif; ?>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initDashboardLoad === 'function') {
        initDashboardLoad({
            cardSelector: '.dash-anim-card',
            onRevealed: function () {
                // Only set right after an actual login — never on a
                // plain refresh of this dashboard.
                if (typeof __loginSuccessMessage !== 'undefined' && typeof notifyLoginSuccess === 'function') {
                    notifyLoginSuccess(__loginSuccessMessage);
                }
                if (typeof __pwChangedMessage !== 'undefined' && typeof notifyLoginSuccess === 'function') {
                    notifyLoginSuccess(__pwChangedMessage);
                }
            }
        });
    }
});
</script>
</body>
</html>
