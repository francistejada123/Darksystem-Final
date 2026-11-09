<?php
// modules/students/portal/subject.php
// Student Portal — Subject/Class Page (Google-Classroom-style).
//
// One page per enrolled subject, reached by clicking that subject's class
// card on dashboard.php. Everything about that ONE subject lives here:
// activities/tasks the teacher generated and sent (quizzes, PDFs, Word
// docs, Google Forms — with Answer Now / Submit Your Answer / score /
// submitted-proof, exactly as before), plain support messages/instructions
// from the teacher, and this student's own submitted proof for each
// activity — one reverse-chronological stream, newest first.
//
// This replaces the old standalone messages.php as far as the student is
// concerned: there is no more top "Messages" bell showing every subject's
// updates in one place — a student only finds out about a new activity or
// message by opening that subject's own page (see dashboard.php's small
// "New" badge per class card, which says THAT something is waiting,
// never WHAT). Opening this page marks this subject's unseen messages as
// Seen, the same way messages.php used to for all subjects at once.
//
// The message-card markup below (attachment cards, quiz-answer modal,
// manual-answer modal) is intentionally the same proven markup that used
// to live in messages.php, just scoped to one subject_code and wrapped in
// this page's own banner/topbar instead of a generic "Messages" header.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/student_password.php';   // spwRequireChangedPassword()
require_once '../../../includes/ai_quiz_gform_helpers.php'; // aiQuizSyncFormResponses(), aiQuizGetResponses()
require_once '../../../includes/ai_resource_submission_helpers.php'; // aiResourceGetSubmission() — for the native in-app answer/manual-check case below
require_once '../../../includes/student_portal_ui.php'; // spSubjectColor(), spInitial()
require_once '../../../includes/classroom_helpers.php'; // classroomFileSizeLabel(), classroomMimeIcon() — for the read-only Class Stream/Classwork section below
require_once '../../../includes/grading_helpers.php'; // gh_getCurrentTerm() for the automatic banner chip

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../../../student_login.php');
    exit;
}

// First login: the account still holds the temporary password issued when the
// Admin approved the enrollment, so the portal may not be used until it is
// changed (see includes/student_password.php).
if (spwRequireChangedPassword()) exit;


$student_id = $_SESSION['student_id'];
$lrn = $_SESSION['student_lrn'];
$student_name = $_SESSION['student_name'];

$subject_code = trim((string) ($_GET['subject'] ?? ''));
if ($subject_code === '') {
    header('Location: dashboard.php');
    exit;
}

$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");

// ---- This student must actually be enrolled in a section this subject is
// taught to, this school year — never trust the ?subject= query string on
// its own just because it looks like a real subject code. ----
$class = getRecord(
    "SELECT sub.subject_code, sub.subject_name, u.first_name AS teacher_first, u.last_name AS teacher_last,
            e.section_id, ts.school_year_id, ts.teacher_id
     FROM enrollments e
     JOIN teacher_subjects ts ON ts.section_id = e.section_id AND ts.school_year_id = e.school_year_id
     JOIN subjects sub ON sub.subject_code = ts.subject_code
     JOIN users u ON u.user_id = ts.teacher_id
     WHERE e.student_id = ? AND e.school_year_id = ? AND sub.subject_code = ?
     LIMIT 1",
    [$student_id, $active_sy['sy_id'] ?? 0, $subject_code]
);
if (!$class) {
    header('Location: dashboard.php');
    exit;
}

// ---- Read-only view of the teacher's Google-Classroom-style Stream and
// Classwork for THIS class (Teacher Portal's modules/teachers/classroom.php
// writes these — this page never inserts, updates, or deletes any of it).
// Scoped to exactly the section/subject/school-year this student's own
// enrollment just proved above, the same "class" identity classroomValidateAccess()
// uses on the teacher side — never trusted from the URL, there is none here.
$class_stream_posts = getRecords(
    "SELECT * FROM class_stream_posts
     WHERE section_id = ? AND subject_code = ? AND school_year_id = ? AND teacher_id = ? AND is_deleted = 0
     ORDER BY created_at DESC LIMIT 50",
    [$class['section_id'], $subject_code, $class['school_year_id'], $class['teacher_id']]
);
if (!empty($class_stream_posts)) {
    $post_ids = array_column($class_stream_posts, 'post_id');
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $all_stream_attachments = getRecords(
        "SELECT * FROM class_stream_attachments WHERE post_id IN ($placeholders) ORDER BY attachment_id ASC",
        $post_ids
    );
    $stream_attachments_by_post = [];
    foreach ($all_stream_attachments as $a) {
        $stream_attachments_by_post[$a['post_id']][] = $a;
    }
    foreach ($class_stream_posts as &$__p) {
        $__p['attachments'] = $stream_attachments_by_post[$__p['post_id']] ?? [];
    }
    unset($__p);
}

