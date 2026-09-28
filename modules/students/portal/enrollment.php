<?php
// modules/students/portal/enrollment.php
// Student Portal - Enrollment Status

session_start();
require_once '../../../config/database.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$lrn = $_SESSION['student_lrn'];

// Get current enrollment
$current_enrollment = getRecord("
    SELECT e.*, sec.section_name, sy.sy_name,
           u.first_name as processed_first, u.last_name as processed_last
    FROM enrollments e
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id
    LEFT JOIN users u ON e.processed_by = u.user_id
    WHERE e.student_id = ? AND sy.is_active = 1
", [$student_id]);

// Get enrollment history
$enrollment_history = getRecords("
    SELECT e.*, sec.section_name, sy.sy_name
    FROM enrollments e
    LEFT JOIN sections sec ON e.section_id = sec.section_id
    LEFT JOIN school_years sy ON e.school_year_id = sy.sy_id
    WHERE e.student_id = ?
    ORDER BY e.school_year_id DESC
", [$student_id]);

// Get student info
$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);

// Profile photo for the topbar avatar/dropdown — same lookup used on
// every other Student Portal page.
$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

$page_title = 'Enrollment Status';
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
    <title>Enrollment Status - EGRADEPRO</title>
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
        .sidebar .logo small { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }
        .sidebar .nav { padding: 12px 0; flex: 1; }
        .sidebar .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: rgba(255,255,255,0.55); font-size: 14.5px; font-weight: 500; text-decoration: none; transition: all 0.15s ease; border-left: 3px solid transparent; }
        .sidebar .nav-link i { width: 18px; text-align: center; font-size: 15px; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #fff; }
        .sidebar .nav-link.text-danger { color: rgba(201,74,74,0.7); }
        .sidebar .nav-link.text-danger:hover { color: #C94A4A; background: rgba(201,74,74,0.08); }
        .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 8px 20px; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 12.5px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 13.5px; }
        .btn-portal-back { padding: 7px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 500; border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray); text-decoration: none; transition: all 0.15s; }
        .btn-portal-back:hover { background: #f1f5f9; color: var(--portal-primary); }

        .card-portal { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 20px; }
        .card-portal-header { padding: 16px 20px; border-bottom: 1px solid var(--portal-border); font-size: 15px; font-weight: 600; color: var(--portal-primary); display: flex; align-items: center; justify-content: space-between; }
        .card-portal-body { padding: 20px; }

        .enrollment-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px; border-radius: 6px; font-size: 14.5px; font-weight: 600; }
        .enrollment-status.approved { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .enrollment-status.pending { background: rgba(183,121,31,0.1); color: var(--portal-yellow); }
        .enrollment-status.rejected { background: rgba(201,74,74,0.08); color: var(--portal-red); }
        .enrollment-status.not-enrolled { background: #f1f5f9; color: var(--portal-gray); }

        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .info-row:last-child { border-bottom: none; }
        .info-label { width: 140px; font-size: 14.5px; font-weight: 500; color: var(--portal-gray); flex-shrink: 0; }
        .info-value { font-size: 14.5px; color: #374151; font-weight: 500; }

        .grade-table { width: 100%; border-collapse: collapse; }
        .grade-table th { text-align: left; padding: 10px 16px; font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--portal-gray); border-bottom: 1px solid var(--portal-border); background: #f9fafb; }
        .grade-table td { padding: 12px 16px; font-size: 14.5px; color: #374151; border-bottom: 1px solid #f3f4f6; }
        .grade-table tr:last-child td { border-bottom: none; }
        .grade-table tr:hover td { background: #f9fafb; }

        .status-badge-sm { padding: 3px 10px; border-radius: 4px; font-size: 13.5px; font-weight: 600; }
        .status-approved { background: rgba(35,136,99,0.08); color: var(--portal-green); }
        .status-pending { background: rgba(183,121,31,0.1); color: var(--portal-yellow); }
        .status-rejected { background: rgba(201,74,74,0.08); color: var(--portal-red); }
        .badge-strand { background: #F2F4F7; color: #344054; padding: 3px 10px; border-radius: 4px; font-size: 13.5px; font-weight: 600; border: 1px solid #E4E7EC; }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; color: #9ca3af; }

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
    <?php include __DIR__ . '/../../../includes/pwa_head.php'; ?>
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
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link active"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <!-- Top Bar -->
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4>Enrollment Status</h4>
                        <small><?php echo htmlspecialchars($student_name); ?> &middot; LRN: <?php echo htmlspecialchars($lrn); ?></small>
                    </div>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                        <i class="fas fa-sun theme-toggle-icon-sun" aria-hidden="true"></i>
                        <i class="fas fa-moon theme-toggle-icon-moon" aria-hidden="true"></i>
                    </button>
                    <a href="dashboard.php" class="btn-portal-back"><i class="fas fa-arrow-left me-1"></i> Back</a>
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

            <!-- Current Enrollment -->
            <div class="card-portal">
                <div class="card-portal-header">
                    <span>Current Enrollment</span>
                    <?php if ($current_enrollment): ?>
                        <span class="enrollment-status <?php echo strtolower($current_enrollment['enrollment_status']); ?>">
                            <i class="fas fa-circle" style="font-size:6px;"></i>
                            <?php echo $current_enrollment['enrollment_status']; ?>
                        </span>
                    <?php else: ?>
                        <span class="enrollment-status not-enrolled">
                            <i class="fas fa-circle" style="font-size:6px;"></i> Not Enrolled
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-portal-body">
                    <?php if ($current_enrollment): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label">School Year</span><span class="info-value"><?php echo htmlspecialchars($current_enrollment['sy_name']); ?></span></div>
                                <div class="info-row"><span class="info-label">Grade Level</span><span class="info-value"><?php echo htmlspecialchars($current_enrollment['grade_level']); ?></span></div>
                                <div class="info-row"><span class="info-label">Section</span><span class="info-value"><?php echo htmlspecialchars($current_enrollment['section_name'] ?? 'Not Assigned'); ?></span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label">Enrollment Date</span><span class="info-value"><?php echo date('F d, Y', strtotime($current_enrollment['enrollment_date'])); ?></span></div>
                                <?php if ($current_enrollment['processed_by']): ?>
                                <div class="info-row"><span class="info-label">Processed By</span><span class="info-value"><?php echo htmlspecialchars($current_enrollment['processed_first'] . ' ' . $current_enrollment['processed_last']); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($current_enrollment['strand'])): ?>
                                <div class="info-row"><span class="info-label">Strand</span><span class="info-value"><span class="badge-strand"><?php echo htmlspecialchars($current_enrollment['strand'] === 'ASSH' ? 'ASSH Cluster' : $current_enrollment['strand']); ?></span></span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-plus d-block"></i>
                            <h5>You are not currently enrolled</h5>
                            <p>Please visit the registrar's office for enrollment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Student Information -->
            <div class="card-portal">
                <div class="card-portal-header">Student Information</div>
                <div class="card-portal-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="info-row"><span class="info-label">LRN</span><span class="info-value"><strong><?php echo htmlspecialchars($student['lrn']); ?></strong></span></div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row"><span class="info-label">Gender</span><span class="info-value"><?php echo $student['gender']; ?></span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enrollment History -->
            <?php if ($enrollment_history && count($enrollment_history) > 0): ?>
            <div class="card-portal">
                <div class="card-portal-header">Enrollment History</div>
                <div class="card-portal-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="grade-table">
                            <thead>
                                <tr>
                                    <th>School Year</th>
                                    <th>Grade Level</th>
                                    <th>Section</th>
                                    <th>Strand</th>
                                    <th>Status</th>
                                    <th>Enrollment Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrollment_history as $e): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($e['sy_name']); ?></td>
                                    <td><?php echo htmlspecialchars($e['grade_level']); ?></td>
                                    <td><?php echo htmlspecialchars($e['section_name'] ?? 'N/A'); ?></td>
                                    <td><?php if (!empty($e['strand'])): ?><span class="badge-strand"><?php echo htmlspecialchars($e['strand'] === 'ASSH' ? 'ASSH Cluster' : $e['strand']); ?></span><?php else: ?><span style="color:var(--portal-gray);">N/A</span><?php endif; ?></td>
                                    <td><span class="status-badge-sm status-<?php echo strtolower($e['enrollment_status']); ?>"><?php echo $e['enrollment_status']; ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($e['enrollment_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle + topbar profile dropdown now live in the shared
// static/js/student-portal-shell.js (linked in <head>).
</script>
</body>
</html>