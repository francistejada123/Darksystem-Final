<?php
// modules/students/portal/tutorial.php
// Student Portal - view own active tutorial plan.
//
// Video watch status (Not Started / In Progress / Completed) is fully
// AUTOMATIC for videos — driven by real embedded YouTube playback progress
// reported to tutorial_progress.php, never by a manual "mark as watched"
// click or simply by opening this page. See includes/tutorial_helpers.php
// for the status/progress rules.
//
// A Learning Material can now also be an uploaded PDF/Word/PowerPoint file
// instead of a video — those are shown read-only below (view/open only,
// no upload/replace/delete here; that stays teacher-only in
// modules/tutorial/view.php) and are served through the ID-based,
// per-student-authorized modules/tutorial/view_material.php endpoint.
//
// Viewing this page still stamps student_viewed_at (proof-of-opening for
// the teacher's view), but this is now purely informational and no longer
// changes the watch status itself.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/tutorial_helpers.php';

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$lrn = $_SESSION['student_lrn'];
$student_name = $_SESSION['student_name'];

$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);

$enrollment = getRecord("
    SELECT e.*, sec.section_name, sy.sy_name
    FROM enrollments e
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id
    WHERE e.student_id = ? AND sy.is_active = 1
", [$student_id]);

$plan = getRecord("
    SELECT * FROM tutorial_plans
    WHERE student_id = ? AND status IN ('Draft','Active')
    ORDER BY created_at DESC LIMIT 1
", [$student_id]);

// Self-report update (student marking their own progress on a subject).
// This is a separate, still-manual field (student_status) shown alongside
// the automatic video status — it was never the video-watch signal itself,
// so it's untouched by the automatic-tracking fix.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $plan && isset($_POST['student_status'])) {
    $item_id = intval($_POST['item_id'] ?? 0);
    $new_status = in_array($_POST['student_status'], ['Not Started', 'Working On It', 'Done']) ? $_POST['student_status'] : 'Not Started';
    executeQuery("
        UPDATE tutorial_plan_items
        SET student_status = ?, student_updated_at = NOW()
        WHERE item_id = ? AND plan_id = ?
    ", [$new_status, $item_id, $plan['plan_id']]);
    header('Location: tutorial.php');
    exit;
}

$items = [];
if ($plan) {
    // Proof-of-opening timestamp only — does NOT touch watch status anymore.
    executeQuery("
        UPDATE tutorial_plan_items
        SET student_viewed_at = NOW()
        WHERE plan_id = ?
    ", [$plan['plan_id']]);

    $items = getRecords("
        SELECT tpi.*, sub.subject_name, u.first_name AS tutor_first, u.last_name AS tutor_last
        FROM tutorial_plan_items tpi
        JOIN subjects sub ON tpi.subject_code = sub.subject_code
        LEFT JOIN users u ON tpi.tutor_id = u.user_id
        WHERE tpi.plan_id = ?
        ORDER BY tpi.current_grade ASC
    ", [$plan['plan_id']]);
}

$plan_progress = $items ? calculatePlanProgressPercent($items) : 0;

$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}
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
    <title>My Tutorial Plan - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../../static/css/style.css" rel="stylesheet">
    <link href="../../../static/css/portal-theme.css" rel="stylesheet">
    <link href="../../../static/css/student-portal-classroom-ui.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/student-portal-classroom-ui.css'); ?>" rel="stylesheet">
    <link href="../../../static/css/theme.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../static/css/theme.css'); ?>" rel="stylesheet">
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

        .sidebar { background: var(--portal-primary) !important; min-height: 100vh; padding: 0; display: flex; flex-direction: column; }
        .sidebar .logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 10px; }
        .sidebar .logo img { height: 36px; width: 36px; border-radius: 8px; object-fit: contain; }
        .sidebar .logo span { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: 0.02em; line-height: 1.3; }
        .sidebar .logo small { display: block; font-size: 9px; font-weight: 500; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }
        .sidebar .nav { padding: 12px 0; flex: 1; }
        .sidebar .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: rgba(255,255,255,0.55); font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.15s ease; border-left: 3px solid transparent; }
        .sidebar .nav-link i { width: 18px; text-align: center; font-size: 14px; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #fff; }
        .sidebar .nav-link.text-danger { color: rgba(201,74,74,0.7); }
        .sidebar .nav-link.text-danger:hover { color: #C94A4A; background: rgba(201,74,74,0.08); }
        .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 8px 20px; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 11px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--portal-border); }
        .topbar-avatar-placeholder { width: 44px; height: 44px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: var(--portal-gray); font-size: 20px; }
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 12px; }
        .btn-portal-logout { padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray); text-decoration: none; transition: all 0.15s; }
        .btn-portal-logout:hover { background: #f1f5f9; color: var(--portal-primary); }

        .card-portal { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 20px; }
        .card-portal-header { padding: 16px 20px; border-bottom: 1px solid var(--portal-border); font-size: 14px; font-weight: 600; color: var(--portal-primary); display: flex; align-items: center; gap: 8px; }
        .card-portal-body { padding: 20px; }

        .info-banner { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; background: rgba(20,33,61,0.03); border: 1px solid var(--portal-border); border-radius: 8px; font-size: 13px; color: #4b5563; line-height: 1.5; margin-bottom: 20px; }
        .info-banner i { color: var(--portal-primary); margin-top: 2px; }

        .progress-section { margin-bottom: 20px; }
        .progress-label { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: #374151; }
        .progress-bar-portal { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 4px; transition: width 0.3s; background: var(--portal-green); }

        .tutorial-card { border: 1px solid var(--portal-border); border-radius: 8px; padding: 20px; margin-bottom: 16px; background: var(--portal-card); transition: border-color 0.15s; }
        .tutorial-card:hover { border-color: #d1d5db; }
        .tutorial-card-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
        .tutorial-card-subject { font-size: 15px; font-weight: 600; color: var(--portal-primary); }
        .tutorial-card-grade { font-size: 13px; color: var(--portal-red); font-weight: 600; }
        .tutorial-card-target { font-size: 13px; color: var(--portal-gray); }
        .tutorial-card-teacher { font-size: 12px; color: var(--portal-gray); }
        .tutorial-card-desc { font-size: 13px; color: #6b7280; margin-bottom: 12px; line-height: 1.5; }

        .status-badge-tutorial { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .status-watching { background: rgba(20,33,61,0.06); color: var(--portal-primary); }
        .status-completed { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .status-pending { background: rgba(102,112,133,0.08); color: var(--portal-gray); }

        .video-progress-text { font-size: 12px; color: var(--portal-gray); }
        .video-progress-bar { height: 6px; background: #f1f5f9; border-radius: 3px; overflow: hidden; max-width: 480px; margin-top: 4px; margin-bottom: 6px; }
        .video-progress-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .video-progress-fill.info { background: #3b82f6; }
        .video-progress-fill.success { background: var(--portal-green); }
        .last-watched { font-size: 11px; color: var(--portal-gray); }

        .btn-portal-primary { padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 500; border: none; background: var(--portal-primary); color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; }
        .btn-portal-primary:hover { background: #1a2f54; color: #fff; }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; color: var(--portal-green); }
        .empty-state p { font-size: 14px; }

        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .topbar h4 { font-size: 16px !important; }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar { display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: var(--sidebar-width, 240px) !important; height: 100vh !important; overflow-y: auto !important; }
            .main-content { margin-left: var(--sidebar-width, 240px) !important; width: calc(100% - var(--sidebar-width, 240px)) !important; max-width: calc(100% - var(--sidebar-width, 240px)) !important; flex: 0 0 auto !important; }
        }
    </style>
</head>
<body>
<script>document.body.classList.add('sidebar-collapsed');</script>
<script>document.body.classList.add('sidebar-collapsed');</script>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link active"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <div class="col-md-10 main-content">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4>My Tutorial Plan</h4>
                        <small>LRN: <?php echo htmlspecialchars($lrn); ?> &middot; <?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not Assigned'); ?></small>
                    </div>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
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

            <?php if (!$plan): ?>
                <div class="card-portal">
                    <div class="card-portal-body">
                        <div class="empty-state">
                            <i class="fas fa-check-circle d-block"></i>
                            <p>You don't have an active tutorial plan right now.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div>This plan focuses on subjects below 75%. Watch each tutorial video below &mdash; your progress is tracked automatically as you watch, no need to mark anything yourself.</div>
                </div>

                <div class="card-portal progress-section">
                    <div class="card-portal-body">
                        <div class="progress-label">
                            <span>Overall Tutorial Plan Progress</span>
                            <span><?php echo number_format($plan_progress, 0); ?>%</span>
                        </div>
                        <div class="progress-bar-portal">
                            <div class="progress-bar-fill" style="width: <?php echo $plan_progress; ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div class="card-portal">
                    <div class="card-portal-header"><i class="fas fa-book-open"></i> Subjects to Focus On</div>
                    <div class="card-portal-body">
                        <?php foreach ($items as $item):
                            $video_id = getYouTubeVideoId($item['resource_url'] ?? '');
                            $status_label = getTutorialWatchStatusLabel($item['status']);
                            $status_badge = getTutorialWatchStatusBadgeClass($item['status']);
                            $progress_pct = floatval($item['watch_progress_percentage'] ?? 0);
                        ?>
                            <div class="tutorial-card">
                                <div class="tutorial-card-header">
                                    <div>
                                        <div class="tutorial-card-subject"><?php echo htmlspecialchars($item['subject_name']); ?></div>
                                        <div>
                                            <span class="tutorial-card-grade"><?php echo number_format($item['current_grade'], 1); ?>%</span>
                                            <span class="tutorial-card-target"> &rarr; target <?php echo number_format($item['target_grade'], 1); ?>%</span>
                                        </div>
                                    </div>
                                    <?php if ($item['tutor_first']): ?>
                                        <span class="tutorial-card-teacher">Teacher: <?php echo htmlspecialchars($item['tutor_last'] . ', ' . $item['tutor_first']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="tutorial-card-desc"><?php echo htmlspecialchars($item['recommended_action']); ?></div>

                                <?php if (!empty($item['resource_url'])): ?>
                                    <?php if ($video_id): ?>
                                        <div class="ratio ratio-16x9 mb-3" style="max-width:520px;border-radius:8px;overflow:hidden;border:1px solid var(--portal-border);">
                                            <div id="yt-player-<?php echo (int)$item['item_id']; ?>"
                                                class="yt-embed"
                                                data-item-id="<?php echo (int)$item['item_id']; ?>"
                                                data-video-id="<?php echo htmlspecialchars($video_id); ?>"
                                                data-last-position="<?php echo (float)($item['last_video_position_seconds'] ?? 0); ?>"
                                                data-watched-seconds="<?php echo (float)($item['watched_seconds'] ?? 0); ?>"
                                                data-duration="<?php echo (float)($item['video_duration_seconds'] ?? 0); ?>"
                                                data-status="<?php echo htmlspecialchars($item['status']); ?>"></div>
                                        </div>

                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="status-badge-tutorial <?php echo $item['status'] === 'Completed' ? 'status-completed' : ($item['status'] === 'In Progress' ? 'status-watching' : 'status-pending'); ?> watch-status-badge-<?php echo (int)$item['item_id']; ?>">
                                                <?php echo htmlspecialchars($status_label); ?>
                                            </span>
                                            <span class="video-progress-text watch-progress-text-<?php echo (int)$item['item_id']; ?>">
                                                Video Watch Progress: <?php echo number_format($progress_pct, 0); ?>%
                                            </span>
                                        </div>
                                        <div class="video-progress-bar">
                                            <div class="video-progress-fill <?php echo $item['status'] === 'Completed' ? 'success' : 'info'; ?> watch-progress-bar-<?php echo (int)$item['item_id']; ?>"
                                                style="width: <?php echo $progress_pct; ?>%;"></div>
                                        </div>
                                        <?php if ($item['last_watched_at']): ?>
                                            <span class="last-watched watch-last-watched-<?php echo (int)$item['item_id']; ?>">
                                                Last Watched: <?php echo date('F j, Y g:i A', strtotime($item['last_watched_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($item['resource_url']); ?>" target="_blank" rel="noopener" class="btn-portal-primary" style="display:inline-flex;">
                                            <i class="fas fa-play"></i> Watch Tutorial Video
                                        </a>
                                        <div style="font-size:12px;color:var(--portal-gray);margin-top:6px;">
                                            Automatic progress tracking isn't available for this link. Ask your teacher to add a specific video link.
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (in_array($item['resource_type'] ?? 'link', ['pdf', 'word', 'powerpoint'], true) && !empty($item['resource_file'])):
                                    $mat_meta = tutorialMaterialMeta($item['resource_type']);
                                    $mat_title = tutorialMaterialDisplayTitle($item);
                                ?>
                                    <div class="material-view-card" style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:rgba(20,33,61,0.03);border:1px solid var(--portal-border);border-radius:8px;margin-bottom:8px;">
                                        <span style="font-size:24px;line-height:1;margin-top:2px;"><i class="fas <?php echo htmlspecialchars($mat_meta['icon']); ?>"></i></span>
                                        <div style="flex:1;min-width:0;">
                                            <div style="font-weight:700;font-size:14px;color:var(--portal-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($mat_title); ?>"><?php echo htmlspecialchars($mat_title); ?></div>
                                            <span class="material-type-badge <?php echo htmlspecialchars($mat_meta['badge_class']); ?>" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.03em;padding:2px 8px;border-radius:20px;display:inline-block;margin:4px 0;"><?php echo htmlspecialchars($mat_meta['label']); ?></span>
                                            <?php if (!empty($item['resource_description'])): ?>
                                                <div style="font-size:12.5px;color:#4b5563;margin:4px 0;line-height:1.5;"><?php echo nl2br(htmlspecialchars($item['resource_description'])); ?></div>
                                            <?php endif; ?>
                                            <div style="margin-top:6px;">
                                                <a href="../../tutorial/view_material.php?id=<?php echo (int)$item['item_id']; ?>" target="_blank" rel="noopener" class="btn-portal-primary" style="display:inline-flex;">
                                                    <i class="fas fa-eye"></i> View Material
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="status-badge-tutorial <?php echo $item['status'] === 'Completed' ? 'status-completed' : ($item['status'] === 'In Progress' ? 'status-watching' : 'status-pending'); ?>">
                                            <?php echo htmlspecialchars($status_label); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:13px;color:var(--portal-yellow);font-weight:600;">No material assigned</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle + topbar profile dropdown now live in the shared
// static/js/student-portal-shell.js (linked in <head>) — this page was
// missing the overlay/outside-click/ESC behavior the other portal pages
// have, so it now gets the same complete behavior as everywhere else.

// ============================================================
// AUTOMATIC TUTORIAL VIDEO WATCH TRACKING (YouTube IFrame Player API)
// ============================================================
// Watch status is derived entirely from real playback activity reported
// here — the student never clicks a "watched" button. See
// modules/students/portal/tutorial_progress.php for the server side.
(function () {
    var ytPlayers = {};     // item_id -> YT.Player
    var progressState = {}; // item_id -> { accumulated, lastTick, lastPos, duration, timer, lastSentAt }
    var SAVE_INTERVAL_MS = 5000;
    // A tick larger than this is treated as a seek/skip, not real watch
    // time, so skipping to the end can't fake completion.
    var MAX_CONTINUOUS_DELTA = 2;

    window.onYouTubeIframeAPIReady = function () {
        document.querySelectorAll('.yt-embed').forEach(function (el) {
            var itemId = el.dataset.itemId;
            progressState[itemId] = {
                accumulated: parseFloat(el.dataset.watchedSeconds || 0),
                lastTick: 0,
                lastPos: parseFloat(el.dataset.lastPosition || 0),
                duration: parseFloat(el.dataset.duration || 0),
                timer: null,
                lastSentAt: 0,
                status: el.dataset.status || 'Pending'
            };
            try {
                ytPlayers[itemId] = new YT.Player(el.id, {
                    videoId: el.dataset.videoId,
                    playerVars: { rel: 0, playsinline: 1 },
                    events: {
                        onReady: function (e) {
                            var st = progressState[itemId];
                            if (st.lastPos > 0) e.target.seekTo(st.lastPos, true);
                            if (!st.duration) st.duration = e.target.getDuration();
                        },
                        onStateChange: function (e) { onPlayerStateChange(itemId, e); },
                        onError: function () { renderVideoFallback(el); }
                    }
                });
            } catch (error) {
                renderVideoFallback(el);
            }
        });
    };

    function renderVideoFallback(el) {
        if (el.dataset.fallbackReady === '1') return;
        el.dataset.fallbackReady = '1';
        var id = encodeURIComponent(el.dataset.videoId || '');
        if (!id) return;
        el.innerHTML = '<iframe src="https://www.youtube.com/embed/' + id + '?rel=0&playsinline=1" title="Tutorial video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen style="width:100%;height:100%;border:0;"></iframe>';
    }

    window.addEventListener('error', function (event) {
        if (event.target && event.target.tagName === 'SCRIPT' && event.target.src.indexOf('youtube.com/iframe_api') !== -1) {
            document.querySelectorAll('.yt-embed').forEach(renderVideoFallback);
        }
    }, true);

    function onPlayerStateChange(itemId, e) {
        if (e.data === YT.PlayerState.PLAYING) {
            startTracking(itemId);
            sendProgress(itemId, 'start');
        } else if (e.data === YT.PlayerState.PAUSED) {
            stopTracking(itemId);
            sendProgress(itemId, 'pause');
        } else if (e.data === YT.PlayerState.ENDED) {
            stopTracking(itemId);
            sendProgress(itemId, 'end');
        }
    }

    function startTracking(itemId) {
        var st = progressState[itemId];
        var player = ytPlayers[itemId];
        if (st.timer) return;
        st.lastTick = player.getCurrentTime();
        if (!st.duration) st.duration = player.getDuration();
        st.timer = setInterval(function () {
            var pos = player.getCurrentTime();
            var delta = pos - st.lastTick;
            // Only count forward-moving, continuous playback — skipped
            // segments (large jumps) are never added to accumulated time.
            if (delta > 0 && delta <= MAX_CONTINUOUS_DELTA) {
                st.accumulated += delta;
            }
            st.lastTick = pos;
            st.lastPos = pos;

            var now = Date.now();
            if (now - st.lastSentAt > SAVE_INTERVAL_MS) {
                sendProgress(itemId, 'progress');
                st.lastSentAt = now;
            }
        }, 1000);
    }

    function stopTracking(itemId) {
        var st = progressState[itemId];
        if (st.timer) { clearInterval(st.timer); st.timer = null; }
    }

    function sendProgress(itemId, eventType, useBeacon) {
        var st = progressState[itemId];
        if (!st) return;
        var body = new URLSearchParams({
            item_id: itemId,
            watched_seconds: st.accumulated.toFixed(2),
            current_position: st.lastPos.toFixed(2),
            video_duration: Math.round(st.duration || 0),
            event: eventType
        });
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('tutorial_progress.php', body);
            return;
        }
        fetch('tutorial_progress.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) { updateProgressUI(itemId, data); })
            .catch(function () {});
    }

    function updateProgressUI(itemId, data) {
        if (!data || data.error) return;
        var badge = document.querySelector('.watch-status-badge-' + itemId);
        var text = document.querySelector('.watch-progress-text-' + itemId);
        var bar = document.querySelector('.watch-progress-bar-' + itemId);
        if (badge) {
            badge.textContent = data.status_label;
            badge.className = 'badge bg-' + data.badge_class + ' watch-status-badge-' + itemId;
        }
        if (text) {
            text.textContent = 'Video Watch Progress: ' + Math.round(data.watch_progress_percentage) + '%';
        }
        if (bar) {
            bar.style.width = data.watch_progress_percentage + '%';
            bar.className = 'progress-bar bg-' + (data.status === 'Completed' ? 'success' : 'info') + ' watch-progress-bar-' + itemId;
        }
    }

    // Best-effort save when the student navigates away or closes the tab.
    window.addEventListener('beforeunload', function () {
        Object.keys(progressState).forEach(function (itemId) {
            if (progressState[itemId].timer) {
                sendProgress(itemId, 'exit', true);
            }
        });
    });
})();
</script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>