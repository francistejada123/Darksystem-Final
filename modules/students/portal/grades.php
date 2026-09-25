<?php
// modules/students/portal/grades.php
// Student Portal - My Grades (Full View)

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/risk_helpers.php';
require_once '../../../includes/grading_helpers.php';

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$lrn = $_SESSION['student_lrn'];
$pass_mark = getGradingPassMark();

// Profile photo for the topbar avatar/dropdown — same lookup used on
// every other Student Portal page.
$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);
$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

// Get active school year
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// Get all grades (filtered by active school year)
$grades = getRecords("
    SELECT g.*, sub.subject_name, sub.subject_code
    FROM grades g
    JOIN subjects sub ON g.subject_code = sub.subject_code
    WHERE g.student_id = ? AND g.school_year_id = ?
      AND " . gh_encodedTermSql('g') . "
    ORDER BY g.term, sub.subject_name
", [$student_id, $active_sy['sy_id'] ?? 0]);

// Group grades by term
$terms = getActiveTerms();
$grades_by_term = [];
foreach ($grades as $g) {
    $grades_by_term[$g['term']][] = $g;
}

// Calculate overall average (exclude 0 grades from incomplete data)
$overall_average = 0;
if (count($grades) > 0) {
    $total = 0;
    $count = 0;
    foreach ($grades as $g) {
        if ($g['final_grade'] > 0) {
            $total += $g['final_grade'];
            $count++;
        }
    }
    $overall_average = $count > 0 ? $total / $count : 0;
}

$passed = 0;
foreach ($grades as $g) { if ($g['final_grade'] >= $pass_mark) $passed++; }

$page_title = 'My Grades';
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
    <title>My Grades - EGRADEPRO</title>
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

        .sidebar {
            background: var(--portal-primary) !important;
            min-height: 100vh; padding: 0;
            display: flex; flex-direction: column;
        }
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
        .topbar h4 { margin: 0; font-size: 20px; font-weight: 700; color: var(--portal-primary); }
        .topbar small { color: var(--portal-gray); font-size: 12px; }
        .btn-portal-back { padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray); text-decoration: none; transition: all 0.15s; }
        .btn-portal-back:hover { background: #f1f5f9; color: var(--portal-primary); }

        .stat-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; padding: 20px; }
        .stat-card h6 { margin: 0 0 6px; font-size: 12px; font-weight: 500; color: var(--portal-gray); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: var(--portal-primary); line-height: 1; }
        .stat-card .stat-value.green { color: var(--portal-green); }
        .stat-card .stat-value.red { color: var(--portal-red); }

        .card-portal { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 20px; }
        .card-portal-header { padding: 16px 20px; border-bottom: 1px solid var(--portal-border); font-size: 14px; font-weight: 600; color: var(--portal-primary); display: flex; align-items: center; gap: 8px; }
        .card-portal-body { padding: 20px; }

        .term-tabs { display: flex; gap: 4px; padding: 0; margin-bottom: 20px; border-bottom: 1px solid var(--portal-border); }
        .term-tab { padding: 10px 18px; font-size: 13px; font-weight: 500; color: var(--portal-gray); border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.15s; }
        .term-tab:hover { color: var(--portal-primary); }
        .term-tab.active { color: var(--portal-primary); border-bottom-color: var(--portal-primary); font-weight: 600; }
        .term-tab .tab-count { font-size: 11px; background: #f1f5f9; padding: 1px 6px; border-radius: 4px; margin-left: 4px; }
        .term-tab.active .tab-count { background: rgba(20,33,61,0.08); color: var(--portal-primary); }

        .grade-table { width: 100%; border-collapse: collapse; }
        .grade-table th { text-align: left; padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--portal-gray); border-bottom: 1px solid var(--portal-border); background: #f9fafb; }
        .grade-table td { padding: 12px 16px; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6; }
        .grade-table tr:last-child td { border-bottom: none; }
        .grade-table tr:hover td { background: #f9fafb; }
        .grade-table tfoot td { background: #f9fafb; font-weight: 600; border-top: 1px solid var(--portal-border); }

        .remark-pass { background: rgba(35,136,99,0.08); color: var(--portal-green); padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .remark-fail { background: rgba(201,74,74,0.08); color: var(--portal-red); padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }

        .term-pane { display: none; }
        .term-pane.active { display: block; }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 36px; margin-bottom: 10px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; color: #9ca3af; }

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
<script>document.body.classList.add('sidebar-collapsed');</script>
<script>document.body.classList.add('sidebar-collapsed');</script>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>EGRADEPRO<small>STUDENT PORTAL</small></span>
            </div>
            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="grades.php" class="nav-link active"><i class="fas fa-chart-bar"></i> My Grades</a>
                <a href="tutorial.php" class="nav-link"><i class="fas fa-play-circle"></i> My Tutorials</a>
                <a href="enrollment.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Enrollment</a>
            </nav>
            <div class="sidebar-footer"><small>&copy; <?php echo date('Y'); ?></small></div>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
                    <div>
                        <h4>My Grades</h4>
                        <small><?php echo htmlspecialchars($student_name); ?> &middot; LRN: <?php echo htmlspecialchars($lrn); ?></small>
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

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <h6>Overall Average</h6>
                        <div class="stat-value <?php echo $overall_average >= 75 ? 'green' : 'red'; ?>"><?php echo number_format($overall_average, 1); ?>%</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <h6>Subjects</h6>
                        <div class="stat-value"><?php echo count($grades); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <h6>Passed</h6>
                        <div class="stat-value green"><?php echo $passed; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <h6>Failed</h6>
                        <div class="stat-value red"><?php echo count($grades) - $passed; ?></div>
                    </div>
                </div>
            </div>

            <!-- Grades by Term -->
            <?php if (count($grades) > 0): ?>
            <div class="card-portal">
                <div class="card-portal-header"><i class="fas fa-layer-group"></i> Grades Summary</div>
                <div class="card-portal-body">
                    <div class="term-tabs">
                        <?php foreach ($terms as $index => $term): ?>
                            <button class="term-tab <?php echo $index === 0 ? 'active' : ''; ?>" onclick="switchTab(<?php echo $index; ?>)">
                                <?php echo $term; ?>
                                <span class="tab-count"><?php echo count($grades_by_term[$term] ?? []); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($terms as $index => $term): ?>
                        <div class="term-pane <?php echo $index === 0 ? 'active' : ''; ?>" id="term-<?php echo $index; ?>">
                            <?php $term_grades = $grades_by_term[$term] ?? []; ?>
                            <?php if (count($term_grades) > 0): ?>
                            <div class="table-responsive">
                                <table class="grade-table">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>WW (30%)</th>
                                            <th>PT (50%)</th>
                                            <th>QA (20%)</th>
                                            <th>Final</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($term_grades as $g): ?>
                                        <tr>
                                            <td style="font-weight:500;"><?php echo htmlspecialchars($g['subject_name']); ?></td>
                                            <td><?php echo number_format($g['written_work'], 1); ?></td>
                                            <td><?php echo number_format($g['performance_task'], 1); ?></td>
                                            <td><?php echo number_format($g['examinations'], 1); ?></td>
                                            <td style="font-weight:600;"><?php echo number_format($g['final_grade'], 1); ?></td>
                                            <td><span class="<?php echo $g['final_grade'] >= 75 ? 'remark-pass' : 'remark-fail'; ?>"><?php echo $g['remarks']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="4">Term Average</td>
                                            <td style="font-weight:700;"><?php echo number_format(array_sum(array_column($term_grades, 'final_grade')) / count($term_grades), 1); ?>%</td>
                                            <td><span class="<?php echo (array_sum(array_column($term_grades, 'final_grade')) / count($term_grades)) >= 75 ? 'remark-pass' : 'remark-fail'; ?>"><?php echo (array_sum(array_column($term_grades, 'final_grade')) / count($term_grades)) >= 75 ? 'Passed' : 'Failed'; ?></span></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state"><i class="fas fa-chart-bar d-block"></i><h5>No grades for <?php echo $term; ?></h5></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card-portal">
                <div class="card-portal-body">
                    <div class="empty-state">
                        <i class="fas fa-chart-bar d-block"></i>
                        <h5>No grades recorded yet</h5>
                        <p>Check back later for your grades.</p>
                    </div>
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
// static/js/student-portal-shell.js (linked in <head>).
function switchTab(index) {
    document.querySelectorAll('.term-tab').forEach(function(t,i) { t.classList.toggle('active', i === index); });
    document.querySelectorAll('.term-pane').forEach(function(p,i) { p.classList.toggle('active', i === index); });
}
</script>
</body>
</html>