$classwork_items = getRecords(
    "SELECT * FROM classwork_assignments
     WHERE section_id = ? AND subject_code = ? AND school_year_id = ? AND teacher_id = ? AND status = 'Published'
     ORDER BY created_at DESC LIMIT 50",
    [$class['section_id'], $subject_code, $class['school_year_id'], $class['teacher_id']]
);
if (!empty($classwork_items)) {
    $assignment_ids = array_column($classwork_items, 'assignment_id');
    $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
    $all_cw_attachments = getRecords(
        "SELECT * FROM classwork_assignment_attachments WHERE assignment_id IN ($placeholders) ORDER BY attachment_id ASC",
        $assignment_ids
    );
    $cw_attachments_by_assignment = [];
    foreach ($all_cw_attachments as $a) {
        $cw_attachments_by_assignment[$a['assignment_id']][] = $a;
    }
    foreach ($classwork_items as &$__c) {
        $__c['attachments'] = $cw_attachments_by_assignment[$__c['assignment_id']] ?? [];
    }
    unset($__c);
}

$student = getRecord("SELECT * FROM students WHERE student_id = ?", [$student_id]);

$csrf_token = generateCSRFToken();
$submitted_flash = isset($_GET['submitted']);
$submit_error = isset($_GET['submit_error']) ? (string) $_GET['submit_error'] : '';

// Same query messages.php used to run, just scoped to THIS subject_code —
// see that file's history for the full reasoning behind every join here.
$messages = getRecords(
    "SELECT m.*, u.first_name AS teacher_first, u.last_name AS teacher_last,
            r.resource_id AS attach_resource_id, r.title AS attach_title, r.resource_type AS attach_resource_type,
            r.output_format AS attach_format, r.original_filename AS attach_filename, r.file_size AS attach_size,
            r.audience AS attach_audience,
            r.google_form_view_url AS attach_gform_view_url, r.quiz_max_score AS attach_gform_max_score,
            r.quiz_questions_json AS attach_gform_questions_json, r.quiz_one_attempt AS attach_gform_one_attempt,
            ans.submission_id AS ans_submission_id, ans.is_auto_graded AS ans_is_auto_graded,
            ans.answers_json AS ans_answers_json, ans.score AS ans_score, ans.max_score AS ans_max_score,
            ans.status AS ans_status, ans.teacher_feedback AS ans_feedback, ans.submitted_at AS ans_submitted_at,
            ans.answer_original_filename AS ans_answer_filename
     FROM student_support_messages m
     JOIN users u ON m.teacher_id = u.user_id
     LEFT JOIN ai_generated_resources r ON r.resource_id = m.resource_id
     LEFT JOIN ai_resource_submissions ans ON ans.resource_id = r.resource_id AND ans.student_id = m.student_id
     WHERE m.student_id = ? AND m.subject_code = ? AND m.status = 'Sent'
     ORDER BY m.sent_at DESC",
    [$student_id, $subject_code]
);

// Same lazy Google-Forms-score sync messages.php used to do, scoped to
// this subject's attachments only.
$gform_scores = [];
foreach ($messages as $m) {
    if (($m['attach_format'] ?? null) === 'gform' && !empty($m['attach_resource_id'])) {
        aiQuizSyncFormResponses((int) $m['attach_resource_id']);
        $responses = aiQuizGetResponses((int) $m['attach_resource_id']);
        if (!empty($responses)) {
            $gform_scores[(int) $m['attach_resource_id']] = $responses[0];
        }
    }
}

