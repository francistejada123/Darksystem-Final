<?php
// modules/students/portal/log_activity.php
// AJAX endpoint (fetch) called from modules/students/portal/tutorial.php
// when a student expands a Published AI Study Reviewer or Practice Quiz —
// records that they actually opened it, for the "Reviewer viewed" /
// "Quiz [viewed]" engagement signal from Section 4 of the improvement spec.
//
// Same lightweight pattern as tutorial_progress.php: session-checked, and
// every ownership check is scoped to the LOGGED-IN student's own id from
// the session — never a client-supplied student_id — so a student can only
// ever log activity against their own record. No CSRF token: this is a
// passive, self-scoped activity note (not a data mutation of academic
// content), matching tutorial_progress.php's existing precedent.

session_start();
require_once '../../../config/database.php';
require_once '../../../includes/student_activity_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'Student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$student_id = (int) $_SESSION['student_id'];

$event_type = (string) ($_POST['event_type'] ?? '');
$reference_id = (int) ($_POST['reference_id'] ?? 0);

// 'class_content_viewed' (Section 10) is the section-wide equivalent of
// reviewer_viewed/quiz_viewed — a student opening a class-wide reviewer,
// quiz, practice quiz, or remedial activity their teacher Published from
// Manage Assessment Items (modules/grading/items.php), rather than one
// generated just for them.
if (!in_array($event_type, ['reviewer_viewed', 'quiz_viewed', 'class_content_viewed'], true) || !$reference_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

// Ownership check — only log a "viewed" event for a Published item that
// actually belongs to THIS student, never anyone else's.
if ($event_type === 'reviewer_viewed') {
    $owns = getRecord("SELECT reviewer_id FROM ai_reviewers WHERE reviewer_id = ? AND student_id = ? AND status = 'Published'", [$reference_id, $student_id]);
} elseif ($event_type === 'quiz_viewed') {
    $owns = getRecord("SELECT quiz_id FROM ai_practice_quizzes WHERE quiz_id = ? AND student_id = ? AND status = 'Published'", [$reference_id, $student_id]);
} else {
    // Class-wide content has no student_id column — "owns" means the
    // student's own CURRENT section actually matches this content's
    // section, and the content is actually Published.
    $enrollment_sec = getRecord("
        SELECT e.section_id FROM enrollments e
        JOIN school_years sy ON e.school_year_id = sy.sy_id
        WHERE e.student_id = ? AND sy.is_active = 1
    ", [$student_id]);
    $owns = $enrollment_sec
        ? getRecord("SELECT content_id FROM ai_class_content WHERE content_id = ? AND section_id = ? AND status = 'Published'", [$reference_id, $enrollment_sec['section_id']])
        : null;
}

if (!$owns) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

// Throttled to once per hour per item — expanding/collapsing the same
// <details> repeatedly during one visit shouldn't flood the activity log.
$event_labels = [
    'reviewer_viewed' => 'Opened an AI study reviewer',
    'quiz_viewed' => 'Opened an AI practice quiz',
    'class_content_viewed' => 'Opened class material from a teacher',
];
$event_tables = [
    'reviewer_viewed' => 'ai_reviewers',
    'quiz_viewed' => 'ai_practice_quizzes',
    'class_content_viewed' => 'ai_class_content',
];
logStudentActivity(
    $student_id,
    $event_type,
    $event_labels[$event_type],
    $event_tables[$event_type],
    $reference_id,
    60
);

echo json_encode(['success' => true]);
