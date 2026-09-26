<?php
// includes/student_portal_page.php
// Page frame for the newer Student Portal pages (My Classes, Tasks &
// Activities, Attendance, Updates): the same head, sidebar and topbar as
// grades.php / tutorial.php, so they look like the rest of the portal. The
// mobile app bar, bottom navigation and drawer come from
// static/js/student-portal-shell.js like on every other portal page.

function spPageStart($title, $subtitle, array $student, $profileImage)
{
    $root = '../../../';
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    ?><!DOCTYPE html>
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
    <title><?php echo $e($title); ?> - EGRADEPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $root; ?>static/css/style.css" rel="stylesheet">
    <link href="<?php echo $root; ?>static/css/portal-theme.css" rel="stylesheet">
    <link href="<?php echo $root; ?>static/css/student-portal-classroom-ui.css?v=<?php echo (int) @filemtime(__DIR__ . '/../static/css/student-portal-classroom-ui.css'); ?>" rel="stylesheet">
    <link href="<?php echo $root; ?>static/css/theme.css?v=<?php echo (int) @filemtime(__DIR__ . '/../static/css/theme.css'); ?>" rel="stylesheet">
    <script src="<?php echo $root; ?>static/js/student-portal-shell.js?v=<?php echo (int) @filemtime(__DIR__ . '/../static/js/student-portal-shell.js'); ?>"></script>
    <script src="<?php echo $root; ?>static/js/theme.js"></script>
    <style>
        :root { --portal-bg: #F1F4F8; --portal-primary: #14213D; --portal-card: #ffffff; --portal-border: #e5e7eb; --portal-green: #238863; --portal-red: #C94A4A; --portal-yellow: #B7791F; --portal-gray: #667085; }
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
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 11px; }
        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 12px; }
        .stat-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 12px; padding: 16px; }
        .stat-card h6 { margin: 0 0 6px; font-size: 12px; font-weight: 500; color: var(--portal-gray); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-card .stat-value { font-size: 24px; font-weight: 700; color: var(--portal-primary); line-height: 1.1; }
        .card-portal { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 12px; margin-bottom: 16px; }
        .empty-state { text-align: center; padding: 36px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 34px; margin-bottom: 10px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; color: #9ca3af; }
        html[data-theme="dark"] .topbar small, html[data-theme="dark"] .stat-card h6 { color: var(--text-muted, #94a3b8) !important; }
        html[data-theme="dark"] .stat-card, html[data-theme="dark"] .card-portal { background: var(--surface, #111827); }
        html[data-theme="dark"] .stat-card .stat-value { color: var(--ink, #e5e7eb); }
        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar h4 { font-size: 17px !important; }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar { display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: var(--sidebar-width, 240px) !important; height: 100vh !important; overflow-y: auto !important; }
            .main-content { margin-left: var(--sidebar-width, 240px) !important; width: calc(100% - var(--sidebar-width, 240px)) !important; max-width: calc(100% - var(--sidebar-width, 240px)) !important; flex: 0 0 auto !important; }
        }
    </style>
    <?php include __DIR__ . '/pwa_head.php'; ?>
</head>
<body>
<script>document.body.classList.add('sidebar-collapsed');</script>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="<?php echo $root; ?>static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>
        <div class="col-md-10 main-content">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4><?php echo $e($title); ?></h4>
                        <small><?php echo $e($subtitle); ?></small>
                    </div>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
                    <div class="app-topbar-profile" id="appTopbarProfile">
                        <button type="button" class="app-topbar-profile-btn" id="appTopbarProfileBtn" onclick="toggleProfileMenu()" aria-haspopup="true" aria-expanded="false">
                            <div class="app-topbar-avatar"><?php echo $profileImage ? '<img src="' . $e($profileImage) . '" alt="">' : '<i class="fas fa-user"></i>'; ?></div>
                            <span class="app-topbar-profile-text"><strong><?php echo $e(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></strong><small>Student</small></span>
                            <i class="fas fa-chevron-down app-topbar-chevron"></i>
                        </button>
                        <div class="app-topbar-dropdown" id="appTopbarDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <div class="app-topbar-dropdown-divider"></div>
                            <a href="<?php echo $root; ?>logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
<?php
}

function spPageEnd()
{
    ?>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

/** Signed-in student (redirects to the Student Login otherwise) + the topbar photo. */
function spRequireStudent()
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
        header('Location: ../../../student_login.php');
        exit;
    }
    $student = getRecord("SELECT * FROM students WHERE student_id = ?", [(int) $_SESSION['student_id']]) ?: [];
    $img = '';
    if (!empty($student['profile_image']) && file_exists(__DIR__ . '/../static/uploads/profiles/' . $student['profile_image'])) {
        $img = '../../../static/uploads/profiles/' . $student['profile_image'];
    }
    return [(int) $_SESSION['student_id'], $student, $img];
}