$attach_type_labels = [
    'quiz' => 'Quiz', 'practice_quiz' => 'Practice Quiz', 'reviewer' => 'Reviewer',
    'remedial_activity' => 'Remedial Activity', 'worksheet' => 'Worksheet', 'practice_exercise' => 'Practice Exercise',
    'performance_task' => 'Performance Task', 'written_work' => 'Written Work', 'question_bank' => 'Question Bank',
    'study_guide' => 'Study Guide', 'study_plan' => 'Study Plan', 'flashcards' => 'Flashcards',
    'short_assessment' => 'Short Assessment', 'multiple_choice' => 'Multiple Choice Questions',
    'true_false' => 'True/False Questions', 'identification' => 'Identification Questions', 'essay' => 'Essay Questions',
    'reflection_activity' => 'Reflection Activity', 'reading_activity' => 'Reading Activity',
    'intervention_activity' => 'Intervention Activity', 'learning_activity' => 'Learning Activity', 'rubric' => 'Rubric',
    'assignment' => 'Assignment', 'practice_test' => 'Practice Test', 'other' => 'Learning Resource',
];

// Mark THIS subject's unseen messages seen now that the student opened it —
// other subjects' unread counts (shown on their own class cards) are
// untouched.
$unseen_ids = array_column(array_filter($messages, fn($m) => (int) $m['is_seen'] === 0), 'message_id');
if (!empty($unseen_ids)) {
    $placeholders = implode(',', array_fill(0, count($unseen_ids), '?'));
    executeQuery("UPDATE student_support_messages SET is_seen = 1, seen_at = NOW() WHERE message_id IN ($placeholders)", $unseen_ids);
    foreach ($messages as &$m) {
        if (in_array($m['message_id'], $unseen_ids, true)) { $m['is_seen'] = 1; }
    }
    unset($m);
}

$profile_image = '';
if (!empty($student['profile_image']) && file_exists('../../../static/uploads/profiles/' . $student['profile_image'])) {
    $profile_image = '../../../static/uploads/profiles/' . $student['profile_image'];
}

