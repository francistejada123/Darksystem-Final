<?php
// modules/ai/save_quiz.php
// AJAX endpoint: the teacher reviews/edits an AI-generated practice quiz
// (or their own manual edits to one already saved) inside the Ask AI
// modal and clicks "Save Draft" or "Publish".
//
// This is the ONLY place a practice quiz is written to the database or
// made visible to a student — modules/ai/chatbot.php's generate_quiz
// action only ever returns a draft array, never saves anything.
// Ownership/eligibility is re-validated here from scratch, same as
// save_reviewer.php / send_to_student.php / chatbot.php.
//
// NOTE: "Published" here only means the practice quiz content is visible
// to the student as a static review sheet (modules/students/portal/
// tutorial.php) — NOT yet an interactive, auto-graded online quiz. That
// is a separate, later feature (Section 11 of the improvement spec) that
// will read Published rows from this same table to create the
// interactive version; it does not replace this one.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gemini_service.php'; // validateTeacherSubjectForStudent()

header('Content-Type: application/json');

if (!hasRole('Teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_code = trim((string) ($_POST['subject_code'] ?? ''));
$title = trim((string) ($_POST['title'] ?? ''));
$question_type = (string) ($_POST['question_type'] ?? 'multiple_choice');
$status = ($_POST['status'] ?? '') === 'Published' ? 'Published' : 'Draft';
$quiz_id = (int) ($_POST['quiz_id'] ?? 0);
$questions_raw = (string) ($_POST['questions_json'] ?? '');

if (!$teacher_id || !$student_id || $subject_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student or subject.']);
    exit;
}
if (!in_array($question_type, ['multiple_choice', 'true_false', 'mixed'], true)) {
    $question_type = 'multiple_choice';
}

// ---- Re-validate the teacher/student/subject from scratch. ----
$active_sy = getRecord("SELECT * FROM school_years WHERE is_active = 1");
if (!$active_sy) {
    echo json_encode(['success' => false, 'error' => 'No active school year is set.']);
    exit;
}

$check = validateTeacherSubjectForStudent($teacher_id, $student_id, $subject_code, $active_sy['sy_id']);
if (!$check['success']) {
    echo json_encode(['success' => false, 'error' => $check['error']]);
    exit;
}
$subject_name = $check['subject']['subject_name'];

if ($title === '') {
    $title = 'Practice Quiz — ' . $subject_name;
}
if (mb_strlen($title) > 150) {
    $title = mb_substr($title, 0, 150);
}

// ---- Strictly re-validate the (possibly teacher-edited) question JSON —
// never trust the browser's shape, even though it started from an
// AI-generated + already-validated draft. Reuses the exact same validator
// getGeminiPracticeQuiz() runs on the model's own output, so a
// hand-edited quiz is held to the same standard as a generated one. ----
$decoded = json_decode($questions_raw, true);
$requested_count = is_array($decoded['questions'] ?? null) ? count($decoded['questions']) : 0;
$questions = $requested_count > 0 ? parseAndValidateQuizJson($questions_raw, $requested_count) : null;

if ($questions === null || empty($questions)) {
    echo json_encode(['success' => false, 'error' => 'The quiz questions are incomplete or invalid. Please check each question has text, choices, and a correct answer selected.']);
    exit;
}

$clean_json = json_encode(['questions' => $questions]);
$published_at_sql = $status === 'Published' ? 'NOW()' : 'NULL';

if ($quiz_id > 0) {
    $existing = getRecord(
        "SELECT quiz_id FROM ai_practice_quizzes WHERE quiz_id = ? AND teacher_id = ? AND student_id = ? AND subject_code = ?",
        [$quiz_id, $teacher_id, $student_id, $subject_code]
    );
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Quiz not found.']);
        exit;
    }
    $ok = updateRecord(
        "UPDATE ai_practice_quizzes SET title = ?, question_type = ?, questions_json = ?, status = ?, published_at = " . $published_at_sql . " WHERE quiz_id = ?",
        [$title, $question_type, $clean_json, $status, $quiz_id]
    );
    if ($ok === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save the quiz. Please try again.']);
        exit;
    }
} else {
    $quiz_id = insertRecord(
        "INSERT INTO ai_practice_quizzes (student_id, teacher_id, subject_code, school_year_id, title, question_type, questions_json, status, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, " . $published_at_sql . ")",
        [$student_id, $teacher_id, $subject_code, $active_sy['sy_id'], $title, $question_type, $clean_json, $status]
    );
    if (!$quiz_id) {
        echo json_encode(['success' => false, 'error' => 'Could not save the quiz. Please try again.']);
        exit;
    }
}

logActivity(
    $teacher_id,
    'AI Quiz',
    $status === 'Published' ? 'Published practice quiz' : 'Saved practice quiz draft',
    "Quiz #$quiz_id (" . count($questions) . " questions) for student #$student_id, subject $subject_code"
);

echo json_encode([
    'success' => true,
    'quiz_id' => (int) $quiz_id,
    'status' => $status,
    'question_count' => count($questions),
]);