$subject_color = spSubjectColor($class['subject_code']);
$teacher_name = trim($class['teacher_first'] . ' ' . $class['teacher_last']);
$current_term = gh_getCurrentTerm($active_sy['sy_id'] ?? 0);
$class_schedule = getRecords("SELECT day_of_week, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time FROM teacher_class_schedule WHERE teacher_id=? AND subject_code=? AND section_id=? AND school_year_id=? AND is_active=1 ORDER BY day_of_week, start_time", [(int)$class['teacher_id'], $class['subject_code'], (int)$class['section_id'], (int)$class['school_year_id']]);
$page_title = $class['subject_name'];
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
    <title><?php echo htmlspecialchars($class['subject_name']); ?> - EGRADEPRO</title>
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
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer small { color: rgba(255,255,255,0.3); font-size: 12.5px; }

        .main-content { padding: 24px 32px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .btn-portal-logout { padding: 7px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 500; border: 1px solid var(--portal-border); background: var(--portal-card); color: var(--portal-gray); text-decoration: none; transition: all 0.15s; }

        /* Subject banner — colored per-subject, matching that subject's
           class card on dashboard.php (see includes/student_portal_ui.php). */
        .subject-banner { background-color: #123B6D; background-image: linear-gradient(110deg, rgba(8,31,61,0.92), rgba(18,59,109,0.72)), url('../../../static/images/school%20bldg%20bg.png'); background-size: cover; background-position: center 42%; color: #fff; border-radius: 10px; padding: 22px 26px 26px; margin-bottom: 22px; position: relative; overflow: hidden; text-shadow: 0 1px 3px rgba(0,0,0,0.55); }
        .subject-banner h2 { margin: 0 0 6px; font-size: 24px; font-weight: 700; }
        .subject-banner-meta { font-size: 14.5px; opacity: 0.9; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .subject-avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; }

        /* Class Stream / Classwork — read-only view of the teacher's
           Google-Classroom-style class page (modules/teachers/classroom.php).
           Visually related to .msg-card but tagged "Class" so it's clearly
           the whole-class feed, not a message sent to this student alone. */
        .class-section-label { font-size: 14.5px; font-weight: 700; color: var(--portal-gray); text-transform: uppercase; letter-spacing: .04em; margin: 22px 0 10px; }
        .class-section-label:first-of-type { margin-top: 0; }
        .class-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 12px; padding: 16px 18px; }
        .class-card-badge { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: rgba(20,33,61,0.08); color: var(--portal-primary); margin-bottom: 6px; letter-spacing: .03em; text-transform: uppercase; }
        .class-card-date { font-size: 12.5px; color: var(--portal-gray); float: right; }
        .class-card-body { font-size: 15px; color: #374151; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .class-card-title { font-size: 15px; font-weight: 700; color: var(--portal-primary); margin-bottom: 2px; }
        .class-card-category { font-size: 12.5px; color: var(--portal-gray); margin-bottom: 8px; }
        .msg-card { background: var(--portal-card); border: 1px solid var(--portal-border); border-radius: 8px; margin-bottom: 14px; padding: 18px 20px; }
        .msg-card.unread { border-left: 3px solid var(--portal-primary); }
        .msg-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
        .msg-teacher { font-size: 14px; font-weight: 600; color: var(--portal-primary); }
        .msg-date { font-size: 12.5px; color: var(--portal-gray); white-space: nowrap; }
        .msg-type { display: inline-block; font-size: 12.5px; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: rgba(20,33,61,0.06); color: var(--portal-primary); margin-bottom: 8px; }
        .msg-text { font-size: 15px; color: #374151; line-height: 1.6; white-space: pre-line; }

        .msg-attachment { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 12px 14px; background: #F8FAFC; border: 1px solid var(--portal-border); border-radius: 8px; flex-wrap: wrap; }
        .msg-attachment-icon { font-size: 22px; flex-shrink: 0; }
        .msg-attachment-icon.pdf { color: #C94A4A; }
        .msg-attachment-icon.docx { color: #2563eb; }
        .msg-attachment-info { flex: 1; min-width: 160px; }
        .msg-attachment-name { font-size: 14.5px; font-weight: 600; color: var(--portal-primary); word-break: break-word; }
        .msg-attachment-meta { font-size: 13px; color: var(--portal-gray); margin-top: 2px; }
        .msg-attachment-actions { display: flex; gap: 6px; }
        .btn-attach-action { font-size: 13.5px; font-weight: 500; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--portal-border); background: #fff; color: var(--portal-primary); text-decoration: none; transition: all 0.15s; white-space: nowrap; cursor: pointer; }
        .btn-attach-action:hover { background: #f1f5f9; color: var(--portal-primary); }

        .attach-status-pill { display: inline-block; font-size: 12.5px; font-weight: 600; padding: 2px 9px; border-radius: 99px; margin-top: 4px; }
        .attach-status-pill.waiting { background: rgba(183,121,31,0.12); color: var(--portal-yellow); }
        .attach-status-pill.reviewed { background: rgba(35,136,99,0.12); color: var(--portal-green); }
        .quiz-question-block { border: 1px solid var(--portal-border); border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; }
        .quiz-question-block .quiz-question-text { font-size: 15px; font-weight: 600; color: var(--portal-primary); margin-bottom: 8px; }
        .quiz-choice-label { display: flex; align-items: center; gap: 8px; font-size: 14.5px; padding: 6px 4px; border-radius: 6px; cursor: pointer; }
        .quiz-choice-label:hover { background: #F8FAFC; }
        .manual-answer-box textarea { font-size: 15px; }
        .flash-banner { border-radius: 8px; padding: 10px 16px; font-size: 14.5px; margin-bottom: 16px; }
        .flash-banner.success { background: rgba(35,136,99,0.10); color: var(--portal-green); border: 1px solid rgba(35,136,99,0.25); }
        .flash-banner.error { background: rgba(201,74,74,0.10); color: var(--portal-red); border: 1px solid rgba(201,74,74,0.25); }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--portal-gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-state h5 { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: #9ca3af; }

        @media (max-width: 768px) {
            .sidebar { position: fixed !important; top: 0; left: -280px; width: 260px; height: 100vh; z-index: 1050; transition: left 0.25s ease; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .sidebar.show { left: 0; }
            .mobile-menu-btn { display: inline-flex !important; }
            .main-content { padding: 16px !important; margin-left: 0 !important; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .subject-banner h2 { font-size: 19px; }
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
        <!-- Sidebar -->
        <div class="col-md-2 sidebar" id="mainSidebar">
            <div class="logo">
                <img src="../../../static/images/logo.png" alt="Logo" onerror="this.style.display='none'">
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

        <!-- Main Content -->
        <div class="col-md-10 main-content">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn btn btn-sm" onclick="toggleSidebar()" style="display:none;border:1px solid var(--portal-border);background:#fff;border-radius:6px;padding:6px 10px;"><i class="fas fa-bars"></i></button>
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

            <div class="subject-banner" style="--card-color: <?php echo htmlspecialchars($subject_color); ?>;">
                <div class="subject-banner-icon"><i class="fas fa-book-open"></i></div>
                <h2><?php echo htmlspecialchars($class['subject_name']); ?></h2>
                <div class="subject-banner-meta">
                    <div class="subject-avatar"><?php echo htmlspecialchars(spInitial($class['teacher_first'])); ?></div>
                    <span><?php echo htmlspecialchars($teacher_name); ?></span>
                    <span class="subject-banner-chip">Student Class</span>
                    <?php if ($current_term): ?><span class="subject-banner-chip active"><?php echo htmlspecialchars($current_term); ?></span><?php endif; ?>
                    <span class="subject-banner-chip">SY <?php echo htmlspecialchars($active_sy['sy_name'] ?? ''); ?></span>
                    <?php if ($class_schedule): ?>
                        <?php $student_day_names = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri']; ?>
                        <span class="subject-banner-chip"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(implode('-', array_map(function ($row) use ($student_day_names) { return $student_day_names[(int)$row['day_of_week']] ?? ''; }, $class_schedule))); ?> · <?php echo date('g:i A', strtotime($class_schedule[0]['start_time'])); ?>–<?php echo date('g:i A', strtotime($class_schedule[0]['end_time'])); ?></span>
                    <?php endif; ?>
                </div>
                <div class="subject-banner-stats">
                    <div><strong><?php echo count($class_stream_posts); ?></strong><span>Announcements</span></div>
                    <div><strong><?php echo count($classwork_items); ?></strong><span>Assignments</span></div>
                </div>
            </div>

            <?php if ($submitted_flash): ?>
                <div class="flash-banner success"><i class="fas fa-check-circle me-1"></i>Your answer was submitted.</div>
            <?php elseif ($submit_error !== ''): ?>
                <div class="flash-banner error"><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($submit_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($class_stream_posts) || !empty($classwork_items)): ?>
            <div class="class-section-label"><i class="fas fa-bullhorn me-1"></i>Class Announcements</div>
            <?php if (empty($class_stream_posts)): ?>
                <div class="class-card" style="color:var(--portal-gray);font-size:14.5px;">No class announcements posted yet.</div>
            <?php else: ?>
                <?php foreach ($class_stream_posts as $post): ?>
                <div class="class-card">
                    <span class="class-card-date"><?php echo date('M j, Y g:i A', strtotime($post['created_at'])); ?></span>
                    <span class="class-card-badge">Class</span>
                    <div class="class-card-body"><?php echo nl2br(htmlspecialchars($post['body'])); ?></div>
                    <?php if (!empty($post['attachments'])): ?>
                        <div class="mt-2">
                        <?php foreach ($post['attachments'] as $att): ?>
                            <?php if ($att['attachment_type'] === 'link'): ?>
                                <a class="msg-attachment-name d-inline-block me-3" href="<?php echo htmlspecialchars($att['external_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($att['external_url']); ?></a>
                            <?php else: ?>
                                <a class="msg-attachment-name d-inline-block me-3" href="../../teachers/classroom_download.php?kind=stream&id=<?php echo (int) $att['attachment_id']; ?>"><i class="fas <?php echo classroomMimeIcon($att['mime_type'], $att['original_filename']); ?> me-1"></i><?php echo htmlspecialchars($att['original_filename']); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="class-section-label"><i class="fas fa-clipboard-list me-1"></i>Classwork</div>
            <?php if (empty($classwork_items)): ?>
                <div class="class-card" style="color:var(--portal-gray);font-size:14.5px;">No classwork has been posted yet.</div>
            <?php else: ?>
                <?php foreach ($classwork_items as $cw): ?>
                <div class="class-card">
                    <span class="class-card-date"><?php echo date('M j, Y g:i A', strtotime($cw['created_at'])); ?></span>
                    <div class="class-card-title"><?php echo htmlspecialchars($cw['title']); ?></div>
                    <div class="class-card-category"><?php echo htmlspecialchars($cw['category']); ?><?php if (!empty($cw['due_date'])) echo ' &middot; Due ' . htmlspecialchars(date('M j, Y g:i A', strtotime($cw['due_date']))); ?></div>
                    <?php if (!empty($cw['instructions'])): ?><div class="class-card-body"><?php echo nl2br(htmlspecialchars($cw['instructions'])); ?></div><?php endif; ?>
                    <?php if (!empty($cw['attachments'])): ?>
                        <div class="mt-2">
                        <?php foreach ($cw['attachments'] as $att): ?>
                            <?php if ($att['attachment_type'] === 'link'): ?>
                                <a class="msg-attachment-name d-inline-block me-3" href="<?php echo htmlspecialchars($att['external_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($att['external_url']); ?></a>
                            <?php else: ?>
                                <a class="msg-attachment-name d-inline-block me-3" href="../../teachers/classroom_download.php?kind=classwork&id=<?php echo (int) $att['attachment_id']; ?>"><i class="fas <?php echo classroomMimeIcon($att['mime_type'], $att['original_filename']); ?> me-1"></i><?php echo htmlspecialchars($att['original_filename']); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="class-section-label"><i class="fas fa-envelope me-1"></i>Messages for you</div>
            <?php endif; ?>

            <?php if (empty($messages)): ?>
                <div class="msg-card empty-state">
                    <i class="fas fa-chalkboard"></i>
                    <h5>Nothing here yet</h5>
                    <p class="mb-0">Activities, tasks, and messages from <?php echo htmlspecialchars($teacher_name); ?> will show up here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="msg-card" <?php echo !empty($m['attach_resource_id']) ? 'id="resource-' . (int) $m['attach_resource_id'] . '"' : ''; ?>>
                        <div class="msg-header">
                            <div>
                                <div class="msg-teacher"><?php echo htmlspecialchars($m['teacher_first'] . ' ' . $m['teacher_last']); ?></div>
                            </div>
                            <div class="msg-date"><?php echo date('M d, Y g:i A', strtotime($m['sent_at'])); ?></div>
                        </div>
                        <span class="msg-type"><?php echo htmlspecialchars($m['support_type']); ?></span>
                        <div class="msg-text"><?php echo htmlspecialchars($m['final_message']); ?></div>
                        <?php if (!empty($m['attach_resource_id']) && $m['attach_audience'] === 'student' && $m['attach_format'] === 'gform'): ?>
                            <?php
                                $type_label = $attach_type_labels[$m['attach_resource_type']] ?? 'Quiz';
                                $question_count = 0;
                                $decoded_q = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
                                if (is_array($decoded_q)) { $question_count = count($decoded_q); }
                                $max_score = (int) ($m['attach_gform_max_score'] ?? 0);
                                $latest = $gform_scores[(int) $m['attach_resource_id']] ?? null;
                                $one_attempt = (int) ($m['attach_gform_one_attempt'] ?? 0) === 1;
                                $locked = $one_attempt && $latest;
                            ?>
                            <div class="msg-attachment">
                                <div class="msg-attachment-icon" style="color:#238863;">
                                    <i class="fas fa-square-poll-vertical"></i>
                                </div>
                                <div class="msg-attachment-info">
                                    <div class="msg-attachment-name"><?php echo htmlspecialchars($m['attach_title']); ?></div>
                                    <div class="msg-attachment-meta">
                                        <?php echo htmlspecialchars(implode(' • ', array_filter([$type_label, $question_count ? $question_count . ' items' : '', $max_score ? $max_score . ' points' : '', $one_attempt ? 'One attempt only' : '']))); ?>
                                        <?php if ($latest): ?>
                                            <?php $latest_pct = ((float) $latest['max_score'] > 0) ? round(((float) $latest['score'] / (float) $latest['max_score']) * 100, 1) : null; ?>
                                            <br><strong style="color:#238863;">Your score: <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $latest['score'], 1), '0'), '.')); ?>/<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $latest['max_score'], 1), '0'), '.')); ?><?php echo $latest_pct !== null ? ' (' . htmlspecialchars(rtrim(rtrim(number_format($latest_pct, 1), '0'), '.')) . '%)' : ''; ?></strong> — submitted <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($latest['submitted_at']))); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="msg-attachment-actions">
                                    <?php if ($locked): ?>
                                        <span class="btn-attach-action" style="cursor:default;color:var(--portal-gray);"><i class="fas fa-lock me-1"></i>Quiz Completed</span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($m['attach_gform_view_url']); ?>" target="_blank" rel="noopener" class="btn-attach-action"><i class="fas fa-square-poll-vertical me-1"></i><?php echo $latest ? 'Open Quiz Again' : 'Open Quiz'; ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif (!empty($m['attach_resource_id']) && $m['attach_audience'] === 'student'): ?>
                            <?php
                                $is_docx = $m['attach_format'] === 'docx';
                                $type_label = $attach_type_labels[$m['attach_resource_type']] ?? 'Learning Resource';
                                $size_kb = $m['attach_size'] ? max(1, round($m['attach_size'] / 1024)) . ' KB' : '';
                                $native_questions = json_decode((string) ($m['attach_gform_questions_json'] ?? ''), true);
                                $has_native_quiz = is_array($native_questions) && count($native_questions) > 0;
                                $rid = (int) $m['attach_resource_id'];
                                $has_submission = !empty($m['ans_submission_id']);
                                $ans_reviewed = $has_submission && $m['ans_status'] === 'Reviewed';
                            ?>
                            <div class="msg-attachment">
                                <div class="msg-attachment-icon <?php echo $is_docx ? 'docx' : 'pdf'; ?>">
                                    <i class="fas <?php echo $is_docx ? 'fa-file-word' : 'fa-file-pdf'; ?>"></i>
                                </div>
                                <div class="msg-attachment-info">
                                    <div class="msg-attachment-name"><?php echo htmlspecialchars($m['attach_filename'] ?: $m['attach_title']); ?></div>
                                    <div class="msg-attachment-meta"><?php echo htmlspecialchars(implode(' • ', array_filter([$type_label, $is_docx ? 'Microsoft Word' : 'PDF', $size_kb]))); ?></div>
                                    <?php if ($has_native_quiz && $has_submission): ?>
                                        <?php $ans_pct = ((float) $m['ans_max_score'] > 0) ? round(((float) $m['ans_score'] / (float) $m['ans_max_score']) * 100, 1) : null; ?>
                                        <div><strong style="color:#238863;">Your score: <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $m['ans_score'], 1), '0'), '.')); ?>/<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $m['ans_max_score'], 1), '0'), '.')); ?><?php echo $ans_pct !== null ? ' (' . htmlspecialchars(rtrim(rtrim(number_format($ans_pct, 1), '0'), '.')) . '%)' : ''; ?></strong> — answered <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($m['ans_submitted_at']))); ?></div>
                                    <?php elseif (!$has_native_quiz && $has_submission): ?>
                                        <div style="font-size:13.5px;color:var(--portal-gray);"><i class="fas fa-paperclip me-1"></i>You attached: <?php echo htmlspecialchars($m['ans_answer_filename'] ?: 'your answer'); ?></div>
                                        <?php if ($ans_reviewed): ?>
                                            <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Checked by your teacher</span>
                                        <?php else: ?>
                                            <span class="attach-status-pill waiting"><i class="fas fa-hourglass-half me-1"></i>Waiting for your teacher to check</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="msg-attachment-actions">
                                    <a href="../../ai/view_resource.php?id=<?php echo $rid; ?>" target="_blank" rel="noopener" class="btn-attach-action"><i class="fas fa-eye me-1"></i>View</a>
                                    <a href="../../ai/download_resource.php?id=<?php echo $rid; ?>" class="btn-attach-action"><i class="fas fa-download me-1"></i>Download</a>
                                    <?php if ($has_native_quiz): ?>
                                        <button type="button" class="btn-attach-action" style="background:#14213D;color:#fff;border-color:#14213D;" data-bs-toggle="modal" data-bs-target="#quizModal<?php echo $rid; ?>">
                                            <i class="fas fa-pen me-1"></i><?php echo $has_submission ? 'Retake' : 'Answer Now'; ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-attach-action" style="background:#14213D;color:#fff;border-color:#14213D;" data-bs-toggle="modal" data-bs-target="#answerModal<?php echo $rid; ?>">
                                            <i class="fas fa-pen me-1"></i><?php echo $has_submission ? 'View My Answer' : 'Submit Your Answer'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($has_native_quiz): ?>
                                <?php $prev_quiz_answers = $has_submission ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : []; ?>
                                <div class="modal fade" id="quizModal<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <form method="POST" action="submit_resource_answer.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="resource_id" value="<?php echo $rid; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><?php echo htmlspecialchars($m['attach_title']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <?php foreach (array_values($native_questions) as $qi => $q): ?>
                                                        <div class="quiz-question-block">
                                                            <div class="quiz-question-text"><?php echo $qi + 1; ?>. <?php echo htmlspecialchars($q['question'] ?? ''); ?></div>
                                                            <?php foreach ((array) ($q['choices'] ?? []) as $ci => $choice): ?>
                                                                <label class="quiz-choice-label">
                                                                    <input type="radio" name="quiz_answers[<?php echo $qi; ?>]" value="<?php echo $ci; ?>" <?php echo (isset($prev_quiz_answers[$qi]) && (int) $prev_quiz_answers[$qi] === $ci) ? 'checked' : ''; ?>>
                                                                    <span><?php echo htmlspecialchars($choice); ?></span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm" style="border:1px solid var(--portal-border);background:#fff;" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;">Submit Answers</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                    $prev_manual = $has_submission ? (json_decode((string) ($m['ans_answers_json'] ?? ''), true) ?: []) : [];
                                    $prev_note = $prev_manual['note'] ?? '';
                                    $prev_filename = $m['ans_answer_filename'] ?? '';
                                ?>
                                <div class="modal fade" id="answerModal<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <form method="POST" action="submit_resource_answer.php" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="resource_id" value="<?php echo $rid; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><?php echo htmlspecialchars($m['attach_title']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body manual-answer-box">
                                                    <p style="font-size:14px;color:var(--portal-gray);">
                                                        View or download the activity above, answer it in your own copy (Word doc, printed and written on, or a photo of your paper), then attach that answered file here. This kind of activity has no single correct answer to auto-check, so your teacher will open it and check it themselves.
                                                    </p>

                                                    <?php if ($prev_filename): ?>
                                                        <div style="display:flex;align-items:center;gap:8px;background:#F8FAFC;border:1px solid var(--portal-border);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:14.5px;">
                                                            <i class="fas fa-paperclip"></i>
                                                            <span style="flex:1;word-break:break-word;"><?php echo htmlspecialchars($prev_filename); ?></span>
                                                            <a href="../../ai/view_submission_answer.php?id=<?php echo (int) $m['ans_submission_id']; ?>" target="_blank" rel="noopener" style="font-size:13.5px;">View</a>
                                                            <a href="../../ai/download_submission_answer.php?id=<?php echo (int) $m['ans_submission_id']; ?>" style="font-size:13.5px;">Download</a>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!$ans_reviewed): ?>
                                                        <label class="form-label" style="font-size:14px;font-weight:600;"><?php echo $prev_filename ? 'Replace attached file (optional)' : 'Attach your answered file'; ?></label>
                                                        <input type="file" name="answer_file" class="form-control mb-2" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                                        <label class="form-label" style="font-size:14px;font-weight:600;">Note for your teacher (optional)</label>
                                                        <textarea name="answer_note" class="form-control" rows="3" placeholder="Anything you want your teacher to know..."><?php echo htmlspecialchars($prev_note); ?></textarea>
                                                    <?php elseif ($prev_note): ?>
                                                        <p class="text-muted mb-1" style="font-size:14px;">Your note:</p>
                                                        <div style="white-space:pre-wrap;font-size:15px;margin-bottom:10px;"><?php echo nl2br(htmlspecialchars($prev_note)); ?></div>
                                                    <?php endif; ?>

                                                    <?php if ($ans_reviewed): ?>
                                                        <div class="mt-3">
                                                            <span class="attach-status-pill reviewed"><i class="fas fa-check me-1"></i>Checked by your teacher</span>
                                                            <?php if (!empty($m['ans_feedback'])): ?>
                                                                <div class="mt-2" style="font-size:14.5px;background:#F8FAFC;border:1px solid var(--portal-border);border-radius:8px;padding:10px 12px;">
                                                                    <strong>Teacher's feedback:</strong><br><?php echo nl2br(htmlspecialchars($m['ans_feedback'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($has_submission): ?>
                                                        <div class="mt-2"><span class="attach-status-pill waiting"><i class="fas fa-hourglass-half me-1"></i>Waiting for your teacher to check</span></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm" style="border:1px solid var(--portal-border);background:#fff;" data-bs-dismiss="modal">Close</button>
                                                    <?php if (!$ans_reviewed): ?>
                                                        <button type="submit" class="btn btn-sm" style="background:#14213D;color:#fff;"><?php echo $has_submission ? 'Update Submission' : 'Submit for Checking'; ?></button>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle + topbar profile dropdown live in the shared
// static/js/student-portal-shell.js (linked in <head>).
</script>
</body>
</html>
